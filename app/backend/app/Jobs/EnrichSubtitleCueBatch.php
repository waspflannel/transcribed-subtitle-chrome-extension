<?php

namespace App\Jobs;

use App\Services\Subtitles\SubtitleCueBatchProcessor;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class EnrichSubtitleCueBatch extends SubtitleCueBatchJob
{
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('subtitle-enrichment:'.$this->runId.':'.$this->batchIndex))
                ->releaseAfter(1)->expireAfter(660),
            ...parent::middleware(),
        ];
    }

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
