<?php

namespace Tests\Feature;

use App\Ai\SubtitleModel;
use App\Exceptions\SubtitleProcessingException;
use App\Jobs\AcquireSubtitleAudio;
use App\Jobs\AnalyzeSubtitleCueBatch;
use App\Jobs\MergeSubtitleTranscript;
use App\Jobs\OptimizeSubtitleAudio;
use App\Jobs\PrepareSubtitleCuesAfterAnalysisBatches;
use App\Jobs\TranscribeSubtitleAudioChunk;
use App\Models\SubtitleJob;
use App\Models\SubtitleJobEvent;
use App\Models\SubtitleTrack;
use App\Services\Audio\TemporaryAudioFile;
use App\Services\Subtitles\SubtitleBatchDispatcher;
use App\Services\Subtitles\SubtitleCueBatchProcessor;
use App\Services\Subtitles\SubtitleJobArtifactStore;
use App\Services\Subtitles\SubtitleJobFailureHandler;
use App\Services\Subtitles\SubtitleProviderCostRecorder;
use App\Services\Subtitles\SubtitleQueue;
use App\Services\TranslationAnalysis\CueEnrichmentResult;
use App\Services\TranslationAnalysis\LaravelAiTranslationAnalysisProvider;
use App\Services\TranslationAnalysis\LearningTokenOutputValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\TestCase;

class SubtitleRuntimeTracingTest extends TestCase
{
    use RefreshDatabase;

