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
     * @param  array<int, object|array<int, object>>  $jobs  batch members; an
     *                                                       array member is dispatched as a chain
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
        );
    }

    /**
     * Transcription chunks ride the tier's generation queue, not the AI
     * batch queue: they are provider uploads bounded per job by the chunk
     * plan, so the per-user AI batch concurrency cap does not apply.
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
            batchQueueName: SubtitleQueue::generationNameForJob($job),
        );
    }

    /**
     * @param  array<int, object>  $jobs
     */
    public function dispatchEnrichment(SubtitleJob $job, array $jobs): void
    {
        $this->dispatchBatch(
            job: $job,
            jobs: $jobs,
            batchName: 'subtitle enrichment '.$job->public_id,
            stage: 'enriching',
            completionJobClass: FinalizeSubtitleJob::class,
            completionJobArguments: [$job->id, true, $job->run_id],
        );
    }

    public function dispatchMergedCueTrackFinalization(SubtitleJob $job): void
    {
        FinalizeSubtitleJob::dispatch($job->id, false, $job->run_id)
            ->onConnection(SubtitleQueue::connection())
            ->onQueue(SubtitleQueue::generationNameForJob($job));
    }

    /**
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
        ?string $batchQueueName = null,
    ): void {
        if ($jobs === []) {
            throw new LogicException('Cannot dispatch an empty subtitle batch.');
        }

        $subtitleJobId = $job->id;
        $runId = $job->run_id;
        $batchQueueName ??= SubtitleQueue::batchNameForJob($job);
        $completionQueueName = SubtitleQueue::generationNameForJob($job);

        Bus::batch($jobs)
            ->name($batchName)
            ->onConnection(SubtitleQueue::connection())
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
                    ->onConnection(SubtitleQueue::connection())
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
