<?php

namespace App\Console\Commands;

use App\Exceptions\SubtitleProcessingException;
use App\Jobs\LyricsCorrectionJob;
use App\Models\SubtitleJob;
use App\Models\SubtitleTrackLyricsCorrection;
use App\Services\Subtitles\LyricsCorrectionService;
use App\Services\Subtitles\SubtitleJobAdmission;
use App\Services\Subtitles\SubtitleJobFailureHandler;
use App\Services\Subtitles\SubtitleQueue;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('subtitles:fail-stalled-jobs')]
#[Description('Fail running subtitle jobs whose stage has exceeded its timeout plus slack.')]
class FailStalledSubtitleJobs extends Command
{
    public function handle(SubtitleJobFailureHandler $failureHandler, SubtitleJobAdmission $admission, LyricsCorrectionService $corrections): int
    {
        if (! (bool) config('subtitles.stalled_job.enabled', true)) {
            $this->components->info('Stalled-job watcher is disabled.');

            return self::SUCCESS;
        }

        $slackSeconds = (int) config('subtitles.stalled_job.slack_seconds', 120);
        $defaultTimeout = (int) config('subtitles.stalled_job.default_stage_timeout_seconds', 600);
        $stageTimeouts = (array) config('subtitles.stalled_job.stage_timeout_seconds', []);

        $now = now();

        $jobs = SubtitleJob::query()
            ->where('status', 'running')
            ->get();

        $failed = 0;

        /** @var SubtitleJob $job */
        foreach ($jobs as $job) {
            $stage = (string) ($job->stage ?? '');
            $stageTimeout = (int) ($stageTimeouts[$stage] ?? $defaultTimeout);
            $cutoff = $job->updated_at?->copy()?->addSeconds($stageTimeout + $slackSeconds) ?? $now;

            if ($cutoff > $now) {
                continue;
            }

            $exception = SubtitleProcessingException::enrichmentFailed(
                'Subtitle generation stalled past its stage timeout.',
                [
                    'reason' => 'stalled_timeout',
                    'stage' => $stage,
                    'stage_timeout_seconds' => $stageTimeout,
                    'slack_seconds' => $slackSeconds,
                    'updated_at' => $job->updated_at?->toIso8601String(),
                ],
            );

            Log::info('backend.subtitle_job_stalled_failed', [
                'subtitle_job_id' => $job->id,
                'run_id' => $job->run_id,
                'stage' => $stage,
                'stage_timeout_seconds' => $stageTimeout,
            ]);

            $failureHandler->failJob(
                subtitleJobId: $job->id,
                stage: $stage ?: 'unknown',
                exception: $exception,
                runId: (string) $job->run_id,
                context: ['reason' => 'stalled_timeout'],
            );

            $failed++;
        }

        // Recover a missing delivery once per revision, after an existing worker
        // and its queue retry have had time to finish. Duplicate deliveries are
        // serialized by the attempt lock and rejected by the revision check.
        $correctionCutoff = $now->copy()->subSeconds(
            (int) config('queue.connections.'.SubtitleQueue::connection().'.retry_after', 0)
                + LyricsCorrectionJob::MAX_BACKOFF_SECONDS
                + $slackSeconds,
        );

        $stalledCorrections = SubtitleTrackLyricsCorrection::query()
            ->whereIn('status', ['queued', 'running'])
            ->where('updated_at', '<=', $correctionCutoff)
            ->get(['id', 'subtitle_track_id', 'attempt_id', 'work_revision']);

        foreach ($stalledCorrections as $correction) {
            $corrections->recoverStalledAttempt($correction, $correctionCutoff);
            if ($correction->fresh()?->status === 'failed') {
                $failed++;
            }
        }

        $this->components->info("Failed {$failed} stalled subtitle job(s).");

        // Promotion normally rides completion/failure/deletion, but a worker
        // killed between marking a job terminal and promoting would strand
        // queued jobs forever; this scheduled sweep heals that window.
        SubtitleJob::query()
            ->where('status', 'queued')
            ->distinct()
            ->pluck('user_id')
            ->each(fn (?int $userId) => $admission->promoteQueuedJobs($userId));

        return self::SUCCESS;
    }
}
