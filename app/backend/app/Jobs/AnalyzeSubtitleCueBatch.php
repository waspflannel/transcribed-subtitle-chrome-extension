<?php

namespace App\Jobs;

use App\Services\Subtitles\SubtitleCueBatchProcessor;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class AnalyzeSubtitleCueBatch extends SubtitleCueBatchJob
{
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('subtitle-analysis:'.$this->runId.':'.$this->batchIndex))
                ->releaseAfter(1)->expireAfter(660),
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
