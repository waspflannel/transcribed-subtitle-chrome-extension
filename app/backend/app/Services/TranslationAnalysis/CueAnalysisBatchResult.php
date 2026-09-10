<?php

namespace App\Services\TranslationAnalysis;

/**
 * Analysis artifacts share validated cue identities and source token boundaries.
 */
final class CueAnalysisBatchResult
{
    public function __construct(
        public readonly CueEnrichmentResult $tokenized,
        public readonly CueEnrichmentResult $translated,
        public readonly ?CueEnrichmentResult $romanized = null,
    ) {}
}
