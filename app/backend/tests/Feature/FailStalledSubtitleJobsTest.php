<?php

namespace Tests\Feature;

use App\Models\SubtitleJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FailStalledSubtitleJobsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_fails_running_jobs_past_their_stage_timeout_plus_slack_and_records_a_trace_event(): void
    {
        // A tokenizing job last touched well past the tokenizing timeout (600s)
        // plus default slack (120s). Set its updated_at 30 minutes ago so it
        // is safely over the deadline regardless of config drift.
        $stalled = SubtitleJob::factory()->create([
            'status' => 'running',
            'stage' => 'tokenizing',
            'run_id' => '018f0000-0000-7000-8000-000000000001',
            'updated_at' => now()->subMinutes(30),
        ]);

        // A running job still inside its stage timeout must be left alone.
        $fresh = SubtitleJob::factory()->create([
            'status' => 'running',
            'stage' => 'tokenizing',
            'run_id' => '018f0000-0000-7000-8000-000000000002',
            'updated_at' => now()->subSecond(),
        ]);

        // A completed job with an old updated_at must not be touched.
        $completed = SubtitleJob::factory()->create([
            'status' => 'completed',
            'stage' => 'finalizing',
            'updated_at' => now()->subHours(2),
        ]);

        $this->artisan('subtitles:fail-stalled-jobs')->assertExitCode(0);

        $stalled->refresh();
        $fresh->refresh();
        $completed->refresh();

        $this->assertSame('failed', $stalled->status);
        $this->assertSame('tokenizing', $stalled->stage);
        $this->assertSame('enrichment_failed', $stalled->error_code);

        $this->assertSame('running', $fresh->status, 'Job inside its stage timeout must not be failed.');
        $this->assertSame('completed', $completed->status, 'Completed jobs must not be re-failed.');

        $this->assertDatabaseHas('subtitle_job_events', [
            'subtitle_job_id' => $stalled->id,
            'event' => 'job.failed',
        ]);
    }

    public function test_it_no_ops_when_disabled(): void
    {
        config(['subtitles.stalled_job.enabled' => false]);

        $stalled = SubtitleJob::factory()->create([
            'status' => 'running',
            'stage' => 'tokenizing',
            'updated_at' => now()->subHours(2),
        ]);

        $this->artisan('subtitles:fail-stalled-jobs')->assertExitCode(0);

        $stalled->refresh();
        $this->assertSame('running', $stalled->status);
    }
}
