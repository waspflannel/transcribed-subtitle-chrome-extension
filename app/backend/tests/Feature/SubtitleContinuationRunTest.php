<?php

namespace Tests\Feature;

use App\Models\CachedVideoTranscript;
use App\Models\SubtitleJob;
use App\Services\Billing\BillingEntitlementService;
use App\Services\Subtitles\SubtitleBatchDispatcher;
use App\Services\Subtitles\SubtitleGenerationPipeline;
use App\Services\Subtitles\SubtitleJobArtifactStore;
use App\Services\Subtitles\SubtitleProviderCostRecorder;
use App\Services\Subtitles\TimestampedSubtitleTrackGenerator;
use App\Services\Transcription\ElevenLabsScribeTranscriptionService;
use App\Services\Transcription\TimestampedTranscript;
use App\Services\Transcription\VideoTranscriptCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SubtitleContinuationRunTest extends TestCase
{
    use RefreshDatabase;

    public function test_cache_continuation_cannot_adopt_a_run_replaced_during_draft_preparation(): void
    {
        $this->assertReplacementSurvivesContinuation(true);
    }

    public function test_merge_continuation_cannot_adopt_a_run_replaced_during_draft_preparation(): void
    {
        $this->assertReplacementSurvivesContinuation(false);
    }

    private function assertReplacementSurvivesContinuation(bool $cached): void
    {
        $job = SubtitleJob::factory()->create([
            'status' => 'running',
            'stage' => $cached ? 'preparing' : 'transcribing',
            'video_duration_seconds' => 60,
        ]);
        $oldRunId = $job->run_id;
        $replacement = (string) Str::uuid();
        $transcript = new TimestampedTranscript('en', 600, [], "WEBVTT\n\n");
        $this->mock(TimestampedSubtitleTrackGenerator::class)->shouldReceive('draftCues')->once()
            ->andReturnUsing(function () use ($job, $replacement): array {
                $job->refresh()->update(['run_id' => $replacement, 'stage' => 'preparing', 'video_duration_seconds' => 123]);

                return [];
            });
        $this->mock(SubtitleBatchDispatcher::class)->shouldNotReceive('dispatchAnalysis');
        $artifacts = $this->mock(SubtitleJobArtifactStore::class);
        $artifacts->shouldNotReceive('putTranscript');
        $artifacts->shouldNotReceive('putCueCollection');

        if ($cached) {
            $cache = $this->mock(VideoTranscriptCache::class);
            $cache->shouldReceive('find')->once()->andReturn(new CachedVideoTranscript(['audio_duration_seconds' => 600]));
            $cache->shouldReceive('transcript')->once()->andReturn($transcript);
            app(SubtitleGenerationPipeline::class)->acquireAudioAndContinue($job->id, $oldRunId);
        } else {
            $artifacts->shouldReceive('transcriptChunks')->once()->andReturn([]);
            $this->mock(ElevenLabsScribeTranscriptionService::class)
                ->shouldReceive('transcriptFromChunkPayloads')->once()->andReturn($transcript);
            app(SubtitleGenerationPipeline::class)->mergeTranscriptAndDispatchAnalysis($job->id, $oldRunId, 0);
        }

        $this->assertSame($replacement, $job->refresh()->run_id);
        $this->assertSame('preparing', $job->stage);
        $this->assertSame(123, $job->video_duration_seconds);
        $this->assertNull($job->detected_source_language);
        $this->assertSame(0, $job->estimated_provider_cost_microusd);
        $this->assertDatabaseCount('billing_usage_events', 0);
    }

    public function test_stale_cost_and_duration_sync_do_not_mutate_or_refresh_to_replacement(): void
    {
        config(['subtitles.costs.elevenlabs_scribe_microusd_per_minute' => 100]);
        $job = SubtitleJob::factory()->create(['status' => 'running']);
        $old = clone $job;
        $job->update(['run_id' => (string) Str::uuid()]);

        app(SubtitleProviderCostRecorder::class)->recordTranscription($old, 600);
        app(BillingEntitlementService::class)->syncJobReservationToActualDuration($old);

        $this->assertNotSame($job->run_id, $old->run_id);
        $this->assertSame(0, $job->refresh()->estimated_provider_cost_microusd);
        $this->assertDatabaseMissing('subtitle_job_events', ['event' => 'provider.cost_estimated']);
    }
}
