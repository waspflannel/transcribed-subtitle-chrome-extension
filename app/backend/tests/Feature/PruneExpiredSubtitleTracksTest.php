<?php

namespace Tests\Feature;

use App\Models\CachedVideoTranscript;
use App\Models\SubtitleJob;
use App\Models\SubtitleJobEvent;
use App\Models\SubtitleTrack;
use App\Services\InstanceSettings;
use App\Services\Subtitles\SubtitleJobFailureHandler;
use App\Services\Subtitles\SubtitleJobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class PruneExpiredSubtitleTracksTest extends TestCase
{
    use RefreshDatabase;

    public function test_terminal_job_backfill_preserves_recent_and_active_jobs(): void
    {
        $this->travelTo(now()->startOfSecond());
        $oldUpdatedAt = now()->subDays(45);
        $oldJobs = collect(['failed', 'cancelled'])->map(fn (string $status): SubtitleJob => SubtitleJob::factory()->create([
            'status' => $status,
            'updated_at' => $oldUpdatedAt,
            'expires_at' => null,
        ]));
        $recent = SubtitleJob::factory()->create(['status' => 'failed', 'updated_at' => now()->subDays(5)]);
        $existingExpiry = SubtitleJob::factory()->create(['status' => 'cancelled', 'expires_at' => now()->addDays(4)]);
        $active = collect(['queued', 'running'])->map(fn (string $status): SubtitleJob => SubtitleJob::factory()->create([
            'status' => $status,
            'updated_at' => $oldUpdatedAt,
        ]));
        $activeExpired = SubtitleJob::factory()->create(['status' => 'running', 'expires_at' => now()->subDay()]);

        foreach ($oldJobs as $job) {
            SubtitleJobEvent::create([
                'subtitle_job_id' => $job->id,
                'public_job_id' => $job->public_id,
                'run_id' => $job->run_id,
                'event' => 'job.'.$job->status,
            ]);
        }

        $migration = require database_path('migrations/2026_09_12_065450_backfill_terminal_subtitle_job_expiry.php');
        $migration->up();
        $migration->up();

        foreach ($oldJobs as $job) {
            $this->assertTrue($job->fresh()->updated_at->equalTo($oldUpdatedAt));
            $this->assertTrue($job->fresh()->expires_at->equalTo($oldUpdatedAt->copy()->addDays(30)));
        }
        $this->assertTrue($recent->fresh()->expires_at->equalTo(now()->addDays(25)));
        $this->assertTrue($existingExpiry->fresh()->expires_at->equalTo(now()->addDays(4)));
        foreach ($active as $job) {
            $this->assertNull($job->fresh()->expires_at);
        }

        $this->artisan('subtitles:prune-expired')->assertSuccessful();

        foreach ($oldJobs as $job) {
            $this->assertModelMissing($job);
            $this->assertDatabaseMissing('subtitle_job_events', ['subtitle_job_id' => $job->id]);
        }
        foreach ([$recent, $existingExpiry, $activeExpired, ...$active] as $job) {
            $this->assertModelExists($job);
        }
    }

    #[TestWith([null])]
    #[TestWith([7])]
    public function test_failed_jobs_receive_a_diagnostic_expiry_without_extending_it_on_duplicate_failure(?int $retentionDays): void
    {
        app(InstanceSettings::class)->update(['retentionDays' => $retentionDays]);
        $this->travelTo(now()->startOfSecond());
        $job = SubtitleJob::factory()->create();
        $failure = app(SubtitleJobFailureHandler::class);
        $this->assertTrue($failure->failJob($job->id, 'transcribing', new RuntimeException('test failure'), $job->run_id));
        $deadline = now()->addDays(30);
        $this->assertTrue($job->fresh()->expires_at->equalTo($deadline));
        $this->travel(1)->days();
        $this->assertFalse($failure->failJob($job->id, 'transcribing', new RuntimeException('duplicate'), $job->run_id));
        $this->assertTrue($job->fresh()->expires_at->equalTo($deadline));
    }

    #[TestWith([null])]
    #[TestWith([7])]
    public function test_cancelled_jobs_receive_a_diagnostic_expiry_independent_of_saved_track_retention(?int $retentionDays): void
    {
        app(InstanceSettings::class)->update(['retentionDays' => $retentionDays]);
        $this->travelTo(now()->startOfSecond());
        $job = SubtitleJob::factory()->create();
        $service = app(SubtitleJobService::class);
        $service->cancel($job);
        $deadline = now()->addDays(30);
        $this->assertTrue($job->fresh()->expires_at->equalTo($deadline));

        $this->travel(1)->days();
        $service->cancel($job->fresh());
        $this->assertTrue($job->fresh()->expires_at->equalTo($deadline));
    }

    public function test_it_deletes_expired_tracks_their_empty_jobs_and_expired_cached_transcripts(): void
    {
        Log::spy();

        $expiredJob = SubtitleJob::factory()->create([
            'status' => 'completed',
            'expires_at' => now()->subDay(),
        ]);
        $expiredTrack = SubtitleTrack::factory()
            ->for($expiredJob, 'job')
            ->create([
                'expires_at' => now()->subDay(),
            ]);
        $freshJob = SubtitleJob::factory()->create([
            'expires_at' => now()->addDay(),
        ]);
        $freshTrack = SubtitleTrack::factory()
            ->for($freshJob, 'job')
            ->create([
                'expires_at' => now()->addDay(),
            ]);
        $expiredTranscript = CachedVideoTranscript::create([
            'youtube_video_id' => 'expiredvid1',
            'requested_source_language' => 'auto',
            'transcription_model' => 'scribe-test',
            'audio_duration_seconds' => 42,
            'payload' => ['language' => 'spa', 'durationSeconds' => 42.0, 'webVtt' => 'WEBVTT', 'segments' => []],
            'expires_at' => now()->subDay(),
        ]);
        $freshTranscript = CachedVideoTranscript::create([
            'youtube_video_id' => 'freshvideo1',
            'requested_source_language' => 'auto',
            'transcription_model' => 'scribe-test',
            'audio_duration_seconds' => 42,
            'payload' => ['language' => 'spa', 'durationSeconds' => 42.0, 'webVtt' => 'WEBVTT', 'segments' => []],
            'expires_at' => now()->addDay(),
        ]);

        $this->artisan('subtitles:prune-expired')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('subtitle_tracks', ['id' => $expiredTrack->id]);
        $this->assertDatabaseMissing('subtitle_jobs', ['id' => $expiredJob->id]);
        $this->assertDatabaseHas('subtitle_tracks', ['id' => $freshTrack->id]);
        $this->assertDatabaseHas('subtitle_jobs', ['id' => $freshJob->id]);
        $this->assertDatabaseMissing('cached_video_transcripts', ['id' => $expiredTranscript->id]);
        $this->assertDatabaseHas('cached_video_transcripts', ['id' => $freshTranscript->id]);

        Log::shouldHaveReceived('info')
            ->with('backend.expired_subtitles_pruned', [
                'expired_track_count' => 1,
                'expired_job_count' => 1,
                'expired_cached_transcript_count' => 1,
            ]);
    }
}
