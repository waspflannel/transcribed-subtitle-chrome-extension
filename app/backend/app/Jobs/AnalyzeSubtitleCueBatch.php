<?php

namespace App\Jobs;

use App\Services\Subtitles\SubtitleCueBatchProcessor;

class AnalyzeSubtitleCueBatch extends SubtitleCueBatchJob
{
    protected function process(SubtitleCueBatchProcessor $processor): void
    {
        $processor->analyzeCueBatch($this->subtitleJobId, $this->batchIndex, $this->runId, $this->queuedAtMs);
    }

    protected function stage(): string
    {
        return 'tokenizing';
    }

    protected function failureMessage(): string
    {
        return 'Subtitle analysis batch failed.';
    }
}
