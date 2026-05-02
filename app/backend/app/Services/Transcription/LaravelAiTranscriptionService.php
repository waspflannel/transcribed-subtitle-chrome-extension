<?php

namespace App\Services\Transcription;

use App\Exceptions\SubtitleProcessingException;
use App\Services\Audio\TemporaryAudioFile;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\Data\TranscriptionSegment;
use Laravel\Ai\Responses\TranscriptionResponse;
use Laravel\Ai\Transcription;
use Throwable;

class LaravelAiTranscriptionService
{
    public function transcribe(TemporaryAudioFile $audio, string $sourceLanguage): TimestampedTranscript
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

            if ($sourceLanguage !== 'auto') {
                $pending->language($sourceLanguage);
            }

            $response = $pending->generate(
                provider: $provider,
            );

            return $this->timestampedTranscript($response, $audio, $sourceLanguage);
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

    private function timestampedTranscript(
        TranscriptionResponse $response,
        TemporaryAudioFile $audio,
        string $sourceLanguage,
    ): TimestampedTranscript {
        $segments = $response->segments
            ->map(function (TranscriptionSegment $segment): ?TimestampedTranscriptSegment {
                $text = trim($segment->text);

                if ($text === '' || $segment->startSeconds < 0 || $segment->endSeconds <= $segment->startSeconds) {
                    return null;
                }

                return new TimestampedTranscriptSegment(
                    startSeconds: $segment->startSeconds,
                    endSeconds: $segment->endSeconds,
                    text: $text,
                );
            })
            ->filter()
            ->sortBy(fn (TimestampedTranscriptSegment $segment): float => $segment->startSeconds)
            ->values()
            ->all();

        if ($segments === []) {
            throw SubtitleProcessingException::transcriptionFailed('Transcription did not return timestamped segments.');
        }

        return new TimestampedTranscript(
            language: $sourceLanguage,
            durationSeconds: $audio->durationSeconds,
            segments: $segments,
        );
    }
}
