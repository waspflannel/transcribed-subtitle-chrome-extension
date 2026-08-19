<?php

namespace Tests\Feature;

use App\Ai\Agents\LyricsAlignmentAgent;
use App\Ai\Agents\CueAnalysisAgent;
use App\Exceptions\SubtitleProcessingException;
use App\Jobs\LyricsCorrectionJob;
use App\Models\SubtitleJob;
use App\Models\SubtitleTrack;
use App\Models\SubtitleTrackLyricsCorrection;
use App\Models\User;
use App\Services\Subtitles\LyricsCorrectionService;
use App\Services\Subtitles\SubtitleJobArtifactStore;
use App\Services\TranslationAnalysis\LaravelAiTranslationAnalysisProvider;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Illuminate\Testing\TestResponse;
use Laravel\Ai\Exceptions\RateLimitedException;
use RuntimeException;
use Tests\TestCase;

class LyricsCorrectionContinuationTest extends TestCase
{
    use RefreshDatabase;

    private RecordingTranslationAnalysisProvider $translationAnalysis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->translationAnalysis = new RecordingTranslationAnalysisProvider;
        $this->app->instance(LaravelAiTranslationAnalysisProvider::class, $this->translationAnalysis);
        Queue::fake();
        config([
            'queue.default' => 'sync',
            'subtitles.queue.connection' => 'sync',
            'subtitles.tiers.default' => 'base',
            'subtitles.tiers.plans.base.generation_concurrency' => 20,
            'subtitles.tiers.plans.base.batch_concurrency' => 20,
            'subtitles.costs.openai_alignment_microusd_per_call' => 25,
            'subtitles.costs.openai_tokenization_microusd_per_cue' => 10,
            'subtitles.costs.openai_translation_microusd_per_cue' => 5,
            'subtitles.costs.openai_romanization_microusd_per_cue' => 5,
            'subtitles.costs.openai_enrichment_microusd_per_cue' => 5,
        ]);
    }

    public function test_initial_queue_dispatch_failure_fails_the_fresh_attempt_instead_of_stranding_it(): void
    {
        $queue = $this->completedTrackWithCues(2, fn (int $position): string => 'Lyrics line '.($position + 1));
        $this->useThrowingQueue();

        // The testing transactions manager executes after-commit callbacks
        // immediately, which turns a post-commit dispatch failure into a
        // pre-commit one. Swap in the production manager (and end the
        // per-test wrapping transaction) so the dispatch callback resolves at
        // the submit's root commit exactly as it does in production, then
        // restore the testing setup and clean up the committed rows.
        $realTransactions = new DatabaseTransactionsManager;
        $database = app('db');
        $database->connection()->setTransactionManager($realTransactions);
        app()->instance('db.transactions', $realTransactions);
        DB::commit();

        try {
            $this->withExtensionAuth($this->installId(), $queue['job']->user)
                ->postJson('/v1/subtitle-jobs/'.$queue['job']->public_id.'/lyrics', ['lyrics' => implode("\n", $queue['texts'])])
                ->assertStatus(500);

            $stranded = SubtitleTrackLyricsCorrection::query()
                ->where('subtitle_track_id', $queue['job']->track->id)
                ->firstOrFail();
            $this->assertSame('failed', $stranded->status);
            $this->assertSame(0, $stranded->work_revision);
            $this->assertNull($stranded->lyrics);
            $this->assertNull($stranded->work_state);

            Queue::fake();

            $this->withExtensionAuth($this->installId(), $queue['job']->user)
                ->postJson('/v1/subtitle-jobs/'.$queue['job']->public_id.'/lyrics', ['lyrics' => implode("\n", $queue['texts'])])
                ->assertAccepted();
        } finally {
            DB::table('subtitle_track_lyrics_corrections')
                ->where('subtitle_track_id', $queue['job']->track->id)
                ->delete();
            DB::table('subtitle_tracks')->where('id', $queue['job']->track->id)->delete();
            DB::table('subtitle_jobs')->where('id', $queue['job']->id)->delete();
            DB::table('personal_access_tokens')->where('tokenable_id', $queue['job']->user->id)->delete();
            DB::table('billing_usage_events')->where('user_id', $queue['job']->user->id)->delete();
            DB::table('users')->where('id', $queue['job']->user->id)->delete();

            $testingTransactions = new \Illuminate\Foundation\Testing\DatabaseTransactionsManager($this->connectionsToTransact());
            $database->connection()->setTransactionManager($testingTransactions);
            app()->instance('db.transactions', $testingTransactions);
            DB::beginTransaction();
        }
    }

    public function test_a_stale_fail_attempt_revision_cannot_fail_or_clear_newer_private_state(): void
    {
        $queue = $this->completedTrackWithCues(2, fn (int $position): string => 'Lyrics line '.($position + 1));
        $response = $this->submitLyrics($queue['job'], $queue['texts']);
        $correction = $this->correctionRow($queue['job'], $response->json('attemptId'));
        $correction->update([
            'status' => 'running',
            'work_revision' => 4,
            'work_state' => ['stage' => 'tokenizing'],
        ]);

        $failed = app(LyricsCorrectionService::class)->failAttempt(
            $queue['job']->track->id,
            $correction->attempt_id,
            'lyrics_correction_failed',
            'stale failure',
            expectedRevision: 3,
        );

        $this->assertFalse($failed);
        $current = $correction->fresh();
        $this->assertSame('running', $current->status);
        $this->assertSame(4, $current->work_revision);
        $this->assertSame("Lyrics line 1\nLyrics line 2", $current->lyrics);
        $this->assertSame('tokenizing', $current->work_state['stage']);
    }

    public function test_a_stale_job_failure_callback_cannot_fail_the_current_revision(): void
    {
        $queue = $this->completedTrackWithCues(2, fn (int $position): string => 'Lyrics line '.($position + 1));
        $response = $this->submitLyrics($queue['job'], $queue['texts']);
        $correction = $this->correctionRow($queue['job'], $response->json('attemptId'));
        $correction->update([
            'status' => 'running',
            'work_revision' => 2,
            'work_state' => ['stage' => 'tokenizing'],
        ]);

        (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $correction->attempt_id, 1))
            ->failed(new RuntimeException('stale worker failure'));

        $current = $correction->fresh();
        $this->assertSame('running', $current->status);
        $this->assertSame(2, $current->work_revision);
        $this->assertNotNull($current->lyrics);
        $this->assertNotNull($current->work_state);
    }

    public function test_continuation_queue_dispatch_failure_fails_the_new_persisted_revision(): void
    {
        $queue = $this->completedTrackWithCues(2, fn (int $position): string => 'Lyrics line '.($position + 1));
        LyricsAlignmentAgent::fake([['isMatch' => true, 'cues' => $queue['alignmentCues']]]);
        $response = $this->submitLyrics($queue['job'], $queue['texts']);
        $this->useThrowingQueue();

        try {
            (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $response->json('attemptId'), 0))
                ->handle(app(LyricsCorrectionService::class));
            $this->fail('Expected the continuation dispatch to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Redis queue unavailable.', $exception->getMessage());
        }

        $correction = $this->correctionRow($queue['job'], $response->json('attemptId'));
        $this->assertSame('failed', $correction->status);
        $this->assertSame(1, $correction->work_revision);
        $this->assertNull($correction->lyrics);
        $this->assertNull($correction->work_state);
    }

    public function test_the_job_fails_on_timeout_and_keeps_its_revision_when_serialized(): void
    {
        $job = new LyricsCorrectionJob(1, 1, (string) Str::uuid(), 4);
        $restored = unserialize(serialize($job));

        $this->assertTrue($restored->failOnTimeout);
        $this->assertSame(4, $restored->expectedRevision);
        $this->assertSame(300, $restored->timeout);
    }

    public function test_entitlement_loss_between_alignment_retries_prevents_the_second_prompt(): void
    {
        $queue = $this->completedTrackWithCues(2, fn (int $position): string => 'Lyrics line '.($position + 1));
        $calls = 0;
        LyricsAlignmentAgent::fake(function () use (&$calls, $queue): array {
            $calls++;
            $queue['job']->user->forceFill(['billing_subscription_status' => 'past_due'])->save();

            return ['isMatch' => true, 'cues' => [
                ['cueId' => 'cue-0001', 'index' => 0, 'sourceText' => 'Invented text'],
                ['cueId' => 'cue-0002', 'index' => 1, 'sourceText' => 'Lyrics line 2'],
            ]];
        })->preventStrayPrompts();
        $response = $this->submitLyrics($queue['job'], $queue['texts']);

        (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $response->json('attemptId'), 0))->handle(app(LyricsCorrectionService::class));

        $this->assertSame(1, $calls, 'The second alignment prompt must not run without an active plan.');
        $row = $this->correctionRow($queue['job'], $response->json('attemptId'));
        $this->assertSame('failed', $row->status);
        $this->assertNull($row->lyrics);
        $this->assertNull($row->work_state);
    }

    public function test_each_returned_alignment_response_records_one_call_including_an_invalid_retry(): void
    {
        $queue = $this->completedTrackWithCues(2, fn (int $position): string => 'Lyrics line '.($position + 1));
        LyricsAlignmentAgent::fake([
            [
                'isMatch' => true,
                'cues' => [
                    ['cueId' => 'cue-0001', 'index' => 0, 'sourceText' => 'Invented text'],
                    ['cueId' => 'cue-0002', 'index' => 1, 'sourceText' => 'Lyrics line 2'],
                ],
            ],
            ['isMatch' => true, 'cues' => $queue['alignmentCues']],
        ])->preventStrayPrompts();
        $response = $this->submitLyrics($queue['job'], $queue['texts']);

        (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $response->json('attemptId'), 0))
            ->handle(app(LyricsCorrectionService::class));

        $this->assertSame(50, $queue['job']->refresh()->estimated_provider_cost_microusd);
        $this->assertSame('tokenizing', $this->correctionRow($queue['job'], $response->json('attemptId'))->work_state['stage']);
    }

    public function test_a_completed_track_with_more_than_22_cues_is_accepted_and_completes(): void
    {
        $queue = $this->completedTrackWithCues(30, fn (int $position): string => 'Lyrics line '.($position + 1));
        LyricsAlignmentAgent::fake([['isMatch' => true, 'cues' => $queue['alignmentCues']]]);

        $response = $this->submitLyrics($queue['job'], $queue['texts']);

        $response->assertAccepted()->assertJsonPath('status', 'queued');

        $this->runCorrectionRevisions($queue['job'], $response->json('attemptId'));

        $row = $this->correctionRow($queue['job'], $response->json('attemptId'));
        $this->assertSame('completed', $row->status);
        $this->assertCount(30, $row->track->cues);
        $this->assertSame('Lyrics line 1', $row->track->cues[0]['sourceText']);
    }

    public function test_alignment_may_leave_an_unused_timing_slot_when_all_lyrics_are_consumed(): void
    {
        $queue = $this->completedTrackWithCues(3, fn (int $position): string => 'Transcript line '.($position + 1));
        LyricsAlignmentAgent::fake([['isMatch' => true, 'cues' => [
            ['cueId' => 'cue-0001', 'index' => 0, 'sourceText' => 'First lyric line'],
            ['cueId' => 'cue-0003', 'index' => 2, 'sourceText' => 'Second lyric line'],
        ]]])->preventStrayPrompts();

        $response = $this->submitLyrics($queue['job'], ['First lyric line', 'Second lyric line']);
        $this->runCorrectionRevisions($queue['job'], $response->json('attemptId'));

        $row = $this->correctionRow($queue['job'], $response->json('attemptId'));
        $this->assertSame('completed', $row->status);
        $this->assertCount(2, $row->track->cues);
        $this->assertSame(0, $row->track->cues[0]['startMs']);
        $this->assertSame(8000, $row->track->cues[1]['startMs']);
        $this->assertSame(['First lyric line', 'Second lyric line'], array_column($row->track->cues, 'sourceText'));
    }

    public function test_incomplete_alignment_fails_before_derived_provider_work(): void
    {
        $queue = $this->completedTrackWithCues(10, fn (int $position): string => 'Lyrics line '.($position + 1));
        LyricsAlignmentAgent::fake([['isMatch' => true, 'cues' => array_slice($queue['alignmentCues'], 0, 5)]])->preventStrayPrompts();
        $response = $this->submitLyrics($queue['job'], array_slice($queue['texts'], 0, 5));

        (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $response->json('attemptId'), 0))
            ->handle(app(LyricsCorrectionService::class));

        $row = $this->correctionRow($queue['job'], $response->json('attemptId'));
        $this->assertSame('failed', $row->status);
        $this->assertSame('lyrics_incomplete', $row->error_code);
        $this->assertSame(0, $this->translationAnalysis->tokenizationCalls);
        $this->assertSame('Lyrics line 1', $row->track->cues[0]['sourceText']);
    }

    public function test_completeness_gate_accepts_exact_slot_threshold_when_the_full_timeline_is_spanned(): void
    {
        $queue = $this->completedTrackWithCues(10, fn (int $position): string => 'Lyrics line '.($position + 1));
        $positions = [0, 1, 2, 3, 4, 9];
        LyricsAlignmentAgent::fake([['isMatch' => true, 'cues' => array_map(
            fn (int $position): array => $queue['alignmentCues'][$position],
            $positions,
        )]])->preventStrayPrompts();
        $response = $this->submitLyrics($queue['job'], array_map(
            fn (int $position): string => $queue['texts'][$position],
            $positions,
        ));

        (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $response->json('attemptId'), 0))
            ->handle(app(LyricsCorrectionService::class));

        $row = $this->correctionRow($queue['job'], $response->json('attemptId'));
        $this->assertSame('tokenizing', $row->work_state['stage']);
    }

    public function test_64_of_69_alignment_passes_the_provisional_gate(): void
    {
        $queue = $this->completedTrackWithCues(69, fn (int $position): string => 'Lyrics line '.($position + 1));
        $positions = [...range(0, 62), 68];
        LyricsAlignmentAgent::fake([['isMatch' => true, 'cues' => array_map(
            fn (int $position): array => $queue['alignmentCues'][$position],
            $positions,
        )]])->preventStrayPrompts();
        $response = $this->submitLyrics($queue['job'], array_map(
            fn (int $position): string => $queue['texts'][$position],
            $positions,
        ));

        (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $response->json('attemptId'), 0))
            ->handle(app(LyricsCorrectionService::class));

        $this->assertSame('tokenizing', $this->correctionRow($queue['job'], $response->json('attemptId'))->work_state['stage']);
    }

    public function test_exact_80_percent_timeline_coverage_passes(): void
    {
        $queue = $this->completedTrackWithCues(10, fn (int $position): string => 'Lyrics line '.($position + 1));
        $positions = range(0, 7);
        LyricsAlignmentAgent::fake([['isMatch' => true, 'cues' => array_map(
            fn (int $position): array => $queue['alignmentCues'][$position],
            $positions,
        )]])->preventStrayPrompts();
        $response = $this->submitLyrics($queue['job'], array_map(
            fn (int $position): string => $queue['texts'][$position],
            $positions,
        ));

        (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $response->json('attemptId'), 0))
            ->handle(app(LyricsCorrectionService::class));

        $this->assertSame('tokenizing', $this->correctionRow($queue['job'], $response->json('attemptId'))->work_state['stage']);
    }

    public function test_every_other_line_paste_is_rejected_as_incomplete(): void
    {
        $queue = $this->completedTrackWithCues(69, fn (int $position): string => 'Lyrics line '.($position + 1));
        $positions = range(0, 68, 2);
        LyricsAlignmentAgent::fake([['isMatch' => true, 'cues' => array_map(
            fn (int $position): array => $queue['alignmentCues'][$position],
            $positions,
        )]])->preventStrayPrompts();
        $response = $this->submitLyrics($queue['job'], array_map(
            fn (int $position): string => $queue['texts'][$position],
            $positions,
        ));

        (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $response->json('attemptId'), 0))
            ->handle(app(LyricsCorrectionService::class));

        $this->assertSame('lyrics_incomplete', $this->correctionRow($queue['job'], $response->json('attemptId'))->error_code);
    }

    public function test_timeline_coverage_below_80_percent_fails_even_when_slot_coverage_passes(): void
    {
        $queue = $this->completedTrackWithCues(20, fn (int $position): string => 'Lyrics line '.($position + 1));
        $positions = range(0, 11);
        LyricsAlignmentAgent::fake([['isMatch' => true, 'cues' => array_map(
            fn (int $position): array => $queue['alignmentCues'][$position],
            $positions,
        )]])->preventStrayPrompts();
        $response = $this->submitLyrics($queue['job'], array_map(
            fn (int $position): string => $queue['texts'][$position],
            $positions,
        ));

        (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $response->json('attemptId'), 0))
            ->handle(app(LyricsCorrectionService::class));

        $this->assertSame('lyrics_incomplete', $this->correctionRow($queue['job'], $response->json('attemptId'))->error_code);
    }

    public function test_cancellation_is_revision_safe_and_idempotent(): void
    {
        $queue = $this->completedTrackWithCues(2, fn (int $position): string => 'Lyrics line '.($position + 1));
        $response = $this->submitLyrics($queue['job'], $queue['texts']);
        $correction = $this->correctionRow($queue['job'], $response->json('attemptId'));
        $trackId = $correction->track->public_id;

        $cancelled = app(LyricsCorrectionService::class)->cancel($queue['job'], $queue['job']->user, $correction->attempt_id);

        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame(1, $cancelled->work_revision);
        $this->assertNull($cancelled->lyrics);
        $this->assertNull($cancelled->work_state);

        (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $correction->attempt_id, 0))
            ->handle(app(LyricsCorrectionService::class));

        $again = app(LyricsCorrectionService::class)->cancel($queue['job'], $queue['job']->user, $correction->attempt_id);
        $this->assertSame('cancelled', $again->status);
        $this->assertSame($trackId, $again->track->public_id);
        $this->assertSame(0, $this->translationAnalysis->tokenizationCalls);
    }

    public function test_cancellation_with_an_old_attempt_id_cannot_cancel_the_new_attempt(): void
    {
        $queue = $this->completedTrackWithCues(2, fn (int $position): string => 'Lyrics line '.($position + 1));
        $response = $this->submitLyrics($queue['job'], $queue['texts']);
        $correction = $this->correctionRow($queue['job'], $response->json('attemptId'));
        $newAttemptId = (string) Str::uuid();
        $correction->update(['attempt_id' => $newAttemptId]);

        $this->expectException(SubtitleProcessingException::class);
        $this->expectExceptionMessage('A pasted-lyrics correction is already in progress.');

        app(LyricsCorrectionService::class)->cancel($queue['job'], $queue['job']->user, $response->json('attemptId'));
    }

    public function test_cancellation_stops_a_derived_provider_retry_after_the_first_invalid_response(): void
    {
        $queue = $this->completedTrackWithCues(20, fn (int $position): string => 'Lyrics line '.($position + 1), ['include_translation' => true]);
        LyricsAlignmentAgent::fake([['isMatch' => true, 'cues' => $queue['alignmentCues']]]);
        $this->translationAnalysis->invalidBatchAboveCueCount = 10;
        $response = $this->submitLyrics($queue['job'], $queue['texts']);
        $correction = $this->correctionRow($queue['job'], $response->json('attemptId'));
        $this->translationAnalysis->beforeRetry = function () use ($correction): void {
            $correction->forceFill(['status' => 'cancelled'])->save();
        };

        (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $correction->attempt_id, 0))
            ->handle(app(LyricsCorrectionService::class));
        (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $correction->attempt_id, 1))
            ->handle(app(LyricsCorrectionService::class));

        $this->assertSame('cancelled', $correction->fresh()->status);
        $this->assertSame(1, $this->translationAnalysis->translationCalls);
    }

    public function test_production_single_cue_reprompt_stops_after_cancellation(): void
    {
        $queue = $this->completedTrackWithCues(1, fn (int $position): string => 'Lyrics line '.($position + 1), ['include_translation' => true]);
        LyricsAlignmentAgent::fake([['isMatch' => true, 'cues' => $queue['alignmentCues']]]);
        $response = $this->submitLyrics($queue['job'], $queue['texts']);
        $correction = $this->correctionRow($queue['job'], $response->json('attemptId'));
        $prompts = 0;

        CueAnalysisAgent::fake(function () use (&$prompts, $correction): array {
            $prompts++;
            $correction->forceFill(['status' => 'cancelled'])->save();

            return ['dialect' => 'unknown', 'cues' => []];
        })->preventStrayPrompts();

        (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $correction->attempt_id, 0))->handle(app(LyricsCorrectionService::class));
        (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $correction->attempt_id, 1))->handle(app(LyricsCorrectionService::class));

        $this->assertSame(1, $prompts);
        $this->assertSame('cancelled', $correction->fresh()->status);
    }

    public function test_valid_derived_response_after_cancellation_does_not_commit_or_dispatch(): void
    {
        $queue = $this->completedTrackWithCues(2, fn (int $position): string => 'Lyrics line '.($position + 1));
        LyricsAlignmentAgent::fake([['isMatch' => true, 'cues' => $queue['alignmentCues']]]);
        $response = $this->submitLyrics($queue['job'], $queue['texts']);
        $correction = $this->correctionRow($queue['job'], $response->json('attemptId'));
        $this->translationAnalysis->beforeTokenizationResult = function () use ($correction): void {
            $correction->forceFill(['status' => 'cancelled'])->save();
        };

        (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $correction->attempt_id, 0))->handle(app(LyricsCorrectionService::class));
        (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $correction->attempt_id, 1))->handle(app(LyricsCorrectionService::class));

        $this->assertSame('cancelled', $correction->fresh()->status);
        $this->assertSame('aligning', $correction->fresh()->work_state['stage'] ?? 'aligning');
        $this->assertSame(1, $this->translationAnalysis->tokenizationCalls);
    }

    public function test_cancellation_of_a_running_nonzero_revision_survives_late_failure_callback(): void
    {
        $queue = $this->completedTrackWithCues(2, fn (int $position): string => 'Lyrics line '.($position + 1));
        $response = $this->submitLyrics($queue['job'], $queue['texts']);
        $correction = $this->correctionRow($queue['job'], $response->json('attemptId'));
        $correction->update([
            'status' => 'running',
            'work_revision' => 4,
            'work_state' => ['stage' => 'tokenizing'],
        ]);

        $cancelled = app(LyricsCorrectionService::class)->cancel($queue['job'], $queue['job']->user, $correction->attempt_id);
        (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $correction->attempt_id, 4))
            ->failed(new RuntimeException('late provider failure'));

        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame('cancelled', $correction->fresh()->status);
        $this->assertNull($correction->fresh()->lyrics);
        $this->assertNull($correction->fresh()->work_state);
    }

    public function test_cancellation_is_idempotent_for_completed_and_failed_attempts(): void
    {
        foreach (['completed', 'failed'] as $terminalStatus) {
            $queue = $this->completedTrackWithCues(2, fn (int $position): string => 'Lyrics line '.($position + 1));
            $response = $this->submitLyrics($queue['job'], $queue['texts']);
            $correction = $this->correctionRow($queue['job'], $response->json('attemptId'));
            $correction->update([
                'status' => $terminalStatus,
                'lyrics' => null,
                'work_state' => null,
                'error_code' => $terminalStatus === 'failed' ? 'lyrics_correction_failed' : null,
                'error_message' => $terminalStatus === 'failed' ? 'failed' : null,
            ]);

            $result = app(LyricsCorrectionService::class)->cancel($queue['job'], $queue['job']->user, $correction->attempt_id);

            $this->assertSame($terminalStatus, $result->status);
            $this->assertSame($terminalStatus, $correction->fresh()->status);
        }
    }

    public function test_work_is_divided_by_the_real_shared_batch_plan(): void
    {
        $queue = $this->completedTrackWithCues(45, fn (int $position): string => 'Lyrics line '.($position + 1));
        LyricsAlignmentAgent::fake([['isMatch' => true, 'cues' => $queue['alignmentCues']]]);
        $response = $this->submitLyrics($queue['job'], $queue['texts']);
        $service = app(LyricsCorrectionService::class);

        (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $response->json('attemptId'), 0))->handle($service);

        $row = $this->correctionRow($queue['job'], $response->json('attemptId'));
        $plan = $row->work_state['batchPlan'];
        $expectedPlan = app(SubtitleJobArtifactStore::class)->batchPlan($row->track->cues);
        $this->assertSame($expectedPlan, $plan);
        $this->assertSame([[0, 19], [20, 39], [40, 44]], $plan);

        $this->runCorrectionRevisions($queue['job'], $response->json('attemptId'), startRevision: 1);

        $this->assertSame(3, $this->translationAnalysis->tokenizationCalls);
        $row = $this->correctionRow($queue['job'], $response->json('attemptId'));
        $this->assertSame('completed', $row->status);
    }

    public function test_invalid_derived_batch_is_narrowed_across_revisions_instead_of_failing_the_correction(): void
    {
        $queue = $this->completedTrackWithCues(20, fn (int $position): string => 'Lyrics line '.($position + 1), ['include_translation' => true]);
        LyricsAlignmentAgent::fake([['isMatch' => true, 'cues' => $queue['alignmentCues']]]);
        $this->translationAnalysis->invalidBatchAboveCueCount = 10;
        $response = $this->submitLyrics($queue['job'], $queue['texts']);
        $service = app(LyricsCorrectionService::class);

        (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $response->json('attemptId'), 0))->handle($service);
        (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $response->json('attemptId'), 1))->handle($service);

        $narrowed = $this->correctionRow($queue['job'], $response->json('attemptId'));
        $this->assertSame('running', $narrowed->status);
        $this->assertSame(2, $narrowed->work_revision);
        $this->assertSame('analyzing', $narrowed->work_state['stage']);
        $this->assertSame([[0, 9], [10, 19]], $narrowed->work_state['batchPlan']);

        $this->runCorrectionRevisions($queue['job'], $response->json('attemptId'), startRevision: 2);

        $completed = $this->correctionRow($queue['job'], $response->json('attemptId'));
        $this->assertSame('completed', $completed->status);
        $this->assertSame(3, $this->translationAnalysis->tokenizationCalls);
        $this->assertSame(3, $this->translationAnalysis->translationCalls);
    }

    public function test_each_continuation_advances_exactly_one_stage_or_batch_revision(): void
    {
        $queue = $this->completedTrackWithCues(45, fn (int $position): string => 'Lyrics line '.($position + 1));
        LyricsAlignmentAgent::fake([['isMatch' => true, 'cues' => $queue['alignmentCues']]]);
        $response = $this->submitLyrics($queue['job'], $queue['texts']);
        $service = app(LyricsCorrectionService::class);

        $expectedStages = ['aligning', 'tokenizing', 'tokenizing', 'tokenizing', 'finalizing'];

        foreach ($expectedStages as $revision => $stage) {
            $row = $this->correctionRow($queue['job'], $response->json('attemptId'));
            $this->assertSame($revision, $row->work_revision);
            $this->assertSame($stage, $row->work_state['stage']);

            (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $response->json('attemptId'), $revision))->handle($service);
        }

        $row = $this->correctionRow($queue['job'], $response->json('attemptId'));
        $this->assertSame('completed', $row->status);
        $this->assertSame(count($expectedStages), $row->work_revision);
        $this->assertNull($row->work_state);
    }

    public function test_replaying_a_stale_job_revision_does_no_provider_work_and_changes_no_state(): void
    {
        $queue = $this->completedTrackWithCues(3, fn (int $position): string => 'Lyrics line '.($position + 1));
        $calls = 0;
        LyricsAlignmentAgent::fake(function () use (&$calls, $queue): array {
            $calls++;

            return ['isMatch' => true, 'cues' => $queue['alignmentCues']];
        })->preventStrayPrompts();
        $response = $this->submitLyrics($queue['job'], $queue['texts']);
        $service = app(LyricsCorrectionService::class);

        (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $response->json('attemptId'), 0))->handle($service);
        $before = $this->correctionRow($queue['job'], $response->json('attemptId'));
        $this->assertSame(1, $before->work_revision);
        $this->assertSame(1, $calls);

        (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $response->json('attemptId'), 0))->handle($service);

        $after = $this->correctionRow($queue['job'], $response->json('attemptId'));
        $this->assertSame(1, $calls, 'A stale delivery must not prompt the provider again.');
        $this->assertSame($before->work_revision, $after->work_revision);
        $this->assertSame($before->work_state['stage'], $after->work_state['stage']);
        $this->assertSame($before->status, $after->status);
    }

    public function test_two_deliveries_of_the_same_attempt_cannot_perform_the_same_provider_unit_twice(): void
    {
        $queue = $this->completedTrackWithCues(3, fn (int $position): string => 'Lyrics line '.($position + 1));
        LyricsAlignmentAgent::fake([['isMatch' => true, 'cues' => $queue['alignmentCues']]]);
        $response = $this->submitLyrics($queue['job'], $queue['texts']);
        $service = app(LyricsCorrectionService::class);

        (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $response->json('attemptId'), 0))->handle($service);
        (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $response->json('attemptId'), 1))->handle($service);
        (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $response->json('attemptId'), 1))->handle($service);

        $row = $this->correctionRow($queue['job'], $response->json('attemptId'));
        $this->assertSame(2, $row->work_revision);
        $this->assertSame(1, $this->translationAnalysis->tokenizationCalls, 'A duplicate delivery must not rerun the batch provider unit.');
        $this->assertSame(55, $queue['job']->refresh()->estimated_provider_cost_microusd, 'A duplicate delivery must not record cost twice.');
    }

    public function test_a_transient_provider_failure_leaves_revision_and_stage_resumable_and_a_retry_completes(): void
    {
        $queue = $this->completedTrackWithCues(2, fn (int $position): string => 'Lyrics line '.($position + 1));
        $calls = 0;
        LyricsAlignmentAgent::fake(function () use (&$calls, $queue): array {
            $calls++;

            if ($calls === 1) {
                throw RateLimitedException::forProvider('openai', 429);
            }

            return ['isMatch' => true, 'cues' => $queue['alignmentCues']];
        })->preventStrayPrompts();
        $response = $this->submitLyrics($queue['job'], $queue['texts']);
        $service = app(LyricsCorrectionService::class);

        try {
            (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $response->json('attemptId'), 0))->handle($service);
            $this->fail('Expected the first provider attempt to be transient.');
        } catch (SubtitleProcessingException $exception) {
            $this->assertTrue($exception->isTransient());
        }

        $resumed = $this->correctionRow($queue['job'], $response->json('attemptId'));
        $this->assertSame('running', $resumed->status);
        $this->assertSame(0, $resumed->work_revision);
        $this->assertSame('aligning', $resumed->work_state['stage']);

        (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $response->json('attemptId'), 0))->handle($service);
        $this->runCorrectionRevisions($queue['job'], $response->json('attemptId'), startRevision: 1);

        $this->assertSame(2, $calls);
        $this->assertSame('completed', $this->correctionRow($queue['job'], $response->json('attemptId'))->status);
    }

    public function test_a_definitive_wrong_song_prompts_once_fails_and_clears_private_state(): void
    {
        $queue = $this->completedTrackWithCues(2, fn (int $position): string => 'Lyrics line '.($position + 1));
        $oldTrackId = $queue['job']->track->public_id;
        $calls = 0;
        LyricsAlignmentAgent::fake(function () use (&$calls): array {
            $calls++;

            return ['isMatch' => false, 'cues' => []];
        })->preventStrayPrompts();
        $response = $this->submitLyrics($queue['job'], $queue['texts']);

        (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $response->json('attemptId'), 0))->handle(app(LyricsCorrectionService::class));

        $this->assertSame(1, $calls, 'A definitive wrong-song result must not trigger a second paid alignment call.');
        $row = $this->correctionRow($queue['job'], $response->json('attemptId'));
        $this->assertSame('failed', $row->status);
        $this->assertSame('lyrics_do_not_match', $row->error_code);
        $this->assertNull($row->lyrics);
        $this->assertNull($row->work_state);
        $this->assertSame($oldTrackId, $queue['job']->track->refresh()->public_id);
        $this->assertSame(25, $queue['job']->refresh()->estimated_provider_cost_microusd, 'The completed alignment call must record cost even on a definitive mismatch.');
    }

    public function test_entitlement_loss_between_units_prevents_the_next_provider_call_and_fails_safely(): void
    {
        $queue = $this->completedTrackWithCues(2, fn (int $position): string => 'Lyrics line '.($position + 1));
        LyricsAlignmentAgent::fake([['isMatch' => true, 'cues' => $queue['alignmentCues']]]);
        $response = $this->submitLyrics($queue['job'], $queue['texts']);
        $oldTrackId = $queue['job']->track->public_id;
        $service = app(LyricsCorrectionService::class);

        (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $response->json('attemptId'), 0))->handle($service);

        $queue['job']->user->forceFill(['billing_subscription_status' => 'past_due'])->save();

        (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $response->json('attemptId'), 1))->handle($service);

        $this->assertSame(0, $this->translationAnalysis->tokenizationCalls, 'No derived provider call may run after entitlement loss.');
        $row = $this->correctionRow($queue['job'], $response->json('attemptId'));
        $this->assertSame('failed', $row->status);
        $this->assertNull($row->lyrics);
        $this->assertNull($row->work_state);
        $this->assertSame($oldTrackId, $queue['job']->track->refresh()->public_id);
    }

    public function test_same_language_correction_rebuilds_tokens_only(): void
    {
        $queue = $this->completedTrackWithCues(2, fn (int $position): string => 'Lyrics line '.($position + 1), ['include_translation' => false, 'include_romanization' => false]);
        LyricsAlignmentAgent::fake([['isMatch' => true, 'cues' => $queue['alignmentCues']]]);
        $response = $this->submitLyrics($queue['job'], $queue['texts']);

        $this->runCorrectionRevisions($queue['job'], $response->json('attemptId'));

        $track = $this->correctionRow($queue['job'], $response->json('attemptId'))->track;
        $this->assertSame('completed', $this->correctionRow($queue['job'], $response->json('attemptId'))->status);
        $this->assertSame(1, $this->translationAnalysis->tokenizationCalls);
        $this->assertSame(0, $this->translationAnalysis->translationCalls);
        $this->assertSame(0, $this->translationAnalysis->romanizationCalls);
        $this->assertSame(0, $this->translationAnalysis->calls);
        $this->assertNotEmpty($track->cues[0]['tokens']);
    }

    public function test_translated_correction_rebuilds_translation(): void
    {
        $queue = $this->completedTrackWithCues(2, fn (int $position): string => 'Lyrics line '.($position + 1), ['include_translation' => true, 'include_romanization' => false]);
        LyricsAlignmentAgent::fake([['isMatch' => true, 'cues' => $queue['alignmentCues']]]);
        $response = $this->submitLyrics($queue['job'], $queue['texts']);

        $this->runCorrectionRevisions($queue['job'], $response->json('attemptId'));

        $row = $this->correctionRow($queue['job'], $response->json('attemptId'));
        $this->assertSame('completed', $row->status);
        $this->assertSame(1, $this->translationAnalysis->translationCalls);
        $this->assertSame('Translated Lyrics line 1', $row->track->cues[0]['translatedText']);
    }

    public function test_non_latin_correction_rebuilds_romanization(): void
    {
        $texts = ['مرحبا بالعالم', 'أهلا وسهلا'];
        $queue = $this->completedTrackWithCues(2, fn (int $position): string => $texts[$position], ['include_translation' => false, 'include_romanization' => true]);
        LyricsAlignmentAgent::fake([['isMatch' => true, 'cues' => $queue['alignmentCues']]]);
        $response = $this->submitLyrics($queue['job'], $texts);

        $this->runCorrectionRevisions($queue['job'], $response->json('attemptId'));

        $row = $this->correctionRow($queue['job'], $response->json('attemptId'));
        $this->assertSame('completed', $row->status);
        $this->assertSame(1, $this->translationAnalysis->romanizationCalls);
        $this->assertStringStartsWith('romanized ', (string) $row->track->cues[0]['romanization']);
    }

    public function test_full_enrichment_correction_rebuilds_word_cards(): void
    {
        $queue = $this->completedTrackWithCues(2, fn (int $position): string => 'Lyrics line '.($position + 1), ['include_translation' => true, 'include_romanization' => false, 'enrichment_mode' => 'full']);
        LyricsAlignmentAgent::fake([['isMatch' => true, 'cues' => $queue['alignmentCues']]]);
        $response = $this->submitLyrics($queue['job'], $queue['texts']);

        $this->runCorrectionRevisions($queue['job'], $response->json('attemptId'));

        $row = $this->correctionRow($queue['job'], $response->json('attemptId'));
        $this->assertSame('completed', $row->status);
        $this->assertSame(1, $this->translationAnalysis->calls);
        $this->assertArrayHasKey('gloss', $row->track->cues[0]['tokens'][0]);
    }

    public function test_final_publication_is_atomic_and_stale_attempts_cannot_publish(): void
    {
        $queue = $this->completedTrackWithCues(3, fn (int $position): string => 'Lyrics line '.($position + 1));
        LyricsAlignmentAgent::fake([['isMatch' => true, 'cues' => $queue['alignmentCues']]]);
        $response = $this->submitLyrics($queue['job'], $queue['texts']);
        $service = app(LyricsCorrectionService::class);

        for ($revision = 0; $revision < 3; $revision++) {
            (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $response->json('attemptId'), $revision))->handle($service);
        }

        $this->assertSame(3, $this->correctionRow($queue['job'], $response->json('attemptId'))->work_revision);
        $publishedId = $queue['job']->track->refresh()->public_id;

        (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $response->json('attemptId'), 3))->handle($service);
        $publishedOnce = $queue['job']->track->refresh()->public_id;
        $this->assertSame('completed', $this->correctionRow($queue['job'], $response->json('attemptId'))->status);
        $this->assertSame($publishedId, $publishedOnce, 'A stale finalizing delivery must not republish the track.');

        (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $response->json('attemptId'), 0))->handle($service);
        $this->assertSame($publishedId, $queue['job']->track->refresh()->public_id, 'A replayed old revision must not publish.');
    }

    public function test_raw_database_values_do_not_contain_lyrics_or_work_state_plaintext(): void
    {
        $queue = $this->completedTrackWithCues(2, fn (int $position): string => 'Lyrics line '.($position + 1));
        LyricsAlignmentAgent::fake([['isMatch' => true, 'cues' => $queue['alignmentCues']]]);
        $response = $this->submitLyrics($queue['job'], $queue['texts']);
        $service = app(LyricsCorrectionService::class);

        (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $response->json('attemptId'), 0))->handle($service);

        $raw = DB::table('subtitle_track_lyrics_corrections')
            ->where('attempt_id', $response->json('attemptId'))
            ->first();
        $this->assertStringNotContainsString('Lyrics line 1', (string) $raw->lyrics);
        $this->assertStringNotContainsString('Lyrics line 1', (string) $raw->work_state);
        $this->assertStringNotContainsString('tokenizing', (string) $raw->work_state);

        $row = $this->correctionRow($queue['job'], $response->json('attemptId'));
        $this->assertSame("Lyrics line 1\nLyrics line 2", $row->lyrics);
        $this->assertSame('tokenizing', $row->work_state['stage']);
    }

    public function test_completed_and_failed_attempts_clear_lyrics_and_work_state(): void
    {
        $queue = $this->completedTrackWithCues(2, fn (int $position): string => 'Lyrics line '.($position + 1));
        LyricsAlignmentAgent::fake([['isMatch' => true, 'cues' => $queue['alignmentCues']]]);
        $response = $this->submitLyrics($queue['job'], $queue['texts']);

        $this->runCorrectionRevisions($queue['job'], $response->json('attemptId'));

        $completed = $this->correctionRow($queue['job'], $response->json('attemptId'));
        $this->assertSame('completed', $completed->status);
        $this->assertNull($completed->lyrics);
        $this->assertNull($completed->work_state);

        LyricsAlignmentAgent::fake([['isMatch' => false, 'cues' => []]])->preventStrayPrompts();
        $failedResponse = $this->submitLyrics($queue['job'], $queue['texts']);
        (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $failedResponse->json('attemptId'), 0))->handle(app(LyricsCorrectionService::class));

        $failed = $this->correctionRow($queue['job'], $failedResponse->json('attemptId'));
        $this->assertSame('failed', $failed->status);
        $this->assertNull($failed->lyrics);
        $this->assertNull($failed->work_state);
    }

    public function test_the_per_unit_job_timeout_models_two_real_provider_calls(): void
    {
        config([
            'subtitles.queue.connection' => 'database',
            'subtitles.enrichment.timeout_seconds' => 120,
            'subtitles.queue.worker_timeout_seconds' => 1200,
            'queue.connections.database.retry_after' => 1260,
        ]);

        $job = new LyricsCorrectionJob(1, 1, (string) Str::uuid(), 0);

        $this->assertSame(300, $job->timeout);
        $this->assertLessThan(config('subtitles.queue.worker_timeout_seconds'), $job->timeout);
        $this->assertLessThan(config('queue.connections.database.retry_after'), $job->timeout);
    }

    /**
     * @param  array<int, callable(int): string>  $texts
     * @return array{job: SubtitleJob, texts: array<int, string>, alignmentCues: array<int, array<string, mixed>>}
     */
    private function completedTrackWithCues(int $cueCount, callable $textAt, array $overrides = []): array
    {
        $user = User::factory()->create();
        $texts = [];

        for ($position = 0; $position < $cueCount; $position++) {
            $texts[] = $textAt($position);
        }

        $job = SubtitleJob::factory()->create([
            'user_id' => $user->id,
            'status' => 'completed',
            'stage' => 'finalizing',
            'progress_percent' => 100,
            'expires_at' => now()->addDays(29),
            'install_id' => $this->installId(),
            ...$overrides,
        ]);
        $track = SubtitleTrack::factory()->create([
            'subtitle_job_id' => $job->id,
            'cues' => array_map(
                fn (string $text, int $position): array => [
                    'cueId' => sprintf('cue-%04d', $position + 1),
                    'index' => $position,
                    'startMs' => $position * 4000,
                    'endMs' => ($position + 1) * 4000,
                    'sourceText' => $text,
                    'translatedText' => $text,
                    'tokens' => [],
                ],
                $texts,
                array_keys($texts),
            ),
        ]);

        return [
            'job' => $job->refresh()->load('track'),
            'texts' => $texts,
            'alignmentCues' => array_map(
                fn (array $cue, int $position): array => [
                    'cueId' => $cue['cueId'],
                    'index' => $cue['index'],
                    'sourceText' => $texts[$position],
                ],
                $track->cues,
                array_keys($track->cues),
            ),
        ];
    }

    /**
     * @param  array<int, string>  $texts
     */
    private function submitLyrics(SubtitleJob $job, array $texts): TestResponse
    {
        return $this->withExtensionAuth($this->installId(), $job->user)
            ->postJson('/v1/subtitle-jobs/'.$job->public_id.'/lyrics', ['lyrics' => implode("\n", $texts)]);
    }

    private function runCorrectionRevisions(SubtitleJob $job, string $attemptId, int $startRevision = 0, int $maxRevisions = 60): void
    {
        $service = app(LyricsCorrectionService::class);

        for ($revision = $startRevision; $revision < $maxRevisions; $revision++) {
            $row = $this->correctionRow($job, $attemptId);

            if (in_array($row->status, ['completed', 'failed', 'cancelled'], true)) {
                return;
            }

            (new LyricsCorrectionJob($job->track->id, $job->id, $attemptId, $revision))->handle($service);
        }

        $this->fail('Lyrics correction did not reach a terminal state within the expected revisions.');
    }

    private function correctionRow(SubtitleJob $job, string $attemptId): SubtitleTrackLyricsCorrection
    {
        return SubtitleTrackLyricsCorrection::query()
            ->with('track')
            ->where('subtitle_track_id', $job->track->id)
            ->where('attempt_id', $attemptId)
            ->firstOrFail();
    }

    private function installId(): string
    {
        return 'install_'.str_repeat('a', 32);
    }

    private function useThrowingQueue(): void
    {
        $connection = new class(app('db')->connection()) extends DatabaseQueue
        {
            public function __construct(Connection $database)
            {
                parent::__construct($database, 'jobs');
            }

            protected function pushToDatabase($queue, $payload, $delay = 0, $attempts = 0)
            {
                throw new RuntimeException('Redis queue unavailable.');
            }
        };
        $connection->setContainer(app());

        $queueRoot = Queue::getFacadeRoot();
        Queue::swap($queueRoot instanceof QueueFake ? $queueRoot->queue : $queueRoot);
        Queue::extend('throwing-database', fn () => new class($connection)
        {
            public function __construct(private readonly \Illuminate\Contracts\Queue\Queue $queue) {}

            public function connect(array $config): \Illuminate\Contracts\Queue\Queue
            {
                return $this->queue;
            }
        });
        config([
            'queue.default' => 'throwing-database',
            'subtitles.queue.connection' => 'throwing-database',
            'queue.connections.throwing-database' => [
                'driver' => 'throwing-database',
                'table' => 'jobs',
                'queue' => 'default',
                'retry_after' => 1260,
            ],
        ]);
    }
}
