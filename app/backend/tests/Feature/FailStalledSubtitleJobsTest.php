<?php

namespace Tests\Feature;

use App\Models\SubtitleJob;
use App\Models\SubtitleTrack;
use App\Models\SubtitleTrackLyricsCorrection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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

    public function test_a_new_generation_heartbeat_wins_over_an_old_timeout_snapshot(): void
    {
        $stalled = SubtitleJob::factory()->create([
            'status' => 'running',
            'stage' => 'tokenizing',
            'updated_at' => now()->subMinutes(30),
        ]);

        SubtitleJob::retrieved(function (SubtitleJob $observed) use ($stalled): void {
            if ($observed->id === $stalled->id) {
                DB::table('subtitle_jobs')
                    ->whereKey($stalled->id)
                    ->update(['updated_at' => now()]);
            }
        });

        $this->artisan('subtitles:fail-stalled-jobs')->assertExitCode(0);

        $this->assertSame('running', $stalled->fresh()->status);
    }

    public function test_it_fails_and_clears_lyrics_corrections_that_stall_again_after_recovery(): void
    {
        $job = SubtitleJob::factory()->create(['status' => 'completed']);
        $track = SubtitleTrack::factory()->for($job, 'job')->create();
        $correction = $track->lyricsCorrection()->create([
            'attempt_id' => '018f9e2f-0d8c-7500-8f38-9f4c5d1b3030',
            'status' => 'running',
            'work_revision' => 3,
            'work_state' => ['stage' => 'tokenizing', 'recoveryDispatched' => true, 'batchIndex' => 1, 'cues' => [['cueId' => 'cue-0001', 'sourceText' => 'private draft cue']]],
            'lyrics' => 'stalled private lyrics',
        ]);
        $correction->forceFill(['updated_at' => now()->subHours(2)])->saveQuietly();

        $this->artisan('subtitles:fail-stalled-jobs')->assertExitCode(0);

        $correction->refresh();
        $this->assertSame('failed', $correction->status);
        $this->assertNull($correction->lyrics);
        $this->assertNull($correction->work_state);
    }

    public function test_it_does_not_fail_cancelled_lyrics_corrections(): void
    {
        $job = SubtitleJob::factory()->create(['status' => 'completed']);
        $track = SubtitleTrack::factory()->for($job, 'job')->create();
        $correction = $track->lyricsCorrection()->create([
            'attempt_id' => '018f9e2f-0d8c-7500-8000-000000000035',
            'status' => 'cancelled',
            'work_revision' => 4,
        ]);
        $correction->forceFill(['updated_at' => now()->subHours(2)])->saveQuietly();

        $this->artisan('subtitles:fail-stalled-jobs')->assertExitCode(0);

        $this->assertSame('cancelled', $correction->fresh()->status);
    }

    public function test_it_does_not_fail_queued_lyrics_corrections_waiting_in_the_queue(): void
    {
        $job = SubtitleJob::factory()->create(['status' => 'completed']);
        $track = SubtitleTrack::factory()->for($job, 'job')->create();
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

    public function test_it_fails_only_the_rows_that_pass_the_atomic_cleanup_recheck(): void
    {
        $stalledJob = SubtitleJob::factory()->create(['status' => 'completed']);
        $stalledTrack = SubtitleTrack::factory()->for($stalledJob, 'job')->create();
        $stalled = $stalledTrack->lyricsCorrection()->create([
            'attempt_id' => '018f9e2f-0d8c-7500-8000-000000000032',
            'status' => 'running',
            'work_revision' => 3,
            'work_state' => ['stage' => 'tokenizing', 'recoveryDispatched' => true],
            'lyrics' => 'stalled private lyrics',
        ]);
        $stalled->forceFill(['updated_at' => now()->subHours(2)])->saveQuietly();

        $revisionJob = SubtitleJob::factory()->create(['status' => 'completed']);
        $revisionTrack = SubtitleTrack::factory()->for($revisionJob, 'job')->create();
        $revisionAdvanced = $revisionTrack->lyricsCorrection()->create([
            'attempt_id' => '018f9e2f-0d8c-7500-8000-000000000033',
            'status' => 'running',
            'work_revision' => 4,
            'work_state' => ['stage' => 'tokenizing', 'recoveryDispatched' => true],
            'lyrics' => 'revision private lyrics',
        ]);
        $revisionAdvanced->forceFill(['updated_at' => now()->subHours(2)])->saveQuietly();

        $heartbeatJob = SubtitleJob::factory()->create(['status' => 'completed']);
        $heartbeatTrack = SubtitleTrack::factory()->for($heartbeatJob, 'job')->create();
        $heartbeatAdvanced = $heartbeatTrack->lyricsCorrection()->create([
            'attempt_id' => '018f9e2f-0d8c-7500-8000-000000000034',
            'status' => 'running',
            'work_revision' => 6,
            'work_state' => ['stage' => 'tokenizing', 'recoveryDispatched' => true],
            'lyrics' => 'heartbeat private lyrics',
        ]);
        $heartbeatAdvanced->forceFill(['updated_at' => now()->subHours(2)])->saveQuietly();

        SubtitleTrackLyricsCorrection::retrieved(function (SubtitleTrackLyricsCorrection $observed) use ($revisionAdvanced, $heartbeatAdvanced): void {
            if ($observed->subtitle_track_id === $revisionAdvanced->subtitle_track_id && $observed->attempt_id === $revisionAdvanced->attempt_id) {
                DB::table('subtitle_track_lyrics_corrections')
                    ->where('subtitle_track_id', $revisionAdvanced->subtitle_track_id)
                    ->where('attempt_id', $revisionAdvanced->attempt_id)
                    ->update(['work_revision' => 5]);

                return;
            }

            if ($observed->subtitle_track_id === $heartbeatAdvanced->subtitle_track_id && $observed->attempt_id === $heartbeatAdvanced->attempt_id) {
                DB::table('subtitle_track_lyrics_corrections')
                    ->where('subtitle_track_id', $heartbeatAdvanced->subtitle_track_id)
                    ->where('attempt_id', $heartbeatAdvanced->attempt_id)
                    ->update(['updated_at' => now()]);
            }
        });

        $this->assertSame(0, Artisan::call('subtitles:fail-stalled-jobs'));
        $this->assertStringContainsString('Failed 1 stalled subtitle job(s).', Artisan::output());

        $this->assertSame('failed', $stalled->fresh()->status);
        $this->assertNull($stalled->fresh()->lyrics);
        $this->assertSame('running', $revisionAdvanced->fresh()->status);
        $this->assertSame(5, $revisionAdvanced->fresh()->work_revision);
        $this->assertSame('revision private lyrics', $revisionAdvanced->fresh()->lyrics);
        $this->assertSame('running', $heartbeatAdvanced->fresh()->status);
        $this->assertSame(6, $heartbeatAdvanced->fresh()->work_revision);
        $this->assertSame('heartbeat private lyrics', $heartbeatAdvanced->fresh()->lyrics);
    }
}
