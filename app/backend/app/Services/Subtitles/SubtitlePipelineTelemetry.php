<?php

namespace App\Services\Subtitles;

use App\Exceptions\SubtitleProcessingException;
use App\Models\SubtitleJob;
use App\Models\SubtitleJobArtifact;
use App\Models\SubtitleJobEvent;
use Illuminate\Bus\Batch;
use Throwable;

class SubtitlePipelineTelemetry
{
    public function __construct(
        private readonly SubtitleWorkflowLogger $logger,
        private readonly SubtitleRuntimeTracer $tracer,
    ) {}

    public function currentTimeMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    public function recordQueueWait(SubtitleJob $job, string $stage, ?int $batchIndex, ?int $queuedAtMs): void
    {
        if ($queuedAtMs === null) {
            return;
        }

        $waitMs = max(0, $this->currentTimeMs() - $queuedAtMs);

        $this->logger->queueWaitObserved(
            job: $job,
            stage: $stage,
            waitMs: $waitMs,
            batchIndex: $batchIndex,
        );
        $this->tracer->jobEvent($job, 'queue.wait_observed', $this->withBatchIndex([
            'stage' => $stage,
            'queue_connection' => SubtitleQueue::connection(),
            'queue_family' => $batchIndex === null ? SubtitleQueue::FAMILY_GENERATION : SubtitleQueue::FAMILY_BATCH,
            'queue' => $batchIndex === null ? SubtitleQueue::generationNameForJob($job) : SubtitleQueue::batchNameForJob($job),
            'wait_ms' => $waitMs,
        ], $batchIndex));
        $this->recordSlowQueueWait($job, $stage, $waitMs, $batchIndex);
    }

    public function recordStageStarted(SubtitleJob $job, string $stage, ?int $batchIndex = null): void
    {
        $this->tracer->jobEvent($job, 'stage.started', $this->withBatchIndex([
            'stage' => $stage,
            'status' => $job->status,
            'queue_connection' => SubtitleQueue::connection(),
            'queue_family' => $batchIndex === null ? SubtitleQueue::FAMILY_GENERATION : SubtitleQueue::FAMILY_BATCH,
            'queue' => $batchIndex === null ? SubtitleQueue::generationNameForJob($job) : SubtitleQueue::batchNameForJob($job),
            'worker_pid' => getmypid() ?: null,
        ], $batchIndex));
    }

    public function recordStageCompleted(SubtitleJob $job, string $stage, int $startedAtMs, ?int $batchIndex = null): void
    {
        $durationMs = $this->durationMs($startedAtMs);

        $this->logger->stageTiming($job, $stage, $durationMs, $batchIndex);
        $this->tracer->jobEvent($job, 'stage.completed', $this->withBatchIndex([
            'stage' => $stage,
            'status' => $job->status,
            'duration_ms' => $durationMs,
            'worker_pid' => getmypid() ?: null,
        ], $batchIndex));
        $this->recordSlowStage($job, $stage, $durationMs, $batchIndex);
    }

    /**
     * First-cue availability begins when source-only draft cues are stored.
     */
    public function recordFirstCueAvailable(SubtitleJob $job): void
    {
        $this->tracer->jobEvent($job, 'delivery.first_cue_available', [
            'stage' => $job->stage,
            'status' => $job->status,
            'duration_ms' => (int) abs(now()->diffInMilliseconds($job->created_at)),
        ]);
    }

    public function recordFirstAnnotatedCueAvailable(SubtitleJob $job, int $readyThroughMs): void
    {
        $this->tracer->jobEvent($job, 'delivery.first_annotated_cue_available', [
            'stage' => $job->stage,
            'status' => $job->status,
            'ready_through_ms' => $readyThroughMs,
            'duration_ms' => (int) abs(now()->diffInMilliseconds($job->created_at)),
        ]);
    }

    public function recordTranscriptionChunkCompleted(SubtitleJob $job, int $chunkIndex, int $startedAtMs, ?int $audioBytes): void
    {
        $this->tracer->jobEvent($job, 'provider.transcription_chunk_completed', [
            'stage' => 'transcribing',
            'chunk_index' => $chunkIndex,
            'audio_bytes' => $audioBytes,
            'duration_ms' => $this->durationMs($startedAtMs),
        ]);
    }

