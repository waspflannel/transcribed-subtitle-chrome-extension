<?php

namespace App\Services\Subtitles;

use App\Exceptions\BillingEntitlementException;
use App\Exceptions\SubtitleProcessingException;
use App\Models\SubtitleJob;
use App\Services\Billing\UsageLedger;
use Throwable;

class SubtitleJobFailureHandler
{
    public function __construct(
        private readonly SubtitleJobArtifactStore $artifacts,
        private readonly SubtitleWorkflowLogger $logger,
        private readonly SubtitlePipelineTelemetry $telemetry,
        private readonly UsageLedger $usageLedger,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function failJob(int $subtitleJobId, string $stage, Throwable $exception, string $runId, array $context = []): void
    {
        $job = SubtitleJob::query()->find($subtitleJobId);

        if ($job === null) {
            return;
        }

        if ($job->run_id !== $runId) {
            $this->telemetry->recordStaleRunSkipped($job, $runId, $stage);

            return;
        }

        [$errorCode, $errorMessage] = $this->resolveErrorPayload($exception);

        $claimed = SubtitleJob::query()
            ->whereKey($subtitleJobId)
            ->where('run_id', $runId)
            ->whereNotIn('status', ['completed', 'failed'])
            ->update([
                'status' => 'failed',
                'stage' => $stage,
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
            ]);

        if ($claimed !== 1) {
            // Another callback already finalized this run, or the row was
            // racingly transitioned to completed/failed. Only the winner
            // proceeds to cleanup, logging, and telemetry.
            return;
        }

        $job->refresh();

        $this->cleanupReservedWork($job);

        if ($exception instanceof BillingEntitlementException) {
            $this->recordExpectedFailure($job, $stage, $exception, $context);

            return;
        }

        if ($exception instanceof SubtitleProcessingException) {
            $this->recordExpectedFailure($job, $stage, $exception, $context);

            return;
        }

        $this->logger->unexpectedFailure($job, $stage, $exception);
        $this->telemetry->recordJobFailed($job, $stage, $exception, $context);
    }

    /**
     * Resolve the error code/message for the atomic update using the same
     * branching as the previous markFailed() callers: expected exceptions
     * surface their public code, everything else falls back to internal_error.
     *
     * @return array{string, string}
     */
    private function resolveErrorPayload(Throwable $exception): array
    {
        if ($exception instanceof BillingEntitlementException || $exception instanceof SubtitleProcessingException) {
            return [$exception->publicCode, $exception->getMessage()];
        }

        return ['internal_error', 'Generation did not complete.'];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function recordExpectedFailure(
        SubtitleJob $job,
        string $stage,
        BillingEntitlementException|SubtitleProcessingException $exception,
        array $context,
    ): void {
        $this->logger->processingFailed($job, $stage, $exception);
        $this->telemetry->recordJobFailed($job, $stage, $exception, $context);
    }

    private function cleanupReservedWork(SubtitleJob $job): void
    {
        $this->usageLedger->releaseReservation($job->load('user'), 'failure');
        $this->artifacts->deleteForJob($job);
    }
}
