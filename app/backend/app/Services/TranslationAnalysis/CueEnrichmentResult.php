<?php

namespace App\Services\TranslationAnalysis;

final readonly class CueEnrichmentResult
{
    /**
     * @param  array<int, array<string, mixed>>  $cues
     */
    public function __construct(
        public array $cues,
        public string $sourceDialect,
    ) {}
}
