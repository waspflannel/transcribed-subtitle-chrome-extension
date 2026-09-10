<?php

namespace Tests\Feature;

use App\Exceptions\SubtitleProcessingException;
use App\Jobs\AcquireSubtitleAudio;
use App\Jobs\AnalyzeSubtitleCueBatch;
use App\Jobs\EnrichSubtitleCueBatch;
use App\Jobs\FinalizeSubtitleJob;
use App\Jobs\MergeSubtitleTranscript;
use App\Jobs\Middleware\LimitSubtitleBatchConcurrency;
use App\Jobs\OptimizeSubtitleAudio;
use App\Jobs\PrepareSubtitleCuesAfterAnalysisBatches;
use App\Jobs\RomanizeSubtitleCueBatch;
use App\Jobs\TokenizeSubtitleCueBatch;
use App\Jobs\TranscribeSubtitleAudioChunk;
use App\Models\SubtitleJob;
use App\Models\SubtitleJobEvent;
use App\Models\SubtitleTrack;
use App\Services\Audio\TemporaryAudioFile;
use App\Services\Subtitles\SubtitleBatchDispatcher;
use App\Services\Subtitles\SubtitleCueBatchProcessor;
use App\Services\Subtitles\SubtitleJobArtifactStore;
use App\Services\Subtitles\SubtitleJobFailureHandler;
use App\Services\Subtitles\SubtitlePipelineTelemetry;
use App\Services\Subtitles\SubtitleQueue;
use App\Services\TranslationAnalysis\CueEnrichmentResult;
use App\Services\TranslationAnalysis\LaravelAiTranslationAnalysisProvider;
use App\Services\TranslationAnalysis\LearningTokenOutputValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
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
            'subtitles.tiers.default' => 'base',
            'subtitles.tiers.concurrency_cache_store' => 'subtitle_concurrency_test',
        ]);
    }

    public function test_queue_hooks_emit_processing_processed_and_stale_run_events(): void
    {
        $job = SubtitleJob::factory()->create([
            'stage' => 'tokenizing',
            'run_id' => (string) Str::uuid(),
        ]);
        $staleRunId = (string) Str::uuid();

        TokenizeSubtitleCueBatch::dispatch($job->id, 0, $staleRunId)
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

    public function test_batch_progress_callbacks_write_real_progress_into_the_job_row(): void
    {
        $job = SubtitleJob::factory()->create([
            'status' => 'running',
            'stage' => 'tokenizing',
            'progress_percent' => 65,
        ]);
        $staleRunId = (string) Str::uuid();

        // Stale-run batch jobs no-op successfully, which still advances the
        // Laravel batch and fires the progress callback with the real run id.
        app(SubtitleBatchDispatcher::class)->dispatchAnalysis($job, [
            new TokenizeSubtitleCueBatch($job->id, 0, $staleRunId),
            new TokenizeSubtitleCueBatch($job->id, 1, $staleRunId),
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

    public function test_analysis_members_over_the_tier_cap_are_windowed_into_chains(): void
    {
        config(['subtitles.tiers.plans.base.batch_concurrency' => 2]);
        $job = SubtitleJob::factory()->create([
            'generation_tier' => 'base',
            'stage' => 'tokenizing',
        ]);
        Bus::fake();

        app(SubtitleBatchDispatcher::class)->dispatchAnalysis($job, [
            new TokenizeSubtitleCueBatch($job->id, 0, $job->run_id),
            [
                new TokenizeSubtitleCueBatch($job->id, 1, $job->run_id),
                new RomanizeSubtitleCueBatch($job->id, 1, $job->run_id),
            ],
            new TokenizeSubtitleCueBatch($job->id, 2, $job->run_id),
        ]);

        Bus::assertBatched(function ($batch): bool {
            $members = $batch->jobs->all();

            if (count($members) !== 2) {
                return false;
            }

            [$firstChain, $secondChain] = $members;

            // Round-robin partition: [T0, T2] and [T1 -> R1] with the
            // existing analyze -> romanize chain flattened in order.
            return is_array($firstChain)
                && array_map('get_class', $firstChain) === [
                    TokenizeSubtitleCueBatch::class,
                    TokenizeSubtitleCueBatch::class,
                ]
                && $firstChain[0]->batchIndex === 0
                && $firstChain[1]->batchIndex === 2
                && is_array($secondChain)
                && array_map('get_class', $secondChain) === [
                    TokenizeSubtitleCueBatch::class,
                    RomanizeSubtitleCueBatch::class,
                ]
                && $secondChain[0]->batchIndex === 1
                && $secondChain[1]->batchIndex === 1;
        });
    }

    public function test_analysis_members_at_or_under_the_tier_cap_dispatch_unchanged(): void
    {
        config(['subtitles.tiers.plans.base.batch_concurrency' => 2]);
        $job = SubtitleJob::factory()->create([
            'generation_tier' => 'base',
            'stage' => 'tokenizing',
        ]);
        Bus::fake();

        app(SubtitleBatchDispatcher::class)->dispatchAnalysis($job, [
            new TokenizeSubtitleCueBatch($job->id, 0, $job->run_id),
            new TokenizeSubtitleCueBatch($job->id, 1, $job->run_id),
        ]);

        Bus::assertBatched(function ($batch): bool {
            $members = $batch->jobs->all();

            return count($members) === 2
                && $members[0] instanceof TokenizeSubtitleCueBatch
                && $members[1] instanceof TokenizeSubtitleCueBatch;
        });
    }

    public function test_pipeline_records_queue_wait_stage_timing_and_slow_warning(): void
    {
        config(['subtitles.tracing.slow_queue_wait_ms' => 1]);
        $job = SubtitleJob::factory()->create(['stage' => 'tokenizing']);
        app(SubtitleJobArtifactStore::class)->putCueCollection($job, SubtitleJobArtifactStore::DRAFT_CUES, [
            $this->sampleCue(),
        ]);

        app(SubtitleCueBatchProcessor::class)->tokenizeCueBatch(
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

    public function test_batch_concurrency_middleware_releases_jobs_over_the_account_tier_limit(): void
    {
        config([
            'subtitles.tiers.plans.base.batch_concurrency' => 1,
            'subtitles.tiers.release_delay_seconds' => 7,
        ]);
        $job = SubtitleJob::factory()->create([
            'generation_tier' => 'base',
            'stage' => 'tokenizing',
        ]);
        $counterKey = 'subtitle-concurrency:ai-batch:'.hash('sha256', (string) $job->user_id).':base';
        Cache::store('subtitle_concurrency_test')->put(
            $counterKey,
            json_encode([['token' => 'occupied', 'expiresAt' => time() + 60]]),
            now()->addMinute(),
        );
        $queuedJob = new class($job->id)
        {
            public bool $released = false;

            public int $releaseDelay = 0;

            public function __construct(public readonly int $subtitleJobId) {}

            public function release(int $delay): void
            {
                $this->released = true;
                $this->releaseDelay = $delay;
            }
        };

        app(LimitSubtitleBatchConcurrency::class)->handle(
            $queuedJob,
            function (): never {
                $this->fail('Expected over-limit job to be released before processing.');
            },
        );

        $this->assertTrue($queuedJob->released);
        // The release site applies ±1s jitter around the configured delay to
        // avoid a thundering herd on the cache lock; the traced value below
        // still records the un-jittered configured base.
        $this->assertGreaterThanOrEqual(6, $queuedJob->releaseDelay);
        $this->assertLessThanOrEqual(8, $queuedJob->releaseDelay);
        $this->assertDatabaseHas('subtitle_job_events', [
            'subtitle_job_id' => $job->id,
            'event' => 'queue.concurrency_delayed',
            'stage' => 'tokenizing',
        ]);
        $event = SubtitleJobEvent::query()
            ->where('subtitle_job_id', $job->id)
            ->where('event', 'queue.concurrency_delayed')
            ->firstOrFail();
        $this->assertSame('limit_reached', $event->context['delay_reason']);
        $this->assertSame('batch', $event->context['queue_family']);
        $this->assertSame('ai_batch', $event->context['limiter_type']);
        $this->assertSame('subtitle_concurrency_test', $event->context['cache_store']);
        $this->assertSame(1, $event->context['concurrency_limit']);
        $this->assertSame(1, $event->context['observed_active_count']);
        $this->assertSame(7, $event->context['release_delay_seconds']);
        $this->assertArrayNotHasKey('user_id', $event->context);
        $this->assertArrayNotHasKey('install_id', $event->context);
    }

    public function test_batch_concurrency_middleware_claims_and_releases_configured_store_slot(): void
    {
        config([
            'subtitles.tiers.plans.base.batch_concurrency' => 1,
        ]);
        $job = SubtitleJob::factory()->create([
            'generation_tier' => 'base',
            'stage' => 'translating',
        ]);
        $counterKey = 'subtitle-concurrency:ai-batch:'.hash('sha256', (string) $job->user_id).':base';
        Cache::put($counterKey, 99, now()->addMinute());
        $queuedJob = new class($job->id)
        {
            public bool $released = false;

            public int $releaseDelay = 0;

            public function __construct(public readonly int $subtitleJobId) {}

            public function release(int $delay): void
            {
                $this->released = true;
                $this->releaseDelay = $delay;
            }
        };
        $processed = false;

        app(LimitSubtitleBatchConcurrency::class)->handle(
            $queuedJob,
            function () use (&$processed): void {
                $processed = true;
            },
        );

        $this->assertTrue($processed);
        $this->assertFalse($queuedJob->released);
        $this->assertSame(99, Cache::get($counterKey));
        $this->assertNull(Cache::store('subtitle_concurrency_test')->get($counterKey));
        $this->assertDatabaseMissing('subtitle_job_events', [
            'subtitle_job_id' => $job->id,
            'event' => 'queue.concurrency_delayed',
        ]);
    }

    public function test_batch_concurrency_middleware_evicts_stale_slot_tokens_on_claim(): void
    {
        config([
            'subtitles.tiers.plans.base.batch_concurrency' => 2,
        ]);
        $job = SubtitleJob::factory()->create([
            'generation_tier' => 'base',
            'stage' => 'tokenizing',
        ]);
        $counterKey = 'subtitle-concurrency:ai-batch:'.hash('sha256', (string) $job->user_id).':base';
        // One live slot plus one stale slot whose deadline has already passed.
        // Without eviction the active count would be 2 (at the limit) and the
        // claim would be rejected; with eviction the stale token is dropped
        // and the new claim succeeds.
        Cache::store('subtitle_concurrency_test')->put(
            $counterKey,
            json_encode([
                ['token' => 'live', 'expiresAt' => time() + 60],
                ['token' => 'stale', 'expiresAt' => time() - 60],
            ]),
            now()->addMinute(),
        );
        $queuedJob = new class($job->id)
        {
            public bool $released = false;

            public function __construct(public readonly int $subtitleJobId) {}

            public function release(int $delay): void
            {
                $this->released = true;
            }
        };
        $processed = false;

        app(LimitSubtitleBatchConcurrency::class)->handle(
            $queuedJob,
            function () use (&$processed): void {
                $processed = true;
            },
        );

        $this->assertTrue($processed);
        $this->assertFalse($queuedJob->released);
        // After the claim/release cycle, the stale token is gone, the live
        // pre-existing slot remains, and the newly claimed token was released
        // by the finally block - leaving exactly one slot.
        $slots = json_decode((string) Cache::store('subtitle_concurrency_test')->get($counterKey), true);
        $tokens = array_column($slots, 'token');
        $this->assertNotContains('stale', $tokens);
        $this->assertContains('live', $tokens);
        $this->assertCount(1, $slots);
    }

    public function test_batch_concurrency_middleware_leaked_slot_frees_capacity_when_deadline_passes(): void
    {
        config([
            'subtitles.tiers.plans.base.batch_concurrency' => 1,
        ]);
        $job = SubtitleJob::factory()->create([
            'generation_tier' => 'base',
            'stage' => 'tokenizing',
        ]);
        $counterKey = 'subtitle-concurrency:ai-batch:'.hash('sha256', (string) $job->user_id).':base';
        // Simulate a worker that was killed mid-batch: its slot was never
        // released, but the absolute deadline has now passed. The next claim
        // must observe the slot as evicted and admit the new job.
        Cache::store('subtitle_concurrency_test')->put(
            $counterKey,
            json_encode([['token' => 'leaked', 'expiresAt' => time() - 1]]),
            now()->addMinute(),
        );
        $queuedJob = new class($job->id)
        {
            public bool $released = false;

            public function __construct(public readonly int $subtitleJobId) {}

            public function release(int $delay): void
            {
                $this->released = true;
            }
        };
        $processed = false;

        app(LimitSubtitleBatchConcurrency::class)->handle(
            $queuedJob,
            function () use (&$processed): void {
                $processed = true;
            },
        );

        $this->assertTrue($processed);
        $this->assertFalse($queuedJob->released);
    }

    public function test_batch_concurrency_middleware_releasing_token_frees_capacity_immediately(): void
    {
        config([
            'subtitles.tiers.plans.base.batch_concurrency' => 1,
        ]);
        $job = SubtitleJob::factory()->create([
            'generation_tier' => 'base',
            'stage' => 'tokenizing',
        ]);
        $queuedJob = new class($job->id)
        {
            public bool $released = false;

            public function __construct(public readonly int $subtitleJobId) {}

            public function release(int $delay): void
            {
                $this->released = true;
            }
        };
        $processedCount = 0;

        app(LimitSubtitleBatchConcurrency::class)->handle(
            $queuedJob,
            function () use (&$processedCount): void {
                $processedCount++;
            },
        );

        $this->assertSame(1, $processedCount);
        $this->assertFalse($queuedJob->released);

        // A second immediate claim must succeed because the first slot was
        // released in the finally block of the first handle() call.
        app(LimitSubtitleBatchConcurrency::class)->handle(
            $queuedJob,
            function () use (&$processedCount): void {
                $processedCount++;
            },
        );

        $this->assertSame(2, $processedCount);
        $this->assertFalse($queuedJob->released);
    }

    public function test_batch_concurrency_middleware_releasing_already_evicted_token_is_safe_noop(): void
    {
        config([
            'subtitles.tiers.plans.base.batch_concurrency' => 1,
        ]);
        $job = SubtitleJob::factory()->create([
            'generation_tier' => 'base',
            'stage' => 'tokenizing',
        ]);
        $counterKey = 'subtitle-concurrency:ai-batch:'.hash('sha256', (string) $job->user_id).':base';
        // A live slot from an unrelated worker, plus a 'ghost' token that was
        // already evicted (e.g. its deadline passed and another claim evicted
        // it). Releasing 'ghost' must not error and must not disturb 'live'.
        Cache::store('subtitle_concurrency_test')->put(
            $counterKey,
            json_encode([['token' => 'live', 'expiresAt' => time() + 60]]),
            now()->addMinute(),
        );

        $middleware = app(LimitSubtitleBatchConcurrency::class);
        $release = new \ReflectionMethod($middleware, 'releaseSlot');
        $release->invoke($middleware, $counterKey, 'ghost');

        $slots = json_decode((string) Cache::store('subtitle_concurrency_test')->get($counterKey), true);
        $this->assertCount(1, $slots);
        $this->assertSame('live', $slots[0]['token']);
    }

    public function test_batch_concurrency_middleware_bypasses_sync_queue_driver(): void
    {
        config([
            'queue.default' => 'sync',
            'subtitles.queue.connection' => 'sync',
            'subtitles.tiers.plans.base.batch_concurrency' => 1,
        ]);
        $job = SubtitleJob::factory()->create([
            'generation_tier' => 'base',
            'stage' => 'tokenizing',
        ]);
        $counterKey = 'subtitle-concurrency:ai-batch:'.hash('sha256', (string) $job->user_id).':base';
        Cache::store('subtitle_concurrency_test')->put($counterKey, 1, now()->addMinute());
        $queuedJob = new class($job->id)
        {
            public bool $released = false;

            public function __construct(public readonly int $subtitleJobId) {}

            public function release(int $delay): void
            {
                $this->released = true;
            }
        };
        $processed = false;

        app(LimitSubtitleBatchConcurrency::class)->handle(
            $queuedJob,
            function () use (&$processed): void {
                $processed = true;
            },
        );

        $this->assertTrue($processed);
        $this->assertFalse($queuedJob->released);
    }

    public function test_concurrency_limited_jobs_allow_release_retries_but_cap_exceptions(): void
    {
        $runId = (string) Str::uuid();
        $stageAudio = new TemporaryAudioFile('unused-path', 'unused-directory', 1, 1, 'audio/flac');
        $serialJobs = [
            new AcquireSubtitleAudio(1, $runId),
            new OptimizeSubtitleAudio(1, $runId, $stageAudio),
            new TranscribeSubtitleAudioChunk(1, 0, 1, $runId, $stageAudio, 0.0, 0.0, null),
            new MergeSubtitleTranscript(1, $runId, 0),
            new PrepareSubtitleCuesAfterAnalysisBatches(1, $runId),
            new FinalizeSubtitleJob(1, false, $runId),
        ];
        $cueBatchJobs = [
            new TokenizeSubtitleCueBatch(1, 0, $runId),
            new AnalyzeSubtitleCueBatch(1, 0, $runId),
            new RomanizeSubtitleCueBatch(1, 0, $runId),
            new EnrichSubtitleCueBatch(1, 0, $runId),
        ];

        foreach ([...$serialJobs, ...$cueBatchJobs] as $job) {
            $this->assertSame(0, $job->tries, get_class($job));
        }

        foreach ($serialJobs as $job) {
            $this->assertSame(1, $job->maxExceptions, get_class($job));
        }

        foreach ($cueBatchJobs as $job) {
            $this->assertSame(3, $job->maxExceptions, get_class($job));
            $this->assertSame([15, 60], $job->backoff(), get_class($job));
        }
    }

    public function test_completed_jobs_record_performance_budget_checks(): void
    {
        config(['subtitles.tiers.plans.base.budgets_seconds.short' => 1]);
        $job = SubtitleJob::factory()->create([
            'generation_tier' => 'base',
            'status' => 'completed',
            'stage' => 'finalizing',
            'progress_percent' => 100,
            'created_at' => now()->subSeconds(5),
            'updated_at' => now()->subSeconds(5),
            'video_duration_seconds' => 120,
        ]);

        app(SubtitlePipelineTelemetry::class)->recordJobCompleted($job);

        $this->assertDatabaseHas('subtitle_job_events', [
            'subtitle_job_id' => $job->id,
            'event' => 'performance.budget_checked',
            'stage' => 'finalizing',
        ]);
        $this->assertDatabaseHas('subtitle_job_events', [
            'subtitle_job_id' => $job->id,
            'event' => 'performance.budget_exceeded',
            'stage' => 'finalizing',
        ]);
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

    public function test_generation_metrics_command_reports_duration_budget_and_cost_groups(): void
    {
        $job = SubtitleJob::factory()->create([
            'generation_tier' => 'plus',
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
        $this->assertSame('plus', $metrics['groups'][0]['tier']);
        $this->assertSame('medium', $metrics['groups'][0]['durationBucket']);
        $this->assertSame(300000, $metrics['groups'][0]['p95DurationMs']);
        $this->assertSame(1200, $metrics['groups'][0]['p95QueueWaitMs']);
        $this->assertSame(50, $metrics['groups'][0]['costPerGeneratedMinuteMicrousd']);
    }

    public function test_generation_metrics_exclude_events_from_previous_runs(): void
    {
        $job = SubtitleJob::factory()->create([
            'status' => 'completed',
            'video_duration_seconds' => 120,
            'estimated_provider_cost_microusd' => 100,
        ]);

        foreach ([(string) Str::uuid() => [300000, 90000], $job->run_id => [20000, 1000]] as $runId => [$duration, $wait]) {
            foreach (['job.completed' => ['duration_ms' => $duration], 'queue.wait_observed' => ['wait_ms' => $wait]] as $event => $timing) {
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
        $this->assertSame(0, $metrics['groups'][0]['budgetExceededCount']);
        $this->assertSame(100, $metrics['summary']['totalCostMicrousd']);
    }

    public function test_chained_job_measures_wait_from_its_own_queue_publication(): void
    {
        $job = SubtitleJob::factory()->create(['stage' => 'tokenizing']);
        app(SubtitleJobArtifactStore::class)->putCueCollection($job, SubtitleJobArtifactStore::DRAFT_CUES, [$this->sampleCue()]);

        // Simulate planning a chain long before the predecessor completes.
        $successor = new TokenizeSubtitleCueBatch($job->id, 0, $job->run_id, $this->currentTimeMs() - 90000);
        Bus::chain([
            new TokenizeSubtitleCueBatch($job->id, 0, (string) Str::uuid()),
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
    public function tokenizeCueBatch(array $batch, array $allCues, string $sourceLanguage, bool $splitInvalidBatches = true, ?\Closure $beforeRetry = null): CueEnrichmentResult
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
        ), 'unknown');
    }
}
