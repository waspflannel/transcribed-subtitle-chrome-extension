<?php

namespace Tests\Feature;

use App\Exceptions\SubtitleProcessingException;
use App\Models\BillingUsageEvent;
use App\Models\SubtitleJob;
use App\Models\SubtitleJobEvent;
use App\Models\User;
use App\Services\Billing\BillingPlanCatalog;
use App\Services\Billing\UsageLedger;
use App\Services\Subtitles\SubtitleJobAdmission;
use App\Services\Subtitles\SubtitleJobArtifactStore;
use App\Services\Subtitles\SubtitleJobFailureHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
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
        $exception = SubtitleProcessingException::enrichmentFailed(context: [
            'reason' => 'token_text_not_in_source',
            'cue_index' => 12,
            'token_position' => 3,
            'prompt' => 'private prompt',
            'tokens' => [['text' => 'private generated token']],
            'provider_payload' => ['private' => 'response'],
        ]);

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
        $failure = SubtitleJobEvent::query()->where('subtitle_job_id', $job->id)->where('event', 'job.failed')->firstOrFail();
        $this->assertSame('token_text_not_in_source', $failure->context['reason']);
        $this->assertSame(12, $failure->context['cue_index']);
        $this->assertSame(3, $failure->context['token_position']);
        $this->assertStringNotContainsString('private', json_encode($failure->context));

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

    public function test_queue_publication_failure_releases_reservation_without_recursive_promotion(): void
    {
        $user = $this->userWithActiveBilling();
        $job = SubtitleJob::factory()->for($user)->create(['status' => 'running', 'stage' => 'preparing']);
        $ledger = app(UsageLedger::class);
        $ledger->reserveForJob($job, $user, app(BillingPlanCatalog::class)->requirePlan('base'), 1);

        $this->mock(SubtitleJobAdmission::class)
            ->shouldReceive('promoteQueuedJobs')
            ->never();

        $settled = app(SubtitleJobFailureHandler::class)->failJob(
            subtitleJobId: $job->id,
            stage: 'preparing',
            exception: SubtitleProcessingException::queuePublicationFailed(),
            runId: $job->run_id,
            promoteQueued: false,
        );

        $this->assertTrue($settled);
        $this->assertSame('failed', $job->refresh()->status);
        $this->assertSame('queue_publication_failed', $job->error_code);
        $this->assertSame(0, $ledger->reservedMinutesForJob($job));
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

    public function test_cleanup_database_failure_rolls_back_status_and_settlement(): void
    {
        $user = $this->userWithActiveBilling();
        $job = SubtitleJob::factory()->for($user)->create(['status' => 'running']);
        $ledger = app(UsageLedger::class);
        $ledger->reserveForJob($job, $user, app(BillingPlanCatalog::class)->requirePlan('base'), 2);
        $this->mock(SubtitleJobArtifactStore::class)
            ->shouldReceive('deleteForJob')->once()->andThrow(new RuntimeException('cleanup failed'));

        try {
            app(SubtitleJobFailureHandler::class)->failJob($job->id, 'tokenizing', SubtitleProcessingException::enrichmentFailed(), $job->run_id);
            $this->fail('Expected cleanup failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('cleanup failed', $exception->getMessage());
        }

        $this->assertSame('running', $job->refresh()->status);
        $this->assertSame(2, $ledger->reservedMinutesForJob($job));
        $this->assertDatabaseMissing('billing_usage_events', ['idempotency_key' => 'settlement:'.$job->id.':'.$job->run_id]);
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
