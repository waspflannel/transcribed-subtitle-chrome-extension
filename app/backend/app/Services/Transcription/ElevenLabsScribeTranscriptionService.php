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
            $response = $this->sendTranscriptionRequest($audio, $sourceLanguage, $provider, $apiKey, $model);

            if ($response->failed()) {
                throw SubtitleProcessingException::transcriptionFailed('Transcription provider request failed.', [
                    'provider' => $provider->value,
                    'adapter' => 'elevenlabs-http',
                    'model' => $model,
                    'status' => $response->status(),
                ]);
            }

            $payload = $response->json();

            if (! is_array($payload)) {
                throw SubtitleProcessingException::transcriptionFailed('Transcription provider returned invalid JSON.', [
                    'provider' => $provider->value,
                    'adapter' => 'elevenlabs-http',
                    'model' => $model,
                    'reason' => 'invalid_json',
                ]);
            }

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
