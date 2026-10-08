<?php

namespace App\Services\Transcription;

use App\Exceptions\SubtitleProcessingException;
use App\Models\SubtitleJob;
use App\Services\Audio\ElevenLabsScribeAudioPreparer;
use App\Services\Audio\TemporaryAudioFile;
use App\Services\Languages\LanguageCatalog;
use App\Services\Subtitles\ProviderAdmission;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Enums\Lab;
use Throwable;

class ElevenLabsScribeTranscriptionService
{
    public function __construct(
        private readonly ScribeTranscriptNormalizer $normalizer,
        private readonly ElevenLabsScribeAudioPreparer $audioPreparer,
        private readonly ScribeChunkPayloadMerger $chunkMerger,
    ) {}

    public function prepareAudio(TemporaryAudioFile $audio): TemporaryAudioFile
    {
        $this->assertAudioCanBePrepared($audio);

        return $this->audioPreparer->prepare($audio);
    }

    public function assertAudioCanBePrepared(TemporaryAudioFile $audio): void
    {
        $this->transcriptionConfig(Lab::ElevenLabs);
        $this->assertSupportedAudioMime($audio);
    }

    /**
     * Transcribes one audio chunk and returns only the provider fields the
     * chunk merger consumes. Chunks are transcribed by independent queue jobs,
     * so this sends exactly one request; merging happens in
     * transcriptFromChunkPayloads.
     *
     * @return array<string, mixed>
     */
    public function transcribeChunk(TemporaryAudioFile $audio, string $sourceLanguage, ?SubtitleJob $job = null): array
    {
        $this->assertSupportedAudioMime($audio);

        return $this->transcribeSource($audio, $sourceLanguage, $job);
    }

    /** @return array<string, mixed> */
    public function transcribeYouTube(string $videoId, string $sourceLanguage, ?SubtitleJob $job = null): array
    {
        if (preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId) !== 1) {
            throw SubtitleProcessingException::transcriptionFailed(context: ['reason' => 'invalid_video_id']);
        }

