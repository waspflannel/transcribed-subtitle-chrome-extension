<?php

namespace App\Services\TranslationAnalysis;

/**
 * Output of one merged tokenize+translate call: the same two artifacts the
 * former separate calls produced, so downstream batch assembly is unchanged.
 */
final class CueAnalysisBatchResult
{
    public function __construct(
        public readonly CueEnrichmentResult $tokenized,
        public readonly CueEnrichmentResult $translated,
    ) {}
}
