<?php

namespace Tests\Feature;

use App\Exceptions\SubtitleProcessingException;
use App\Jobs\TokenizeSubtitleCueBatch;
use App\Models\SubtitleJob;
use App\Models\SubtitleJobEvent;
use App\Models\SubtitleTrack;
use App\Services\Subtitles\SubtitleGenerationPipeline;
use App\Services\Subtitles\SubtitleJobArtifactStore;
use App\Services\TranslationAnalysis\CueEnrichmentResult;
use App\Services\TranslationAnalysis\LaravelAiTranslationAnalysisProvider;
use App\Services\TranslationAnalysis\LearningTokenOutputValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
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
            'queue.default' => 'database',
            'subtitles.queue.connection' => 'database',
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
            '--queue' => SubtitleGenerationPipeline::queue().',default',
            '--once' => true,
            '--tries' => 1,
            '--sleep' => 0,
        ]);

        $this->assertDatabaseHas('subtitle_job_events', [
            'subtitle_job_id' => $job->id,
            'run_id' => $staleRunId,
            'event' => 'queue.processing',
            'queue_connection' => 'database',
            'queue' => SubtitleGenerationPipeline::queue(),
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
            'queue' => SubtitleGenerationPipeline::queue(),
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

        app(SubtitleGenerationPipeline::class)->tokenizeBatch(
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

        app(SubtitleGenerationPipeline::class)->failJob(
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
