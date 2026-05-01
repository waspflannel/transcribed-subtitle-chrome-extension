<?php

namespace App\Services\Transcription;

use App\Exceptions\SubtitleProcessingException;
use Illuminate\Support\Collection;

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

    public function fromLaravelAiResponse(object $response, ?float $fallbackDurationSeconds): TimestampedTranscript
    {
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
            language: null,
            durationSeconds: $fallbackDurationSeconds,
            segments: $segments,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function fromOpenAiVerboseJson(array $data): TimestampedTranscript
    {
        $segments = $data['segments'] ?? [];

        if (! $segments instanceof Collection && ! is_array($segments)) {
            $segments = [];
        }

        return $this->normalize(
            language: is_string($data['language'] ?? null) ? $data['language'] : null,
            durationSeconds: is_int($data['duration'] ?? null) || is_float($data['duration'] ?? null)
                ? (float) $data['duration']
                : null,
            segments: $segments,
        );
    }
}
