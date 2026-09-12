<?php

namespace App\Services\TranslationAnalysis;

/**
 * Micro-averaged tokenization quality across a set of cues.
 *
 * Boundary and word precision/recall/F1 are micro-averaged over the
 * tokenization-testable cues (transcription-faulty cues are excluded from the
 * F1 pools because their gold segmentation references characters the source no
 * longer contains, so no tokenizer can recover them). Failure-mode counts are
 * summed over the same scored cues; unlocatable gold tokens and the
 * transcription-fault cue count are reported separately to attribute that class correctly.
 */
final readonly class TokenizationQualitySummary
{
    public function __construct(
        public string $lang,
        public int $totalCues,
        public int $scoredCues,
        public int $transcriptionFaultCues,
        public int $boundaryTruePositives,
        public int $predictedBoundaries,
        public int $goldBoundaries,
        public float $boundaryPrecision,
        public float $boundaryRecall,
        public float $boundaryF1,
        public int $correctWords,
        public int $predictedWords,
        public int $goldWords,
        public float $wordPrecision,
        public float $wordRecall,
        public float $wordF1,
        public int $goldWordSplits,
        public int $orphanFragments,
        public int $truncatedWords,
        public int $unlocatableGoldTokens,
        public int $unlocatablePredictedTokens,
    ) {}
}
