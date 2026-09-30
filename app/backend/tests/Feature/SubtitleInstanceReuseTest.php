<?php

namespace Tests\Feature;

use App\Jobs\AcquireSubtitleAudio;
use App\Models\SubtitleJob;
use App\Models\SubtitleTrack;
use App\Services\Subtitles\SubtitleJobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SubtitleInstanceReuseTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_saved_track_is_reused_without_keys_and_preserves_other_legacy_rows(): void
    {
        Queue::fake();
        $saved = $this->legacyJob(['status' => 'completed']);
        $track = SubtitleTrack::factory()->create(['subtitle_job_id' => $saved->id, 'expires_at' => null]);
        $failed = $this->legacyJob(['status' => 'failed']);
        config(['ai.providers.openai.key' => null, 'ai.providers.eleven.key' => null]);

        $reused = app(SubtitleJobService::class)->generate($this->payload(), 'install_first');
        $again = app(SubtitleJobService::class)->generate($this->payload(), 'install_second');

        $this->assertSame($saved->id, $reused->id);
        $this->assertSame($saved->id, $again->id);
        $this->assertSame($track->id, $reused->track->id);
        $this->assertSame(64, strlen($reused->reuse_key));
        $this->assertNull($failed->refresh()->reuse_key);
        $this->assertDatabaseCount('subtitle_jobs', 2);
        Queue::assertNothingPushed();
    }

    public function test_regeneration_remains_on_the_canonical_legacy_row_across_installations(): void
    {
        Queue::fake();
        $saved = $this->legacyJob(['status' => 'completed']);
        SubtitleTrack::factory()->create(['subtitle_job_id' => $saved->id, 'expires_at' => null]);
        $other = $this->legacyJob(['status' => 'failed']);
        $service = app(SubtitleJobService::class);
        $service->generate($this->payload(), 'install_first');
        $oldRun = $saved->run_id;

        $reset = $service->generate([...$this->payload(), 'forceRegenerate' => true], 'install_second');
        $again = $service->generate($this->payload(), 'install_third');

        $this->assertSame($saved->id, $reset->id);
        $this->assertSame($saved->id, $again->id);
        $this->assertSame('running', $reset->status);
        $this->assertNotSame($oldRun, $reset->run_id);
        $this->assertSame($reset->run_id, $again->run_id);
        $this->assertNull($other->refresh()->reuse_key);
        $this->assertDatabaseCount('subtitle_jobs', 2);
        Queue::assertPushed(AcquireSubtitleAudio::class, 1);
    }

    public function test_pinning_a_legacy_job_does_not_hide_a_stale_preparing_run(): void
    {
        Queue::fake();
        $job = $this->legacyJob(['updated_at' => now()->subHour()]);
        $oldRun = $job->run_id;

        $reset = app(SubtitleJobService::class)->generate($this->payload(), 'install_first');

        $this->assertSame($job->id, $reset->id);
        $this->assertNotSame($oldRun, $reset->run_id);
        $this->assertNotNull($reset->reuse_key);
        Queue::assertPushed(AcquireSubtitleAudio::class, 1);
    }

    private function legacyJob(array $overrides = []): SubtitleJob
    {
        return SubtitleJob::factory()->create([
            'youtube_video_id' => 'dQw4w9WgXcQ',
            'ai_provider' => 'openai',
            'ai_model' => config('ai.providers.openai.models.text.default'),
            'include_romanization' => false,
            'processing_version' => SubtitleJobService::processingVersionFor(false, false),
            ...$overrides,
        ]);
    }

    private function payload(): array
    {
        return [
            'youtubeVideoId' => 'dQw4w9WgXcQ',
            'youtubeUrl' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'sourceLanguage' => 'auto',
            'targetLanguage' => 'eng',
            'includeRomanization' => false,
            'includeTranslation' => false,
            'aiProvider' => 'openai',
        ];
    }
}