    private TraceRecordingTranslationAnalysisProvider $translationAnalysis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->translationAnalysis = new TraceRecordingTranslationAnalysisProvider;
        $this->app->instance(LaravelAiTranslationAnalysisProvider::class, $this->translationAnalysis);
        config([
            'cache.stores.subtitle_concurrency_test' => [
                'driver' => 'array',
                'serialize' => false,
            ],
            'queue.default' => 'database',
            'subtitles.queue.connection' => 'database',
            'subtitles.providers.concurrency_cache_store' => 'subtitle_concurrency_test',
        ]);
    }

    public function test_cerebras_costs_use_its_model_and_rates(): void
    {
        config([
            'ai.default' => 'cerebras',
            'ai.providers.cerebras.models.text.default' => 'cerebras-analysis',
            'subtitles.costs.cerebras_tokenization_microusd_per_cue' => 3,
            'subtitles.costs.openai_tokenization_microusd_per_cue' => 999,
        ]);
        $job = SubtitleJob::factory()->create(['status' => 'running', 'run_id' => (string) Str::uuid()]);
        app(SubtitleProviderCostRecorder::class)->recordAnalyzedCueBatch($job, 2, false);
        $event = SubtitleJobEvent::where('subtitle_job_id', $job->id)->where('event', 'provider.cost_estimated')->firstOrFail();
        $this->assertSame('cerebras', $event->context['provider']);
        $this->assertSame('cerebras-analysis', $event->context['model']);
        $this->assertSame(6, $event->context['cost_microusd']);
        $this->assertSame(6, $job->fresh()->estimated_provider_cost_microusd);
    }

    public function test_queue_hooks_emit_processing_processed_and_stale_run_events(): void
    {
        $job = SubtitleJob::factory()->create([
            'stage' => 'tokenizing',
            'run_id' => (string) Str::uuid(),
        ]);
        $staleRunId = (string) Str::uuid();

        AnalyzeSubtitleCueBatch::dispatch($job->id, 0, $staleRunId)
            ->onConnection(SubtitleQueue::connection())
            ->onQueue(SubtitleQueue::batchName());

        Artisan::call('queue:work', [
            '--queue' => SubtitleQueue::workerQueueList().',default',
            '--once' => true,
            '--tries' => 1,
            '--sleep' => 0,
        ]);

        $this->assertDatabaseHas('subtitle_job_events', [
            'subtitle_job_id' => $job->id,
            'run_id' => $staleRunId,
            'event' => 'queue.processing',
            'queue_connection' => 'database',
            'queue' => SubtitleQueue::batchName(),
            'batch_index' => 0,
        ]);
        $this->assertDatabaseHas('subtitle_job_events', [
            'subtitle_job_id' => $job->id,
            'event' => 'job.stale_run_skipped',
            'stage' => 'tokenizing',
        ]);
        $this->assertDatabaseHas('subtitle_job_events', [
            'subtitle_job_id' => $job->id,
            'run_id' => $staleRunId,
            'event' => 'queue.processed',
            'queue_connection' => 'database',
            'queue' => SubtitleQueue::batchName(),
        ]);
        $this->assertSame(0, $this->translationAnalysis->tokenizationCalls);
    }

    public function test_queue_hooks_trace_audio_jobs_that_carry_an_audio_file(): void
    {
        $job = SubtitleJob::factory()->create(['stage' => 'optimizing-audio']);
        $staleRunId = (string) Str::uuid();
        $audio = new TemporaryAudioFile('unused-path', 'unused-directory', 1, 1, 'audio/flac');
        OptimizeSubtitleAudio::dispatch($job->id, $staleRunId, $audio)->onQueue(SubtitleQueue::generationName());
        TranscribeSubtitleAudioChunk::dispatch($job->id, 0, 1, $staleRunId, $audio, 0.0, 0.0, null)
            ->onQueue(SubtitleQueue::generationName());

        foreach ([1, 2] as $delivery) {
            Artisan::call('queue:work', [
                '--queue' => SubtitleQueue::workerQueueList(),
                '--once' => true,
                '--tries' => 1,
                '--sleep' => 0,
            ]);
        }

        foreach ([OptimizeSubtitleAudio::class, TranscribeSubtitleAudioChunk::class] as $jobClass) {
            $this->assertSame(1, SubtitleJobEvent::query()->where('subtitle_job_id', $job->id)
                ->where('event', 'queue.processing')->where('context->job_class', $jobClass)->count(), $jobClass);
        }
    }

    public function test_batch_progress_callbacks_write_real_progress_into_the_job_row(): void
    {
        $job = SubtitleJob::factory()->create([
            'status' => 'running',
            'stage' => 'tokenizing',
            'progress_percent' => 65,
        ]);
        $staleRunId = (string) Str::uuid();

        // Progress follows persisted results across all progressive batches.
        // A stale queue member completing must not count as analyzed work.
        config(['subtitles.enrichment.cue_batch_max_cues' => 1]);
        $store = app(SubtitleJobArtifactStore::class);
        $cues = [$this->sampleCue(), [...$this->sampleCue(), 'cueId' => 'cue-0002', 'index' => 1]];
        $store->putCueCollection($job, SubtitleJobArtifactStore::DRAFT_CUES, $cues);
        $store->putCueBatchResult($job, SubtitleJobArtifactStore::ANALYZED_CUES, 0, new CueEnrichmentResult([$cues[0]]));
        app(SubtitleBatchDispatcher::class)->dispatchAnalysis($job, [
            new AnalyzeSubtitleCueBatch($job->id, 0, $staleRunId),
            new AnalyzeSubtitleCueBatch($job->id, 1, $staleRunId),
        ]);

        Artisan::call('queue:work', [
            '--queue' => SubtitleQueue::workerQueueList().',default',
            '--once' => true,
            '--tries' => 1,
            '--sleep' => 0,
        ]);

        // 1 of 2 analysis jobs done -> halfway through the 65-90 band.
        $this->assertSame(77, $job->refresh()->progress_percent);
        $this->assertDatabaseHas('subtitle_job_events', [
            'subtitle_job_id' => $job->id,
            'event' => 'batch.progress',
        ]);
    }

    public function test_transcription_batch_progress_moves_the_job_through_its_progress_band(): void
    {
        $job = SubtitleJob::factory()->create([
            'status' => 'running',
            'stage' => 'transcribing',
            'progress_percent' => 50,
        ]);
        $staleRunId = (string) Str::uuid();
        $chunks = [0, 1];

        app(SubtitleBatchDispatcher::class)->dispatchTranscription($job, array_map(
            fn (int $index): TranscribeSubtitleAudioChunk => new TranscribeSubtitleAudioChunk(
                $job->id,
                $index,
                count($chunks),
                $staleRunId,
                null,
                0.0,
                0.0,
                null,
            ),
            $chunks,
        ), 0);

        Artisan::call('queue:work', [
            '--queue' => SubtitleQueue::workerQueueList().',default',
            '--once' => true,
            '--tries' => 1,
            '--sleep' => 0,
        ]);

        // 1 of 2 transcription jobs done -> halfway through the 50-65 band.
        $this->assertSame(57, $job->refresh()->progress_percent);
    }

    public function test_analysis_members_dispatch_together_without_tier_windows(): void
    {
        $job = SubtitleJob::factory()->create([
            'stage' => 'tokenizing',
        ]);
        Bus::fake();

        app(SubtitleBatchDispatcher::class)->dispatchAnalysis($job, array_map(
            fn (int $index) => new AnalyzeSubtitleCueBatch($job->id, $index, $job->run_id),
            range(0, 23),
        ));

        Bus::assertBatched(fn ($batch): bool => $batch->jobs->count() === 24
            && $batch->jobs->every(fn ($member): bool => $member instanceof AnalyzeSubtitleCueBatch));
    }

    public function test_pipeline_records_queue_wait_stage_timing_and_slow_warning(): void
    {
        config(['subtitles.tracing.slow_queue_wait_ms' => 1]);
        $job = SubtitleJob::factory()->create(['stage' => 'tokenizing']);
        app(SubtitleJobArtifactStore::class)->putCueCollection($job, SubtitleJobArtifactStore::DRAFT_CUES, [
            $this->sampleCue(),
        ]);

        app(SubtitleCueBatchProcessor::class)->analyzeCueBatch(
            $job->id,
            0,
            $job->run_id,
            $this->currentTimeMs() - 1000,
        );

        $this->assertSame(1, $this->translationAnalysis->tokenizationCalls);
        $this->assertDatabaseHas('subtitle_job_events', [
            'subtitle_job_id' => $job->id,
            'event' => 'queue.wait_observed',
            'stage' => 'tokenizing',
            'batch_index' => 0,
        ]);
        $this->assertDatabaseHas('subtitle_job_events', [
            'subtitle_job_id' => $job->id,
            'event' => 'stage.started',
            'stage' => 'tokenizing',
            'batch_index' => 0,
        ]);
        $this->assertDatabaseHas('subtitle_job_events', [
            'subtitle_job_id' => $job->id,
            'event' => 'stage.completed',
            'stage' => 'tokenizing',
            'batch_index' => 0,
        ]);
        $this->assertDatabaseHas('subtitle_job_events', [
            'subtitle_job_id' => $job->id,
            'event' => 'stage.slow',
            'stage' => 'tokenizing',
            'batch_index' => 0,
        ]);
    }

    public function test_concurrency_limited_jobs_allow_release_retries_but_cap_exceptions(): void
    {
        $runId = (string) Str::uuid();
        $stageAudio = new TemporaryAudioFile('unused-path', 'unused-directory', 1, 1, 'audio/flac');
        $serialJobs = [
            new AcquireSubtitleAudio(1, $runId),
            new OptimizeSubtitleAudio(1, $runId, $stageAudio),
            new MergeSubtitleTranscript(1, $runId, 0),
            new PrepareSubtitleCuesAfterAnalysisBatches(1, $runId),
        ];
        $providerJobs = [
            new TranscribeSubtitleAudioChunk(1, 0, 1, $runId, $stageAudio, 0.0, 0.0, null),
            new AnalyzeSubtitleCueBatch(1, 0, $runId),
        ];

        foreach ([...$serialJobs, ...$providerJobs] as $job) {
            $this->assertSame(0, $job->tries, get_class($job));
        }

        foreach ($serialJobs as $job) {
            $this->assertSame(1, $job->maxExceptions, get_class($job));
        }

        foreach ($providerJobs as $job) {
            $this->assertSame(3, $job->maxExceptions, get_class($job));
            $this->assertSame([15, 60], $job->backoff(), get_class($job));
        }
    }

    public function test_failure_trace_records_public_error_and_exception_context(): void
    {
        $job = SubtitleJob::factory()->create(['stage' => 'tokenizing']);
        SubtitleJobEvent::query()->create([
            'subtitle_job_id' => $job->id,
            'public_job_id' => $job->public_id,
            'run_id' => $job->run_id,
            'event' => 'stage.completed',
            'stage' => 'transcribing',
        ]);

        app(SubtitleJobFailureHandler::class)->failJob(
            $job->id,
            'tokenizing',
            SubtitleProcessingException::enrichmentFailed(),
            $job->run_id,
            ['batch_index' => 2],
        );

        $this->assertDatabaseHas('subtitle_job_events', [
            'subtitle_job_id' => $job->id,
            'event' => 'job.failed',
            'stage' => 'tokenizing',
            'status' => 'failed',
            'batch_index' => 2,
            'error_code' => 'enrichment_failed',
            'exception' => SubtitleProcessingException::class,
        ]);
        $event = SubtitleJobEvent::query()->where('event', 'job.failed')->sole();
        $this->assertIsInt($event->worker_pid);
        $this->assertSame('stage.completed', $event->context['last_successful_event']);
        $this->assertSame('transcribing', $event->context['last_successful_stage']);
    }

    public function test_trace_runtime_and_slow_commands_support_json_output(): void
    {
        $job = SubtitleJob::factory()->create(['stage' => 'tokenizing']);
        SubtitleJobEvent::query()->create([
            'subtitle_job_id' => $job->id,
            'public_job_id' => $job->public_id,
            'run_id' => $job->run_id,
            'event' => 'stage.slow',
            'stage' => 'tokenizing',
            'duration_ms' => 500,
            'context' => ['threshold_ms' => 100, 'slow_type' => 'stage_duration'],
        ]);

        $this->assertSame(0, Artisan::call('subtitles:trace', ['jobId' => $job->public_id, '--json' => true]));
        $this->assertStringContainsString('"event": "stage.slow"', Artisan::output());

        $this->assertSame(0, Artisan::call('subtitles:runtime', ['--json' => true]));
        $runtimeOutput = Artisan::output();
        $this->assertStringContainsString('"queueFamilies"', $runtimeOutput);
        $this->assertStringContainsString('"workerGroups"', $runtimeOutput);

        $this->assertSame(0, Artisan::call('subtitles:slow', ['--json' => true]));
        $this->assertStringContainsString('"slowType": "stage_duration"', Artisan::output());
    }

    public function test_generation_metrics_command_reports_duration_and_cost_groups(): void
    {
        $job = SubtitleJob::factory()->create([
            'status' => 'completed',
            'stage' => 'finalizing',
            'progress_percent' => 100,
            'video_duration_seconds' => 600,
            'estimated_provider_cost_microusd' => 500,
        ]);
        SubtitleJobEvent::query()->create([
            'subtitle_job_id' => $job->id,
            'public_job_id' => $job->public_id,
            'run_id' => $job->run_id,
            'event' => 'job.completed',
            'stage' => 'finalizing',
            'status' => 'completed',
            'duration_ms' => 300000,
        ]);
        SubtitleJobEvent::query()->create([
            'subtitle_job_id' => $job->id,
            'public_job_id' => $job->public_id,
            'run_id' => $job->run_id,
            'event' => 'queue.wait_observed',
            'stage' => 'tokenizing',
            'wait_ms' => 1200,
        ]);

        $this->assertSame(0, Artisan::call('subtitles:metrics', ['--json' => true]));
        $metrics = json_decode(Artisan::output(), true);

        $this->assertSame(1, $metrics['summary']['completedJobCount']);
        $this->assertSame('medium', $metrics['groups'][0]['durationBucket']);
        $this->assertSame(300000, $metrics['groups'][0]['p95DurationMs']);
        $this->assertSame(1200, $metrics['groups'][0]['p95QueueWaitMs']);
        $this->assertSame(50, $metrics['groups'][0]['costPerGeneratedMinuteMicrousd']);
        $this->assertNull($metrics['groups'][0]['p50FirstCueMs']);
        $this->assertNull($metrics['groups'][0]['p50FirstAnnotatedCueMs']);
        $this->assertSame(0, $metrics['groups'][0]['firstAnnotatedCueSampleCount']);
    }

    public function test_generation_metrics_exclude_events_from_previous_runs(): void
    {
        $job = SubtitleJob::factory()->create([
            'status' => 'completed',
            'video_duration_seconds' => 120,
            'estimated_provider_cost_microusd' => 100,
        ]);

        foreach ([(string) Str::uuid() => [300000, 90000], $job->run_id => [20000, 1000]] as $runId => [$duration, $wait]) {
            foreach (['job.completed' => ['duration_ms' => $duration], 'queue.wait_observed' => ['wait_ms' => $wait],
                'delivery.first_cue_available' => ['duration_ms' => intdiv($duration, 2)],
                'delivery.first_annotated_cue_available' => ['duration_ms' => intdiv($duration, 2) + 1000],
            ] as $event => $timing) {
                SubtitleJobEvent::query()->create([
                    'subtitle_job_id' => $job->id,
                    'public_job_id' => $job->public_id,
                    'run_id' => $runId,
                    'event' => $event,
                    ...$timing,
                ]);
            }
        }

        $this->assertSame(0, Artisan::call('subtitles:metrics', ['--json' => true]));
        $metrics = json_decode(Artisan::output(), true);
        $this->assertSame(20000, $metrics['groups'][0]['p95DurationMs']);
        $this->assertSame(1000, $metrics['groups'][0]['p95QueueWaitMs']);
        $this->assertSame(100, $metrics['summary']['totalCostMicrousd']);
        $this->assertSame(10000, $metrics['groups'][0]['p50FirstCueMs']);
        $this->assertSame(11000, $metrics['groups'][0]['p95FirstAnnotatedCueMs']);
        $this->assertSame(1, $metrics['groups'][0]['firstAnnotatedCueSampleCount']);
    }

    public function test_metrics_separate_provider_model_and_cached_transcripts(): void
    {
        foreach ([['openai', 'model-a', false], ['openai', 'model-b', false], ['cerebras', 'model-b', false], ['openai', 'model-a', true]] as [$provider, $model, $cached]) {
            $job = SubtitleJob::factory()->create(['status' => 'completed', 'ai_provider' => $provider, 'ai_model' => $model, 'video_duration_seconds' => 120]);
            foreach ($cached ? ['job.completed', 'transcript.cache_hit'] : ['job.completed'] as $event) {
                SubtitleJobEvent::query()->create([
                    'subtitle_job_id' => $job->id, 'public_job_id' => $job->public_id,
                    'run_id' => $job->run_id, 'event' => $event, 'duration_ms' => 20000,
                ]);
            }
        }
        Artisan::call('subtitles:metrics', ['--json' => true]);
        $metrics = json_decode(Artisan::output(), true);
        $this->assertSame(4, $metrics['summary']['completedJobCount']);
        $this->assertCount(4, $metrics['groups']);
        $this->assertSame(1, collect($metrics['groups'])->where('transcriptCacheHit', true)->count());
    }

    public function test_chained_job_measures_wait_from_its_own_queue_publication(): void
    {
        $job = SubtitleJob::factory()->create(['stage' => 'tokenizing']);
        app(SubtitleJobArtifactStore::class)->putCueCollection($job, SubtitleJobArtifactStore::DRAFT_CUES, [$this->sampleCue()]);

        // Simulate planning a chain long before the predecessor completes.
        $successor = new AnalyzeSubtitleCueBatch($job->id, 0, $job->run_id, $this->currentTimeMs() - 90000);
        Bus::chain([
            new AnalyzeSubtitleCueBatch($job->id, 0, (string) Str::uuid()),
            $successor,
        ])->onConnection('database')->onQueue(SubtitleQueue::batchName())->dispatch();

        $this->assertSame(0, Artisan::call('queue:work', [
            '--queue' => SubtitleQueue::batchName(),
            '--stop-when-empty' => true,
            '--sleep' => 0,
        ]));

        $wait = SubtitleJobEvent::query()->where('subtitle_job_id', $job->id)
            ->where('run_id', $job->run_id)->where('event', 'queue.wait_observed')->sole();
        $this->assertLessThan(10000, $wait->wait_ms);
        $this->assertSame(1, $this->translationAnalysis->tokenizationCalls);
    }

    public function test_pruning_expired_jobs_removes_related_trace_events(): void
    {
        $job = SubtitleJob::factory()->create([
            'status' => 'completed',
            'expires_at' => now()->subDay(),
        ]);
        SubtitleTrack::factory()->for($job, 'job')->create([
            'expires_at' => now()->subDay(),
        ]);
        SubtitleJobEvent::query()->create([
            'subtitle_job_id' => $job->id,
            'public_job_id' => $job->public_id,
            'run_id' => $job->run_id,
            'event' => 'job.completed',
        ]);

        $this->artisan('subtitles:prune-expired')
            ->assertSuccessful();

        $this->assertDatabaseMissing('subtitle_job_events', [
            'subtitle_job_id' => $job->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function sampleCue(): array
    {
        return [
            'cueId' => 'cue-0001',
            'index' => 0,
            'startMs' => 0,
            'endMs' => 1000,
            'sourceText' => 'first',
            'translatedText' => 'first',
        ];
    }

    private function currentTimeMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }
}

class TraceRecordingTranslationAnalysisProvider extends LaravelAiTranslationAnalysisProvider
{
    public int $tokenizationCalls = 0;

    public function __construct()
    {
        parent::__construct(new LearningTokenOutputValidator);
    }

    /**
     * @param  array<int, array<string, mixed>>  $batch
     * @param  array<int, array<string, mixed>>  $allCues
     */
    public function analyzeCueBatch(array $batch, array $allCues, string $sourceLanguage, string $targetLanguage, bool $includeTranslation = true, bool $includeRomanization = false, ?\Closure $beforeRetry = null, ?SubtitleModel $selection = null, bool $validateOutput = true, ?SubtitleJob $job = null): CueEnrichmentResult
    {
        $this->tokenizationCalls++;

        return new CueEnrichmentResult(array_map(
            fn (array $cue): array => [
                ...$cue,
                'translatedText' => (string) $cue['sourceText'],
                'tokens' => [
                    ['index' => 0, 'text' => 'first', 'normalizedText' => 'first'],
                ],
            ],
            $batch,
        ));
    }
}
