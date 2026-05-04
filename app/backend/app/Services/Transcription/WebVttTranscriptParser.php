<?php

namespace App\Services\Transcription;

use App\Exceptions\SubtitleProcessingException;

class WebVttTranscriptParser
{
    /**
     * @return array{webVtt: string, segments: array<int, TimestampedTranscriptSegment>}
     */
    public function parse(string $webVtt): array
    {
        $normalized = $this->normalizeWebVtt($webVtt);

        return [
            'webVtt' => $normalized,
            'segments' => $this->segments($normalized),
        ];
    }

    /**
     * @return array<int, TimestampedTranscriptSegment>
     */
    private function segments(string $normalizedWebVtt): array
    {
        $blocks = preg_split("/\n{2,}/", $normalizedWebVtt, flags: PREG_SPLIT_NO_EMPTY);

        if (! is_array($blocks) || $blocks === []) {
            $this->failInvalidWebVtt('empty_vtt');
        }

        array_shift($blocks);

        $segments = [];

        foreach ($blocks as $blockIndex => $block) {
            $lines = array_values(array_filter(
                array_map('trim', explode("\n", $block)),
                fn (string $line): bool => $line !== '',
            ));

            if ($lines === [] || in_array($lines[0], ['NOTE', 'STYLE', 'REGION'], true)) {
                continue;
            }

            if (str_starts_with($lines[0], 'NOTE ')) {
                continue;
            }

            $timingLineIndex = str_contains($lines[0], '-->') ? 0 : 1;
            $timingLine = $lines[$timingLineIndex] ?? null;

            if (! is_string($timingLine) || ! str_contains($timingLine, '-->')) {
                $this->failInvalidWebVtt('missing_timing_line', ['block_index' => $blockIndex]);
            }

            [$startSeconds, $endSeconds] = $this->parseTimingLine($timingLine, $blockIndex);
            $text = $this->normalizeCueText(array_slice($lines, $timingLineIndex + 1));

            if ($text === '') {
                $this->failInvalidWebVtt('empty_source_text', ['block_index' => $blockIndex]);
            }

            if ($startSeconds < 0 || $endSeconds <= $startSeconds) {
                $this->failInvalidWebVtt('invalid_timing', [
                    'block_index' => $blockIndex,
                    'start_ms' => (int) round($startSeconds * 1000),
                    'end_ms' => (int) round($endSeconds * 1000),
                ]);
            }

            $segments[] = new TimestampedTranscriptSegment(
                startSeconds: $startSeconds,
                endSeconds: $endSeconds,
                text: $text,
            );
        }

        usort(
            $segments,
            fn (TimestampedTranscriptSegment $first, TimestampedTranscriptSegment $second): int => $first->startSeconds <=> $second->startSeconds,
        );

        if ($segments === []) {
            $this->failInvalidWebVtt('empty_cue_output');
        }

        $previousEndSeconds = null;

        foreach ($segments as $index => $segment) {
            if ($previousEndSeconds !== null && $segment->startSeconds < $previousEndSeconds) {
                $this->failInvalidWebVtt('overlapping_timing', [
                    'cue_index' => $index,
                    'start_ms' => (int) round($segment->startSeconds * 1000),
                    'previous_end_ms' => (int) round($previousEndSeconds * 1000),
                ]);
            }

            $previousEndSeconds = $segment->endSeconds;
        }

        return $segments;
    }

    private function normalizeWebVtt(string $webVtt): string
    {
        $normalized = trim(str_replace(["\r\n", "\r"], "\n", $webVtt));
        $normalized = (string) preg_replace('/^\x{FEFF}/u', '', $normalized);

        if (! str_starts_with($normalized, 'WEBVTT')) {
            $this->failInvalidWebVtt('missing_header');
        }

        return $normalized."\n";
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function parseTimingLine(string $timingLine, int $blockIndex): array
    {
        $parts = preg_split('/\s+-->\s+/', trim($timingLine), 2);

        if (! is_array($parts) || count($parts) !== 2) {
            $this->failInvalidWebVtt('invalid_timing_line', ['block_index' => $blockIndex]);
        }

        $endParts = preg_split('/\s+/', trim($parts[1]), 2);
        $startSeconds = $this->parseTimestamp($parts[0]);
        $endSeconds = $this->parseTimestamp($endParts[0] ?? '');

        if ($startSeconds === null || $endSeconds === null) {
            $this->failInvalidWebVtt('invalid_timestamp', ['block_index' => $blockIndex]);
        }

        return [$startSeconds, $endSeconds];
    }

    private function parseTimestamp(string $timestamp): ?float
    {
        if (! preg_match('/^(?:(\d+):)?(\d{2}):(\d{2})\.(\d{3})$/', trim($timestamp), $matches)) {
            return null;
        }

        $hasHours = isset($matches[1]) && $matches[1] !== '';
        $hours = $hasHours ? (int) $matches[1] : 0;
        $minutes = (int) $matches[2];
        $seconds = (int) $matches[3];
        $milliseconds = (int) $matches[4];

        if ($seconds > 59 || ($hasHours && $minutes > 59)) {
            return null;
        }

        return ($hours * 3600) + ($minutes * 60) + $seconds + ($milliseconds / 1000);
    }

    /**
     * @param  array<int, string>  $textLines
     */
    private function normalizeCueText(array $textLines): string
    {
        $text = implode(' ', $textLines);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function failInvalidWebVtt(string $reason, array $context = []): never
    {
        throw SubtitleProcessingException::transcriptionFailed(
            'Transcription did not return valid WebVTT cues.',
            [
                'reason' => $reason,
                ...$context,
            ],
        );
    }
}
