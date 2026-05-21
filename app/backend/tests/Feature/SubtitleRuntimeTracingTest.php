<?php

namespace Tests\Feature;

use App\Exceptions\SubtitleProcessingException;
use App\Jobs\EnrichSubtitleCueBatch;
use App\Jobs\FinalizeSubtitleJob;
use App\Jobs\MergeSubtitleCuesAfterRomanizationBatches;
use App\Jobs\Middleware\LimitSubtitleInstallConcurrency;
use App\Jobs\PrepareSubtitleCuesAfterAnalysisBatches;
use App\Jobs\ProcessSubtitleJob;
use App\Jobs\RomanizeSubtitleCueBatch;
use App\Jobs\TokenizeSubtitleCueBatch;
use App\Jobs\TranslateSubtitleCueBatch;
use App\Models\SubtitleJob;
use App\Models\SubtitleJobEvent;
use App\Models\SubtitleTrack;
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

        TokenizeSubtitleCueBatch::dispatch($job->id, 0, $staleRunId);

        Artisan::call('queue:work', [
            '--queue' => SubtitleQueue::name().',default',
            '--once' => true,
            '--tries' => 1,
            '--sleep' => 0,
        ]);

        $this->assertDatabaseHas('subtitle_job_events', [
            'subtitle_job_id' => $job->id,
            'run_id' => $staleRunId,
            'event' => 'queue.processing',
            'queue_connection' => 'database',
            'queue' => SubtitleQueue::name(),
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
            'queue' => SubtitleQueue::name(),
        ]);
        $this->assertSame(0, $this->translationAnalysis->tokenizationCalls);
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

    public function test_per_install_concurrency_middleware_releases_jobs_over_the_tier_limit(): void
    {
        config([
            'subtitles.tiers.plans.base.per_install_concurrency' => 1,
            'subtitles.tiers.release_delay_seconds' => 7,
        ]);
        $job = SubtitleJob::factory()->create([
            'generation_tier' => 'base',
            'stage' => 'tokenizing',
        ]);
        $counterKey = 'subtitle-install-concurrency:'.hash('sha256', $job->install_id).':base';
        Cache::store('subtitle_concurrency_test')->put($counterKey, 1, now()->addMinute());
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

        app(LimitSubtitleInstallConcurrency::class)->handle(
            $queuedJob,
            function (): never {
                $this->fail('Expected over-limit job to be released before processing.');
            },
        );

        $this->assertTrue($queuedJob->released);
        $this->assertSame(7, $queuedJob->releaseDelay);
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
        $this->assertSame('subtitle_concurrency_test', $event->context['cache_store']);
        $this->assertSame(1, $event->context['concurrency_limit']);
        $this->assertSame(1, $event->context['observed_active_count']);
        $this->assertSame(7, $event->context['release_delay_seconds']);
    }

    public function test_per_install_concurrency_middleware_claims_and_releases_configured_store_slot(): void
    {
        config([
            'subtitles.tiers.plans.base.per_install_concurrency' => 1,
        ]);
        $job = SubtitleJob::factory()->create([
            'generation_tier' => 'base',
            'stage' => 'translating',
        ]);
        $counterKey = 'subtitle-install-concurrency:'.hash('sha256', $job->install_id).':base';
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

        app(LimitSubtitleInstallConcurrency::class)->handle(
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

    public function test_concurrency_limited_jobs_allow_release_retries_but_cap_exceptions(): void
    {
        $runId = (string) Str::uuid();
        $jobs = [
            new ProcessSubtitleJob(1, $runId),
            new TokenizeSubtitleCueBatch(1, 0, $runId),
            new TranslateSubtitleCueBatch(1, 0, $runId),
            new RomanizeSubtitleCueBatch(1, 0, $runId),
            new PrepareSubtitleCuesAfterAnalysisBatches(1, $runId),
            new MergeSubtitleCuesAfterRomanizationBatches(1, $runId),
            new EnrichSubtitleCueBatch(1, 0, $runId),
            new FinalizeSubtitleJob(1, false, $runId),
        ];

        foreach ($jobs as $job) {
            $this->assertSame(0, $job->tries, get_class($job));
            $this->assertSame(1, $job->maxExceptions, get_class($job));
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
        $this->assertStringContainsString('"summary"', Artisan::output());

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
    public function tokenizeCueBatch(array $batch, array $allCues, string $sourceLanguage): CueEnrichmentResult
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
