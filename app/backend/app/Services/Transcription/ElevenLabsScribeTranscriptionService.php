<?php

namespace App\Services\Transcription;

use App\Exceptions\SubtitleProcessingException;
use App\Services\Audio\ElevenLabsScribeAudioPreparer;
use App\Services\Audio\TemporaryAudioFile;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
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
        $this->transcriptionConfig(Lab::ElevenLabs);
        $this->assertSupportedAudioMime($audio);

        return $this->audioPreparer->prepare($audio);
    }

    /**
     * Transcribes one audio chunk and returns only the provider fields the
     * chunk merger consumes. Chunks are transcribed by independent queue jobs,
     * so this sends exactly one request; merging happens in
     * transcriptFromChunkPayloads.
     *
     * @return array<string, mixed>
     */
    public function transcribeChunk(TemporaryAudioFile $audio, string $sourceLanguage): array
    {
        $provider = Lab::ElevenLabs;
        ['apiKey' => $apiKey, 'model' => $model] = $this->transcriptionConfig($provider);

        $this->assertSupportedAudioMime($audio);

        try {
            return $this->validatedTranscriptionPayload(
                $this->sendTranscriptionRequest($audio, $sourceLanguage, $provider, $apiKey, $model),
                $provider,
                $model,
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
    ): TimestampedTranscript {
        $provider = Lab::ElevenLabs;
        ['model' => $model] = $this->transcriptionConfig($provider);

        try {
            return $this->normalizer->normalize(
                payload: $this->chunkMerger->merge($chunks),
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

        if (! is_array($words) || $words === []) {
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
            $hasStart = array_key_exists('start', $word);
            $hasEnd = array_key_exists('end', $word);

            if ($hasStart !== $hasEnd || ($hasStart && (! is_numeric($word['start']) || ! is_numeric($word['end'])))) {
                $this->failInvalidProviderPayload($provider, $model, 'invalid_word_timing', $context, $index);
            }

            if ($hasStart) {
                $normalized['start'] = (float) $word['start'];
                $normalized['end'] = (float) $word['end'];
            }

            $normalizedWords[] = $normalized;
        }

        $normalizedPayload = ['words' => $normalizedWords];
        $language = $payload['language_code'] ?? null;

        if (is_string($language) && trim($language) !== '') {
            $normalizedPayload['language_code'] = trim($language);
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
        TemporaryAudioFile $audio,
        string $sourceLanguage,
        Lab $provider,
        string $apiKey,
        string $model,
    ): Response {
        $payload = $this->transcriptionRequestPayload($sourceLanguage, $model);
        $stream = $this->openAudioStream($audio);

        try {
            return Http::withHeaders(['xi-api-key' => $apiKey])
                ->timeout((int) config('subtitles.transcription.timeout_seconds'))
                ->attach(
                    'file',
                    $stream,
                    $this->audioFilename($audio),
                    ['Content-Type' => $audio->mimeType],
                )
                ->post($this->transcriptionUrl($provider), $payload);
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
