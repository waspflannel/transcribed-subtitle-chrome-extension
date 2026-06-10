<?php

namespace App\Jobs;

use App\Services\Subtitles\SubtitleCueBatchProcessor;

class TranslateSubtitleCueBatch extends SubtitleCueBatchJob
{
    protected function process(SubtitleCueBatchProcessor $processor): void
    {
        $processor->translateCueBatch($this->subtitleJobId, $this->batchIndex, $this->runId, $this->queuedAtMs);
    }

    protected function stage(): string
    {
        return 'translating';
    }

    protected function failureMessage(): string
    {
        return 'Subtitle translation batch failed.';
    }
}
