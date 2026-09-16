<?php

namespace Tests\Feature;

use App\Exceptions\BillingEntitlementException;
use App\Exceptions\SubtitleProcessingException;
use App\Models\BillingUsageEvent;
use App\Models\SubtitleJob;
use App\Models\SubtitleTrack;
use App\Models\User;
use App\Services\Audio\TemporaryAudioFile;
use App\Services\Billing\BillingPlanCatalog;
use App\Services\Billing\UsageLedger;
use App\Services\Subtitles\ProviderAdmission;
use App\Services\Subtitles\SubtitleCueBatchProcessor;
use App\Services\Subtitles\SubtitleGenerationPipeline;
use App\Services\Subtitles\SubtitleJobFailureHandler;
use App\Services\Subtitles\SubtitleJobService;
use App\Services\Transcription\ElevenLabsScribeTranscriptionService;
use App\Services\Transcription\TimestampedTranscript;
use App\Services\Transcription\TimestampedTranscriptSegment;
use App\Services\Transcription\VideoTranscriptCache;
use App\Services\TranslationAnalysis\LaravelAiTranslationAnalysisProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class PaidGenerationCancellationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        config([
            'ai.providers.eleven.key' => 'fake-key', 'ai.providers.eleven.url' => 'https://api.elevenlabs.test/v1',
            'ai.providers.eleven.models.transcription.default' => 'scribe_v2',
        ]);
    }

    #[TestWith(['scribe_url'])]
    #[TestWith(['scribe_upload'])]
    #[TestWith(['analysis'])]
    public function test_cancellation_before_dispatch_refunds_and_fences_a_stale_worker(string $provider): void
    {
        $job = $this->reservedJob();
        app(SubtitleJobService::class)->cancel($job, $job->user);
        Http::fake();

        try {
            $this->callProvider($provider, $job);
            $this->fail('A cancelled run reached paid work.');
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('generation_cancelled', $exception->publicCode);
        }

        Http::assertNothingSent();
        $this->assertNull($job->fresh()->paid_work_started_at);
        $this->assertSettlement($job, 'refund', 0);
    }

    #[TestWith(['scribe_url'])]
    #[TestWith(['scribe_upload'])]
    #[TestWith(['analysis'])]
    public function test_cancellation_during_paid_request_debits_all_minutes_once(string $provider): void
    {
        $job = $this->reservedJob();
        Http::fake(function () use ($job, $provider) {
            $this->assertNotNull($job->fresh()->paid_work_started_at);
            app(SubtitleJobService::class)->cancel($job, $job->user);

            return Http::response($this->providerResponse($provider));
        });

        $this->callProvider($provider, $job);
        app(SubtitleJobService::class)->cancel($job, $job->user);
        app(SubtitleJobFailureHandler::class)->failJob($job->id, 'transcribing', new \RuntimeException('late failure'), $job->run_id);
        app(UsageLedger::class)->releaseReservation($job, 'reset');

        Http::assertSentCount(1);
        $this->assertSame('Generation was cancelled.', $job->fresh()->error_message);
        $this->assertSettlement($job, 'debit', 4);
    }

    #[TestWith(['queued', 'preparing'])]
    #[TestWith(['running', 'preparing'])]
    #[TestWith(['running', 'acquiring-audio'])]
    #[TestWith(['running', 'optimizing-audio'])]
    #[TestWith(['running', 'transcribing'])]
    public function test_stage_alone_never_charges_cancellation(string $status, string $stage): void
    {
        $job = $this->reservedJob(['status' => $status, 'stage' => $stage]);
        app(SubtitleJobService::class)->cancel($job, $job->user);
        $this->assertSettlement($job, 'refund', 0);
        Http::assertNothingSent();
    }

    public function test_saturated_provider_does_not_mark_or_charge_a_waiting_job(): void
    {
        config(['subtitles.providers.global_concurrency' => 1]);
        $holder = $this->reservedJob();
        $waiting = $this->reservedJob();
        $gate = app(ProviderAdmission::class);
        $gate->run('eleven', $holder, function () use ($waiting): void {
            try {
                $this->callProvider('scribe_url', $waiting);
                $this->fail('Expected provider saturation.');
            } catch (SubtitleProcessingException $exception) {
                $this->assertSame('provider_admission', $exception->context['reason']);
            }
        });
        $this->assertNull($waiting->fresh()->paid_work_started_at);
        app(SubtitleJobService::class)->cancel($waiting, $waiting->user);
        $this->assertSettlement($waiting, 'refund', 0);
        Http::assertNothingSent();
    }

    public function test_cached_transcript_stays_free_until_the_first_analysis_request(): void
    {
        $job = $this->reservedJob(['source_language' => 'eng', 'include_romanization' => false]);
        app(VideoTranscriptCache::class)->store($job->youtube_video_id, 'eng', new TimestampedTranscript(
            'eng', 213, [new TimestampedTranscriptSegment(0, 2, 'Hello world')], 'WEBVTT',
        ), 213);
        app(SubtitleGenerationPipeline::class)->acquireAudioAndContinue($job->id, $job->run_id);
        $this->assertNull($job->fresh()->paid_work_started_at);
        Http::assertNothingSent();
        Http::fake(function ($request) use ($job) {
            $this->assertStringContainsString('/responses', $request->url());
            $this->assertNotNull($job->fresh()->paid_work_started_at);
            app(SubtitleJobService::class)->cancel($job, $job->user);

            return Http::response($this->providerResponse('analysis'));
        });
        app(SubtitleCueBatchProcessor::class)->analyzeCueBatch($job->id, 0, $job->run_id);
        Http::assertSentCount(1);
        $this->assertSettlement($job, 'debit', 4);
    }

    #[TestWith(['failure'])]
    #[TestWith(['cancelled'])]
    public function test_terminal_settlement_is_preserved_when_a_new_run_resets_the_marker(string $reason): void
    {
        $job = $this->reservedJob();
        $oldRun = $job->run_id;
        app(ProviderAdmission::class)->run('openai', $job, fn () => null);
        if ($reason === 'failure') {
            app(SubtitleJobFailureHandler::class)->failJob($job->id, 'tokenizing', SubtitleProcessingException::enrichmentFailed(), $oldRun);
        } else {
            app(SubtitleJobService::class)->cancel($job, $job->user);
        }
        $this->assertSettlement($job, $reason === 'failure' ? 'refund' : 'debit', $reason === 'failure' ? 0 : 4);

        $new = app(SubtitleJobService::class)->generate([
            'youtubeVideoId' => $job->youtube_video_id, 'youtubeUrl' => $job->youtube_url,
            'videoDurationSeconds' => 213, 'sourceLanguage' => $job->source_language, 'targetLanguage' => 'eng',
            'includeTranslation' => false, 'includeRomanization' => false,
        ], $job->user, $job->install_id);
        $this->assertSame($job->id, $new->id);
        $this->assertNotSame($oldRun, $new->run_id);
        $this->assertNull($new->paid_work_started_at);
        try {
            app(ProviderAdmission::class)->run('openai', $job, fn () => $this->fail('Stale run was dispatched.'));
            $this->fail('Expected stale run rejection.');
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('generation_cancelled', $exception->publicCode);
        }
        app(SubtitleJobService::class)->cancel($new, $new->user);
        $this->assertSettlement($new, 'refund', 0);
        $this->assertSettlement($job, $reason === 'failure' ? 'refund' : 'debit', $reason === 'failure' ? 0 : 4);
    }

    public function test_cancel_between_malformed_response_and_retry_blocks_the_second_paid_request(): void
    {
        $job = $this->reservedJob();
        Http::fake(function () use ($job) {
            app(SubtitleJobService::class)->cancel($job, $job->user);
            $response = $this->providerResponse('analysis');
            $response['output'][0]['content'][0]['text'] = json_encode(['cues' => []]);

            return Http::response($response);
        });
        $cue = ['cueId' => 'cue-0001', 'index' => 0, 'startMs' => 0, 'endMs' => 2000, 'sourceText' => 'Hello world'];
        try {
            app(LaravelAiTranslationAnalysisProvider::class)->analyzeCueBatch([$cue], [$cue], 'eng', 'eng', false, false, beforeRetry: fn () => true, job: $job);
            $this->fail('Cancelled malformed-output retry was dispatched.');
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('generation_cancelled', $exception->publicCode);
        }
        Http::assertSentCount(1);
        $this->assertSettlement($job, 'debit', 4);
    }

    public function test_invalid_run_and_previously_settled_run_cannot_start_paid_work(): void
    {
        $job = $this->reservedJob();
        $invalid = clone $job;
        $invalid->run_id = null;
        app(UsageLedger::class)->releaseReservation($job, 'failure');
        foreach ([$invalid, $job, new SubtitleJob(['user_id' => $job->user_id, 'generation_tier' => 'base'])] as $blocked) {
            try {
                app(ProviderAdmission::class)->run('openai', $blocked, fn () => $this->fail('Invalid run reached a provider.'));
                $this->fail('Expected generation admission rejection.');
            } catch (SubtitleProcessingException $exception) {
                $this->assertSame('generation_cancelled', $exception->publicCode);
            }
        }
        $this->assertNull($job->fresh()->paid_work_started_at);
        $this->assertSettlement($job, 'refund', 0);
    }

    public function test_repeated_paid_cancellation_and_deletion_exhaust_the_shared_account_balance(): void
    {
        $job = $this->reservedJob();
        $user = $job->user;
        $ledger = app(UsageLedger::class);
        $summary = $ledger->summary($user, $user->billing_current_period_start, $user->billing_current_period_end);
        $ledger->adjust($user, 8 - $summary['available'] - $summary['reserved'], 'Limit test balance to eight minutes.', 'test');
        $service = app(SubtitleJobService::class);
        $payload = [
            'youtubeVideoId' => 'abusevideo2', 'youtubeUrl' => 'https://www.youtube.com/watch?v=abusevideo2',
            'videoDurationSeconds' => 213, 'sourceLanguage' => 'auto', 'targetLanguage' => 'eng',
            'includeTranslation' => false, 'includeRomanization' => false,
        ];
        foreach ([1, 2] as $run) {
            if ($run === 2) {
                $job = $service->generate($payload, $user, $job->install_id);
            }
            app(ProviderAdmission::class)->run('openai', $job, fn () => null);
            $service->cancel($job, $user);
            $service->delete($job);
            $this->assertModelMissing($job);
            $this->assertSettlement($job, 'debit', 4);
        }
        $this->assertSame(['available' => 0, 'reserved' => 0, 'used' => 8],
            $ledger->summary($user, $user->billing_current_period_start, $user->billing_current_period_end));
        $this->assertSame(2, BillingUsageEvent::where('event_type', 'debit')->whereNull('subtitle_job_id')->count());
        try {
            $service->generate([...$payload, 'youtubeVideoId' => 'abusevideo3', 'youtubeUrl' => 'https://www.youtube.com/watch?v=abusevideo3'], $user, $job->install_id);
            $this->fail('Repeated cancellation restored spent minutes.');
        } catch (BillingEntitlementException $exception) {
            $this->assertSame('usage_exhausted', $exception->publicCode);
        }
        Http::assertNothingSent();
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_deletion_settles_work_and_preserves_ledger_after_job_removal(bool $started): void
    {
        $job = $this->reservedJob();
        if ($started) {
            app(ProviderAdmission::class)->run('openai', $job, fn () => null);
        }
        app(SubtitleJobService::class)->delete($job);
        app(SubtitleJobService::class)->delete($job);
        $this->assertModelMissing($job);
        $this->assertSettlement($job, $started ? 'debit' : 'refund', $started ? 4 : 0);
        $this->assertNull($this->settlement($job)->subtitle_job_id);
        $this->assertSame($job->user_id, $this->settlement($job)->user_id);
    }

    public function test_paid_cancellation_uses_original_period_and_does_not_charge_current_period(): void
    {
        $job = $this->reservedJob();
        $start = $job->user->billing_current_period_start;
        $subscription = $job->user->stripe_subscription_id;
        app(ProviderAdmission::class)->run('openai', $job, fn () => null);
        $job->user->forceFill([
            'billing_current_period_start' => now()->addMonth()->startOfMonth(),
            'billing_current_period_end' => now()->addMonths(2)->startOfMonth(),
            'stripe_subscription_id' => 'sub_new_period',
        ])->save();
        app(SubtitleJobService::class)->cancel($job, $job->user);
        $event = $this->settlement($job);
        $this->assertTrue($event->billing_period_start->equalTo($start));
        $this->assertSame($subscription, $event->stripe_subscription_id);
        $this->assertSettlement($job, 'debit', 4);
    }

    public function test_completed_track_work_does_not_start_generation_or_change_its_settlement(): void
    {
        $job = $this->reservedJob();
        $track = SubtitleTrack::factory()->for($job, 'job')->create();
        app(UsageLedger::class)->debitCompletedJob($job, $track);
        $job->update(['status' => 'completed']);
        app(ProviderAdmission::class)->run('openai', $job, fn () => null);
        $this->assertNull($job->fresh()->paid_work_started_at);
        try {
            app(SubtitleJobService::class)->cancel($job, $job->user);
            $this->fail('Completed job accepted cancellation.');
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('generation_not_cancellable', $exception->publicCode);
        }
        $this->assertSettlement($job, 'debit', 4);
    }

    private function reservedJob(array $attributes = []): SubtitleJob
    {
        $user = User::factory()->create([
            'billing_plan_code' => 'base', 'billing_subscription_status' => 'active',
            'billing_current_period_start' => now()->startOfMonth(),
            'billing_current_period_end' => now()->addMonthNoOverflow()->startOfMonth(),
            'stripe_subscription_id' => 'sub_cancellation_'.bin2hex(random_bytes(8)),
        ]);
        $job = SubtitleJob::factory()->for($user)->create([
            'include_romanization' => false,
            'processing_version' => SubtitleJobService::processingVersionFor(false, false),
            ...$attributes,
        ]);
        $ledger = app(UsageLedger::class);
        $plan = app(BillingPlanCatalog::class)->requirePlan('base');
        $ledger->ensureMonthlyGrant($user, $plan, $user->billing_current_period_start, $user->billing_current_period_end);
        $ledger->reserveForJob($job, $user, $plan, 4);

        return $job->load('user');
    }

    private function callProvider(string $provider, SubtitleJob $job): void
    {
        if ($provider === 'scribe_url') {
            app(ElevenLabsScribeTranscriptionService::class)->transcribeYouTube($job->youtube_video_id, 'eng', $job);
        } elseif ($provider === 'scribe_upload') {
            $path = tempnam(SUBTITLE_TEST_STORAGE, 'audio-');
            file_put_contents($path, 'test-audio');
            try {
                app(ElevenLabsScribeTranscriptionService::class)->transcribeChunk(new TemporaryAudioFile($path, dirname($path), 213, 10, 'audio/mp4'), 'eng', $job);
            } finally {
                unlink($path);
            }
        } else {
            $cue = ['cueId' => 'cue-0001', 'index' => 0, 'startMs' => 0, 'endMs' => 2000, 'sourceText' => 'Hello world'];
            app(LaravelAiTranslationAnalysisProvider::class)->analyzeCueBatch([$cue], [$cue], 'eng', 'eng', false, false, job: $job);
        }
    }

    private function providerResponse(string $provider): array
    {
        if ($provider !== 'analysis') {
            return ['language_code' => 'eng', 'words' => [['text' => 'Hello', 'type' => 'word', 'start' => 0, 'end' => 2]]];
        }

        return [
            'id' => 'resp_test', 'status' => 'completed', 'model' => 'test-model',
            'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [[
                'type' => 'output_text', 'text' => json_encode(['cues' => [[
                    'cueId' => 'cue-0001', 'index' => 0, 'startMs' => 0, 'endMs' => 2000, 'sourceText' => 'Hello world',
                    'tokens' => [['index' => 0, 'text' => 'Hello'], ['index' => 1, 'text' => 'world']],
                ]]]),
            ]]]],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ];
    }

    private function settlement(SubtitleJob $job): BillingUsageEvent
    {
        return BillingUsageEvent::where('idempotency_key', 'settlement:'.$job->id.':'.$job->run_id)->sole();
    }

    private function assertSettlement(SubtitleJob $job, string $type, int $charged): void
    {
        $event = $this->settlement($job);
        $this->assertSame($type, $event->event_type);
        $this->assertSame($charged, $event->used_minutes_delta);
        $this->assertSame(-4, $event->reserved_minutes_delta);
        $this->assertSame(-$charged, $event->available_minutes_delta);
    }
}
