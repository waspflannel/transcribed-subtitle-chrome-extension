<?php

namespace App\Services\Transcription;

use App\Exceptions\SubtitleProcessingException;
use App\Services\Languages\LanguageCatalog;
use App\Services\Subtitles\SubtitleWebVttFormatter;
use App\Services\Text\NoSpaceArtifactBoundary;
use App\Services\Text\SubtitleText;
use IntlBreakIterator;

class ScribeTranscriptNormalizer
{
    private const MAX_CUE_DURATION_SECONDS = 6.0;

    private const MAX_CUE_CHARACTERS = 84;

    private const MAX_CUE_WORDS = 14;

    private const PAUSE_BREAK_SECONDS = 0.9;

    /**
     * Smallest inter-word gap that is a good cue break candidate. Kept well
     * under PAUSE_BREAK_SECONDS so a normal pause is a preferred boundary
     * before a hard limit forces a break elsewhere.
     */
    private const SOFT_GAP_BREAK_SECONDS = 0.25;

    private const MAX_GAP_SCORE = 3.0;

    private const CLAUSE_SCORE = 1.0;

    /**
     * Clause punctuation that is a good but non-forcing break candidate:
     * comma, semicolon, colon, and CJK equivalents.
     */
    private const CLAUSE_PUNCTUATION = '/[,;:、，；：]$/u';

    private ?IntlBreakIterator $wordBreaks = null;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function normalize(array $payload, string $requestedSourceLanguage, ?float $durationSeconds, ?float $stableBeforeSeconds = null): TimestampedTranscript
    {
        $words = $this->timedWords($payload);
        if ($stableBeforeSeconds !== null) {
            $words = array_values(array_filter($words, fn (array $word): bool => $word['end'] < $stableBeforeSeconds));
        }
        $segments = $this->segmentsFromWords($words, $stableBeforeSeconds === null);
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

            $text = SubtitleText::collapseWhitespace($token['text']);

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

            // A point timestamp inside the preceding word anchors its suffix,
            // even though it cannot supply a standalone cue duration. A word
            // after a sentence end starts the next sentence instead.
            $lastWordIndex = array_key_last($words);
            if ($start === $end && $lastWordIndex !== null && $pendingUntimedText === []
                && $start >= $words[$lastWordIndex]['start'] && $end <= $words[$lastWordIndex]['end']
                && ! $this->endsSentence($words[$lastWordIndex]['text'])) {
                $words[$lastWordIndex]['text'] .= ' '.$text;

                continue;
            }

            if ($start < 0 || $end <= $start) {
                $pendingUntimedText[] = $text;
                $untimedWordCount++;

                continue;
            }

            if ($pendingUntimedText !== []) {
                $text = SubtitleText::collapseWhitespace(implode(' ', [...$pendingUntimedText, $text]));
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
            $words[$lastWordIndex]['text'] = SubtitleText::collapseWhitespace(
                $words[$lastWordIndex]['text'].' '.implode(' ', $pendingUntimedText),
            );
        }

        usort($words, fn (array $first, array $second): int => $first['start'] <=> $second['start']);

        if ($words === []) {
            $this->failInvalidScribeResponse($untimedWordCount > 0 ? 'invalid_word_timing' : 'empty_words');
        }

        // Overlapping words cannot be split into separate non-overlapping cues.
        // Group before the streaming cutoff so connected words stay unpublished together.
        // Timing jitter at the end of a finished sentence moves the next word
        // after it, so the sentence-final punctuation still closes its cue.
        $groups = [];
        foreach ($words as $word) {
            $last = array_key_last($groups);
            if ($last !== null && $word['start'] < $groups[$last]['end']
                && $groups[$last]['end'] - $word['start'] <= self::SOFT_GAP_BREAK_SECONDS
                && $word['end'] > $groups[$last]['end'] && $this->endsSentence($groups[$last]['text'])) {
                $word['start'] = $groups[$last]['end'];
            }
            if ($last !== null && $word['start'] < $groups[$last]['end']) {
                $groups[$last]['text'] .= ' '.$word['text'];
                $groups[$last]['end'] = max($groups[$last]['end'], $word['end']);
            } else {
                $groups[] = $word;
            }
        }

        return $groups;
    }

