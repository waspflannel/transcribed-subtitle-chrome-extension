<?php

namespace App\Services\TranslationAnalysis;

/**
 * Computes boundary/word F1 and failure-mode counts for tokenized cues.
 *
 * Normalization mirrors LearningTokenOutputValidator exactly (whitespace
 * collapse + no-space artifact-space stripping + lowercasing) so evaluation
 * boundaries are scored over the same comparable source string the production
 * validator enforces. Token spans are resolved with the same advancing-offset
 * mb_strpos search the validator uses, keeping eval aligned with production.
 */
class TokenizationBoundaryMetric
{
    public function __construct(
        private readonly LearningTokenOutputValidator $validator,
    ) {}

    /**
     * @param  array<int, string>  $goldTokens
     * @param  array<int, string>  $predictedTokens
     */
    public function evaluate(
        string $id,
        string $lang,
        string $sourceText,
        array $goldTokens,
        array $predictedTokens,
        ?string $note = null,
    ): SegmentationEvaluation {
        $comparableSource = $this->validator->normalizeTokenText($sourceText);
        $sourceLength = mb_strlen($comparableSource, 'UTF-8');

        $goldSpans = $this->spansOf($goldTokens, $comparableSource);
        $predictedSpans = $this->spansOf($predictedTokens, $comparableSource);

        $goldBoundaries = $this->boundariesOf($goldSpans, $sourceLength);
        $predictedBoundaries = $this->boundariesOf($predictedSpans, $sourceLength);

        $truePositiveBoundaries = count(array_intersect($predictedBoundaries, $goldBoundaries));

        $goldSpanKeys = $this->spanKeys($goldSpans);
        $correctWords = 0;
        $validPredictedSpans = [];

        foreach ($predictedSpans as $span) {
            if ($span === null) {
                continue;
            }
            $validPredictedSpans[] = $span;

            if (isset($goldSpanKeys[$this->spanKey($span)])) {
                $correctWords++;
            }
        }

        $lostCharacters = $this->lostCharacterCount($goldSpans);
        [$goldWordSplits, $orphanFragments, $truncatedWords] = $this->failureModes(
            $goldSpans,
            $validPredictedSpans,
        );

        return new SegmentationEvaluation(
            id: $id,
            lang: $lang,
            sourceText: $sourceText,
            goldTokens: $goldTokens,
            predictedTokens: $predictedTokens,
            sourceLength: $sourceLength,
            goldSpans: $goldSpans,
            predictedSpans: $predictedSpans,
            goldBoundaries: $goldBoundaries,
            predictedBoundaries: $predictedBoundaries,
            truePositiveBoundaries: $truePositiveBoundaries,
            predictedBoundaryCount: count($predictedBoundaries),
            goldBoundaryCount: count($goldBoundaries),
            correctWords: $correctWords,
            predictedWordCount: count($validPredictedSpans),
            goldWordCount: count(array_filter($goldSpans, fn ($span) => $span !== null)),
            goldWordSplits: $goldWordSplits,
            orphanFragments: $orphanFragments,
            truncatedWords: $truncatedWords,
            lostCharacters: $lostCharacters,
            transcriptionFault: $lostCharacters > 0,
            note: $note,
        );
    }

