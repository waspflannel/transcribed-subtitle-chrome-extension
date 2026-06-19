<?php

namespace App\Services\Transcription;

use App\Exceptions\SubtitleProcessingException;
use App\Services\Languages\LanguageCatalog;
use App\Services\Text\NoSpaceArtifactBoundary;

class ScribeTranscriptNormalizer
{
    private const MAX_CUE_DURATION_SECONDS = 6.0;

    private const MAX_CUE_CHARACTERS = 84;

    private const MAX_CUE_WORDS = 14;

    private const PAUSE_BREAK_SECONDS = 0.9;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function normalize(array $payload, string $requestedSourceLanguage, ?float $durationSeconds): TimestampedTranscript
    {
        $segments = $this->segmentsFromWords($this->timedWords($payload));
        $webVtt = $this->webVttFromSegments($segments);
        $language = $requestedSourceLanguage === 'auto'
            ? $this->detectedLanguage($payload)
            : $requestedSourceLanguage;

        return new TimestampedTranscript(
            language: $language,
            durationSeconds: $durationSeconds,
            segments: $segments,
            webVtt: $webVtt,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function detectedLanguage(array $payload): string
    {
        if (! is_string($payload['language_code'] ?? null)) {
            $this->failInvalidScribeResponse('missing_detected_language');
        }

        $language = LanguageCatalog::normalizeCode($payload['language_code']);

        if ($language === null) {
            $this->failInvalidScribeResponse('unsupported_detected_language', [
                'language_code' => $payload['language_code'],
            ]);
        }

        return $language;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array{text: string, start: float, end: float}>
     */
    private function timedWords(array $payload): array
    {
        if (! is_array($payload['words'] ?? null)) {
            $this->failInvalidScribeResponse('missing_words');
        }

        $words = [];
        $pendingUntimedText = [];
        $untimedWordCount = 0;

        foreach ($payload['words'] as $index => $token) {
            if (! is_array($token)) {
                $this->failInvalidScribeResponse('invalid_token', ['token_index' => $index]);
            }

            if (($token['type'] ?? null) !== 'word') {
                continue;
            }

            if (! is_string($token['text'] ?? null)) {
                continue;
            }

            $text = $this->normalizeText($token['text']);

            if ($text === '') {
                continue;
            }

            if (! is_numeric($token['start'] ?? null) || ! is_numeric($token['end'] ?? null)) {
                $pendingUntimedText[] = $text;
                $untimedWordCount++;

                continue;
            }

            $start = (float) $token['start'];
            $end = (float) $token['end'];

            if ($start < 0 || $end <= $start) {
                $pendingUntimedText[] = $text;
                $untimedWordCount++;

                continue;
            }

            if ($pendingUntimedText !== []) {
                $text = $this->normalizeText(implode(' ', [...$pendingUntimedText, $text]));
                $pendingUntimedText = [];
            }

            $words[] = [
                'text' => $text,
                'start' => $start,
                'end' => $end,
            ];
        }

        if ($pendingUntimedText !== [] && $words !== []) {
            $lastWordIndex = array_key_last($words);
            $words[$lastWordIndex]['text'] = $this->normalizeText(
                $words[$lastWordIndex]['text'].' '.implode(' ', $pendingUntimedText),
            );
        }

        usort($words, fn (array $first, array $second): int => $first['start'] <=> $second['start']);

        if ($words === []) {
            $this->failInvalidScribeResponse($untimedWordCount > 0 ? 'invalid_word_timing' : 'empty_words');
        }

        return $words;
    }

    /**
     * @param  array<int, TimestampedTranscriptSegment>  $segments
     */
    private function webVttFromSegments(array $segments): string
    {
        $blocks = ['WEBVTT'];

        foreach ($segments as $index => $segment) {
            $blocks[] = implode("\n", [
                sprintf('cue-%04d', $index + 1),
                $this->formatTimestamp($segment->startSeconds).' --> '.$this->formatTimestamp($segment->endSeconds),
                $segment->text,
            ]);
        }

        return implode("\n\n", $blocks)."\n";
    }

    /**
     * @param  array<int, array{text: string, start: float, end: float}>  $words
     * @return array<int, TimestampedTranscriptSegment>
     */
    private function segmentsFromWords(array $words): array
    {
        $segments = [];
        $currentWords = [];
        $previousWord = null;
        $previousSegmentEnd = null;

        foreach ($words as $word) {
            if ($currentWords !== [] && $this->startsNewCue($currentWords, $word, $previousWord)) {
                $segments[] = $this->segmentFromWords($currentWords, $previousSegmentEnd);
                $previousSegmentEnd = $segments[array_key_last($segments)]->endSeconds;
                $currentWords = [];
            }

            $currentWords[] = $word;
            $previousWord = $word;

            if ($this->shouldCloseCue($currentWords)) {
                $segments[] = $this->segmentFromWords($currentWords, $previousSegmentEnd);
                $previousSegmentEnd = $segments[array_key_last($segments)]->endSeconds;
                $currentWords = [];
            }
        }

        if ($currentWords !== []) {
            $segments[] = $this->segmentFromWords($currentWords, $previousSegmentEnd);
        }

        return $segments;
    }

    /**
     * @param  array<int, array{text: string, start: float, end: float}>  $currentWords
     * @param  array{text: string, start: float, end: float}|null  $previousWord
     */
    private function startsNewCue(array $currentWords, array $word, ?array $previousWord): bool
    {
        if ($previousWord !== null && ($word['start'] - $previousWord['end']) >= self::PAUSE_BREAK_SECONDS) {
            return true;
        }

        return $this->cueDuration($currentWords, $word) > self::MAX_CUE_DURATION_SECONDS
            || $this->cueCharacterCount($currentWords, $word) > self::MAX_CUE_CHARACTERS
            || count($currentWords) >= self::MAX_CUE_WORDS;
    }

    /**
     * @param  array<int, array{text: string, start: float, end: float}>  $words
     */
    private function shouldCloseCue(array $words): bool
    {
        $lastWord = $words[array_key_last($words)];

        return preg_match('/[.!?\x{061F}\x{3002}\x{FF01}\x{FF1F}]$/u', $lastWord['text']) === 1;
    }

    /**
     * @param  array<int, array{text: string, start: float, end: float}>  $words
     */
    private function segmentFromWords(array $words, ?float $previousSegmentEnd): TimestampedTranscriptSegment
    {
        $firstWord = $words[array_key_first($words)];
        $lastWord = $words[array_key_last($words)];
        $start = $firstWord['start'];

        if ($previousSegmentEnd !== null && $start < $previousSegmentEnd) {
            $start = $previousSegmentEnd;
        }

        $end = $lastWord['end'];

        if ($end <= $start) {
            $this->failInvalidScribeResponse('invalid_segment_timing');
        }

        return new TimestampedTranscriptSegment(
            startSeconds: $start,
            endSeconds: $end,
            text: $this->normalizeTranscriptText(implode(' ', array_column($words, 'text'))),
        );
    }

    /**
     * @param  array<int, array{text: string, start: float, end: float}>  $currentWords
     * @param  array{text: string, start: float, end: float}  $candidate
     */
    private function cueDuration(array $currentWords, array $candidate): float
    {
        $firstWord = $currentWords[array_key_first($currentWords)];

        return $candidate['end'] - $firstWord['start'];
    }

    /**
     * @param  array<int, array{text: string, start: float, end: float}>  $currentWords
     * @param  array{text: string, start: float, end: float}  $candidate
     */
    private function cueCharacterCount(array $currentWords, array $candidate): int
    {
        return mb_strlen($this->normalizeText(implode(' ', [
            ...array_column($currentWords, 'text'),
            $candidate['text'],
        ])));
    }

    private function formatTimestamp(float $seconds): string
    {
        $milliseconds = (int) round($seconds * 1000);
        $hours = intdiv($milliseconds, 3_600_000);
        $milliseconds -= $hours * 3_600_000;
        $minutes = intdiv($milliseconds, 60_000);
        $milliseconds -= $minutes * 60_000;
        $wholeSeconds = intdiv($milliseconds, 1000);
        $milliseconds -= $wholeSeconds * 1000;

        return sprintf('%02d:%02d:%02d.%03d', $hours, $minutes, $wholeSeconds, $milliseconds);
    }

    private function normalizeText(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function normalizeTranscriptText(string $text): string
    {
        return NoSpaceArtifactBoundary::strip($this->normalizeText($text));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function failInvalidScribeResponse(string $reason, array $context = []): never
    {
        throw SubtitleProcessingException::transcriptionFailed(
            'Transcription provider returned unusable word timings.',
            [
                'reason' => $reason,
                ...$context,
            ],
        );
    }
}
