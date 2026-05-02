<?php

namespace App\Services\Transcription;

use App\Exceptions\SubtitleProcessingException;

class TimestampedTranscriptNormalizer
{
    /**
     * @param  iterable<int, mixed>  $segments
     */
    public function normalize(?string $language, ?float $durationSeconds, iterable $segments): TimestampedTranscript
    {
        $normalized = collect($segments)
            ->map(function (mixed $segment): ?TimestampedTranscriptSegment {
                if (! is_array($segment)) {
                    return null;
                }

                $text = trim((string) ($segment['text'] ?? ''));
                $start = $segment['start'] ?? null;
                $end = $segment['end'] ?? null;

                if ($text === '' || (! is_int($start) && ! is_float($start)) || (! is_int($end) && ! is_float($end))) {
                    return null;
                }

                $startSeconds = (float) $start;
                $endSeconds = (float) $end;

                if ($startSeconds < 0 || $endSeconds <= $startSeconds) {
                    return null;
                }

                return new TimestampedTranscriptSegment($startSeconds, $endSeconds, $text);
            })
            ->filter()
            ->sortBy(fn (TimestampedTranscriptSegment $segment): float => $segment->startSeconds)
            ->values();

        if ($normalized->isEmpty()) {
            throw SubtitleProcessingException::transcriptionFailed('Transcription did not return timestamped segments.');
        }

        return new TimestampedTranscript(
            language: $language,
            durationSeconds: $durationSeconds,
            segments: $normalized->all(),
        );
    }

    public function fromLaravelAiResponse(
        object $response,
        ?float $fallbackDurationSeconds,
        ?string $language = null,
    ): TimestampedTranscript {
        $segments = collect($response->segments ?? [])
            ->map(function (mixed $segment): array {
                if (is_array($segment)) {
                    return [
                        'text' => $segment['text'] ?? '',
                        'start' => $segment['startSeconds'] ?? $segment['start_seconds'] ?? null,
                        'end' => $segment['endSeconds'] ?? $segment['end_seconds'] ?? null,
                    ];
                }

                return [
                    'text' => is_object($segment) ? ($segment->text ?? '') : '',
                    'start' => is_object($segment) ? ($segment->startSeconds ?? null) : null,
                    'end' => is_object($segment) ? ($segment->endSeconds ?? null) : null,
                ];
            });

        return $this->normalize(
            language: $language,
            durationSeconds: $fallbackDurationSeconds,
            segments: $segments,
        );
    }
}