    /**
     * @param  array<int, SegmentationEvaluation>  $evaluations
     */
    public function aggregate(string $lang, array $evaluations): TokenizationQualitySummary
    {
        $scored = array_filter($evaluations, fn (SegmentationEvaluation $eval) => ! $eval->transcriptionFault);

        $boundaryTruePositives = array_sum(array_map(fn (SegmentationEvaluation $eval) => $eval->truePositiveBoundaries, $scored));
        $predictedBoundaries = array_sum(array_map(fn (SegmentationEvaluation $eval) => $eval->predictedBoundaryCount, $scored));
        $goldBoundaries = array_sum(array_map(fn (SegmentationEvaluation $eval) => $eval->goldBoundaryCount, $scored));
        $correctWords = array_sum(array_map(fn (SegmentationEvaluation $eval) => $eval->correctWords, $scored));
        $predictedWords = array_sum(array_map(fn (SegmentationEvaluation $eval) => $eval->predictedWordCount, $scored));
        $goldWords = array_sum(array_map(fn (SegmentationEvaluation $eval) => $eval->goldWordCount, $scored));

        [$boundaryPrecision, $boundaryRecall, $boundaryF1] = $this->rates($boundaryTruePositives, $predictedBoundaries, $goldBoundaries);
        [$wordPrecision, $wordRecall, $wordF1] = $this->rates($correctWords, $predictedWords, $goldWords);

        return new TokenizationQualitySummary(
            lang: $lang,
            totalCues: count($evaluations),
            scoredCues: count($scored),
            transcriptionFaultCues: count($evaluations) - count($scored),
            boundaryTruePositives: $boundaryTruePositives,
            predictedBoundaries: $predictedBoundaries,
            goldBoundaries: $goldBoundaries,
            boundaryPrecision: $boundaryPrecision,
            boundaryRecall: $boundaryRecall,
            boundaryF1: $boundaryF1,
            correctWords: $correctWords,
            predictedWords: $predictedWords,
            goldWords: $goldWords,
            wordPrecision: $wordPrecision,
            wordRecall: $wordRecall,
            wordF1: $wordF1,
            goldWordSplits: array_sum(array_map(fn (SegmentationEvaluation $eval) => $eval->goldWordSplits, $scored)),
            orphanFragments: array_sum(array_map(fn (SegmentationEvaluation $eval) => $eval->orphanFragments, $scored)),
            truncatedWords: array_sum(array_map(fn (SegmentationEvaluation $eval) => $eval->truncatedWords, $scored)),
            lostCharacters: array_sum(array_map(fn (SegmentationEvaluation $eval) => $eval->lostCharacters, $evaluations)),
        );
    }

    /**
     * @param  array<int, string>  $tokenTexts
     * @return array<int, array{0: int, 1: int}|null>
     */
    private function spansOf(array $tokenTexts, string $comparableSource): array
    {
        $spans = [];
        $offset = 0;

        foreach ($tokenTexts as $tokenText) {
            $comparable = $this->validator->normalizeTokenText((string) $tokenText);

            if ($comparable === '') {
                $spans[] = null;

                continue;
            }

            $position = mb_strpos($comparableSource, $comparable, $offset, 'UTF-8');

            if ($position === false) {
                $spans[] = null;

                continue;
            }

            $length = mb_strlen($comparable, 'UTF-8');
            $spans[] = [$position, $position + $length];
            $offset = $position + $length;
        }

        return $spans;
    }

    /**
     * @param  array<int, array{0: int, 1: int}|null>  $spans
     * @return list<int>
     */
    private function boundariesOf(array $spans, int $sourceLength): array
    {
        $boundaries = [];

        foreach ($spans as $span) {
            if ($span === null) {
                continue;
            }

            [$start, $end] = $span;

            if ($start > 0) {
                $boundaries[$start] = true;
            }

            if ($end < $sourceLength) {
                $boundaries[$end] = true;
            }
        }

        $positions = array_keys($boundaries);
        sort($positions);

        return $positions;
    }

    /**
     * @param  array<int, array{0: int, 1: int}|null>  $spans
     * @return array<string, bool>
     */
    private function spanKeys(array $spans): array
    {
        $keys = [];

        foreach ($spans as $span) {
            if ($span !== null) {
                $keys[$this->spanKey($span)] = true;
            }
        }

        return $keys;
    }

    /**
     * @param  array{0: int, 1: int}  $span
     */
    private function spanKey(array $span): string
    {
        return $span[0].':'.$span[1];
    }

    /**
     * @param  array<int, array{0: int, 1: int}|null>  $goldSpans
     */
    private function lostCharacterCount(array $goldSpans): int
    {
        return count(array_filter($goldSpans, fn ($span) => $span === null));
    }

