<?php

namespace App\Services\Subtitles;

use App\Exceptions\SubtitleProcessingException;
use App\Jobs\AcquireSubtitleAudio;
use App\Models\SubtitleJob;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Promotes a user's queued subtitle jobs into processing as running slots
 * free up. Submission accepts jobs beyond the per-tier processing
 * concurrency as status "queued"; every transition out of "running"
 * (completion, failure, deletion) promotes the oldest queued job FIFO.
 */
class SubtitleJobAdmission
{
    public function __construct(
        private readonly SubtitleRuntimeTracer $tracer,
    ) {}

    public function promoteQueuedJobs(?int $userId): void
    {
        if ($userId === null) {
            return;
        }

        // Deleting several jobs at once can free several slots; keep
        // promoting until the next queued job no longer fits.
        while (($promoted = $this->promoteNext($userId)) !== null) {
            try {
                AcquireSubtitleAudio::dispatch($promoted->id, $promoted->run_id)
                    ->onConnection(SubtitleQueue::connection())
                    ->onQueue(SubtitleQueue::generationNameForJob($promoted));
            } catch (Throwable $exception) {
                if (SubtitleQueue::connection() === 'sync') {
                    throw $exception;
                }

                app(SubtitleJobFailureHandler::class)->failJob(
                    subtitleJobId: $promoted->id,
                    stage: 'preparing',
                    exception: SubtitleProcessingException::queuePublicationFailed(
                        ['reason' => 'queue_publication_failed'],
                        $exception,
                    ),
                    runId: (string) $promoted->run_id,
                    context: ['reason' => 'queue_publication_failed'],
                    promoteQueued: false,
                );

                break;
            }
        }
    }

    private function promoteNext(int $userId): ?SubtitleJob
    {
        $promoted = DB::transaction(function () use ($userId): ?SubtitleJob {
            $lockedUser = User::query()->whereKey($userId)->lockForUpdate()->first();

            if ($lockedUser === null) {
                return null;
            }

            $next = SubtitleJob::query()
                ->whereBelongsTo($lockedUser)
                ->where('status', 'queued')
                ->orderBy('created_at')
                ->orderBy('id')
                ->first();

            if ($next === null) {
                return null;
            }

            $runningJobs = SubtitleJob::query()
                ->whereBelongsTo($lockedUser)
                ->where('status', 'running')
                ->count();

            if ($runningJobs >= SubtitleTier::generationConcurrency($next->generation_tier)) {
                return null;
            }

            $next->update([
                'status' => 'running',
                'stage' => 'preparing',
                'progress_percent' => 5,
            ]);

            return $next->refresh();
        });

        if ($promoted !== null) {
            $this->tracer->jobEvent($promoted, 'job.promoted_from_queue', [
                'stage' => 'preparing',
                'status' => 'running',
                'queue' => SubtitleQueue::generationNameForJob($promoted),
            ]);
        }

        return $promoted;
    }
}