    /**
     * @param  array<int, TimestampedTranscriptSegment>  $segments
     */
    private function webVttFromSegments(array $segments): string
    {
        return SubtitleWebVttFormatter::fromSegments($segments);
    }

    /**
     * @param  array<int, array{text: string, start: float, end: float}>  $words
     * @return array<int, TimestampedTranscriptSegment>
     */
    private function segmentsFromWords(array $words, bool $complete): array
    {
        $segments = [];
        $currentWords = [];
        /** @var array<int, array{index: int, score: float}>  $candidates */
        $candidates = [];
        $previousWord = null;
        $previousSegmentEnd = null;

        $flush = function (int $breakAfter) use (
            &$currentWords, &$candidates, &$segments, &$previousSegmentEnd
        ): void {
            $cut = $breakAfter + 1;
            $segments[] = $this->segmentFromWords(array_slice($currentWords, 0, $cut), $previousSegmentEnd);
            $previousSegmentEnd = $segments[array_key_last($segments)]->endSeconds;
            $currentWords = array_slice($currentWords, $cut);
            $shift = $cut;
            $remapped = [];
            foreach ($candidates as $candidate) {
                $newIndex = $candidate['index'] - $shift;
                if ($newIndex >= 0) {
                    $remapped[] = ['index' => $newIndex, 'score' => $candidate['score']];
                }
            }
            $candidates = $remapped;
        };

        foreach ($words as $word) {
            if ($currentWords !== [] && $previousWord !== null) {
                $gap = $word['start'] - $previousWord['end'];
                if ($this->joinsNoSpaceText($previousWord, $word) && $gap < self::MAX_CUE_DURATION_SECONDS) {
                    // Scribe times unspaced scripts per character, and a sung
                    // note held inside a word leaves a long gap. Only a pause
                    // where a word ends can break the cue. It forces a break
                    // before a kanji or katakana word, not before kana that
                    // continue a phrase (okurigana, particles, endings).
                    if ($gap >= self::SOFT_GAP_BREAK_SECONDS && $this->isWordBoundary($currentWords, $word)) {
                        if ($gap >= self::PAUSE_BREAK_SECONDS && preg_match('/^[\p{Han}\p{Katakana}]/u', $word['text']) === 1) {
                            $flush(count($currentWords) - 1);
                        } else {
                            $this->recordCandidate($candidates, count($currentWords) - 1, min($gap, self::MAX_GAP_SCORE));
                        }
                    }
                } elseif ($gap >= self::PAUSE_BREAK_SECONDS) {
                    // A real pause is always the strongest boundary. Close now
                    // rather than waiting for a hard limit to fire somewhere else.
                    $flush(count($currentWords) - 1);
                } elseif ($gap >= self::SOFT_GAP_BREAK_SECONDS) {
                    $this->recordCandidate($candidates, count($currentWords) - 1, min($gap, self::MAX_GAP_SCORE));
                }
            }

            if ($currentWords !== [] && $this->exceedsHardLimit($currentWords, $word)) {
                $best = $this->bestCandidate($candidates);
                $flush($best !== null ? $best['index'] : (count($currentWords) - 1));
            }

            $currentWords[] = $word;
            $previousWord = $word;

            if ($this->endsWithClausePunctuation($word['text'])) {
                $this->recordCandidate($candidates, count($currentWords) - 1, self::CLAUSE_SCORE);
            }

            if ($this->shouldCloseCue($currentWords)) {
                $flush(count($currentWords) - 1);
            }
        }

        if ($complete && $currentWords !== []) {
            $segments[] = $this->segmentFromWords($currentWords, $previousSegmentEnd);
        }

        return $segments;
    }

