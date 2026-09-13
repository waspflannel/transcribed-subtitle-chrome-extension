<?php

namespace App\Services\TranslationAnalysis;

/**
 * Per-cue tokenization evaluation produced by TokenizationBoundaryMetric.
 *
 * All counts are raw tallies; precision/recall/F1 are derived in the aggregate
 * summary so micro-averaging pools true positives across cues.
 *
 * @param  array<int, string>  $goldTokens
 * @param  array<int, string>  $predictedTokens
 * @param  array<int, array{0: int, 1: int}|null>  $goldSpans
 * @param  array<int, array{0: int, 1: int}|null>  $predictedSpans
 * @param  list<int>  $goldBoundaries
 * @param  list<int>  $predictedBoundaries
 */
final readonly class SegmentationEvaluation
{
    public function __construct(
        public string $id,
        public string $lang,
        public string $sourceText,
        public array $goldTokens,
        public array $predictedTokens,
        public int $sourceLength,
        public array $goldSpans,
        public array $predictedSpans,
        public array $goldBoundaries,
        public array $predictedBoundaries,
        public int $truePositiveBoundaries,
        public int $predictedBoundaryCount,
        public int $goldBoundaryCount,
        public int $correctWords,
        public int $predictedWordCount,
        public int $goldWordCount,
        public int $goldWordSplits,
        public int $orphanFragments,
        public int $truncatedWords,
        public int $unlocatableGoldTokens,
        public int $unlocatablePredictedTokens,
        public bool $transcriptionFault,
        public ?string $note,
    ) {}
}
