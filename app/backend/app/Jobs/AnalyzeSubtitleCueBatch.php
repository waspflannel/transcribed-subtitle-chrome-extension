<?php

namespace App\Jobs;

use App\Services\Subtitles\SubtitleCueBatchProcessor;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class AnalyzeSubtitleCueBatch extends SubtitleCueBatchJob
{
    /**
     * A held lock means a twin delivery is analyzing this batch, so the
     * duplicate is dropped instead of polling. The lock outlives the 300s
     * timeout but expires before the batch connection's 360s retry_after,
     * so a killed worker's own redelivery can still take it.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('subtitle-analysis:'.$this->runId.':'.$this->batchIndex))
                ->dontRelease()->expireAfter(330),
            ...parent::middleware(),
        ];
    }

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