    /**
     * @param  array<int, array{index: int, score: float}>  $candidates
     */
    private function recordCandidate(array &$candidates, int $index, float $score): void
    {
        foreach ($candidates as &$candidate) {
            if ($candidate['index'] === $index) {
                if ($score > $candidate['score']) {
                    $candidate['score'] = $score;
                }

                return;
            }
        }
        unset($candidate);
        $candidates[] = ['index' => $index, 'score' => $score];
    }

    /**
     * @param  array<int, array{index: int, score: float}>  $candidates
     * @return array{index: int, score: float}|null
     */
    private function bestCandidate(array $candidates): ?array
    {
        $best = null;
        foreach ($candidates as $candidate) {
            if ($best === null || $candidate['score'] > $best['score']) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * @param  array<int, array{text: string, start: float, end: float}>  $currentWords
     * @param  array{text: string, start: float, end: float}  $candidate
     */
    private function exceedsHardLimit(array $currentWords, array $candidate): bool
    {
        return $this->cueDuration($currentWords, $candidate) > self::MAX_CUE_DURATION_SECONDS
            || $this->cueCharacterCount($currentWords, $candidate) > self::MAX_CUE_CHARACTERS
            || $this->logicalWordCount($currentWords) >= self::MAX_CUE_WORDS;
    }

    /**
     * @param  array<int, array{text: string, start: float, end: float}>  $currentWords
     */
    private function logicalWordCount(array $currentWords): int
    {
        $count = 0;
        foreach ($currentWords as $word) {
            foreach (explode(' ', $word['text']) as $text) {
                if ($this->countsAsLogicalWord($text)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    private function countsAsLogicalWord(string $text): bool
    {
        if ($text === '') {
            return false;
        }

        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($chars as $char) {
            if (trim($char) === '') {
                continue;
            }

            if (! NoSpaceArtifactBoundary::isNoSpaceScriptChar($char) && preg_match('/[\p{L}\p{N}]/u', $char) === 1) {
                return true;
            }
        }

        return false;
    }

    private function endsWithClausePunctuation(string $text): bool
    {
        return preg_match(self::CLAUSE_PUNCTUATION, $text) === 1;
    }

    /**
     * @param  array<int, array{text: string, start: float, end: float}>  $words
     */
    private function shouldCloseCue(array $words): bool
    {
        return $this->endsSentence($words[array_key_last($words)]['text']);
    }

    private function endsSentence(string $text): bool
    {
        return preg_match('/[.!?\x{061F}\x{3002}\x{FF01}\x{FF1F}]$/u', $text) === 1;
    }

    /**
     * @param  array{text: string, start: float, end: float}  $previous
     * @param  array{text: string, start: float, end: float}  $next
     */
    private function joinsNoSpaceText(array $previous, array $next): bool
    {
        return NoSpaceArtifactBoundary::isNoSpaceScriptChar(mb_substr($previous['text'], -1, 1, 'UTF-8'))
            && NoSpaceArtifactBoundary::isNoSpaceScriptChar(mb_substr($next['text'], 0, 1, 'UTF-8'));
    }

    /**
     * Uses ICU dictionary word breaks. Only the current cue and the incoming
     * word are read, so a published streaming prefix never changes later.
     *
     * @param  array<int, array{text: string, start: float, end: float}>  $currentWords
     * @param  array{text: string, start: float, end: float}  $next
     */
    private function isWordBoundary(array $currentWords, array $next): bool
    {
        $left = SubtitleText::canonicalComparable(implode(' ', array_column($currentWords, 'text')));
        $this->wordBreaks ??= IntlBreakIterator::createWordInstance();
        $this->wordBreaks->setText($left.SubtitleText::canonicalComparable($next['text']));

        return $this->wordBreaks->isBoundary(strlen($left));
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
            text: SubtitleText::canonicalComparable(implode(' ', array_column($words, 'text'))),
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
        return mb_strlen(SubtitleText::canonicalComparable(implode(' ', [
            ...array_column($currentWords, 'text'),
            $candidate['text'],
        ])), 'UTF-8');
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
