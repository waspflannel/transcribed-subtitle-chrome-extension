<?php

namespace Tests\Feature;

use App\Models\BillingUsageEvent;
use App\Models\SubtitleJob;
use App\Models\SubtitleJobArtifact;
use App\Models\SubtitleJobEvent;
use App\Models\SubtitleTrack;
use App\Models\User;
use App\Services\Audio\SubtitleAudioWorkspace;
use App\Services\Billing\BillingEntitlementService;
use App\Services\Billing\BillingPlanCatalog;
use App\Services\Billing\UsageLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class WebSubtitleJobDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_destroy_requires_auth(): void
    {
        $job = SubtitleJob::factory()->create();

        $this
            ->delete(route('dashboard.jobs.destroy', ['jobId' => $job->public_id], absolute: false))
            ->assertRedirect(route('login', absolute: false));
    }

    public function test_destroy_requires_verified_email(): void
    {
        $user = User::factory()->unverified()->create();
        $job = SubtitleJob::factory()->for($user)->create();

        $this
            ->actingAs($user)
            ->delete(route('dashboard.jobs.destroy', ['jobId' => $job->public_id], absolute: false))
            ->assertRedirect(route('verification.notice', absolute: false));
    }

    public function test_owner_can_delete_their_job_and_relations_cascade(): void
    {
        $user = User::factory()->create();
        $job = SubtitleJob::factory()->for($user)->create([
            'status' => 'completed',
            'stage' => 'finalizing',
            'progress_percent' => 100,
        ]);
        $track = SubtitleTrack::factory()->for($job, 'job')->create();
        $artifact = SubtitleJobArtifact::create([
            'subtitle_job_id' => $job->id,
            'artifact_type' => 'transcript',
            'batch_index' => 0,
            'payload' => ['sample' => 'payload'],
        ]);
        $event = SubtitleJobEvent::create([
            'subtitle_job_id' => $job->id,
            'public_job_id' => $job->public_id,
            'run_id' => $job->run_id,
            'event' => 'job.created',
            'stage' => 'preparing',
            'status' => 'running',
        ]);

        $this
            ->actingAs($user)
            ->from(route('dashboard', absolute: false))
            ->delete(route('dashboard.jobs.destroy', ['jobId' => $job->public_id], absolute: false))
            ->assertRedirect(route('dashboard', absolute: false))
            ->assertSessionHas('jobs_status');

        $this->assertDatabaseMissing('subtitle_jobs', ['id' => $job->id]);
        $this->assertDatabaseMissing('subtitle_tracks', ['id' => $track->id]);
        $this->assertDatabaseMissing('subtitle_job_artifacts', ['id' => $artifact->id]);
        $this->assertDatabaseMissing('subtitle_job_events', ['id' => $event->id]);
    }

    public function test_deleting_jobs_removes_their_audio_workspaces(): void
    {
        $user = User::factory()->create();
        $job = SubtitleJob::factory()->for($user)->create(['status' => 'running']);
        $directory = SubtitleAudioWorkspace::directory($job->run_id);
        File::ensureDirectoryExists($directory);
        File::put($directory.DIRECTORY_SEPARATOR.'chunk.flac', 'temporary audio');

        $this
            ->actingAs($user)
            ->delete(route('dashboard.jobs.destroy', ['jobId' => $job->public_id], absolute: false))
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertDirectoryDoesNotExist($directory);
    }

    public function test_destroy_releases_reservation_for_running_job_but_preserves_billing_audit_trail(): void
    {
        $user = $this->userWithActiveBilling();
        $job = SubtitleJob::factory()->for($user)->create([
            'status' => 'running',
            'stage' => 'preparing',
            'video_duration_seconds' => 120,
        ]);

        $billing = app(BillingEntitlementService::class);
        $ledger = app(UsageLedger::class);
        $plans = app(BillingPlanCatalog::class);
        $period = $ledger->periodForUser($user);
        $this->assertNotNull($period);
        $ledger->reserveForJob($job, $user, $plans->requirePlan('base'), $ledger->billableMinutes($job->video_duration_seconds));

        $reservedBefore = $ledger->reservedMinutesForJob($job);
        $this->assertGreaterThan(0, $reservedBefore);

        $this
            ->actingAs($user)
            ->from(route('dashboard', absolute: false))
            ->delete(route('dashboard.jobs.destroy', ['jobId' => $job->public_id], absolute: false))
            ->assertRedirect(route('dashboard', absolute: false))
            ->assertSessionHas('jobs_status');

        $this->assertDatabaseMissing('subtitle_jobs', ['id' => $job->id]);

        $auditEvent = BillingUsageEvent::query()
            ->where('user_id', $user->id)
            ->where('event_type', 'refund')
            ->whereNull('subtitle_job_id')
            ->first();
        $this->assertNotNull($auditEvent, 'Billing usage audit trail should remain with subtitle_job_id nulled after delete.');
    }

    public function test_destroy_returns_404_for_other_users_job(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $job = SubtitleJob::factory()->for($owner)->create();

        $this
            ->actingAs($intruder)
            ->delete(route('dashboard.jobs.destroy', ['jobId' => $job->public_id], absolute: false))
            ->assertNotFound();

        $this->assertDatabaseHas('subtitle_jobs', ['id' => $job->id]);
    }

    public function test_deleting_a_completed_track_does_not_refund_used_minutes(): void
    {
        $user = $this->userWithActiveBilling();
        $job = SubtitleJob::factory()->for($user)->create(['status' => 'completed']);
        $track = SubtitleTrack::factory()->for($job, 'job')->create();
        $ledger = app(UsageLedger::class);
        $period = $ledger->periodForUser($user);
        $ledger->reserveForJob($job, $user, app(BillingPlanCatalog::class)->requirePlan('base'), 4);
        $ledger->debitCompletedJob($job, $track);
        $before = $ledger->summary($user, $period['start'], $period['end']);
        $this->assertSame(4, $before['used']);

        $this->actingAs($user)->delete(route('dashboard.jobs.destroy', ['jobId' => $job->public_id]))
            ->assertRedirect(route('dashboard'));

        $this->assertModelMissing($job);
        $this->assertModelMissing($track);
        $this->assertSame($before, $ledger->summary($user, $period['start'], $period['end']));
    }

    public function test_destroy_returns_404_for_unknown_job_id(): void
    {
        $user = User::factory()->create();

        $this
            ->actingAs($user)
            ->delete(route('dashboard.jobs.destroy', ['jobId' => '00000000-0000-0000-0000-000000000000'], absolute: false))
            ->assertNotFound();
    }

    public function test_clear_all_requires_auth(): void
    {
        $this
            ->delete(route('dashboard.jobs.clear', absolute: false))
            ->assertRedirect(route('login', absolute: false));
    }

    public function test_clear_all_deletes_only_the_authenticated_users_jobs(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $ownJobs = SubtitleJob::factory()->for($user)->count(3)->create([
            'status' => 'completed',
            'stage' => 'finalizing',
        ]);
        SubtitleJob::factory()->for($otherUser)->count(2)->create([
            'status' => 'completed',
            'stage' => 'finalizing',
        ]);

        $this
            ->actingAs($user)
            ->from(route('dashboard', absolute: false))
            ->delete(route('dashboard.jobs.clear', absolute: false))
            ->assertRedirect(route('dashboard', absolute: false))
            ->assertSessionHas('jobs_status');

        foreach ($ownJobs as $job) {
            $this->assertDatabaseMissing('subtitle_jobs', ['id' => $job->id]);
        }

        $this->assertSame(2, SubtitleJob::query()->whereBelongsTo($otherUser)->count());
    }

    public function test_clear_all_deletes_histories_larger_than_chunk_size_and_reports_accurate_count(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        SubtitleJob::factory()->for($user)->count(250)->create([
            'status' => 'completed',
            'stage' => 'finalizing',
        ]);
        SubtitleJob::factory()->for($otherUser)->count(2)->create([
            'status' => 'completed',
            'stage' => 'finalizing',
        ]);

        $this
            ->actingAs($user)
            ->from(route('dashboard', absolute: false))
            ->delete(route('dashboard.jobs.clear', absolute: false))
            ->assertRedirect(route('dashboard', absolute: false))
            ->assertSessionHas('jobs_status', '250 subtitle job(s) cleared.');

        $this->assertSame(0, SubtitleJob::query()->whereBelongsTo($user)->count());
        $this->assertSame(2, SubtitleJob::query()->whereBelongsTo($otherUser)->count());
    }

    public function test_clear_all_releases_reservations_for_running_jobs_in_each_chunk(): void
    {
        $user = $this->userWithActiveBilling();

        $jobs = SubtitleJob::factory()->for($user)->count(3)->create([
            'status' => 'running',
            'stage' => 'preparing',
            'video_duration_seconds' => 120,
        ]);

        $ledger = app(UsageLedger::class);
        $plans = app(BillingPlanCatalog::class);
        $period = $ledger->periodForUser($user);
        $this->assertNotNull($period);

        foreach ($jobs as $job) {
            $ledger->reserveForJob($job, $user, $plans->requirePlan('base'), $ledger->billableMinutes($job->video_duration_seconds));
            $this->assertGreaterThan(0, $ledger->reservedMinutesForJob($job));
        }

        $this
            ->actingAs($user)
            ->from(route('dashboard', absolute: false))
            ->delete(route('dashboard.jobs.clear', absolute: false))
            ->assertRedirect(route('dashboard', absolute: false))
            ->assertSessionHas('jobs_status', '3 subtitle job(s) cleared.');

        foreach ($jobs as $job) {
            $this->assertDatabaseMissing('subtitle_jobs', ['id' => $job->id]);
        }

        $refundEvents = BillingUsageEvent::query()
            ->where('user_id', $user->id)
            ->where('event_type', 'refund')
            ->whereNull('subtitle_job_id')
            ->count();
        $this->assertSame(3, $refundEvents, 'Each running job should have released its reservation, producing a refund event.');
    }

    public function test_clear_all_reports_when_there_are_no_jobs(): void
    {
        $user = User::factory()->create();

        $this
            ->actingAs($user)
            ->from(route('dashboard', absolute: false))
            ->delete(route('dashboard.jobs.clear', absolute: false))
            ->assertRedirect(route('dashboard', absolute: false))
            ->assertSessionHas('jobs_status', 'No subtitle jobs to clear.');
    }

    public function test_dashboard_renders_clear_all_and_per_row_delete_controls_when_jobs_exist(): void
    {
        $user = User::factory()->create();
        $jobs = SubtitleJob::factory()->for($user)->count(9)->sequence(
            ['status' => 'queued'],
            ['status' => 'running'],
            ['status' => 'completed'],
            ['status' => 'failed'],
        )->create([
            'source_language' => 'spa',
            'target_language' => 'eng',
            'updated_at' => now()->subMinute(),
        ]);
        $visibleJob = $jobs->last();
        $visibleJob->update(['updated_at' => now(), 'youtube_url' => 'https://example.test/not-the-source']);
        $oldJob = SubtitleJob::factory()->for($user)->create(['processing_version' => 'old-version']);
        $otherJob = SubtitleJob::factory()->create();

        $this
            ->actingAs($user)
            ->get(route('dashboard', absolute: false))
            ->assertOk()
            ->assertViewHas('totalJobs', 10)
            ->assertViewHas('recentJobs', fn ($jobs): bool => $jobs->count() === 8)
            ->assertSeeText('Showing 8 recent jobs. 10 total jobs in your account at this check.')
            ->assertSeeText('Clear all jobs (10)')
            ->assertSee('data-confirm="Clear all jobs in your account (10 at this check), including jobs not shown here and older processing versions?', false)
            ->assertSeeText('Deleting removes jobs and stored tracks, so those tracks cannot be reused.')
            ->assertSeeText('Completed usage is not refunded.')
            ->assertSeeText('For queued or running jobs, deletion attempts to release reserved minutes.')
            ->assertSeeText('Provider requests already in progress may finish.')
            ->assertSee('data-confirm="Delete subtitle job '.$visibleJob->public_id.' for video '.$visibleJob->youtube_video_id.'? Deleting removes jobs and stored tracks', false)
            ->assertSee('aria-label="Delete job '.$visibleJob->public_id.' for video '.$visibleJob->youtube_video_id.'"', false)
            ->assertSee('href="https://www.youtube.com/watch?v='.$visibleJob->youtube_video_id.'"', false)
            ->assertDontSee('https://example.test/not-the-source', false)
            ->assertDontSee($oldJob->public_id)
            ->assertDontSee($otherJob->public_id);
    }

    public function test_dashboard_can_clear_owned_jobs_even_when_all_are_hidden_by_version(): void
    {
        $user = User::factory()->create();
        SubtitleJob::factory()->for($user)->create(['processing_version' => 'old-version']);
        SubtitleJob::factory()->create();

        $this->actingAs($user)->get(route('dashboard', absolute: false))
            ->assertOk()
            ->assertViewHas('totalJobs', 1)
            ->assertViewHas('recentJobs', fn ($jobs): bool => $jobs->isEmpty())
            ->assertSeeText('Clear all jobs (1)')
            ->assertSeeText('No recent jobs to display.')
            ->assertDontSeeText('No subtitle jobs yet.');
    }

    public function test_dashboard_omits_clear_all_control_when_no_jobs_exist(): void
    {
        $user = User::factory()->create();
        SubtitleJob::factory()->create();

        $this
            ->actingAs($user)
            ->get(route('dashboard', absolute: false))
            ->assertOk()
            ->assertViewHas('totalJobs', 0)
            ->assertSeeText('No subtitle jobs yet.')
            ->assertDontSeeText('Clear all jobs');
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
        $ledger->ensureMonthlyGrant($user->refresh(), $plans->requirePlan('base'), $period['start'], $period['end']);

        return $user->refresh();
    }
}
