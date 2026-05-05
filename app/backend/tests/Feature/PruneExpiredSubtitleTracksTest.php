<?php

namespace Tests\Feature;

use App\Models\SubtitleJob;
use App\Models\SubtitleTrack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class PruneExpiredSubtitleTracksTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_deletes_expired_tracks_and_their_empty_jobs(): void
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

        $this->artisan('subtitles:prune-expired')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('subtitle_tracks', ['id' => $expiredTrack->id]);
        $this->assertDatabaseMissing('subtitle_jobs', ['id' => $expiredJob->id]);
        $this->assertDatabaseHas('subtitle_tracks', ['id' => $freshTrack->id]);
        $this->assertDatabaseHas('subtitle_jobs', ['id' => $freshJob->id]);

        Log::shouldHaveReceived('info')
            ->with('backend.expired_subtitles_pruned', [
                'expired_track_count' => 1,
                'expired_job_count' => 1,
            ]);
    }
}
