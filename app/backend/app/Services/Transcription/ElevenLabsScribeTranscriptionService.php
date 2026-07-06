<?php

namespace App\Services\Transcription;

use App\Exceptions\SubtitleProcessingException;
use App\Services\Audio\ElevenLabsScribeAudioPreparer;
use App\Services\Audio\ScribeAudioChunker;
use App\Services\Audio\TemporaryAudioFile;
use Illuminate\Http\Client\Pool;
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
        private readonly ScribeAudioChunker $chunker,
        private readonly ScribeChunkPayloadMerger $chunkMerger,
    ) {}

    public function transcribe(TemporaryAudioFile $audio, string $sourceLanguage): TimestampedTranscript
    {
        return $this->transcribePreparedAudio(
            $this->prepareAudio($audio),
            $sourceLanguage,
        );
    }

    public function prepareAudio(TemporaryAudioFile $audio): TemporaryAudioFile
    {
        $this->transcriptionConfig(Lab::ElevenLabs);
        $this->assertSupportedAudioMime($audio);

        return $this->audioPreparer->prepare($audio);
    }

    public function transcribePreparedAudio(TemporaryAudioFile $audio, string $sourceLanguage): TimestampedTranscript
    {
        $provider = Lab::ElevenLabs;
        ['apiKey' => $apiKey, 'model' => $model] = $this->transcriptionConfig($provider);

        $this->assertSupportedAudioMime($audio);

        try {
            $chunkPlan = $this->chunker->plan($audio->durationSeconds);

            $payload = $chunkPlan === []
                ? $this->transcribeWholeAudio($audio, $sourceLanguage, $provider, $apiKey, $model)
                : $this->transcribeChunkedAudio($audio, $chunkPlan, $sourceLanguage, $provider, $apiKey, $model);

            return $this->normalizer->normalize(
                payload: $payload,
                requestedSourceLanguage: $sourceLanguage,
                durationSeconds: $audio->durationSeconds,
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
     * @return array<string, mixed>
     */
    private function transcribeWholeAudio(
        TemporaryAudioFile $audio,
        string $sourceLanguage,
        Lab $provider,
        string $apiKey,
        string $model,
    ): array {
        return $this->validatedTranscriptionPayload(
            $this->sendTranscriptionRequest($audio, $sourceLanguage, $provider, $apiKey, $model),
            $provider,
            $model,
        );
    }

    /**
     * Splits long audio into overlapping chunks, transcribes them with
     * parallel Scribe requests, and merges the word payloads back into one
     * transcript. Drops the transcribing ceiling from the full audio length
     * to the longest chunk.
     *
     * @param  array<int, array{nominalStart: float, nominalEnd: float, audioStart: float, audioEnd: float}>  $chunkPlan
     * @return array<string, mixed>
     */
    private function transcribeChunkedAudio(
        TemporaryAudioFile $audio,
        array $chunkPlan,
        string $sourceLanguage,
        Lab $provider,
        string $apiKey,
        string $model,
    ): array {
        $chunkFiles = $this->chunker->split($audio, $chunkPlan);

        Log::info('backend.transcription_chunked', [
            'provider' => $provider->value,
            'adapter' => 'elevenlabs-http',
            'model' => $model,
            'audio_duration_seconds' => $audio->durationSeconds,
            'chunk_count' => count($chunkFiles),
        ]);

        $streams = array_map(fn (TemporaryAudioFile $chunk) => $this->openAudioStream($chunk), $chunkFiles);
        $requestPayload = $this->transcriptionRequestPayload($sourceLanguage, $model);
        $url = $this->transcriptionUrl($provider);
        $timeoutSeconds = (int) config('subtitles.transcription.timeout_seconds');

        try {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (int $index) => $pool->as((string) $index)
                    ->withHeaders(['xi-api-key' => $apiKey])
                    ->timeout($timeoutSeconds)
                    ->attach(
                        'file',
                        $streams[$index],
                        $this->audioFilename($chunkFiles[$index]),
                        ['Content-Type' => $chunkFiles[$index]->mimeType],
                    )
                    ->post($url, $requestPayload),
                array_keys($chunkFiles),
            ));
        } finally {
            foreach ($streams as $stream) {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }

        $chunks = [];
        $lastChunkIndex = array_key_last($chunkPlan);

        foreach ($chunkPlan as $index => $bounds) {
            $response = $responses[(string) $index] ?? null;

            if (! $response instanceof Response) {
                throw SubtitleProcessingException::transcriptionFailed('Transcription provider request failed.', [
                    'provider' => $provider->value,
                    'adapter' => 'elevenlabs-http',
                    'model' => $model,
                    'chunk_index' => $index,
                    ...($response instanceof Throwable ? ['exception' => $response::class] : []),
                ], $response instanceof Throwable ? $response : null);
            }

            $chunks[] = [
                'payload' => $this->validatedTranscriptionPayload($response, $provider, $model, ['chunk_index' => $index]),
                'audioStartSeconds' => $bounds['audioStart'],
                'nominalStartSeconds' => $bounds['nominalStart'],
                // The last chunk keeps everything past its nominal start.
                'nominalEndSeconds' => $index === $lastChunkIndex ? null : $bounds['nominalEnd'],
            ];
        }

        return $this->chunkMerger->merge($chunks);
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

        return $payload;
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
