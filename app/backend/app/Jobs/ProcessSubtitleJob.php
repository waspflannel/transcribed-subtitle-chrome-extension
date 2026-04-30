<?php

namespace App\Jobs;

use App\Models\SubtitleJob;
use App\Services\Subtitles\MockSubtitleTrackGenerator;
use App\Services\Subtitles\SubtitleJobService;
use App\SubtitleJobStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessSubtitleJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public SubtitleJob $subtitleJob) {}

    public function handle(SubtitleJobService $subtitleJobs, MockSubtitleTrackGenerator $tracks): void
    {
        $this->subtitleJob->refresh();

        if ($this->subtitleJob->status === SubtitleJobStatus::Completed) {
            return;
        }

        $subtitleJobs->markProcessing($this->subtitleJob);

        $track = $tracks->generate($this->subtitleJob->refresh());

        $subtitleJobs->markCompleted($this->subtitleJob, $track);
    }

    public function failed(?Throwable $exception): void
    {
        $this->subtitleJob->refresh()->update([
            'status' => SubtitleJobStatus::Failed,
            'error_code' => 'internal_error',
            'error_message' => 'Mock subtitle processing failed.',
            'error_details' => null,
        ]);

        Log::error('subtitle_job_failed', [
            'jobId' => $this->subtitleJob->public_id,
            'error' => $exception?->getMessage(),
        ]);
    }
}
