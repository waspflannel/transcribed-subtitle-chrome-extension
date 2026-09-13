<?php

namespace Tests\Feature;

use App\Ai\Agents\CueAnalysisAgent;
use App\Exceptions\SubtitleProcessingException;
use App\Jobs\AnalyzeSubtitleCueBatch;
use App\Jobs\FinalizeSubtitleJob;
use App\Jobs\TranscribeSubtitleAudioChunk;
use App\Models\BillingUsageEvent;
use App\Models\CachedVideoTranscript;
use App\Models\SubtitleJob;
use App\Models\SubtitleJobArtifact;
use App\Services\Audio\ScribeAudioChunker;
use App\Services\Audio\SubtitleAudioWorkspace;
use App\Services\Audio\TemporaryAudioFile;
use App\Services\Billing\BillingPlanCatalog;
use App\Services\Billing\UsageLedger;
use App\Services\Subtitles\SubtitleCueBatchProcessor;
use App\Services\Subtitles\SubtitleGenerationPipeline;
use App\Services\Subtitles\SubtitleJobArtifactStore;
use App\Services\Subtitles\SubtitleJobFailureHandler;
use App\Services\Subtitles\SubtitlePartialTrackAssembler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
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

    public function test_pre_extracted_queued_payloads_without_extraction_bounds_still_run(): void
    {
        $queued = new TranscribeSubtitleAudioChunk(1, 0, 1, 'legacy-run', null, 0, 0, null);
        $payload = $queued->__serialize();
        unset($payload['audioEndSeconds']);
        $restored = (new \ReflectionClass(TranscribeSubtitleAudioChunk::class))->newInstanceWithoutConstructor();
        $restored->__unserialize($payload);
        $pipeline = $this->mock(SubtitleGenerationPipeline::class);
        $pipeline->shouldReceive('transcribeAudioChunk')->once()->with(
            1, 'legacy-run', 0, 1, null, 0.0, 0.0, null, $queued->queuedAtMs, null, null,
        );
        $restored->handle($pipeline);
    }

    #[TestWith([0])]
    #[TestWith([20])]
    public function test_opening_cues_are_ready_before_later_audio_is_prepared(int $secondSeconds): void
    {
        config([
            'subtitles.audio_preparation.direct_chunks' => true,
            'subtitles.transcription.chunking.first_seconds' => 15,
            'subtitles.transcription.chunking.second_seconds' => $secondSeconds,
            'subtitles.transcription.chunking.min_audio_seconds' => 45,
            'subtitles.transcription.chunking.target_seconds' => 60,
            'subtitles.enrichment.first_batch_max_cues' => 2,
        ]);
        $job = $this->job();
        $job->update(['stage' => 'optimizing-audio']);
        $directory = SubtitleAudioWorkspace::directory($job->run_id);
        File::ensureDirectoryExists($directory);
        $source = $directory.'/source.m4a';
        File::put($source, 'fake-audio');
        Process::preventStrayProcesses();
        Process::fake(function (PendingProcess $process) {
            $this->assertSame(60, $process->timeout);
            File::put($process->command[array_key_last($process->command)], 'fake-flac');

            return Process::result();
        });
        $pipeline = app(SubtitleGenerationPipeline::class);
        $pipeline->optimizeAudioAndDispatchTranscription($job->id, $job->run_id,
            new TemporaryAudioFile($source, $directory, 60, 10, 'audio/mp4'));
        Process::assertNothingRan();
        $members = [];
        Bus::assertBatched(function ($batch) use (&$members, $secondSeconds): bool {
            $members = $batch->jobs->all();

            return count($members) === ($secondSeconds > 0 ? 3 : 2) && $members[0] instanceof TranscribeSubtitleAudioChunk;
        });
        $this->assertSame(17.0, $members[0]->audioEndSeconds);
        $this->assertSame(13.0, $members[1]->audioStartSeconds);
        $this->transcriptionPayload = ['language_code' => 'spa', 'words' => [
            ['text' => 'First.', 'start' => 0.5, 'end' => 2, 'type' => 'word'],
            ['text' => 'Second.', 'start' => 6, 'end' => 8, 'type' => 'word'],
            ['text' => 'Third.', 'start' => 10, 'end' => 11, 'type' => 'word'],
            ['text' => 'edge', 'start' => 14.5, 'end' => 15, 'type' => 'word'],
        ]];
        $members[0]->handle($pipeline);
        Bus::assertBatched(fn ($batch): bool => $batch->jobs->contains(fn ($member): bool => $member instanceof AnalyzeSubtitleCueBatch));
        app(SubtitleCueBatchProcessor::class)->analyzeCueBatch($job->id, 0, $job->run_id);
        $this->assertGreaterThan(0, $this->preview($job)['readyThroughMs']);
        $this->assertLessThanOrEqual(2, count($this->analysisInputs[0]['cues']));
        $this->assertFileDoesNotExist($directory.'/transcribe-chunk-001.flac');
        $this->assertSame('transcribing', $job->fresh()->stage);
        $this->assertDatabaseCount('subtitle_tracks', 0);
        $this->assertSame(1, $job->events()->where('event', 'audio.chunk_prepared')->count());
        $members[0]->handle($pipeline);
        $pipeline->transcribeAudioChunk($job->id, (string) Str::uuid(), 0, 2,
            $members[0]->chunkAudio, 0, 0, 15, audioEndSeconds: 17);
        Process::assertRanTimes(fn (): bool => true, 1);
        Http::assertSentCount(1);

        // A failed upload retains the shared source for a bounded retry.
        Http::swap(new Factory);
        Http::fake(['*' => Http::response([], 503)]);
        try {
            $members[1]->handle($pipeline);
            $this->fail('Expected a retryable transcription failure.');
        } catch (SubtitleProcessingException $exception) {
            $this->assertTrue($exception->isTransient());
        }
        $this->assertFileExists($source);
        $this->assertSame('running', $job->fresh()->status);
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['language_code' => 'spa', 'words' => [
            ['text' => 'edge', 'start' => 1.5, 'end' => 2, 'type' => 'word'],
            ['text' => 'ends.', 'start' => 2.5, 'end' => 3, 'type' => 'word'],
            ['text' => 'Last.', 'start' => 10, 'end' => 12, 'type' => 'word'],
        ]])]);
        $members[1]->handle($pipeline);
        if ($secondSeconds > 0) {
            $this->assertSame(37.0, $members[1]->audioEndSeconds);
            $this->assertGreaterThan(8_000, $this->preview($job)['cues'][array_key_last($this->preview($job)['cues'])]['endMs']);
            $members[2]->handle($pipeline);
        }
        $pipeline->mergeTranscriptAndDispatchAnalysis($job->id, $job->run_id, (int) (microtime(true) * 1000));
        $this->assertDirectoryDoesNotExist($directory);
        $this->assertSame('tokenizing', $job->fresh()->stage);
        $this->assertGreaterThan(0, $this->preview($job)['readyThroughMs']);
    }

    public function test_failure_during_chunk_preparation_prevents_upload_and_cleans_the_workspace(): void
    {
        $job = $this->job();
        $directory = SubtitleAudioWorkspace::directory($job->run_id);
        File::ensureDirectoryExists($directory);
        $source = $directory.'/source.m4a';
        File::put($source, 'fake-audio');
        $audio = new TemporaryAudioFile($source, $directory, 60, 10, 'audio/mp4');
        $this->partialMock(ScribeAudioChunker::class, function ($mock) use ($job, $audio): void {
            $mock->shouldReceive('extractChunk')->once()->andReturnUsing(function () use ($job, $audio): TemporaryAudioFile {
                app(SubtitleJobFailureHandler::class)->failJob($job->id, 'transcribing',
                    SubtitleProcessingException::transcriptionFailed(), $job->run_id);

                return $audio;
            });
        });
        $member = new TranscribeSubtitleAudioChunk($job->id, 0, 2, $job->run_id,
            $audio, 0, 0, 15,
            nextAudioStartSeconds: 13, audioEndSeconds: 17);
        $member->handle(app(SubtitleGenerationPipeline::class));
        Http::assertNothingSent();
        $this->assertDatabaseCount('subtitle_job_artifacts', 0);
        $this->assertSame('failed', $job->fresh()->status);
        $this->assertDirectoryDoesNotExist($directory);
    }

    public function test_slow_transcription_retries_refresh_the_watchdog_but_abandoned_attempts_still_expire(): void
    {
        $this->travelTo(now()->startOfSecond());
        config([
            'subtitles.stalled_job.enabled' => true,
            'subtitles.stalled_job.stage_timeout_seconds.transcribing' => 1200,
            'subtitles.stalled_job.slack_seconds' => 120,
        ]);
        $job = $this->job();
        $this->transcribe($job, 0);
        $originalStart = now()->copy();
        Http::swap(new Factory);
        Http::fake(['*' => function () use ($job) {
            $this->travel(600)->seconds();
            $this->artisan('subtitles:fail-stalled-jobs')->assertSuccessful();
            $this->assertSame('running', $job->fresh()->status);
            $this->assertDirectoryExists(SubtitleAudioWorkspace::directory($job->run_id));

            return Http::response([], 503);
        }]);

        foreach ([0, 15, 60] as $backoff) {
            $this->travel($backoff)->seconds();
            $attemptStart = now()->copy();
            try {
                $this->transcribe($job, 1);
                $this->fail('Expected a retryable provider failure.');
            } catch (SubtitleProcessingException $exception) {
                $this->assertTrue($exception->isTransient());
            }
            $this->assertTrue($job->fresh()->updated_at->equalTo($attemptStart));
        }
        $this->assertTrue(now()->greaterThan($originalStart->addSeconds(1320)));
        $this->assertTrue(app(SubtitleJobArtifactStore::class)->hasArtifact($job, SubtitleJobArtifactStore::TRANSCRIPT_CHUNK, 0));

        $this->travel(721)->seconds();
        $this->artisan('subtitles:fail-stalled-jobs')->assertSuccessful();
        $this->assertSame('failed', $job->fresh()->status);
        $this->assertDirectoryDoesNotExist(SubtitleAudioWorkspace::directory($job->run_id));
    }

    public function test_transient_chunk_failure_preserves_completed_work_and_retries_only_the_missing_chunk(): void
    {
        $job = $this->job();
        $store = app(SubtitleJobArtifactStore::class);
        $this->transcribe($job, 0);
        Http::swap(new Factory);
        Http::fake(['*' => Http::response([], 503)]);
        try {
            $this->transcribe($job, 1);
            $this->fail('Expected transient error.');
        } catch (SubtitleProcessingException $exception) {
            $this->assertTrue($exception->isTransient());
        }
        $this->assertSame('running', $job->fresh()->status);
        $this->assertTrue($store->hasArtifact($job, SubtitleJobArtifactStore::TRANSCRIPT_CHUNK, 0));
        $this->assertDirectoryExists(SubtitleAudioWorkspace::directory($job->run_id));
        Http::swap(new Factory);
        Http::fake(['*' => fn () => Http::response($this->transcriptionPayload)]);
        $this->transcribe($job, 1);
        $this->assertTrue($store->hasArtifact($job, SubtitleJobArtifactStore::TRANSCRIPT_CHUNK, 1));
        $this->assertSame(2, $job->events()->where('event', 'provider.transcription_chunk_completed')->count());
        $queueJob = new TranscribeSubtitleAudioChunk($job->id, 2, 3, $job->run_id, null, 0, 0, null);
        $this->assertSame(3, $queueJob->maxExceptions);
        $this->assertSame([15, 60], $queueJob->backoff());
        $queueJob->failed(SubtitleProcessingException::providerUnavailable());
        $this->assertSame('failed', $job->fresh()->status);
        $this->assertFalse($store->hasArtifact($job, SubtitleJobArtifactStore::TRANSCRIPT_CHUNK, 0));
        $this->assertDirectoryDoesNotExist(SubtitleAudioWorkspace::directory($job->run_id));
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
        $this->assertSame('eng', $job->fresh()->detected_source_language);
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
        $this->assertSame(3, $job->events()->where('event', 'provider.transcription_chunk_completed')->count());
        $this->assertCount($batchCount, $this->analysisInputs);
        $pipeline->prepareCuesAfterCompletedAnalysisBatches($job->id, $job->run_id);
        $pipeline->prepareCuesAfterCompletedAnalysisBatches($job->id, $job->run_id);
        Bus::assertDispatchedTimes(FinalizeSubtitleJob::class, 1);
        $pipeline->persistGeneratedSubtitleTrack($job->id, $job->run_id);
        $pipeline->persistGeneratedSubtitleTrack($job->id, $job->run_id);
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
        $lastProgress = $job->fresh()->updated_at;
        $this->travel(60)->seconds();
        $this->transcribe($job, 0);
        $this->assertTrue($job->fresh()->updated_at->equalTo($lastProgress));
        $this->assertSame($preview, $this->preview($job));
        Http::assertSentCount(2);
        Bus::assertBatchCount(1);
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_english_intro_does_not_disable_punjabi_translation(bool $translate): void
    {
        $job = $this->job();
        $job->update(['source_language' => 'auto', 'target_language' => 'eng', 'include_translation' => $translate]);
        $this->transcribe($job, 0, ['language_code' => 'eng', 'words' => [
            ['text' => 'Hello.', 'start' => 0.5, 'end' => 1, 'type' => 'word'],
        ]]);
        $this->assertSame('eng', $job->fresh()->detected_source_language);
        app(SubtitleCueBatchProcessor::class)->analyzeCueBatch($job->id, 0, $job->run_id);
        $this->assertSame($translate, $this->analysisInputs[0]['includeTranslation']);
        $this->assertSame('auto', $this->analysisInputs[0]['sourceLanguage']);
        $opening = $this->preview($job)['cues'];

        $this->transcribe($job, 1, ['language_code' => 'pan', 'words' => [
            ['text' => 'ਸਤ ਸ੍ਰੀ ਅਕਾਲ.', 'start' => 2, 'end' => 10, 'type' => 'word'],
        ]]);
        $this->transcribe($job, 2, ['words' => []]);
        $pipeline = app(SubtitleGenerationPipeline::class);
        $pipeline->mergeTranscriptAndDispatchAnalysis($job->id, $job->run_id, (int) (microtime(true) * 1000));
        $this->assertSame('pan', $job->fresh()->detected_source_language);
        $this->assertSame('pan', CachedVideoTranscript::query()->firstOrFail()->payload['language']);
        foreach (app(SubtitleJobArtifactStore::class)->pendingAnalysisIndexes($job) as $index) {
            app(SubtitleCueBatchProcessor::class)->analyzeCueBatch($job->id, $index, $job->run_id);
        }
        $this->assertSame($opening, array_slice($this->preview($job)['cues'], 0, 1));
        $this->assertSame($translate ? 'Meaning 1' : 'ਸਤ ਸ੍ਰੀ ਅਕਾਲ.', $this->preview($job)['cues'][1]['translatedText']);
        foreach ($this->analysisInputs as $input) {
            $this->assertSame('auto', $input['sourceLanguage']);
            $this->assertSame($translate, $input['includeTranslation']);
        }
        Http::assertSentCount(3);
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

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_untimed_chunk_tail_waits_until_its_cue_is_stable(bool $silentTail): void
    {
        $job = $this->job();
        $this->transcribe($job, 0, ['language_code' => 'eng', 'words' => [
            ['text' => 'Earlier.', 'start' => 0.5, 'end' => 1, 'type' => 'word'],
            ['text' => 'Hello.', 'start' => 2, 'end' => 3, 'type' => 'word'],
            ['text' => 'Again.', 'start' => 3, 'end' => 3, 'type' => 'word'],
        ]]);
        $opening = $this->preview($job);
        $this->assertSame(['Earlier.'], array_column($opening['cues'], 'sourceText'));
        app(SubtitleCueBatchProcessor::class)->analyzeCueBatch($job->id, 0, $job->run_id);

        $this->transcribe($job, 1, ['words' => $silentTail ? [] : [
            ['text' => 'There.', 'start' => 2, 'end' => 3, 'type' => 'word'],
        ]]);
        $this->transcribe($job, 2, ['words' => []]);
        $pipeline = app(SubtitleGenerationPipeline::class);
        $pipeline->mergeTranscriptAndDispatchAnalysis($job->id, $job->run_id, (int) (microtime(true) * 1000));
        $this->assertSame('tokenizing', $job->fresh()->stage);
        $store = app(SubtitleJobArtifactStore::class);
        foreach ($store->pendingAnalysisIndexes($job) as $index) {
            app(SubtitleCueBatchProcessor::class)->analyzeCueBatch($job->id, $index, $job->run_id);
        }
        $pipeline->prepareCuesAfterCompletedAnalysisBatches($job->id, $job->run_id);
        $pipeline->persistGeneratedSubtitleTrack($job->id, $job->run_id);
        $this->assertSame('completed', $job->fresh()->status);
        $this->assertSame($silentTail ? ['Earlier.', 'Hello. Again.'] : ['Earlier.', 'Hello.', 'Again. There.'],
            array_column($job->fresh()->track->cues, 'sourceText'));
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
        $lastProgress = $job->fresh()->updated_at;
        $this->travel(60)->seconds();
        app(SubtitleGenerationPipeline::class)->transcribeAudioChunk($job->id, $oldRun, 1, 3, null, 18, 20, 40);
        $this->assertTrue($job->fresh()->updated_at->equalTo($lastProgress));
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
