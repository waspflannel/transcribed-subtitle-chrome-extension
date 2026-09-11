<?php

namespace Tests\Feature;

use App\Ai\Agents\CueAnalysisAgent;
use App\Exceptions\SubtitleProcessingException;
use App\Jobs\FinalizeSubtitleJob;
use App\Models\BillingUsageEvent;
use App\Models\SubtitleJob;
use App\Models\SubtitleJobArtifact;
use App\Services\Audio\SubtitleAudioWorkspace;
use App\Services\Audio\TemporaryAudioFile;
use App\Services\Billing\BillingPlanCatalog;
use App\Services\Billing\UsageLedger;
use App\Services\Subtitles\SubtitleCueBatchProcessor;
use App\Services\Subtitles\SubtitleGenerationPipeline;
use App\Services\Subtitles\SubtitleJobArtifactStore;
use App\Services\Subtitles\SubtitlePartialTrackAssembler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ProgressiveSubtitlePipelineTest extends TestCase
{
    use RefreshDatabase;

    private array $workspaces = [];

    private array $analysisInputs = [];

    private array $transcriptionPayload = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'subtitles.queue.connection' => 'database',
            'ai.providers.eleven.key' => 'fake-key',
            'ai.providers.eleven.url' => 'https://api.elevenlabs.test/v1',
            'ai.providers.eleven.models.transcription.default' => 'scribe_v2',
            'subtitles.enrichment.first_batch_seconds' => 10,
            'subtitles.enrichment.batch_seconds' => 30,
            'subtitles.enrichment.balanced_batches' => true,
            'subtitles.costs.elevenlabs_scribe_microusd_per_minute' => 1000,
        ]);
        Bus::fake();
        Http::preventStrayRequests();
        Http::fake(['api.elevenlabs.test/*' => fn () => Http::response($this->transcriptionPayload)]);
        CueAnalysisAgent::fake(function (string $prompt): array {
            $input = json_decode($prompt, true, flags: JSON_THROW_ON_ERROR);
            $this->analysisInputs[] = $input;

            return ['dialect' => 'unknown', 'cues' => array_map(fn (array $cue): array => [
                ...$cue, 'translatedText' => 'Meaning '.$cue['index'],
                'tokens' => [['index' => 0, 'text' => $cue['sourceText']]],
            ], $input['cues'])];
        })->preventStrayPrompts();
    }

    protected function tearDown(): void
    {
        foreach ($this->workspaces as $runId) {
            SubtitleAudioWorkspace::delete($runId);
        }
        parent::tearDown();
    }

    public function test_opening_subtitles_are_analyzed_before_later_audio_and_survive_finalization(): void
    {
        $job = $this->job();
        $pipeline = app(SubtitleGenerationPipeline::class);
        $store = app(SubtitleJobArtifactStore::class);
        $this->transcribe($job, 0);
        $preview = $this->preview($job);
        $this->assertCount(2, $preview['cues']);
        $this->assertSame(0, $preview['readyThroughMs']);
        $this->assertSame('transcribing', $job->fresh()->stage);
        $this->assertFalse($store->hasArtifact($job, SubtitleJobArtifactStore::TRANSCRIPT));

        app(SubtitleCueBatchProcessor::class)->analyzeCueBatch($job->id, 0, $job->run_id);
        $opening = $this->preview($job);
        $this->assertSame(8000, $opening['readyThroughMs']);
        $this->assertSame('Meaning 0', $opening['cues'][0]['translatedText']);
        $this->assertSame('spa', $job->fresh()->detected_source_language);
        $pipeline->prepareCuesAfterCompletedAnalysisBatches($job->id, $job->run_id);
        Bus::assertNotDispatched(FinalizeSubtitleJob::class);
        $this->assertDatabaseCount('subtitle_tracks', 0);
        $this->assertSame(0, BillingUsageEvent::where('event_type', 'debit')->count());

        $this->transcribe($job, 1);
        $expanded = $this->preview($job);
        $this->assertGreaterThan($opening['revision'], $expanded['revision']);
        $this->assertSame($opening['cues'], array_slice($expanded['cues'], 0, 2));
        $this->transcribe($job, 2);
        $pipeline->mergeTranscriptAndDispatchAnalysis($job->id, $job->run_id, (int) (microtime(true) * 1000));
        $this->assertSame('tokenizing', $job->fresh()->stage);
        $this->assertSame('spa', $job->fresh()->detected_source_language);
        $pipeline->prepareCuesAfterCompletedAnalysisBatches($job->id, $job->run_id);
        Bus::assertNotDispatched(FinalizeSubtitleJob::class);

        // Finish later batches first. Coverage must stop at the first gap.
        $batchCount = $store->batchCount($job, SubtitleJobArtifactStore::DRAFT_CUES);
        for ($index = $batchCount - 1; $index >= 1; $index--) {
            app(SubtitleCueBatchProcessor::class)->analyzeCueBatch($job->id, $index, $job->run_id);
            if ($index > 1) {
                $this->assertSame(8000, $this->preview($job)['readyThroughMs']);
            }
        }
        $this->assertCount($batchCount, $this->analysisInputs);
        // Replays must not prompt or charge again.
        app(SubtitleCueBatchProcessor::class)->analyzeCueBatch($job->id, 0, $job->run_id);
        $this->transcribe($job, 0);
        Http::assertSentCount(3);
        $this->assertCount($batchCount, $this->analysisInputs);
        $pipeline->prepareCuesAfterCompletedAnalysisBatches($job->id, $job->run_id);
        $pipeline->prepareCuesAfterCompletedAnalysisBatches($job->id, $job->run_id);
        Bus::assertDispatchedTimes(FinalizeSubtitleJob::class, 1);
        $pipeline->persistGeneratedSubtitleTrack($job->id, false, $job->run_id);
        $pipeline->persistGeneratedSubtitleTrack($job->id, false, $job->run_id);
        $job->refresh()->load('track');
        $this->assertSame('completed', $job->status);
        $this->assertSame(1, BillingUsageEvent::where('event_type', 'debit')->count());
        $this->assertSame(0, SubtitleJobArtifact::where('subtitle_job_id', $job->id)->count());
        $this->assertSame($opening['cues'], array_map(
            fn (array $cue): array => Arr::only($cue, array_keys($opening['cues'][0])),
            array_slice($job->track->cues, 0, 2),
        ));
        $this->assertSame(1, $job->events()->where('event', 'delivery.first_cue_available')->count());
        $this->assertSame(1, $job->events()->where('event', 'delivery.first_annotated_cue_available')->count());
    }

    public function test_out_of_order_chunks_wait_for_the_missing_prefix_and_replays_do_not_repeat_uploads(): void
    {
        $job = $this->job();
        $this->transcribe($job, 1);
        $this->assertNull($this->preview($job));
        Bus::assertNothingBatched();
        $this->transcribe($job, 0);
        $preview = $this->preview($job);
        $this->assertGreaterThan(2, count($preview['cues']));
        $this->transcribe($job, 0);
        $this->assertSame($preview, $this->preview($job));
        Http::assertSentCount(2);
        Bus::assertBatchCount(1);
    }

    public function test_silent_opening_chunk_does_not_fail_the_video(): void
    {
        $job = $this->job();
        $this->transcribe($job, 0, ['language_code' => 'spa', 'words' => []]);
        $this->assertNull($this->preview($job));
        $this->transcribe($job, 1);
        $this->assertNotNull($this->preview($job));
        $this->assertSame('running', $job->fresh()->status);
    }

    public function test_silent_tail_finalizes_without_an_empty_analysis_batch(): void
    {
        $job = $this->job();
        $this->transcribe($job, 0, ['language_code' => 'eng', 'words' => [
            ['text' => 'Hello.', 'start' => 0.5, 'end' => 1, 'type' => 'word'],
        ]]);
        app(SubtitleCueBatchProcessor::class)->analyzeCueBatch($job->id, 0, $job->run_id);
        $this->transcribe($job, 1, ['words' => []]);
        $this->transcribe($job, 2, ['words' => []]);
        $pipeline = app(SubtitleGenerationPipeline::class);
        $pipeline->mergeTranscriptAndDispatchAnalysis($job->id, $job->run_id, (int) (microtime(true) * 1000));
        Bus::assertBatchCount(1);
        $pipeline->prepareCuesAfterCompletedAnalysisBatches($job->id, $job->run_id);
        Bus::assertDispatchedTimes(FinalizeSubtitleJob::class, 1);
    }

    public function test_final_merge_requeues_an_early_analysis_dispatch_lost_after_commit(): void
    {
        $job = $this->job();
        $this->transcribe($job, 0);
        Bus::fake(); // Simulate losing queue publication while durable cues survive.
        $this->transcribe($job, 1);
        $this->transcribe($job, 2);
        app(SubtitleGenerationPipeline::class)->mergeTranscriptAndDispatchAnalysis($job->id, $job->run_id, (int) (microtime(true) * 1000));
        Bus::assertBatched(fn ($batch): bool => $batch->jobs->flatten()->contains(
            fn ($member): bool => $member->batchIndex === 0,
        ));
    }

    #[TestWith(['cancelled', false])]
    #[TestWith(['failed', false])]
    #[TestWith(['running', true])]
    public function test_inactive_runs_cannot_upload_or_extend_their_preview(string $status, bool $replaceRun): void
    {
        $job = $this->job();
        $this->transcribe($job, 0);
        $oldRun = $job->run_id;
        app(SubtitleJobArtifactStore::class)->deleteForJob($job);
        $job->update(['status' => $status, 'run_id' => $replaceRun ? (string) Str::uuid() : $oldRun]);
        app(SubtitleGenerationPipeline::class)->transcribeAudioChunk($job->id, $oldRun, 1, 3, null, 18, 20, 40);
        app(SubtitleCueBatchProcessor::class)->analyzeCueBatch($job->id, 0, $oldRun);
        $this->assertNull($this->preview($job));
        $this->assertSame([], $this->analysisInputs);
        Http::assertSentCount(1);
    }

    public function test_published_cues_and_batch_bounds_cannot_be_rewritten(): void
    {
        $job = $this->job();
        $this->transcribe($job, 0);
        $store = app(SubtitleJobArtifactStore::class);
        $cues = $store->cueCollection($job, SubtitleJobArtifactStore::DRAFT_CUES)->cues;
        $cues[0]['sourceText'] = 'A different transcript';
        $this->expectException(SubtitleProcessingException::class);
        $store->appendDraftCues($job, $cues);
    }

    private function job(): SubtitleJob
    {
        $job = SubtitleJob::factory()->create([
            'stage' => 'transcribing', 'progress_percent' => 50, 'video_duration_seconds' => 60,
            'include_translation' => true, 'include_romanization' => false, 'target_language' => 'fra',
        ]);
        $this->withExtensionAuth($job->install_id, $job->user);
        app(UsageLedger::class)->reserveForJob($job, $job->user->refresh(), app(BillingPlanCatalog::class)->requirePlan('base'), 1);
        $this->workspaces[] = $job->run_id;

        return $job;
    }

    private function preview(SubtitleJob $job): ?array
    {
        return app(SubtitlePartialTrackAssembler::class)->assemble($job->fresh());
    }

    private function transcribe(SubtitleJob $job, int $index, ?array $payload = null): void
    {
        $directory = SubtitleAudioWorkspace::directory($job->run_id);
        File::ensureDirectoryExists($directory);
        $path = $directory.'/chunk.flac';
        File::put($path, 'fake-flac');
        $words = [
            [['First.', 0.5, 2], ['Second.', 6, 8], ['Next', 9, 10], ['phrase', 10.2, 11], ['bridge', 18.5, 19.2]],
            [['bridge', 0.5, 1.2], ['continues.', 2.5, 4], ['Middle.', 8, 10], ['unfinished', 16, 17], ['edge', 20.5, 21.2]],
            [['edge', 0.5, 1.2], ['ends.', 2.5, 4], ['Last.', 15, 17]],
        ];
        $this->transcriptionPayload = $payload ?? [
            'language_code' => $index === 0 ? 'spa' : 'eng', 'language_probability' => 0.99,
            'words' => array_map(fn (array $word): array => ['text' => $word[0], 'start' => $word[1], 'end' => $word[2], 'type' => 'word'], $words[$index]),
        ];
        app(SubtitleGenerationPipeline::class)->transcribeAudioChunk(
            $job->id, $job->run_id, $index, 3,
            new TemporaryAudioFile($path, $directory, 24, 9, 'audio/flac'),
            $index === 0 ? 0 : $index * 20 - 2, $index * 20, $index === 2 ? null : ($index + 1) * 20,
            nextAudioStartSeconds: $index === 2 ? null : ($index + 1) * 20 - 2,
        );
    }
}
