<?php

namespace App\Services\Transcription;

use App\Exceptions\SubtitleProcessingException;
use App\Services\Audio\TemporaryAudioFile;
use Laravel\Ai\Transcription;
use Throwable;

class LaravelAiTranscriptionProvider implements TranscriptionProvider
{
    public function __construct(private readonly TimestampedTranscriptNormalizer $normalizer) {}

    public function transcribe(TemporaryAudioFile $audio, TranscriptionOptions $options): TimestampedTranscript
    {
        try {
            $pending = Transcription::fromPath($audio->path, $audio->mimeType)
                ->timeout((int) config('subtitles.transcription.timeout_seconds'));

            if ($options->providerLanguage() !== null) {
                $pending->language($options->providerLanguage());
            }

            $response = $pending->generate('openai', (string) config('subtitles.transcription.model'));

            return $this->normalizer->fromLaravelAiResponse($response, (float) $audio->durationSeconds);
        } catch (SubtitleProcessingException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw SubtitleProcessingException::transcriptionFailed(
                context: ['provider' => 'laravel-ai-sdk', 'exception' => $exception::class],
                previous: $exception,
            );
        }
    }
}
