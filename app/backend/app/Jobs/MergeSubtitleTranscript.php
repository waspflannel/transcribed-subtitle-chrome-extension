<?php

namespace App\Jobs;

use App\Services\Subtitles\SubtitleGenerationPipeline;

/**
 * Final generation stage: assembles the per-chunk Scribe payloads into one
 * normalized transcript, stores the draft cues, and dispatches the analysis
 * batches. Runs after a transcription batch or a persisted transcript cache hit.
 */
class MergeSubtitleTranscript extends SubtitleBatchContinuationJob
{
    public function __construct(
        int $subtitleJobId,
        string $runId,
        public readonly int $transcribingStartedAtMs,
        ?int $queuedAtMs = null,
    ) {
        parent::__construct($subtitleJobId, $runId, $queuedAtMs);
    }

    protected function process(SubtitleGenerationPipeline $pipeline): void
    {
        $pipeline->mergeTranscriptAndDispatchAnalysis(
            $this->subtitleJobId,
            $this->runId,
            $this->transcribingStartedAtMs,
            $this->queuedAtMs,
        );
    }

    protected function stage(): string
    {
        return 'transcribing';
    }

    protected function failureMessage(): string
    {
        return 'Subtitle transcript merge failed.';
    }
}
