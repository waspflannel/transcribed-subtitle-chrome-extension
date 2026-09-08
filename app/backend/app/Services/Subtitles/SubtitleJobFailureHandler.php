<?php

namespace App\Services\Subtitles;

use App\Exceptions\BillingEntitlementException;
use App\Exceptions\SubtitleProcessingException;
use App\Models\SubtitleJob;
use App\Services\Audio\SubtitleAudioWorkspace;
use App\Services\Billing\UsageLedger;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

class SubtitleJobFailureHandler
{
    public function __construct(
        private readonly SubtitleJobArtifactStore $artifacts,
        private readonly SubtitleWorkflowLogger $logger,
        private readonly SubtitlePipelineTelemetry $telemetry,
        private readonly UsageLedger $usageLedger,
        private readonly SubtitleJobAdmission $admission,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function failJob(
        int $subtitleJobId,
        string $stage,
        Throwable $exception,
        string $runId,
        array $context = [],
        bool $promoteQueued = true,
        ?CarbonInterface $expectedUpdatedAt = null,
        ?string $expectedStage = null,
    ): bool
    {
        $job = SubtitleJob::query()->find($subtitleJobId);

        if ($job === null) {
            return false;
        }

        if ($job->run_id !== $runId) {
            $this->telemetry->recordStaleRunSkipped($job, $runId, $stage);

            return false;
        }

        [$errorCode, $errorMessage] = $this->resolveErrorPayload($exception);

        $job = DB::transaction(function () use (
            $subtitleJobId,
            $runId,
            $stage,
            $errorCode,
            $errorMessage,
            $expectedUpdatedAt,
            $expectedStage,
        ): ?SubtitleJob {
            $current = SubtitleJobLock::current($subtitleJobId, $runId);

            if ($current === null || in_array($current->status, ['completed', 'failed', 'cancelled'], true)) {
                return null;
            }

            if (
                ($expectedUpdatedAt !== null && ! $current->updated_at?->equalTo($expectedUpdatedAt))
                || ($expectedStage !== null && $current->stage !== $expectedStage)
            ) {
                return null;
            }

            $current->update([
                'status' => 'failed',
                'stage' => $stage,
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
            ]);
            $this->usageLedger->releaseReservation($current, 'failure');
            $this->artifacts->deleteForJob($current);
            DB::afterCommit(fn () => SubtitleAudioWorkspace::delete($runId));

            return $current;
        }, attempts: 5);

        if ($job === null) {
            return false;
        }

        if ($promoteQueued) {
            $this->admission->promoteQueuedJobs($job->user_id);
        }

        if ($exception instanceof BillingEntitlementException) {
            $this->recordExpectedFailure($job, $stage, $exception, $context);

            return true;
        }

        if ($exception instanceof SubtitleProcessingException) {
            $this->recordExpectedFailure($job, $stage, $exception, $context);

            return true;
        }

        $this->logger->unexpectedFailure($job, $stage, $exception);
        $this->telemetry->recordJobFailed($job, $stage, $exception, $context);

        return true;
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
}
