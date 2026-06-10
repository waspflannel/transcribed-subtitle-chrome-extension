<?php

namespace App\Jobs;

use App\Services\Subtitles\SubtitleGenerationPipeline;

class PrepareSubtitleCuesAfterAnalysisBatches extends SubtitleBatchContinuationJob
{
    protected function process(SubtitleGenerationPipeline $pipeline): void
    {
        $pipeline->prepareCuesAfterCompletedAnalysisBatches($this->subtitleJobId, $this->runId, $this->queuedAtMs);
    }

    protected function stage(): string
    {
        return 'tokenizing';
    }

    protected function failureMessage(): string
    {
        return 'Subtitle analysis continuation failed.';
    }
}