        return $this->transcribeSource('https://www.youtube.com/watch?v='.$videoId, $sourceLanguage, $job);
    }

    /** @return array<string, mixed> */
    private function transcribeSource(TemporaryAudioFile|string $audio, string $sourceLanguage, ?SubtitleJob $job = null): array
    {
        $provider = Lab::ElevenLabs;
        ['apiKey' => $apiKey, 'model' => $model] = $this->transcriptionConfig($provider);

        try {
            return $this->validatedTranscriptionPayload(
                $this->sendTranscriptionRequest($audio, $sourceLanguage, $provider, $apiKey, $model, $job),
                $provider,
                $model,
            );
        } catch (ConnectionException $exception) {
            throw SubtitleProcessingException::providerUnavailable(context: ['provider' => $provider->value, 'adapter' => 'elevenlabs-http', 'model' => $model], previous: $exception);
        } catch (SubtitleProcessingException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw SubtitleProcessingException::transcriptionFailed(
                context: [
                    'provider' => $provider->value,
                    'adapter' => 'elevenlabs-http',
                    'model' => $model,
                    'exception' => $exception::class,
                ],
                previous: $exception,
            );
        }
    }

    /**
     * Merges per-chunk payloads (in chunk order) into one normalized
     * transcript. A single whole-audio chunk goes through the same merge
     * path with zero offset and an unbounded nominal window.
     *
     * @param  array<int, array{payload: array<string, mixed>, audioStartSeconds: float, nominalStartSeconds: float, nominalEndSeconds: float|null}>  $chunks
     */
    public function transcriptFromChunkPayloads(
        array $chunks,
        string $sourceLanguage,
        ?int $durationSeconds,
        ?string $jobId = null,
        ?string $runId = null,
    ): TimestampedTranscript {
        $provider = Lab::ElevenLabs;
        ['model' => $model] = $this->transcriptionConfig($provider);

        try {
            $payload = $this->chunkMerger->merge($chunks);
            $scores = array_column(array_filter($payload['words'], fn (array $word): bool => $word['type'] === 'word'), 'logprob');
            Log::info('backend.transcription_quality', [
                'job_id' => $jobId,
                'run_id' => $runId,
                'chunk_count' => count($chunks),
                'detected_language' => $payload['language_code'] ?? null,
                'chunk_languages' => array_map(fn (array $chunk): ?string => LanguageCatalog::normalizeCode($chunk['payload']['language_code'] ?? null), $chunks),
                'chunk_language_probabilities' => array_map(fn (array $chunk): mixed => $chunk['payload']['language_probability'] ?? null, $chunks),
                'scored_word_count' => count($scores),
                'mean_word_logprob' => $scores === [] ? null : round(array_sum($scores) / count($scores), 4),
                'min_word_logprob' => $scores === [] ? null : min($scores),
            ]);

            return $this->normalizer->normalize(
                payload: $payload,
                requestedSourceLanguage: $sourceLanguage,
                durationSeconds: $durationSeconds,
            );
        } catch (SubtitleProcessingException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw SubtitleProcessingException::transcriptionFailed(
                context: [
                    'provider' => $provider->value,
                    'adapter' => 'elevenlabs-http',
                    'model' => $model,
                    'exception' => $exception::class,
                ],
                previous: $exception,
            );
        }
    }

    /** Only closed cues before the unresolved overlap can be published early. */
    public function stableTranscriptPrefix(array $chunks, string $sourceLanguage, int $durationSeconds): ?TimestampedTranscript
    {
        if ($chunks === []) {
            return null;
        }
        $last = $chunks[array_key_last($chunks)];
        $boundary = $last['nextAudioStartSeconds'] ?? null;
        if (! is_numeric($boundary)) {
            return null;
        }
        $safeEnd = (float) $boundary;
        // A long word can start before the overlap. Hold its entire cue back,
        // since the next chunk may supply a better version of that word.
        foreach ($last['payload']['words'] as $word) {
            if (($word['type'] ?? null) === 'word' && is_numeric($word['end'] ?? null)
                && $boundary <= $word['end'] + $last['audioStartSeconds']) {
                $safeEnd = min($safeEnd, $word['start'] + $last['audioStartSeconds']);
            }
        }
        $payload = $this->chunkMerger->merge($chunks);
        // Trailing untimed words attach to the last timed word until another
        // chunk supplies a following word. That final cue is not stable yet.
        $untimedTail = false;
        foreach (array_reverse($payload['words']) as $word) {
            if (! is_numeric($word['start'] ?? null) || ! is_numeric($word['end'] ?? null) || $word['end'] <= $word['start']) {
                $untimedTail = true;

                continue;
            }
            if ($untimedTail) {
                $safeEnd = min($safeEnd, (float) $word['start']);
            }
            break;
        }
        if ($safeEnd <= 0 || ! array_filter($payload['words'], fn (array $word): bool => is_numeric($word['start'] ?? null) && is_numeric($word['end'] ?? null) && $word['end'] > $word['start']) || ($sourceLanguage === 'auto'
            && LanguageCatalog::normalizeCode($payload['language_code'] ?? null) === null)) {
            return null;
        }

        return $this->normalizer->normalize($payload, $sourceLanguage, $durationSeconds, $safeEnd);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function validatedTranscriptionPayload(
        Response $response,
        Lab $provider,
        string $model,
        array $context = [],
    ): array {
        if ($response->failed()) {
            $context = ['provider' => $provider->value, 'adapter' => 'elevenlabs-http', 'model' => $model, 'status' => $response->status(), ...$context];
            $quotaExhausted = in_array($response->json('detail.status'), ['quota_exceeded', 'insufficient_credits', 'credit_balance_exhausted'], true);
            if ($response->status() === 429 && ! $quotaExhausted) {
                throw SubtitleProcessingException::rateLimited(context: $context);
            }
            if ($response->serverError() && ! $quotaExhausted) {
                throw SubtitleProcessingException::providerUnavailable(context: $context);
            }
            throw SubtitleProcessingException::transcriptionFailed('Transcription provider request failed.', [
                'provider' => $provider->value,
                'adapter' => 'elevenlabs-http',
                'model' => $model,
                'status' => $response->status(),
                ...$context,
            ]);
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw SubtitleProcessingException::transcriptionFailed('Transcription provider returned invalid JSON.', [
                'provider' => $provider->value,
                'adapter' => 'elevenlabs-http',
                'model' => $model,
                'reason' => 'invalid_json',
                ...$context,
            ]);
        }

        return $this->normalizedTranscriptionPayload($payload, $provider, $model, $context);
    }

    /**
     * Store only the fields required by ScribeChunkPayloadMerger. Keeping the
     * provider response at this boundary would persist unbounded provider
     * metadata in job artifacts.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     * @return array{language_code?: string, words: array<int, array{text: string, type: string, start?: float, end?: float}>}
     */
    private function normalizedTranscriptionPayload(
        array $payload,
        Lab $provider,
        string $model,
        array $context,
    ): array {
        $words = $payload['words'] ?? null;

        if (! is_array($words) || ! array_is_list($words)) {
            $this->failInvalidProviderPayload($provider, $model, 'missing_words', $context);
        }

        $normalizedWords = [];

        foreach ($words as $index => $word) {
            if (! is_array($word) || ! is_string($word['text'] ?? null) || ! is_string($word['type'] ?? null)) {
                $this->failInvalidProviderPayload($provider, $model, 'invalid_word', $context, $index);
            }

            $normalized = [
                'text' => $word['text'],
                'type' => $word['type'],
            ];
            $logprob = $word['logprob'] ?? null;
            if (is_numeric($logprob) && is_finite((float) $logprob) && (float) $logprob <= 0) {
                $normalized['logprob'] = (float) $logprob;
            }

            // Scribe permits paired null timings for untimed words/events.
            if (($word['start'] ?? null) === null && ($word['end'] ?? null) === null) {
                unset($word['start'], $word['end']);
            }
            $hasStart = array_key_exists('start', $word);
            $hasEnd = array_key_exists('end', $word);

            if ($hasStart !== $hasEnd || ($hasStart && (! is_numeric($word['start']) || ! is_numeric($word['end'])))) {
                $this->failInvalidProviderPayload($provider, $model, 'invalid_word_timing', $context, $index);
            }

            if ($hasStart) {
                $start = (float) $word['start'];
                $end = (float) $word['end'];

                if (! is_finite($start) || ! is_finite($end) || $start < 0 || $end < $start) {
                    $this->failInvalidProviderPayload($provider, $model, 'invalid_word_timing', $context, $index);
                }

                $normalized['start'] = $start;
                $normalized['end'] = $end;
            }

            $normalizedWords[] = $normalized;
        }

        $normalizedPayload = ['words' => $normalizedWords];
        $language = $payload['language_code'] ?? null;

        if (is_string($language) && trim($language) !== '') {
            $normalizedPayload['language_code'] = trim($language);
        }

        $probability = $payload['language_probability'] ?? null;
        if (is_numeric($probability) && is_finite((float) $probability) && $probability >= 0 && $probability <= 1) {
            $normalizedPayload['language_probability'] = (float) $probability;
        }

        return $normalizedPayload;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function failInvalidProviderPayload(
        Lab $provider,
        string $model,
        string $reason,
        array $context,
        ?int $wordIndex = null,
    ): never {
        throw SubtitleProcessingException::transcriptionFailed('Transcription provider returned an invalid response.', [
            'provider' => $provider->value,
            'adapter' => 'elevenlabs-http',
            'model' => $model,
            'reason' => $reason,
            ...($wordIndex === null ? [] : ['word_index' => $wordIndex]),
            ...$context,
        ]);
    }

    /**
     * @return array{apiKey: string, model: string}
     */
    private function transcriptionConfig(Lab $provider): array
    {
        $apiKey = config('ai.providers.'.$provider->value.'.key');
        $model = config('ai.providers.'.$provider->value.'.models.transcription.default');

        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw SubtitleProcessingException::transcriptionFailed('Transcription provider is not configured.', [
                'provider' => $provider->value,
                'adapter' => 'elevenlabs-http',
            ]);
        }

        if (! is_string($model) || trim($model) === '') {
            throw SubtitleProcessingException::transcriptionFailed('Transcription model is not configured.', [
                'provider' => $provider->value,
                'adapter' => 'elevenlabs-http',
            ]);
        }

        return [
            'apiKey' => trim($apiKey),
            'model' => trim($model),
        ];
    }

    private function sendTranscriptionRequest(
        TemporaryAudioFile|string $audio,
        string $sourceLanguage,
        Lab $provider,
        string $apiKey,
        string $model,
        ?SubtitleJob $job,
    ): Response {
        $payload = $this->transcriptionRequestPayload($sourceLanguage, $model);
        $url = $this->transcriptionUrl($provider);
        $request = Http::withHeaders(['xi-api-key' => $apiKey])
            ->timeout((int) config('subtitles.transcription.timeout_seconds'));
        if (is_string($audio)) {
            $request->asMultipart();

            return app(ProviderAdmission::class)->run($provider->value, $job, fn () => $request->post($url, [...$payload, 'source_url' => $audio]));
        }
        $stream = $this->openAudioStream($audio);

        try {
            $request->attach(
                'file',
                $stream,
                $this->audioFilename($audio),
                ['Content-Type' => $audio->mimeType],
            );

            return app(ProviderAdmission::class)->run($provider->value, $job, fn () => $request->post($url, $payload));
        } finally {
            fclose($stream);
        }
    }

    /**
     * @return array<string, string>
     */
    private function transcriptionRequestPayload(string $sourceLanguage, string $model): array
    {
        $payload = [
            'model_id' => $model,
            'timestamps_granularity' => 'word',
            'tag_audio_events' => 'false',
            'diarize' => 'false',
            'no_verbatim' => 'false',
        ];

        $languageCode = $this->languageCode($sourceLanguage);

        if ($languageCode !== null) {
            $payload['language_code'] = $languageCode;
        }

        return $payload;
    }

    private function languageCode(string $sourceLanguage): ?string
    {
        if ($sourceLanguage === 'auto') {
            return null;
        }

        return $sourceLanguage;
    }

    private function transcriptionUrl(Lab $provider): string
    {
        $url = config('ai.providers.'.$provider->value.'.url');

        if (! is_string($url) || trim($url) === '') {
            throw SubtitleProcessingException::transcriptionFailed('Transcription provider URL is not configured.', [
                'provider' => $provider->value,
                'adapter' => 'elevenlabs-http',
            ]);
        }

        return rtrim(trim($url), '/').'/speech-to-text';
    }

    private function assertSupportedAudioMime(TemporaryAudioFile $audio): void
    {
        match ($audio->mimeType) {
            'audio/flac',
            'audio/mp4',
            'audio/mpeg',
            'audio/wav',
            'audio/x-wav',
            'audio/webm',
            'audio/ogg' => true,
            default => throw SubtitleProcessingException::transcriptionFailed('Transcription audio type is not supported.', [
                'mime_type' => $audio->mimeType,
            ]),
        };
    }

    private function audioFilename(TemporaryAudioFile $audio): string
    {
        return match ($audio->mimeType) {
            'audio/flac' => 'audio.flac',
            'audio/mp4' => 'audio.m4a',
            'audio/mpeg' => 'audio.mp3',
            'audio/wav', 'audio/x-wav' => 'audio.wav',
            'audio/webm' => 'audio.webm',
            'audio/ogg' => 'audio.ogg',
            default => throw SubtitleProcessingException::transcriptionFailed('Transcription audio type is not supported.', [
                'mime_type' => $audio->mimeType,
            ]),
        };
    }

    /**
     * @return resource
     */
    private function openAudioStream(TemporaryAudioFile $audio)
    {
        $stream = fopen($audio->path, 'rb');

        if ($stream === false) {
            throw SubtitleProcessingException::transcriptionFailed('Transcription audio could not be opened.', [
                'provider' => Lab::ElevenLabs->value,
                'adapter' => 'elevenlabs-http',
                'reason' => 'file_open_failed',
            ]);
        }

        return $stream;
    }
}
