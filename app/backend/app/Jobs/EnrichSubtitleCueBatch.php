<?php

namespace App\Jobs;

use App\Services\Subtitles\SubtitleCueBatchProcessor;

class EnrichSubtitleCueBatch extends SubtitleCueBatchJob
{
    protected function process(SubtitleCueBatchProcessor $processor): void
    {
        $processor->enrichCueBatch($this->subtitleJobId, $this->batchIndex, $this->runId, $this->queuedAtMs);
    }

    protected function stage(): string
    {
        return 'enriching';
    }

    protected function failureMessage(): string
    {
        return 'Subtitle enrichment batch failed.';
    }
}
