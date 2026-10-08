<?php

namespace App\Services\Subtitles;

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
            batchQueueName: SubtitleQueue::batchName(),
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
            batchQueueName: SubtitleQueue::generationName(),
        );
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
        $completionQueueName = SubtitleQueue::batchName();

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
