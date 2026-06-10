<?php

namespace App\Jobs;

use App\Services\Subtitles\SubtitleCueBatchProcessor;

class RomanizeSubtitleCueBatch extends SubtitleCueBatchJob
{
    protected function process(SubtitleCueBatchProcessor $processor): void
    {
        $processor->romanizeCueBatch($this->subtitleJobId, $this->batchIndex, $this->runId, $this->queuedAtMs);
    }

    protected function stage(): string
    {
        return 'romanizing';
    }

    protected function failureMessage(): string
    {
        return 'Subtitle romanization batch failed.';
    }
}
