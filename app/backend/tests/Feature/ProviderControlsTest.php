<?php

namespace Tests\Feature;

use App\Ai\SubtitleModel;
use App\Exceptions\SubtitleProcessingException;
use App\Jobs\AnalyzeSubtitleCueBatch;
use App\Models\SubtitleJob;
use App\Services\InstanceSettings;
use App\Services\Subtitles\ProviderAdmission;
use App\Services\Subtitles\SubtitleCueBatchProcessor;
use App\Services\Subtitles\SubtitleJobArtifactStore;
use App\Services\TranslationAnalysis\LaravelAiTranslationAnalysisProvider;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ProviderControlsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.providers.openai.url' => 'https://api.openai.com/v1', 'ai.providers.openai.key' => 'fake-key']);
        Http::preventStrayRequests();
    }

    #[TestWith([503, null, 'provider_unavailable'])]
    #[TestWith([500, null, 'provider_unavailable'])]
    #[TestWith([429, null, 'rate_limited'])]
    #[TestWith([401, null, 'enrichment_failed'])]
    #[TestWith([400, null, 'enrichment_failed'])]
    #[TestWith([429, 'insufficient_quota', 'enrichment_failed'])]
    #[TestWith([503, 'credit_balance_exhausted', 'enrichment_failed'])]
    public function test_real_sdk_errors_are_classified_before_sensitive_causes_are_discarded(int $status, ?string $code, string $expected): void
    {
        Http::fake(['api.openai.com/v1/responses' => Http::response([
            'error' => ['code' => $code, 'message' => 'PRIVATE_PROVIDER_SENTINEL'],
        ], $status, ['x-request-id' => 'req_safe123'])]);
        try {
            $this->analyze();
            $this->fail('Expected a provider error.');
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame($expected, $exception->publicCode, json_encode($exception->context));
            $this->assertSame($status, $exception->context['status']);
            $this->assertSame('req_safe123', $exception->context['provider_request_id']);
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('PRIVATE_PROVIDER_SENTINEL', (string) $exception);
            $this->assertStringNotContainsString('PRIVATE_PROVIDER_SENTINEL', json_encode($exception->context()));

            $logPath = storage_path('logs/provider-privacy.log');
            Log::build(['driver' => 'single', 'path' => $logPath])->error('safe provider failure', ['exception' => $exception]);
            (new DatabaseUuidFailedJobProvider(app('db'), 'sqlite', 'failed_jobs'))->log(
                'database', 'subtitles', json_encode(['uuid' => (string) Str::uuid()]), $exception,
            );
            $this->assertStringNotContainsString('PRIVATE_PROVIDER_SENTINEL', file_get_contents($logPath));
            $this->assertStringNotContainsString('PRIVATE_PROVIDER_SENTINEL', DB::table('failed_jobs')->value('exception'));
            $this->assertStringContainsString('req_safe123', DB::table('failed_jobs')->value('exception'));
        }
    }

    public function test_connection_failure_is_transient_without_exposing_its_message(): void
    {
        Http::fake(fn () => throw new ConnectionException('PRIVATE_PROVIDER_SENTINEL'));
        try {
            $this->analyze();
            $this->fail('Expected a connection failure.');
        } catch (SubtitleProcessingException $exception) {
            $this->assertTrue($exception->isTransient(), json_encode($exception->context));
            $this->assertSame('connection_failure', $exception->context['reason']);
            $this->assertStringNotContainsString('PRIVATE_PROVIDER_SENTINEL', (string) $exception);
        }
    }

    public function test_real_sdk_503_retries_through_queue_wrapper_and_preserves_work(): void
    {
        Http::fake(['api.openai.com/v1/responses' => Http::sequence()->push(['error' => ['message' => 'busy']], 503)->push($this->response())]);
        $job = SubtitleJob::factory()->create(['stage' => 'tokenizing', 'source_language' => 'eng', 'include_romanization' => false]);
        $artifacts = app(SubtitleJobArtifactStore::class);
        $artifacts->putCueCollection($job, SubtitleJobArtifactStore::DRAFT_CUES, [$this->cue()]);
        $queued = new AnalyzeSubtitleCueBatch($job->id, 0, $job->run_id);
        try {
            $queued->handle(app(SubtitleCueBatchProcessor::class));
            $this->fail('Expected retryable overload.');
        } catch (SubtitleProcessingException $exception) {
            $this->assertTrue($exception->isTransient(), json_encode($exception->context));
        }
        $this->assertSame('running', $job->fresh()->status);
        $this->assertTrue($artifacts->hasArtifact($job, SubtitleJobArtifactStore::DRAFT_CUES));
        try {
            $queued->handle(app(SubtitleCueBatchProcessor::class));
        } catch (SubtitleProcessingException $exception) {
            $this->fail(json_encode($exception->context));
        }
        $this->assertTrue($artifacts->hasArtifact($job, SubtitleJobArtifactStore::ANALYZED_CUES, 0));
        Http::assertSentCount(2);
    }

    public function test_identical_malformed_retry_uses_a_second_actual_request_permit(): void
    {
        config(['subtitles.enrichment.global_rate_limit_per_minute' => 1]);
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->response(['cues' => []]))]);
        try {
            app(LaravelAiTranslationAnalysisProvider::class)->analyzeCueBatch(
                [$this->cue()], [$this->cue()], 'eng', 'eng', false, false, beforeRetry: fn () => true,
            );
            $this->fail('Expected the retry to require a new request permit.');
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('provider_admission', $exception->context['reason']);
        }
        Http::assertSentCount(1);
    }

    public function test_provider_concurrency_releases_permits_even_after_failure(): void
    {
        config(['subtitles.providers.global_concurrency' => 1]);
        $job = SubtitleJob::factory()->create();
        $other = SubtitleJob::factory()->create();
        $gate = app(ProviderAdmission::class);
        $gate->run('openai', $job, function () use ($gate, $other): void {
            foreach ([['openai', $other]] as [$provider, $blocked]) {
                try {
                    $gate->run($provider, $blocked, fn () => $this->fail('Overlapping provider work was admitted.'));
                    $this->fail('Expected capacity rejection.');
                } catch (SubtitleProcessingException $exception) {
                    $this->assertSame('provider_admission', $exception->context['reason']);
                }
            }
        });
        try {
            $gate->run('openai', $job, fn () => throw new \RuntimeException('failure'));
        } catch (\RuntimeException) {
        }
        $this->assertSame('released', $gate->run('openai', $job, fn () => 'released'));
    }

    public function test_local_admission_backpressure_releases_queue_work_without_a_failed_attempt(): void
    {
        $processor = \Mockery::mock(SubtitleCueBatchProcessor::class);
        $processor->shouldReceive('analyzeCueBatch')->once()->andThrow(
            SubtitleProcessingException::rateLimited(context: ['reason' => 'provider_admission', 'retry_after_seconds' => 60]),
        );
        $queueJob = \Mockery::mock(Job::class);
        $queueJob->shouldReceive('payload')->once()->andReturn([]);
        $queueJob->shouldReceive('release')->once()->with(60);
        $queueJob->shouldNotReceive('fail');
        $queued = new AnalyzeSubtitleCueBatch(1, 0, 'test-run');
        $queued->setJob($queueJob);
        $queued->handle($processor);
        $this->assertTrue(true);
    }

    public function test_different_providers_have_independent_capacity_without_an_account(): void
    {
        config(['subtitles.providers.global_concurrency' => 1]);
        $job = SubtitleJob::factory()->create(['status' => 'completed']);
        $gate = app(ProviderAdmission::class);
        $this->assertSame('admitted', $gate->run('openai', $job,
            fn () => $gate->run('cerebras', $job, fn () => 'admitted')));
    }

    public function test_clearing_a_provider_key_prevents_new_work_on_a_saved_track(): void
    {
        $job = SubtitleJob::factory()->create(['status' => 'completed']);
        $gate = app(ProviderAdmission::class);
        $this->assertSame('ready', $gate->run('openai', $job, fn () => 'ready'));
        app(InstanceSettings::class)->update(['providers' => ['openai' => ['apiKey' => null]]]);
        try {
            $gate->run('openai', $job, fn () => $this->fail('Cleared key was reused.'));
            $this->fail('Expected provider setup error.');
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('provider_not_configured', $exception->publicCode);
            $this->assertSame(422, $exception->status);
        }
    }

    public function test_a_cancelled_run_cannot_start_another_provider_request(): void
    {
        $job = SubtitleJob::factory()->create(['status' => 'cancelled']);
        $this->expectException(SubtitleProcessingException::class);
        app(ProviderAdmission::class)->run('openai', $job, fn () => $this->fail('Cancelled work started.'));
    }

    private function analyze(): void
    {
        app(LaravelAiTranslationAnalysisProvider::class)->analyzeCueBatch(
            [$this->cue()], [$this->cue()], 'eng', 'eng', false, false, selection: SubtitleModel::configured('openai'),
        );
    }

    private function cue(): array
    {
        return ['cueId' => 'cue-0001', 'index' => 0, 'startMs' => 0, 'endMs' => 2000, 'sourceText' => 'Hello world'];
    }

    private function response(?array $output = null): array
    {
        return [
            'id' => 'resp_test', 'status' => 'completed', 'model' => 'test-model',
            'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [[
                'type' => 'output_text', 'text' => json_encode($output ?? ['cues' => [[...$this->cue(), 'tokens' => [['index' => 0, 'text' => 'Hello'], ['index' => 1, 'text' => 'world']]]]]),
            ]]]],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ];
    }
}
