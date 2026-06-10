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

        if (in_array($job->status, ['completed', 'failed'], true)) {
            return;
        }

        if ($exception instanceof BillingEntitlementException) {
            $this->failExpectedException($job, $stage, $exception, $context);

            return;
        }

        if ($exception instanceof SubtitleProcessingException) {
            $this->failExpectedException($job, $stage, $exception, $context);

            return;
        }

        $this->markFailed($job, $stage, 'internal_error', 'Generation did not complete.');
        $this->cleanupReservedWork($job);
        $this->logger->unexpectedFailure($job->refresh(), $stage, $exception);
        $this->telemetry->recordJobFailed($job->refresh(), $stage, $exception, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function failExpectedException(
        SubtitleJob $job,
        string $stage,
        BillingEntitlementException|SubtitleProcessingException $exception,
        array $context,
    ): void {
        $this->markFailed($job, $stage, $exception->publicCode, $exception->getMessage());
        $this->cleanupReservedWork($job);
        $this->logger->processingFailed($job->refresh(), $stage, $exception);
        $this->telemetry->recordJobFailed($job->refresh(), $stage, $exception, $context);
    }

    private function cleanupReservedWork(SubtitleJob $job): void
    {
        $this->usageLedger->releaseReservation($job->load('user'), 'failure');
        $this->artifacts->deleteForJob($job);
    }

    private function markFailed(SubtitleJob $job, string $stage, string $errorCode, string $errorMessage): void
    {
        $job->update([
            'status' => 'failed',
            'stage' => $stage,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
        ]);
    }

}
