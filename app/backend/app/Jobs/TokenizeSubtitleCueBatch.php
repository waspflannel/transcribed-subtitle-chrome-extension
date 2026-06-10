<?php

namespace App\Jobs;

use App\Services\Subtitles\SubtitleCueBatchProcessor;

class TokenizeSubtitleCueBatch extends SubtitleCueBatchJob
{
    protected function process(SubtitleCueBatchProcessor $processor): void
    {
        $processor->tokenizeCueBatch($this->subtitleJobId, $this->batchIndex, $this->runId, $this->queuedAtMs);
    }

    protected function stage(): string
    {
        return 'tokenizing';
    }

    protected function failureMessage(): string
    {
        return 'Subtitle tokenization batch failed.';
    }
}
