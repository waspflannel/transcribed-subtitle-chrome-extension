<?php

namespace App\Services\Transcription;

use App\Exceptions\SubtitleProcessingException;
use App\Services\Audio\TemporaryAudioFile;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Enums\Lab;
use Throwable;

class ElevenLabsScribeTranscriptionService implements TranscriptionService
{
    /**
     * @var array<string, string>
     */
    private const LANGUAGE_CODES = [
        'ar' => 'ara',
        'en' => 'eng',
        'es' => 'spa',
        'pt' => 'por',
        'fr' => 'fra',
        'de' => 'deu',
        'it' => 'ita',
    ];

    public function __construct(private readonly ScribeTranscriptNormalizer $normalizer) {}

    public function transcribe(TemporaryAudioFile $audio, string $sourceLanguage): TimestampedTranscript
    {
        $provider = Lab::ElevenLabs;
        $apiKey = config('ai.providers.'.$provider->value.'.key');
        $model = (string) config('ai.providers.'.$provider->value.'.models.transcription.default', 'scribe_v2');

        if (! is_string($apiKey) || $apiKey === '') {
            throw SubtitleProcessingException::transcriptionFailed('Transcription provider is not configured.', [
                'provider' => $provider->value,
                'adapter' => 'elevenlabs-http',
            ]);
        }

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

        return Http::withHeaders(['xi-api-key' => $apiKey])
            ->timeout((int) config('subtitles.transcription.timeout_seconds'))
            ->attach(
                'file',
                File::get($audio->path),
                $this->audioFilename($audio),
                ['Content-Type' => $audio->mimeType],
            )
            ->post($this->transcriptionUrl($provider), $payload);
    }

    private function languageCode(string $sourceLanguage): ?string
    {
        if ($sourceLanguage === 'auto') {
            return null;
        }

        return self::LANGUAGE_CODES[$sourceLanguage] ?? null;
    }

    private function transcriptionUrl(Lab $provider): string
    {
        return rtrim((string) config('ai.providers.'.$provider->value.'.url', 'https://api.elevenlabs.io/v1'), '/').'/speech-to-text';
    }

    private function audioFilename(TemporaryAudioFile $audio): string
    {
        return match ($audio->mimeType) {
            'audio/mpeg' => 'audio.mp3',
            'audio/wav', 'audio/x-wav' => 'audio.wav',
            'audio/webm' => 'audio.webm',
            default => 'audio.m4a',
        };
    }
}
