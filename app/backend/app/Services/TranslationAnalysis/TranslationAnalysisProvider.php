<?php

namespace App\Services\TranslationAnalysis;

interface TranslationAnalysisProvider
{
    /**
     * @param  array<int, array<string, mixed>>  $cues
     */
    public function enrich(array $cues, string $sourceLanguage, string $targetLanguage): CueEnrichmentResult;

    /**
     * @param  array<int, array<string, mixed>>  $cues
     */
    public function romanize(array $cues, string $sourceLanguage): CueEnrichmentResult;

    /**
     * @param  array<string, mixed>  $cue
     * @param  array<string, mixed>  $token
     * @return array<string, mixed>
     */
    public function enrichToken(array $cue, array $token, string $sourceLanguage, string $targetLanguage): array;
}
