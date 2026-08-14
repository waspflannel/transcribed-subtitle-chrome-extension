<?php

namespace Tests\Feature;

use App\Models\SubtitleJob;
use App\Models\SubtitleTrack;
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

    public function test_it_fails_and_clears_stalled_lyrics_corrections(): void
    {
        $job = SubtitleJob::factory()->create(['status' => 'completed']);
        $track = SubtitleTrack::factory()->create(['subtitle_job_id' => $job->id]);
        $correction = $track->lyricsCorrection()->create([
            'attempt_id' => '018f9e2f-0d8c-7500-8f38-9f4c5d1b3030',
            'status' => 'running',
            'work_revision' => 3,
            'work_state' => ['stage' => 'tokenizing', 'batchIndex' => 1, 'cues' => [['cueId' => 'cue-0001', 'sourceText' => 'private draft cue']]],
            'lyrics' => 'stalled private lyrics',
        ]);
        $correction->forceFill(['updated_at' => now()->subHours(2)])->saveQuietly();

        $this->artisan('subtitles:fail-stalled-jobs')->assertExitCode(0);

        $correction->refresh();
        $this->assertSame('failed', $correction->status);
        $this->assertNull($correction->lyrics);
        $this->assertNull($correction->work_state);
    }

    public function test_it_does_not_fail_queued_lyrics_corrections_waiting_in_the_queue(): void
    {
        $job = SubtitleJob::factory()->create(['status' => 'completed']);
        $track = SubtitleTrack::factory()->create(['subtitle_job_id' => $job->id]);
        $correction = $track->lyricsCorrection()->create([
            'attempt_id' => '018f9e2f-0d8c-7500-8f38-9f4c5d1b3031',
            'status' => 'queued',
            'work_revision' => 0,
            'work_state' => ['stage' => 'aligning'],
            'lyrics' => 'waiting private lyrics',
        ]);
        $correction->forceFill(['updated_at' => now()->subHours(2)])->saveQuietly();

        $this->artisan('subtitles:fail-stalled-jobs')->assertExitCode(0);

        $correction->refresh();
        $this->assertSame('queued', $correction->status);
        $this->assertSame('waiting private lyrics', $correction->lyrics);
    }
}