    public function recordAudioChunkPrepared(SubtitleJob $job, int $chunkIndex, int $startedAtMs, int $audioBytes): void
    {
        $this->tracer->jobEvent($job, 'audio.chunk_prepared', [
            'stage' => 'transcribing',
            'chunk_index' => $chunkIndex,
            'audio_bytes' => $audioBytes,
            'duration_ms' => $this->durationMs($startedAtMs),
        ]);
    }

    public function recordTranscriptCacheHit(SubtitleJob $job): void
    {
        $this->tracer->jobEvent($job, 'transcript.cache_hit', [
            'stage' => 'transcribing',
            'status' => $job->status,
        ]);
    }

    public function recordJobCompleted(SubtitleJob $job): void
    {
        $durationMs = (int) abs(now()->diffInMilliseconds($job->created_at));

        $this->tracer->jobEvent($job, 'job.completed', [
            'stage' => 'finalizing',
            'status' => 'completed',
            'duration_ms' => $durationMs,
        ]);
    }

    public function recordStaleRunSkipped(SubtitleJob $job, string $queuedRunId, string $stage): void
    {
        $this->tracer->jobEvent($job, 'job.stale_run_skipped', [
            'stage' => $stage,
            'status' => $job->status,
            'queued_run_id' => $queuedRunId,
            'current_run_id' => $job->run_id,
        ], 'warning');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function recordJobFailed(SubtitleJob $job, string $stage, Throwable $exception, array $context): void
    {
        $previous = $exception->getPrevious();
        $lastSuccessfulEvent = $this->lastSuccessfulEvent($job);

        $this->tracer->jobEvent($job, 'job.failed', [
            'stage' => $stage,
            'status' => 'failed',
            'error_code' => $job->error_code,
            'exception' => $exception::class,
            'previous_exception' => $previous !== null ? $previous::class : null,
            'worker_pid' => getmypid() ?: null,
            'last_successful_event' => $lastSuccessfulEvent?->event,
            'last_successful_stage' => $lastSuccessfulEvent?->stage,
            ...($exception instanceof SubtitleProcessingException ? $this->logger->sanitizedFailureContext($exception->context) : []),
            ...$context,
        ], 'error');
    }

    public function recordBatchDispatched(
        int $subtitleJobId,
        string $runId,
        string $batchName,
        string $queueName,
        Batch $batch,
    ): void {
        $this->tracer->jobEventById($subtitleJobId, 'batch.dispatched', [
            'run_id' => $runId,
            'laravel_batch_id' => $batch->id,
            'queue_connection' => SubtitleQueue::connection(),
            'queue_family' => SubtitleQueue::FAMILY_BATCH,
            'queue' => $queueName,
            ...$this->batchContext($batchName, $batch),
        ]);
    }

    /**
     * Maps each batch stage's real completion ratio into the job's overall
     * progress band so users see movement during the longest phase instead
     * of a frozen hardcoded percentage.
     */
    private const BATCH_PROGRESS_BANDS = [
        'transcribing' => [50, 65],
        // Romanization now runs chained inside the analysis batch, so the whole
        // tokenize/romanize/translate phase reports under the 'analysis' band.
        'analysis' => [65, 90],
    ];

    public function recordBatchProgress(int $subtitleJobId, string $runId, string $batchName, string $stage, Batch $batch): void
    {
        $this->tracer->jobEventById($subtitleJobId, 'batch.progress', [
            'run_id' => $runId,
            'laravel_batch_id' => $batch->id,
            ...$this->batchContext($batchName, $batch),
            'progress_percent' => $batch->progress(),
        ]);

        $this->writeJobBatchProgress($subtitleJobId, $runId, $stage, $batch);
    }

    private function writeJobBatchProgress(int $subtitleJobId, string $runId, string $stage, Batch $batch): void
    {
        $band = self::BATCH_PROGRESS_BANDS[$stage] ?? null;

        if ($band === null) {
            return;
        }

        [$from, $to] = $band;
        $progress = $batch->progress();
        if ($stage === 'analysis') {
            $job = SubtitleJob::query()->find($subtitleJobId);
            if ($job === null || $job->run_id !== $runId || $job->status !== 'running' || $job->stage !== 'tokenizing') {
                return;
            }
            $total = app(SubtitleJobArtifactStore::class)->batchCount($job, SubtitleJobArtifactStore::DRAFT_CUES);
            $completed = SubtitleJobArtifact::query()->where('subtitle_job_id', $subtitleJobId)
                ->where('run_id', $runId)->where('artifact_type', SubtitleJobArtifactStore::ANALYZED_CUES)->count();
            $progress = 100 * $completed / $total;
        }
        $percent = min($to, $from + (int) floor(($to - $from) * min(100, max(0, $progress)) / 100));

        // The monotonic guard doubles as a write throttle: only batch
        // completions that move the integer percent forward touch the row.
        SubtitleJob::query()
            ->whereKey($subtitleJobId)
            ->where('run_id', $runId)
            ->where('status', 'running')
            ->where('progress_percent', '<', $percent)
            ->update(['progress_percent' => $percent]);
    }

    public function recordBatchCompleted(int $subtitleJobId, string $runId, string $batchName, Batch $batch): void
    {
        $this->tracer->jobEventById($subtitleJobId, 'batch.completed', [
            'run_id' => $runId,
            'laravel_batch_id' => $batch->id,
            ...$this->batchContext($batchName, $batch),
            'progress_percent' => $batch->progress(),
        ]);
    }

    public function recordBatchFailed(
        int $subtitleJobId,
        string $stage,
        string $runId,
        string $batchName,
        Batch $batch,
        Throwable $exception,
    ): void {
        $this->tracer->jobEventById($subtitleJobId, 'batch.failed', [
            'run_id' => $runId,
            'stage' => $stage,
            'laravel_batch_id' => $batch->id,
            ...$this->batchContext($batchName, $batch),
            'exception' => $exception::class,
        ], 'error');
    }

    public function recordBatchFinalized(int $subtitleJobId, string $runId, string $batchName, Batch $batch): void
    {
        $this->tracer->jobEventById($subtitleJobId, $batch->cancelled() ? 'batch.cancelled' : 'batch.finalized', [
            'run_id' => $runId,
            'laravel_batch_id' => $batch->id,
            ...$this->batchContext($batchName, $batch),
            'progress_percent' => $batch->progress(),
        ], $batch->cancelled() ? 'warning' : 'info');
    }

    private function recordSlowQueueWait(SubtitleJob $job, string $stage, int $waitMs, ?int $batchIndex): void
    {
        $thresholdMs = max(0, (int) config('subtitles.tracing.slow_queue_wait_ms', 0));

        if ($thresholdMs === 0 || $waitMs <= $thresholdMs) {
            return;
        }

        $this->tracer->jobEvent($job, 'stage.slow', $this->withBatchIndex([
            'stage' => $stage,
            'wait_ms' => $waitMs,
            'threshold_ms' => $thresholdMs,
            'slow_type' => 'queue_wait',
        ], $batchIndex), 'warning');
    }

    private function recordSlowStage(SubtitleJob $job, string $stage, int $durationMs, ?int $batchIndex): void
    {
        $thresholdMs = max(0, (int) config('subtitles.tracing.slow_stage_ms', 0));

        if ($thresholdMs === 0 || $durationMs <= $thresholdMs) {
            return;
        }

        $this->tracer->jobEvent($job, 'stage.slow', $this->withBatchIndex([
            'stage' => $stage,
            'duration_ms' => $durationMs,
            'threshold_ms' => $thresholdMs,
            'slow_type' => 'stage_duration',
        ], $batchIndex), 'warning');
    }

    private function durationMs(int $startedAtMs): int
    {
        return max(0, $this->currentTimeMs() - $startedAtMs);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function withBatchIndex(array $context, ?int $batchIndex): array
    {
        if ($batchIndex === null) {
            return $context;
        }

        return [
            ...$context,
            'batch_index' => $batchIndex,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function batchContext(string $batchName, Batch $batch): array
    {
        return [
            'batch_name' => $batchName,
            'total_jobs' => $batch->totalJobs,
            'pending_jobs' => $batch->pendingJobs,
            'failed_jobs' => $batch->failedJobs,
            'processed_jobs' => $batch->processedJobs(),
        ];
    }

    private function lastSuccessfulEvent(SubtitleJob $job): ?SubtitleJobEvent
    {
        return $job->events()
            ->whereNotIn('event', [
                'artifact.deleted',
                'batch.failed',
                'job.failed',
                'job.stale_run_skipped',
                'queue.failed',
                'stage.slow',
            ])
            ->latest('created_at')
            ->latest('id')
            ->first();
    }
}
