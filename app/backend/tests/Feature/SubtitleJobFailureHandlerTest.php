<?php

namespace Tests\Feature;

use App\Exceptions\SubtitleProcessingException;
use App\Models\BillingUsageEvent;
use App\Models\SubtitleJob;
use App\Models\SubtitleJobEvent;
use App\Models\User;
use App\Services\Billing\BillingPlanCatalog;
use App\Services\Billing\UsageLedger;
use App\Services\Subtitles\SubtitleJobFailureHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SubtitleJobFailureHandlerTest extends TestCase
{
    use RefreshDatabase;

    public function test_fail_job_claims_failure_atomically_and_only_one_callback_finalizes(): void
    {
        $user = $this->userWithActiveBilling();
        $job = SubtitleJob::factory()->for($user)->create([
            'status' => 'running',
            'stage' => 'tokenizing',
            'video_duration_seconds' => 120,
        ]);

        $ledger = app(UsageLedger::class);
        $plans = app(BillingPlanCatalog::class);
        $period = $ledger->periodForUser($user);
        $this->assertNotNull($period);
        $ledger->reserveForJob(
            $job,
            $user,
            $plans->requirePlan('base'),
            $ledger->billableMinutes($job->video_duration_seconds),
        );
        $this->assertGreaterThan(0, $ledger->reservedMinutesForJob($job));

        $handler = app(SubtitleJobFailureHandler::class);
        $exception = SubtitleProcessingException::enrichmentFailed();

        $handler->failJob($job->id, 'tokenizing', $exception, $job->run_id);
        $handler->failJob($job->id, 'tokenizing', $exception, $job->run_id);

        $refreshed = $job->refresh();
        $this->assertSame('failed', $refreshed->status);
        $this->assertSame('enrichment_failed', $refreshed->error_code);
        $this->assertSame('tokenizing', $refreshed->stage);

        $refundCount = (int) BillingUsageEvent::query()
            ->where('subtitle_job_id', $job->id)
            ->where('event_type', 'refund')
            ->count();
        $this->assertSame(1, $refundCount, 'Only the winning callback should release the reservation.');

        $failureTelemetryCount = (int) SubtitleJobEvent::query()
            ->where('subtitle_job_id', $job->id)
            ->where('event', 'job.failed')
            ->count();
        $this->assertSame(1, $failureTelemetryCount, 'Only the winning callback should emit failure telemetry.');

        $reservedMinutes = $ledger->reservedMinutesForJob($job);
        $this->assertSame(0, $reservedMinutes, 'Reservation should be fully released after failure.');
    }

    public function test_fail_job_with_stale_run_id_does_not_mark_job_failed(): void
    {
        $user = $this->userWithActiveBilling();
        $job = SubtitleJob::factory()->for($user)->create([
            'status' => 'running',
            'stage' => 'tokenizing',
        ]);
        $staleRunId = (string) Str::uuid();

        app(SubtitleJobFailureHandler::class)->failJob(
            $job->id,
            'tokenizing',
            SubtitleProcessingException::enrichmentFailed(),
            $staleRunId,
        );

        $this->assertSame('running', $job->refresh()->status);
        $this->assertDatabaseHas('subtitle_job_events', [
            'subtitle_job_id' => $job->id,
            'event' => 'job.stale_run_skipped',
            'stage' => 'tokenizing',
        ]);
        $this->assertDatabaseMissing('subtitle_job_events', [
            'subtitle_job_id' => $job->id,
            'event' => 'job.failed',
        ]);
        $this->assertSame(0, (int) BillingUsageEvent::query()->where('subtitle_job_id', $job->id)->where('event_type', 'refund')->count());
    }

    public function test_fail_job_does_not_overwrite_already_completed_job(): void
    {
        $user = $this->userWithActiveBilling();
        $job = SubtitleJob::factory()->for($user)->create([
            'status' => 'completed',
            'stage' => 'finalizing',
            'progress_percent' => 100,
            'error_code' => null,
            'error_message' => null,
        ]);

        app(SubtitleJobFailureHandler::class)->failJob(
            $job->id,
            'tokenizing',
            SubtitleProcessingException::enrichmentFailed(),
            $job->run_id,
        );

        $refreshed = $job->refresh();
        $this->assertSame('completed', $refreshed->status);
        $this->assertSame('finalizing', $refreshed->stage);
        $this->assertNull($refreshed->error_code);
        $this->assertNull($refreshed->error_message);
        $this->assertDatabaseMissing('subtitle_job_events', [
            'subtitle_job_id' => $job->id,
            'event' => 'job.failed',
        ]);
        $this->assertSame(0, (int) BillingUsageEvent::query()->where('subtitle_job_id', $job->id)->where('event_type', 'refund')->count());
    }

    private function userWithActiveBilling(): User
    {
        $user = User::factory()->create();

        $user->forceFill([
            'stripe_customer_id' => 'cus_test_'.$user->id,
            'stripe_subscription_id' => 'sub_test_'.$user->id,
            'stripe_subscription_item_id' => 'si_test_'.$user->id,
            'billing_plan_code' => 'base',
            'billing_subscription_status' => 'active',
            'billing_current_period_start' => now()->startOfMonth()->toImmutable(),
            'billing_current_period_end' => now()->addMonthNoOverflow()->startOfMonth()->toImmutable(),
            'billing_cancel_at_period_end' => false,
        ])->save();

        $ledger = app(UsageLedger::class);
        $plans = app(BillingPlanCatalog::class);
        $period = $ledger->periodForUser($user);
        $this->assertNotNull($period);
        $ledger->ensureMonthlyGrant($user->refresh(), $plans->requirePlan('base'), $period['start'], $period['end']);

        return $user->refresh();
    }
}
