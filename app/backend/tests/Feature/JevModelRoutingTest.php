<?php

namespace Tests\Feature;

use App\Exceptions\SubtitleProcessingException;
use App\Models\SubtitleJob;
use App\Services\Subtitles\JevModelRouter;
use App\Services\Subtitles\SubtitleCueBatchProcessor;
use App\Services\Subtitles\SubtitleJobArtifactStore;
use App\Services\Subtitles\SubtitleTier;
use App\Services\TranslationAnalysis\CueEnrichmentResult;
use App\Services\TranslationAnalysis\LaravelAiTranslationAnalysisProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class JevModelRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['typesafe.key' => 'test-only-key', 'ai.providers.cerebras.key' => 'test-spark',
            'ai.providers.openai.models.text.default' => 'saved-transcriber',
            'ai.providers.cerebras.models.text.default' => 'saved-spark']);
    }

    #[TestWith(['spark', 'cerebras', 'saved-spark'])]
    #[TestWith(['transcriber', 'openai', 'saved-transcriber'])]
    public function test_selects_once_before_progressive_analysis_and_reuses_the_saved_model(string $choice, string $provider, string $model): void
    {
        $job = $this->automaticJob();
        $staleSnapshot = clone $job;
        $testTransactionLevel = DB::transactionLevel();
        Http::fake(function ($request) use ($choice, $testTransactionLevel) {
            $this->assertSame($testTransactionLevel, DB::transactionLevel());
            $this->assertSame('eng', $request['state']['detected_source_language']);
            $this->assertStringContainsString('station', $request['state']['transcript_sample']);
            $this->assertSame(['transcriber', 'spark'], array_keys($request['questions']['route']['criteria']));
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer test-only-key'));

            return Http::response($this->answer($choice));
        });
        config(['ai.providers.openai.models.text.default' => 'changed', 'ai.providers.cerebras.models.text.default' => 'changed']);
        $analysis = $this->mock(LaravelAiTranslationAnalysisProvider::class);
        $analysis->shouldReceive('analyzeCueBatch')->once()->withArgs(function (...$arguments) use ($provider, $model): bool {
            $this->assertSame($provider, $arguments[7]->provider);
            $this->assertSame($model, $arguments[7]->model);

            return true;
        })->andReturn(new CueEnrichmentResult($this->cues()));
        app(SubtitleCueBatchProcessor::class)->analyzeCueBatch($job->id, 0, $job->run_id);
        $this->assertSame($provider, app(JevModelRouter::class)->resolve($staleSnapshot)->ai_provider);
        app(SubtitleCueBatchProcessor::class)->analyzeCueBatch($job->id, 0, $job->run_id);
        Http::assertSentCount(1);
        $this->assertSame('transcribing', $job->refresh()->stage);
        $this->assertSame($model, $job->ai_model);
        $this->assertNotNull($job->paid_work_started_at);
        $this->assertSame(1, $job->events()->where('event', 'model.auto_selected')->count());
        $this->assertStringNotContainsString('station', json_encode($job->ai_routing));
        $this->assertStringNotContainsString('test-only-key', $job->events->toJson());
    }

    #[TestWith(['low_confidence'])]
    #[TestWith(['http_error'])]
    #[TestWith(['connection_failed'])]
    #[TestWith(['invalid_response'])]
    #[TestWith(['missing_key'])]
    #[TestWith(['spark_unavailable'])]
    public function test_falls_back_once_without_failing_generation(string $reason): void
    {
        $job = $this->automaticJob();
        $response = $this->answer('spark', $reason === 'low_confidence' ? 0.2 : 0.99);
        if ($reason === 'invalid_response') {
            $response['answers']['route']['probabilities']['spark'] = '0.99';
        }
        if ($reason === 'missing_key') {
            config(['typesafe.key' => '']);
        }
        if ($reason === 'spark_unavailable') {
            config(['ai.providers.cerebras.key' => '']);
        }
        Http::fake(['*' => $reason === 'connection_failed' ? Http::failedConnection() : Http::response($response, $reason === 'http_error' ? 503 : 200)]);
        $selected = app(JevModelRouter::class)->resolve($job);
        $this->assertSame('openai', $selected->ai_provider);
        $this->assertSame($reason, $selected->ai_routing['decision']['reason']);
        $this->assertSame('running', $selected->status);
        app(JevModelRouter::class)->resolve($job);
        Http::assertSentCount(in_array($reason, ['missing_key', 'spark_unavailable'], true) ? 0 : 1);
    }

    #[TestWith(['cancelled'])]
    #[TestWith(['reset'])]
    #[TestWith(['deleted'])]
    public function test_late_response_cannot_change_a_cancelled_deleted_or_new_run(string $change): void
    {
        $job = $this->automaticJob();
        Http::fake(function () use ($job, $change) {
            if ($change === 'deleted') {
                $job->delete();
            } else {
                SubtitleJob::whereKey($job->id)->update($change === 'reset' ? ['run_id' => (string) Str::uuid()] : ['status' => 'cancelled']);
            }

            return Http::response($this->answer());
        });
        $this->assertNull(app(JevModelRouter::class)->resolve($job));
        $this->assertDatabaseMissing('subtitle_job_events', ['event' => 'model.auto_selected']);
        if ($change !== 'deleted') {
            $this->assertSame('auto', $job->refresh()->ai_provider);
        }
    }

    public function test_manual_and_stale_jobs_do_not_call_jev(): void
    {
        Http::fake();
        $manual = SubtitleJob::factory()->create();
        $this->assertSame($manual, app(JevModelRouter::class)->resolve($manual));
        $automatic = $this->automaticJob();
        SubtitleJob::whereKey($automatic->id)->update(['run_id' => (string) Str::uuid()]);
        $this->assertNull(app(JevModelRouter::class)->resolve($automatic));
        Http::assertNothingSent();
    }

    public function test_a_competing_batch_requeues_without_selecting_its_own_fallback(): void
    {
        $job = $this->automaticJob();
        $lock = Cache::store(SubtitleTier::concurrencyCacheStore())->lock('subtitle-model-routing:'.$job->id.':'.$job->run_id, 30);
        $this->assertTrue($lock->get());
        Http::fake();
        try {
            app(JevModelRouter::class)->resolve($job);
            $this->fail('Expected the competing batch to wait for the saved decision.');
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('provider_admission', $exception->context['reason']);
        } finally {
            $lock->release();
        }
        Http::assertNothingSent();
        $this->assertSame('auto', $job->refresh()->ai_provider);
    }

    private function automaticJob(): SubtitleJob
    {
        $job = SubtitleJob::factory()->create([
            'stage' => 'transcribing', 'ai_provider' => 'auto', 'ai_model' => 'pending',
            'ai_selection_key' => 'test-auto', 'ai_routing' => ['configuration' => JevModelRouter::configuration()],
            'source_language' => 'auto', 'detected_source_language' => 'eng', 'target_language' => 'spa',
            'include_translation' => true, 'include_romanization' => false,
        ]);
        app(SubtitleJobArtifactStore::class)->putCueCollection($job, SubtitleJobArtifactStore::DRAFT_CUES, $this->cues());

        return $job;
    }

    private function cues(): array
    {
        return [['cueId' => 'cue-0001', 'index' => 0, 'startMs' => 0, 'endMs' => 3000,
            'sourceText' => 'Meet me at the station.', 'tokens' => [['index' => 0, 'text' => 'station']]]];
    }

    private function answer(string $choice = 'spark', float $confidence = 0.99): array
    {
        return ['model' => 'jev-1.13.0', 'answers' => ['route' => [
            'type' => 'choice', 'choice' => $choice, 'confidence' => $confidence,
            'probabilities' => ['spark' => $choice === 'spark' ? 0.99 : 0.01, 'transcriber' => $choice === 'transcriber' ? 0.99 : 0.01],
        ]], 'usage' => ['input_tokens' => 555, 'output_tokens' => 33]];
    }
}
