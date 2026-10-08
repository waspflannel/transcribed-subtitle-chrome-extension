<?php

namespace App\Services\Subtitles;

use App\Jobs\FinalizeSubtitleJob;
use App\Jobs\MergeSubtitleTranscript;
use App\Jobs\PrepareSubtitleCuesAfterAnalysisBatches;
use App\Models\SubtitleJob;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use LogicException;
use Throwable;

class SubtitleBatchDispatcher
{
    /**
     * @param  array<int, object>  $jobs
     */
    public function dispatchAnalysis(SubtitleJob $job, array $jobs): void
    {
        $this->dispatchBatch(
            job: $job,
            jobs: $jobs,
            batchName: 'subtitle analysis '.$job->public_id,
            stage: 'analysis',
            completionJobClass: PrepareSubtitleCuesAfterAnalysisBatches::class,
            completionJobArguments: [$job->id, $job->run_id],
            batchConnection: SubtitleQueue::batchConnection(),
            batchQueueName: SubtitleQueue::batchNameForJob($job),
        );
    }

    /**
     * Transcription chunks use the generation queue and are bounded by the chunk plan.
     *
     * @param  array<int, object>  $jobs
     */
    public function dispatchTranscription(SubtitleJob $job, array $jobs, int $transcribingStartedAtMs): void
    {
        $this->dispatchBatch(
            job: $job,
            jobs: $jobs,
            batchName: 'subtitle transcription '.$job->public_id,
            stage: 'transcribing',
            completionJobClass: MergeSubtitleTranscript::class,
            completionJobArguments: [$job->id, $job->run_id, $transcribingStartedAtMs],
            batchConnection: SubtitleQueue::connection(),
            batchQueueName: SubtitleQueue::generationNameForJob($job),
        );
    }

    public function dispatchMergedCueTrackFinalization(SubtitleJob $job): void
    {
        // Assembly already has a worker. Publish its local result immediately
        // after commit instead of waiting behind more audio/provider work.
        FinalizeSubtitleJob::dispatchSync($job->id, $job->run_id);
    }

    /**
     * Completion jobs are short continuations, so they run as batch work.
     *
     * @param  array<int, object|array<int, object>>  $jobs
     * @param  class-string  $completionJobClass
     * @param  array<int, mixed>  $completionJobArguments
     */
    private function dispatchBatch(
        SubtitleJob $job,
        array $jobs,
        string $batchName,
        string $stage,
        string $completionJobClass,
        array $completionJobArguments,
        string $batchConnection,
        string $batchQueueName,
    ): void {
        if ($jobs === []) {
            throw new LogicException('Cannot dispatch an empty subtitle batch.');
        }

        $subtitleJobId = $job->id;
        $runId = $job->run_id;
        $completionQueueName = SubtitleQueue::batchNameForJob($job);

        Bus::batch($jobs)
            ->name($batchName)
            ->onConnection($batchConnection)
            ->onQueue($batchQueueName)
            ->before(static function (Batch $batch) use ($subtitleJobId, $runId, $batchName, $batchQueueName): void {
                app(SubtitlePipelineTelemetry::class)->recordBatchDispatched(
                    $subtitleJobId,
                    $runId,
                    $batchName,
                    $batchQueueName,
                    $batch,
                );
            })
            ->progress(static function (Batch $batch) use ($subtitleJobId, $runId, $batchName, $stage): void {
                app(SubtitlePipelineTelemetry::class)->recordBatchProgress($subtitleJobId, $runId, $batchName, $stage, $batch);
            })
            ->then(static function (Batch $batch) use (
                $subtitleJobId,
                $runId,
                $batchName,
                $completionJobClass,
                $completionJobArguments,
                $completionQueueName,
            ): void {
                app(SubtitlePipelineTelemetry::class)->recordBatchCompleted($subtitleJobId, $runId, $batchName, $batch);
                $completionJobClass::dispatch(...$completionJobArguments)
                    ->onConnection(SubtitleQueue::batchConnection())
                    ->onQueue($completionQueueName);
            })
            ->catch(static function (Batch $batch, Throwable $exception) use ($subtitleJobId, $stage, $runId, $batchName): void {
                app(SubtitlePipelineTelemetry::class)->recordBatchFailed($subtitleJobId, $stage, $runId, $batchName, $batch, $exception);
            })
            ->finally(static function (Batch $batch) use ($subtitleJobId, $runId, $batchName): void {
                app(SubtitlePipelineTelemetry::class)->recordBatchFinalized($subtitleJobId, $runId, $batchName, $batch);
            })
            ->dispatch();
    }
}
