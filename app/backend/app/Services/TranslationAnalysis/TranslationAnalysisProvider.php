<?php

namespace App\Services\TranslationAnalysis;

interface TranslationAnalysisProvider
{
    /**
     * @param  array<int, array<string, mixed>>  $cues
     */
    public function enrich(array $cues, string $sourceLanguage, string $targetLanguage): CueEnrichmentResult;
}
