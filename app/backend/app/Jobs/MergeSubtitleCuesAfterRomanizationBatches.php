<?php

namespace App\Jobs;

use App\Services\Subtitles\SubtitleGenerationPipeline;

class MergeSubtitleCuesAfterRomanizationBatches extends SubtitleBatchContinuationJob
{
    protected function process(SubtitleGenerationPipeline $pipeline): void
    {
        $pipeline->mergeCuesAfterCompletedRomanizationBatches($this->subtitleJobId, $this->runId, $this->queuedAtMs);
    }

    protected function stage(): string
    {
        return 'romanizing';
    }

    protected function failureMessage(): string
    {
        return 'Subtitle romanization continuation failed.';
    }
}
