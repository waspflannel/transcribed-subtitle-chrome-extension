<?php

namespace App\Services\Transcription;

use App\Exceptions\SubtitleProcessingException;
use App\Services\Audio\TemporaryAudioFile;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Transcription;
use Throwable;

class LaravelAiTranscriptionService
{
    public function __construct(private readonly TimestampedTranscriptNormalizer $normalizer) {}

    public function transcribe(TemporaryAudioFile $audio, TranscriptionOptions $options): TimestampedTranscript
    {
        $provider = Lab::OpenAI;
        $apiKey = config('ai.providers.'.$provider->value.'.key');

        if (! is_string($apiKey) || $apiKey === '') {
            throw SubtitleProcessingException::transcriptionFailed('Transcription provider is not configured.', [
                'provider' => $provider->value,
                'sdk' => 'laravel-ai',
            ]);
        }

        try {
            $pending = Transcription::fromPath($audio->path, $audio->mimeType)
                ->diarize()
                ->timeout((int) config('subtitles.transcription.timeout_seconds'));

            $language = $options->providerLanguage();

            if ($language !== null) {
                $pending->language($language);
            }

            $response = $pending->generate(
                provider: $provider,
            );

            return $this->normalizer->fromLaravelAiResponse(
                response: $response,
                fallbackDurationSeconds: $audio->durationSeconds,
                language: $options->sourceLanguage,
            );
        } catch (SubtitleProcessingException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw SubtitleProcessingException::transcriptionFailed(
                context: [
                    'provider' => $provider->value,
                    'sdk' => 'laravel-ai',
                    'exception' => $exception::class,
                ],
                previous: $exception,
            );
        }
    }
}