    /**
     * Failure modes over gold content words (length > 1) and predicted spans.
     *
     * - goldWordSplits: gold word fully covered by predicted tokens but split
     *   into more than one piece (no single predicted span equals the gold
     *   word). Could be valid learner re-segmentation or over-splitting; a
     *   dictionary guardrail is required to judge it (Track B Option c).
     * - orphanFragments: predicted single-character token that is a proper
     *   subset of a gold content word (stranded kana/mora such as a lone り).
     * - truncatedWords: gold content word whose characters are not fully
     *   covered by predicted tokens (dropped tail such as ならなく from
     *   ならなくちゃ).
     *
     * @param  array<int, array{0: int, 1: int}|null>  $goldSpans
     * @param  array<int, array{0: int, 1: int}>  $predictedSpans
     * @return array{0: int, 1: int, 2: int}
     */
    private function failureModes(array $goldSpans, array $predictedSpans): array
    {
        $goldWordSplits = 0;
        $truncatedWords = 0;
        $orphanFragments = 0;

        foreach ($goldSpans as $goldSpan) {
            if ($goldSpan === null) {
                continue;
            }

            [$goldStart, $goldEnd] = $goldSpan;

            if ($goldEnd - $goldStart <= 1) {
                continue;
            }

            $coverage = $this->coverageWithin($goldSpan, $predictedSpans);

            if (! $this->coverageIsComplete($coverage, $goldSpan)) {
                $truncatedWords++;

                continue;
            }

            $matchedExactly = false;

            foreach ($predictedSpans as $predictedSpan) {
                if ($predictedSpan === $goldSpan) {
                    $matchedExactly = true;
                    break;
                }
            }

            if (! $matchedExactly) {
                $goldWordSplits++;
            }
        }

        foreach ($predictedSpans as $predictedSpan) {
            [$predictedStart, $predictedEnd] = $predictedSpan;

            if ($predictedEnd - $predictedStart !== 1) {
                continue;
            }

            if ($this->isProperSubsetOfGoldWord($predictedSpan, $goldSpans)) {
                $orphanFragments++;
            }
        }

        return [$goldWordSplits, $orphanFragments, $truncatedWords];
    }

    /**
     * @param  array{0: int, 1: int}  $goldSpan
     * @param  array<int, array{0: int, 1: int}>  $predictedSpans
     * @return array<int, array{0: int, 1: int}>
     */
    private function coverageWithin(array $goldSpan, array $predictedSpans): array
    {
        [$goldStart, $goldEnd] = $goldSpan;
        $intervals = [];

        foreach ($predictedSpans as $predictedSpan) {
            [$predictedStart, $predictedEnd] = $predictedSpan;

            if ($predictedEnd <= $goldStart || $predictedStart >= $goldEnd) {
                continue;
            }

            $intervals[] = [max($predictedStart, $goldStart), min($predictedEnd, $goldEnd)];
        }

        return $this->mergeIntervals($intervals);
    }

    /**
     * @param  array<int, array{0: int, 1: int}>  $intervals
     * @return array<int, array{0: int, 1: int}>
     */
    private function mergeIntervals(array $intervals): array
    {
        if ($intervals === []) {
            return [];
        }

        usort($intervals, fn (array $a, array $b) => $a[0] <=> $b[0]);

        $merged = [$intervals[0]];

        for ($index = 1; $index < count($intervals); $index++) {
            $last = array_key_last($merged);
            [$start, $end] = $intervals[$index];

            if ($start <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $end);
            } else {
                $merged[] = [$start, $end];
            }
        }

        return $merged;
    }

    /**
     * @param  array<int, array{0: int, 1: int}>  $coverage
     * @param  array{0: int, 1: int}  $goldSpan
     */
    private function coverageIsComplete(array $coverage, array $goldSpan): bool
    {
        [$goldStart, $goldEnd] = $goldSpan;
        $position = $goldStart;

        foreach ($coverage as [$start, $end]) {
            if ($start > $position) {
                return false;
            }

            $position = max($position, $end);
        }

        return $position >= $goldEnd;
    }

    /**
     * @param  array{0: int, 1: int}  $predictedSpan
     * @param  array<int, array{0: int, 1: int}|null>  $goldSpans
     */
    private function isProperSubsetOfGoldWord(array $predictedSpan, array $goldSpans): bool
    {
        [$predictedStart, $predictedEnd] = $predictedSpan;

        foreach ($goldSpans as $goldSpan) {
            if ($goldSpan === null) {
                continue;
            }

            [$goldStart, $goldEnd] = $goldSpan;

            if ($goldEnd - $goldStart <= 1) {
                continue;
            }

            if ($goldStart <= $predictedStart
                && $predictedEnd <= $goldEnd
                && ($predictedStart !== $goldStart || $predictedEnd !== $goldEnd)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: float, 1: float, 2: float}
     */
    private function rates(int $truePositives, int $predictedCount, int $goldCount): array
    {
        $precision = $predictedCount > 0 ? $truePositives / $predictedCount : 0.0;
        $recall = $goldCount > 0 ? $truePositives / $goldCount : 0.0;
        $f1 = $precision + $recall > 0.0 ? 2 * $precision * $recall / ($precision + $recall) : 0.0;

        return [$precision, $recall, $f1];
    }
}
