<?php

namespace Tests\Feature;

use App\Models\CachedVideoTranscript;
use App\Models\SubtitleJob;
use App\Models\SubtitleTrack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class PruneExpiredSubtitleTracksTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_deletes_expired_tracks_their_empty_jobs_and_expired_cached_transcripts(): void
    {
        Log::spy();

        $expiredJob = SubtitleJob::factory()->create([
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
