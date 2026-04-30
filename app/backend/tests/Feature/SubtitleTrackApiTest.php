<?php

namespace Tests\Feature;

use App\Models\SubtitleJob;
use App\Models\SubtitleTrack;
use App\SubtitleJobStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubtitleTrackApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_lookup_returns_ready_track_for_video_and_language(): void
    {
        $track = $this->createReadyTrack();

        $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->getJson('/v1/tracks/lookup?youtubeVideoId=dQw4w9WgXcQ&sourceLanguage=ar&targetLanguage=en')
            ->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('trackId', $track->public_id)
            ->assertJsonPath('status', 'ready');
    }

    public function test_lookup_returns_missing_when_track_is_not_ready(): void
    {
        $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->getJson('/v1/tracks/lookup?youtubeVideoId=dQw4w9WgXcQ&sourceLanguage=ar&targetLanguage=en')
            ->assertOk()
            ->assertExactJson(['found' => false]);
    }

    public function test_get_track_returns_canonical_track_shape(): void
    {
        $track = $this->createReadyTrack();

        $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->getJson("/v1/tracks/{$track->public_id}")
            ->assertOk()
            ->assertJsonPath('trackId', $track->public_id)
            ->assertJsonPath('jobId', $track->job->public_id)
            ->assertJsonPath('youtubeVideoId', 'dQw4w9WgXcQ')
            ->assertJsonStructure([
                'trackId',
                'jobId',
                'youtubeVideoId',
                'sourceLanguage',
                'targetLanguage',
                'generatedAt',
                'expiresAt',
                'cues' => [
                    '*' => ['cueId', 'index', 'startMs', 'endMs', 'sourceText', 'translatedText', 'tokens'],
                ],
            ]);
    }

    public function test_get_track_returns_stable_expired_error(): void
    {
        $track = $this->createReadyTrack([
            'expires_at' => now()->subMinute(),
        ]);

        $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->getJson("/v1/tracks/{$track->public_id}")
            ->assertStatus(410)
            ->assertJsonPath('error.code', 'expired');
    }

    /**
     * @param  array<string, mixed>  $trackOverrides
     */
    private function createReadyTrack(array $trackOverrides = []): SubtitleTrack
    {
        $job = SubtitleJob::factory()->create([
            'youtube_video_id' => 'dQw4w9WgXcQ',
            'source_language' => 'ar',
            'target_language' => 'en',
            'status' => SubtitleJobStatus::Completed,
        ]);

        $track = SubtitleTrack::factory()
            ->for($job, 'job')
            ->create(array_merge([
                'youtube_video_id' => $job->youtube_video_id,
                'source_language' => $job->source_language,
                'target_language' => $job->target_language,
            ], $trackOverrides));

        $job->update(['expires_at' => $track->expires_at]);

        return $track->load('job');
    }

    private function installId(): string
    {
        return 'install_'.str_repeat('a', 32);
    }
}
