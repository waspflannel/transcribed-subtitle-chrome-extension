<?php

namespace Tests\Feature;

use App\Ai\Agents\CueAnalysisAgent;
use App\Ai\Agents\LyricsAlignmentAgent;
use App\Exceptions\SubtitleProcessingException;
use App\Jobs\LyricsCorrectionJob;
use App\Models\SubtitleJob;
use App\Models\SubtitleTrack;
use App\Models\SubtitleTrackLyricsCorrection;
use App\Models\User;
use App\Services\Subtitles\LyricsCorrectionService;
use App\Services\Subtitles\SubtitleJobArtifactStore;
use App\Services\TranslationAnalysis\LaravelAiTranslationAnalysisProvider;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Illuminate\Testing\TestResponse;
use Laravel\Ai\Exceptions\RateLimitedException;
use PHPUnit\Framework\Attributes\TestWith;
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
                ->postJson('/v1/subtitle-jobs/'.$queue['job']->public_id.'/lyrics', ['expectedTrackId' => $queue['job']->track->public_id, 'lyrics' => implode("\n", $queue['texts'])])
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
                ->postJson('/v1/subtitle-jobs/'.$queue['job']->public_id.'/lyrics', ['expectedTrackId' => $queue['job']->track->public_id, 'lyrics' => implode("\n", $queue['texts'])])
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
            'work_state' => ['stage' => 'analyzing'],
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
        $this->assertSame('analyzing', $current->work_state['stage']);
    }

    public function test_a_stale_job_failure_callback_cannot_fail_the_current_revision(): void
    {
        $queue = $this->completedTrackWithCues(2, fn (int $position): string => 'Lyrics line '.($position + 1));
        $response = $this->submitLyrics($queue['job'], $queue['texts']);
        $correction = $this->correctionRow($queue['job'], $response->json('attemptId'));
        $correction->update([
            'status' => 'running',
            'work_revision' => 2,
            'work_state' => ['stage' => 'analyzing'],
        ]);

        (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $correction->attempt_id, 1, 0))
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
        LyricsAlignmentAgent::fake([['isMatch' => true, 'isComplete' => true, 'cues' => $queue['alignmentCues']]]);
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
        $this->assertSame(180, $restored->timeout);
    }

    public function test_entitlement_loss_between_alignment_retries_prevents_the_second_prompt(): void
    {
        $queue = $this->completedTrackWithCues(2, fn (int $position): string => 'Lyrics line '.($position + 1));
        $calls = 0;
        LyricsAlignmentAgent::fake(function () use (&$calls, $queue): array {
            $calls++;
            $queue['job']->user->forceFill(['billing_subscription_status' => 'past_due'])->save();

            return ['isMatch' => true, 'isComplete' => true, 'cues' => [
                ['cueId' => 'cue-0001', 'index' => 0, 'segments' => [['source' => 'pasted', 'startPartIndex' => 0, 'endPartIndex' => 1000, 'separator' => '']]],
                ['cueId' => 'cue-0002', 'index' => 1, 'segments' => [['source' => 'pasted', 'startPartIndex' => 1001, 'endPartIndex' => 5, 'separator' => '']]],
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

    public function test_each_returned_alignment_response_records_one_call_even_when_the_only_response_is_invalid(): void
    {
        $queue = $this->completedTrackWithCues(2, fn (int $position): string => 'Lyrics line '.($position + 1));
        LyricsAlignmentAgent::fake([
            [
                'isMatch' => true,
                'isComplete' => true,
                'cues' => [
                    ['cueId' => 'cue-0001', 'index' => 0, 'segments' => [['source' => 'pasted', 'startPartIndex' => 0, 'endPartIndex' => 1000, 'separator' => '']]],
                    ['cueId' => 'cue-0002', 'index' => 1, 'segments' => [['source' => 'pasted', 'startPartIndex' => 1001, 'endPartIndex' => 5, 'separator' => '']]],
                ],
            ],
            ['isMatch' => true, 'isComplete' => true, 'cues' => $queue['alignmentCues']],
        ])->preventStrayPrompts();
        $response = $this->submitLyrics($queue['job'], $queue['texts']);

        (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $response->json('attemptId'), 0))
            ->handle(app(LyricsCorrectionService::class));

        $this->assertSame(25, $queue['job']->refresh()->estimated_provider_cost_microusd);
        $this->assertSame('failed', $this->correctionRow($queue['job'], $response->json('attemptId'))->status);
    }

    public function test_a_completed_track_with_more_than_22_cues_is_accepted_and_completes(): void
    {
        $queue = $this->completedTrackWithCues(30, fn (int $position): string => 'Lyrics line '.($position + 1));
        LyricsAlignmentAgent::fake([['isMatch' => true, 'isComplete' => true, 'cues' => $queue['alignmentCues']]]);

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
        LyricsAlignmentAgent::fake([['isMatch' => true, 'isComplete' => true, 'cues' => [
            ['cueId' => 'cue-0001', 'index' => 0, 'segments' => [['source' => 'pasted', 'startPartIndex' => 0, 'endPartIndex' => 2, 'separator' => '']]],
            ['cueId' => 'cue-0003', 'index' => 2, 'segments' => [['source' => 'pasted', 'startPartIndex' => 3, 'endPartIndex' => 5, 'separator' => '']]],
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

    public function test_alignment_only_sends_existing_parts_when_partial_replacement_is_enabled(): void
    {
        foreach ([false, true] as $allowPartial) {
            $queue = $this->completedTrackWithCues(2, fn (int $position): string => 'Lyrics line '.($position + 1));
            $input = null;
            LyricsAlignmentAgent::fake(function ($prompt) use (&$input, $queue): array {
                $input = json_decode($prompt, true, flags: JSON_THROW_ON_ERROR);

                return ['isMatch' => true, 'isComplete' => true, 'cues' => $queue['alignmentCues']];
            })->preventStrayPrompts();
            $response = $this->submitLyrics($queue['job'], $queue['texts'], $allowPartial);
            $this->runCorrectionRevisions($queue['job'], $response->json('attemptId'));

            $this->assertSame('completed', $this->correctionRow($queue['job'], $response->json('attemptId'))->status);
            $this->assertSame($allowPartial, $input['allowPartial']);
            $this->assertSame($allowPartial, array_key_exists('existingParts', $input));
            $this->assertSame($queue['texts'], array_column($input['cues'], 'sourceText'));
            if ($allowPartial) {
                $this->assertSame('cue-0001', $input['existingParts'][0]['cueId']);
                $this->assertSame([0, 1, 2], array_column($input['existingParts'][0]['parts'], 'index'));
                $this->assertSame($queue['texts'][0], implode('', array_column($input['existingParts'][0]['parts'], 'text')));
            }
        }
    }

    public function test_incomplete_alignment_requires_confirmation_then_merges_with_existing_cues(): void
    {
        $queue = $this->completedTrackWithCues(3, fn (int $position): string => 'Lyrics line '.($position + 1));
        LyricsAlignmentAgent::fake([
            ['isMatch' => true, 'isComplete' => false, 'cues' => []],
            ['isMatch' => true, 'isComplete' => false, 'cues' => [
                ['cueId' => 'cue-0001', 'index' => 0, 'segments' => [['source' => 'pasted', 'startPartIndex' => 0, 'endPartIndex' => 1, 'separator' => '']]],
                ['cueId' => 'cue-0002', 'index' => 1, 'segments' => [['source' => 'existing', 'startPartIndex' => 0, 'endPartIndex' => 2, 'separator' => '']]],
                ['cueId' => 'cue-0003', 'index' => 2, 'segments' => [['source' => 'existing', 'startPartIndex' => 0, 'endPartIndex' => 2, 'separator' => '']]],
            ]],
        ])->preventStrayPrompts();

        $first = $this->submitLyrics($queue['job'], ['Corrected first']);
        (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $first->json('attemptId'), 0))
            ->handle(app(LyricsCorrectionService::class));

        $warning = $this->correctionRow($queue['job'], $first->json('attemptId'));
        $this->assertSame('failed', $warning->status);
        $this->assertSame('lyrics_incomplete', $warning->error_code);
        $this->assertSame('Lyrics line 1', $warning->track->cues[0]['sourceText']);

        $confirmed = $this->submitLyrics($queue['job'], ['Corrected first'], true);
        $queued = $this->correctionRow($queue['job'], $confirmed->json('attemptId'));
        $this->assertTrue($queued->work_state['allowPartial']);
        $this->runCorrectionRevisions($queue['job'], $confirmed->json('attemptId'));

        $completed = $this->correctionRow($queue['job'], $confirmed->json('attemptId'));
        $this->assertSame('completed', $completed->status);
        $this->assertSame(['Corrected first', 'Lyrics line 2', 'Lyrics line 3'], array_column($completed->track->cues, 'sourceText'));
    }

    public function test_unrelated_lyrics_are_rejected_even_after_partial_confirmation(): void
    {
        $queue = $this->completedTrackWithCues(2, fn (int $position): string => 'Lyrics line '.($position + 1));
        LyricsAlignmentAgent::fake([['isMatch' => false, 'isComplete' => false, 'cues' => []]])->preventStrayPrompts();
        $response = $this->submitLyrics($queue['job'], ['Lyrics from another song'], true);

        (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $response->json('attemptId'), 0))
            ->handle(app(LyricsCorrectionService::class));

        $failed = $this->correctionRow($queue['job'], $response->json('attemptId'));
        $this->assertSame('failed', $failed->status);
        $this->assertSame('lyrics_do_not_match', $failed->error_code);
        $this->assertSame(['Lyrics line 1', 'Lyrics line 2'], array_column($failed->track->cues, 'sourceText'));
    }

    public function test_partial_alignment_can_replace_words_inside_a_generated_cue(): void
    {
        $queue = $this->completedTrackWithCues(1, fn (): string => 'Generated alpha beta');
        LyricsAlignmentAgent::fake([['isMatch' => true, 'isComplete' => false, 'cues' => [
            ['cueId' => 'cue-0001', 'index' => 0, 'segments' => [
                ['source' => 'existing', 'startPartIndex' => 0, 'endPartIndex' => 0, 'separator' => ''],
                ['source' => 'pasted', 'startPartIndex' => 0, 'endPartIndex' => 1, 'separator' => ' '],
                ['source' => 'existing', 'startPartIndex' => 2, 'endPartIndex' => 2, 'separator' => ' '],
            ]],
        ]]])->preventStrayPrompts();
        $response = $this->submitLyrics($queue['job'], ['corrected words'], true);

        $this->runCorrectionRevisions($queue['job'], $response->json('attemptId'));

        $completed = $this->correctionRow($queue['job'], $response->json('attemptId'));
        $this->assertSame('completed', $completed->status);
        $this->assertSame('Generated corrected words beta', $completed->track->cues[0]['sourceText']);
    }

    public function test_partial_alignment_preserves_unspaced_graphemes_around_a_correction(): void
    {
        $queue = $this->completedTrackWithCues(1, fn (): string => 'ก้ข้ค้');
        LyricsAlignmentAgent::fake([['isMatch' => true, 'isComplete' => false, 'cues' => [
            ['cueId' => 'cue-0001', 'index' => 0, 'segments' => [
                ['source' => 'existing', 'startPartIndex' => 0, 'endPartIndex' => 0, 'separator' => ''],
                ['source' => 'pasted', 'startPartIndex' => 0, 'endPartIndex' => 0, 'separator' => ''],
                ['source' => 'existing', 'startPartIndex' => 2, 'endPartIndex' => 2, 'separator' => ''],
            ]],
        ]]])->preventStrayPrompts();
        $response = $this->submitLyrics($queue['job'], ['ง้'], true);

        $this->runCorrectionRevisions($queue['job'], $response->json('attemptId'));

        $completed = $this->correctionRow($queue['job'], $response->json('attemptId'));
        $this->assertSame('completed', $completed->status);
        $this->assertSame('ก้ง้ค้', $completed->track->cues[0]['sourceText']);
    }

    public function test_incomplete_alignment_fails_before_derived_provider_work(): void
    {
        $queue = $this->completedTrackWithCues(10, fn (int $position): string => 'Lyrics line '.($position + 1));
        LyricsAlignmentAgent::fake([['isMatch' => true, 'isComplete' => false, 'cues' => array_slice($queue['alignmentCues'], 0, 5)]])->preventStrayPrompts();
        $response = $this->submitLyrics($queue['job'], array_slice($queue['texts'], 0, 5));

        (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $response->json('attemptId'), 0))
            ->handle(app(LyricsCorrectionService::class));

        $row = $this->correctionRow($queue['job'], $response->json('attemptId'));
        $this->assertSame('failed', $row->status);
        $this->assertSame('lyrics_incomplete', $row->error_code);
        $this->assertSame(0, $this->translationAnalysis->tokenizationCalls);
        $this->assertSame('Lyrics line 1', $row->track->cues[0]['sourceText']);
    }

    public function test_ai_completion_classification_allows_sparse_timing_slots(): void
    {
        $queue = $this->completedTrackWithCues(10, fn (int $position): string => 'Lyrics line '.($position + 1));
        $positions = [0, 1, 2, 3, 4, 9];
        LyricsAlignmentAgent::fake([['isMatch' => true, 'isComplete' => true, 'cues' => $this->alignmentForPositions($queue, $positions)]])->preventStrayPrompts();
        $response = $this->submitLyrics($queue['job'], array_map(
            fn (int $position): string => $queue['texts'][$position],
            $positions,
        ));

        (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $response->json('attemptId'), 0))
            ->handle(app(LyricsCorrectionService::class));

        $row = $this->correctionRow($queue['job'], $response->json('attemptId'));
        $this->assertSame('analyzing', $row->work_state['stage']);
    }

    public function test_ai_completion_classification_allows_low_slot_coverage(): void
    {
        $queue = $this->completedTrackWithCues(69, fn (int $position): string => 'Lyrics line '.($position + 1));
        $positions = [...range(0, 62), 68];
        LyricsAlignmentAgent::fake([['isMatch' => true, 'isComplete' => true, 'cues' => $this->alignmentForPositions($queue, $positions)]])->preventStrayPrompts();
        $response = $this->submitLyrics($queue['job'], array_map(
            fn (int $position): string => $queue['texts'][$position],
            $positions,
        ));

        (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $response->json('attemptId'), 0))
            ->handle(app(LyricsCorrectionService::class));

        $this->assertSame('analyzing', $this->correctionRow($queue['job'], $response->json('attemptId'))->work_state['stage']);
    }

    public function test_ai_completion_classification_allows_short_timeline_span(): void
    {
        $queue = $this->completedTrackWithCues(10, fn (int $position): string => 'Lyrics line '.($position + 1));
        $positions = range(0, 7);
        LyricsAlignmentAgent::fake([['isMatch' => true, 'isComplete' => true, 'cues' => $this->alignmentForPositions($queue, $positions)]])->preventStrayPrompts();
        $response = $this->submitLyrics($queue['job'], array_map(
            fn (int $position): string => $queue['texts'][$position],
            $positions,
        ));

        (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $response->json('attemptId'), 0))
            ->handle(app(LyricsCorrectionService::class));

        $this->assertSame('analyzing', $this->correctionRow($queue['job'], $response->json('attemptId'))->work_state['stage']);
    }

    public function test_ai_incomplete_classification_is_rejected_without_confirmation(): void
    {
        $queue = $this->completedTrackWithCues(69, fn (int $position): string => 'Lyrics line '.($position + 1));
        $positions = range(0, 68, 2);
        LyricsAlignmentAgent::fake([['isMatch' => true, 'isComplete' => false, 'cues' => $this->alignmentForPositions($queue, $positions)]])->preventStrayPrompts();
        $response = $this->submitLyrics($queue['job'], array_map(
            fn (int $position): string => $queue['texts'][$position],
            $positions,
        ));

        (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $response->json('attemptId'), 0))
            ->handle(app(LyricsCorrectionService::class));

        $this->assertSame('lyrics_incomplete', $this->correctionRow($queue['job'], $response->json('attemptId'))->error_code);
    }

    public function test_ai_incomplete_classification_is_rejected_even_when_slots_are_present(): void
    {
        $queue = $this->completedTrackWithCues(20, fn (int $position): string => 'Lyrics line '.($position + 1));
        $positions = range(0, 11);
        LyricsAlignmentAgent::fake([['isMatch' => true, 'isComplete' => false, 'cues' => $this->alignmentForPositions($queue, $positions)]])->preventStrayPrompts();
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

    public function test_cancellation_during_analysis_discards_the_response(): void
    {
        $queue = $this->completedTrackWithCues(20, fn (int $position): string => 'Lyrics line '.($position + 1), ['include_translation' => true]);
        LyricsAlignmentAgent::fake([['isMatch' => true, 'isComplete' => true, 'cues' => $queue['alignmentCues']]]);
        $response = $this->submitLyrics($queue['job'], $queue['texts']);
        $correction = $this->correctionRow($queue['job'], $response->json('attemptId'));
        $this->translationAnalysis->beforeTokenizationResult = function () use ($correction): void {
            $correction->forceFill(['status' => 'cancelled'])->save();
        };

        (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $correction->attempt_id, 0))
            ->handle(app(LyricsCorrectionService::class));
        (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $correction->attempt_id, 1, 0))
            ->handle(app(LyricsCorrectionService::class));

        $this->assertSame('cancelled', $correction->fresh()->status);
        $this->assertSame(1, $this->translationAnalysis->translationCalls);
    }

    public function test_invalid_analysis_after_cancellation_does_not_replace_cancelled_state(): void
    {
        $this->app->forgetInstance(LaravelAiTranslationAnalysisProvider::class);
        $queue = $this->completedTrackWithCues(1, fn (int $position): string => 'Lyrics line '.($position + 1), ['include_translation' => true]);
        LyricsAlignmentAgent::fake([['isMatch' => true, 'isComplete' => true, 'cues' => $queue['alignmentCues']]]);
        $response = $this->submitLyrics($queue['job'], $queue['texts']);
        $correction = $this->correctionRow($queue['job'], $response->json('attemptId'));
        $prompts = 0;

        CueAnalysisAgent::fake(function () use (&$prompts, $correction): array {
            $prompts++;
            $correction->forceFill(['status' => 'cancelled'])->save();

            return ['dialect' => 'unknown', 'cues' => []];
        })->preventStrayPrompts();

        (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $correction->attempt_id, 0))->handle(app(LyricsCorrectionService::class));
        (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $correction->attempt_id, 1, 0))->handle(app(LyricsCorrectionService::class));

        $this->assertSame(1, $prompts);
        $this->assertSame('cancelled', $correction->fresh()->status);
    }

    public function test_valid_derived_response_after_cancellation_does_not_commit_or_dispatch(): void
    {
        $queue = $this->completedTrackWithCues(2, fn (int $position): string => 'Lyrics line '.($position + 1));
        LyricsAlignmentAgent::fake([['isMatch' => true, 'isComplete' => true, 'cues' => $queue['alignmentCues']]]);
        $response = $this->submitLyrics($queue['job'], $queue['texts']);
        $correction = $this->correctionRow($queue['job'], $response->json('attemptId'));
        $this->translationAnalysis->beforeTokenizationResult = function () use ($queue, $correction): void {
            app(LyricsCorrectionService::class)->cancel($queue['job'], $queue['job']->user, $correction->attempt_id);
        };

        (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $correction->attempt_id, 0))->handle(app(LyricsCorrectionService::class));
        (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $correction->attempt_id, 1, 0))->handle(app(LyricsCorrectionService::class));

        $this->assertSame('cancelled', $correction->fresh()->status);
        $this->assertNull($correction->fresh()->work_state);
        $this->assertNull($correction->fresh()->lyrics);
        $this->assertSame('Lyrics line 1', $queue['job']->track->fresh()->cues[0]['sourceText']);
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
            'work_state' => ['stage' => 'analyzing'],
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

    /** @param array<int, array{0: int, 1: int}> $expectedBounds */
    #[TestWith([false, [[0, 19], [20, 39], [40, 44]]])]
    #[TestWith([true, [[0, 14], [15, 29], [30, 44]]])]
    public function test_work_is_divided_by_the_real_shared_batch_plan(bool $balanced, array $expectedBounds): void
    {
        config([
            'subtitles.enrichment.balanced_batches' => $balanced,
            'subtitles.enrichment.cue_batch_char_budget' => 1000,
            'subtitles.enrichment.cue_batch_max_cues' => 20,
        ]);
        $queue = $this->completedTrackWithCues(45, fn (int $position): string => 'Lyrics line '.($position + 1));
        LyricsAlignmentAgent::fake([['isMatch' => true, 'isComplete' => true, 'cues' => $queue['alignmentCues']]]);
        $response = $this->submitLyrics($queue['job'], $queue['texts']);
        $service = app(LyricsCorrectionService::class);

        (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $response->json('attemptId'), 0))->handle($service);

        $row = $this->correctionRow($queue['job'], $response->json('attemptId'));
        $plan = $row->work_state['batchPlan'];
        $expectedPlan = app(SubtitleJobArtifactStore::class)->batchPlan($row->track->cues, $queue['job']);
        $this->assertSame($expectedPlan, $plan);
        $this->assertSame($expectedBounds, $plan);

        $this->runCorrectionRevisions($queue['job'], $response->json('attemptId'), startRevision: 1);

        $this->assertSame(3, $this->translationAnalysis->tokenizationCalls);
        $row = $this->correctionRow($queue['job'], $response->json('attemptId'));
        $this->assertSame('completed', $row->status);
    }

    public function test_invalid_analysis_fails_once_and_preserves_the_published_track(): void
    {
        $queue = $this->completedTrackWithCues(20, fn (int $position): string => 'Lyrics line '.($position + 1), ['include_translation' => true]);
        LyricsAlignmentAgent::fake([['isMatch' => true, 'isComplete' => true, 'cues' => $queue['alignmentCues']]]);
        $this->translationAnalysis->invalidBatchAboveCueCount = 10;
        $response = $this->submitLyrics($queue['job'], $queue['texts']);
        $service = app(LyricsCorrectionService::class);

        (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $response->json('attemptId'), 0))->handle($service);
        (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $response->json('attemptId'), 1, 0))->handle($service);

        $failed = $this->correctionRow($queue['job'], $response->json('attemptId'));
        $this->assertSame('failed', $failed->status);
        $this->assertNull($failed->work_state);
        $this->assertSame('Lyrics line 1', $queue['job']->track->fresh()->cues[0]['sourceText']);
        $this->assertSame(1, $this->translationAnalysis->tokenizationCalls);
        $this->assertSame(1, $this->translationAnalysis->translationCalls);
    }

    public function test_batches_fan_out_and_finish_out_of_order_before_atomic_publication(): void
    {
        $queue = $this->completedTrackWithCues(45, fn (int $position): string => 'Lyrics line '.($position + 1));
        LyricsAlignmentAgent::fake([['isMatch' => true, 'isComplete' => true, 'cues' => $queue['alignmentCues']]]);
        $response = $this->submitLyrics($queue['job'], $queue['texts']);
        $service = app(LyricsCorrectionService::class);
        $track = $queue['job']->track;
        $originalId = $track->public_id;
        $attempt = $response->json('attemptId');
        $service->process($track->id, $attempt, 0);
        Queue::assertPushed(LyricsCorrectionJob::class, 4);
        foreach ([0, 1, 2] as $index) {
            Queue::assertPushed(LyricsCorrectionJob::class, fn ($job): bool => $job->expectedRevision === 1 && $job->batchIndex === $index);
        }
        $service->process($track->id, $attempt, 1, 2);
        $this->assertSame($originalId, $track->fresh()->public_id);
        $this->assertSame(1, $this->correctionRow($queue['job'], $attempt)->work_revision);
        $service->process($track->id, $attempt, 1, 2);
        (new LyricsCorrectionJob($track->id, $queue['job']->id, $attempt, 1, 2))->failed(new RuntimeException('late duplicate failure'));
        $this->assertSame(1, $this->translationAnalysis->tokenizationCalls);
        $this->assertSame('running', $this->correctionRow($queue['job'], $attempt)->status);
        $service->process($track->id, $attempt, 1, 0);
        $this->assertSame($originalId, $track->fresh()->public_id);
        $service->process($track->id, $attempt, 1, 1);
        $row = $this->correctionRow($queue['job'], $attempt);
        $this->assertSame('completed', $row->status);
        $this->assertSame(2, $row->work_revision);
        $this->assertNull($row->work_state);
        $this->assertNotSame($originalId, $track->fresh()->public_id);
        $this->assertCount(45, $track->fresh()->cues);
        $this->assertSame(3, $this->translationAnalysis->tokenizationCalls);
        Queue::assertPushed(LyricsCorrectionJob::class, 4);
    }

    public function test_overlapping_batches_merge_current_results_and_recover_only_unfinished_work(): void
    {
        $queue = $this->completedTrackWithCues(45, fn (int $position): string => 'Lyrics line '.($position + 1));
        LyricsAlignmentAgent::fake([['isMatch' => true, 'isComplete' => true, 'cues' => $queue['alignmentCues']]]);
        $response = $this->submitLyrics($queue['job'], $queue['texts']);
        $service = app(LyricsCorrectionService::class);
        $track = $queue['job']->track;
        $originalId = $track->public_id;
        $attempt = $response->json('attemptId');
        $service->process($track->id, $attempt, 0);
        $this->translationAnalysis->beforeTokenizationResult = function () use ($service, $track, $attempt): void {
            $this->translationAnalysis->beforeTokenizationResult = null;
            $service->process($track->id, $attempt, 1, 2);
        };
        $service->process($track->id, $attempt, 1, 0);
        $row = $this->correctionRow($queue['job'], $attempt);
        $this->assertSame([2, 0], $row->work_state['completedBatches']);
        $this->assertNotEmpty($row->work_state['cues'][0]['tokens']);
        $this->assertNotEmpty($row->work_state['cues'][44]['tokens']);
        $this->assertSame($originalId, $track->fresh()->public_id);
        Queue::fake();
        $row->forceFill(['updated_at' => now()->subHours(2)])->saveQuietly();
        $service->recoverStalledAttempt($row, now()->subHour());
        Queue::assertPushed(LyricsCorrectionJob::class, 1);
        Queue::assertPushed(LyricsCorrectionJob::class, fn ($job): bool => $job->batchIndex === 1);
        $service->process($track->id, $attempt, 1, 1);
        $this->assertSame('completed', $row->fresh()->status);
        foreach ($track->fresh()->cues as $cue) {
            $this->assertNotEmpty($cue['tokens']);
        }
        $this->assertSame(3, $this->translationAnalysis->tokenizationCalls);
    }

    public function test_batch_overlap_locks_allow_different_batches_and_block_the_same_batch(): void
    {
        $attempt = (string) Str::uuid();
        $first = new LyricsCorrectionJob(1, 1, $attempt, 1, 0);
        $second = new LyricsCorrectionJob(1, 1, $attempt, 1, 1);
        $duplicate = new LyricsCorrectionJob(1, 1, $attempt, 1, 0);
        $delivery = \Mockery::mock(Job::class);
        $delivery->shouldReceive('release')->once()->with(5);
        $duplicate->setJob($delivery);
        $calls = 0;
        $first->middleware()[1]->handle($first, function () use ($second, $duplicate, &$calls): void {
            $second->middleware()[1]->handle($second, function () use (&$calls): void {
                $calls++;
            });
            $duplicate->middleware()[1]->handle($duplicate, function (): void {
                $this->fail('Duplicate lock was acquired.');
            });
        });
        $this->assertSame(1, $calls);
    }

    public function test_persisted_serial_work_dispatches_only_its_remaining_batches(): void
    {
        $queue = $this->completedTrackWithCues(45, fn (int $position): string => 'Lyrics line '.($position + 1));
        LyricsAlignmentAgent::fake([['isMatch' => true, 'isComplete' => true, 'cues' => $queue['alignmentCues']]]);
        $response = $this->submitLyrics($queue['job'], $queue['texts']);
        $service = app(LyricsCorrectionService::class);
        $attempt = $response->json('attemptId');
        $service->process($queue['job']->track->id, $attempt, 0);
        $service->process($queue['job']->track->id, $attempt, 1, 0);
        $row = $this->correctionRow($queue['job'], $attempt);
        $state = $row->work_state;
        unset($state['completedBatches']);
        $row->update(['work_state' => $state, 'work_revision' => 2]);
        Queue::fake();
        $service->process($queue['job']->track->id, $attempt, 2);
        Queue::assertPushed(LyricsCorrectionJob::class, 2);
        Queue::assertNotPushed(LyricsCorrectionJob::class, fn ($job): bool => $job->batchIndex === 0);
        $this->assertSame(1, $this->translationAnalysis->tokenizationCalls);
        $this->runCorrectionRevisions($queue['job'], $attempt, 2);
        $this->assertSame('completed', $row->fresh()->status);
        $this->assertSame(3, $this->translationAnalysis->tokenizationCalls);
    }

    public function test_a_failed_parallel_batch_discards_all_results_and_stops_pending_work(): void
    {
        $queue = $this->completedTrackWithCues(45, fn (int $position): string => 'Lyrics line '.($position + 1));
        LyricsAlignmentAgent::fake([['isMatch' => true, 'isComplete' => true, 'cues' => $queue['alignmentCues']]]);
        $response = $this->submitLyrics($queue['job'], $queue['texts']);
        $service = app(LyricsCorrectionService::class);
        $track = $queue['job']->track;
        $originalId = $track->public_id;
        $attempt = $response->json('attemptId');
        $service->process($track->id, $attempt, 0);
        $service->process($track->id, $attempt, 1, 2);
        $this->translationAnalysis->tokenizationShouldFail = true;
        $service->process($track->id, $attempt, 1, 1);
        $service->process($track->id, $attempt, 1, 0);
        $this->assertSame(2, $this->translationAnalysis->tokenizationCalls);
        $row = $this->correctionRow($queue['job'], $attempt);
        $this->assertSame('failed', $row->status);
        $this->assertNull($row->work_state);
        $this->assertNull($row->lyrics);
        $this->assertSame($originalId, $track->fresh()->public_id);
    }

    public function test_replaying_a_stale_job_revision_does_no_provider_work_and_changes_no_state(): void
    {
        $queue = $this->completedTrackWithCues(3, fn (int $position): string => 'Lyrics line '.($position + 1));
        $calls = 0;
        LyricsAlignmentAgent::fake(function () use (&$calls, $queue): array {
            $calls++;

            return ['isMatch' => true, 'isComplete' => true, 'cues' => $queue['alignmentCues']];
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
        LyricsAlignmentAgent::fake([['isMatch' => true, 'isComplete' => true, 'cues' => $queue['alignmentCues']]]);
        $response = $this->submitLyrics($queue['job'], $queue['texts']);
        $service = app(LyricsCorrectionService::class);

        (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $response->json('attemptId'), 0))->handle($service);
        (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $response->json('attemptId'), 1, 0))->handle($service);
        (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $response->json('attemptId'), 1, 0))->handle($service);

        $row = $this->correctionRow($queue['job'], $response->json('attemptId'));
        $this->assertSame(2, $row->work_revision);
        $this->assertSame(1, $this->translationAnalysis->tokenizationCalls, 'A duplicate delivery must not rerun the batch provider unit.');
        $this->assertSame(70, $queue['job']->refresh()->estimated_provider_cost_microusd, 'A duplicate delivery must not record cost twice.');
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

            return ['isMatch' => true, 'isComplete' => true, 'cues' => $queue['alignmentCues']];
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

            return ['isMatch' => false, 'isComplete' => false, 'cues' => []];
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
        LyricsAlignmentAgent::fake([['isMatch' => true, 'isComplete' => true, 'cues' => $queue['alignmentCues']]]);
        $response = $this->submitLyrics($queue['job'], $queue['texts']);
        $oldTrackId = $queue['job']->track->public_id;
        $service = app(LyricsCorrectionService::class);

        (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $response->json('attemptId'), 0))->handle($service);

        $queue['job']->user->forceFill(['billing_subscription_status' => 'past_due'])->save();

        (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $response->json('attemptId'), 1, 0))->handle($service);

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
        LyricsAlignmentAgent::fake([['isMatch' => true, 'isComplete' => true, 'cues' => $queue['alignmentCues']]]);
        $response = $this->submitLyrics($queue['job'], $queue['texts']);

        $this->runCorrectionRevisions($queue['job'], $response->json('attemptId'));

        $track = $this->correctionRow($queue['job'], $response->json('attemptId'))->track;
        $this->assertSame('completed', $this->correctionRow($queue['job'], $response->json('attemptId'))->status);
        $this->assertSame(1, $this->translationAnalysis->tokenizationCalls);
        $this->assertSame(0, $this->translationAnalysis->translationCalls);
        $this->assertSame(0, $this->translationAnalysis->romanizationCalls);
        $this->assertNotEmpty($track->cues[0]['tokens']);
    }

    public function test_translated_correction_rebuilds_translation(): void
    {
        $queue = $this->completedTrackWithCues(2, fn (int $position): string => 'Lyrics line '.($position + 1), ['include_translation' => true, 'include_romanization' => false]);
        LyricsAlignmentAgent::fake([['isMatch' => true, 'isComplete' => true, 'cues' => $queue['alignmentCues']]]);
        $response = $this->submitLyrics($queue['job'], $queue['texts']);

        $this->runCorrectionRevisions($queue['job'], $response->json('attemptId'));

        $row = $this->correctionRow($queue['job'], $response->json('attemptId'));
        $this->assertSame('completed', $row->status);
        $this->assertSame(1, $this->translationAnalysis->translationCalls);
        $this->assertSame('Translated Lyrics line 1', $row->track->cues[0]['translatedText']);
    }

    public function test_auto_correction_translates_and_enriches_punjabi_despite_english_detection(): void
    {
        $texts = ['Hello there', 'ਸਤ ਸ੍ਰੀ ਅਕਾਲ'];
        $queue = $this->completedTrackWithCues(2, fn (int $position): string => $texts[$position], [
            'source_language' => 'auto',
            'detected_source_language' => 'eng',
            'target_language' => 'eng',
            'include_translation' => true,
            'include_romanization' => false,
        ]);
        LyricsAlignmentAgent::fake([['isMatch' => true, 'isComplete' => true, 'cues' => $queue['alignmentCues']]]);
        $response = $this->submitLyrics($queue['job'], $texts);

        $this->runCorrectionRevisions($queue['job'], $response->json('attemptId'));

        $row = $this->correctionRow($queue['job'], $response->json('attemptId'));
        $this->assertSame('completed', $row->status);
        $this->assertSame('Translated '.$texts[1], $row->track->cues[1]['translatedText']);
        $this->assertSame(1, $this->translationAnalysis->translationCalls);
        $this->assertSame(['auto'], $this->translationAnalysis->sourceLanguages);
    }

    public function test_non_latin_correction_rebuilds_romanization(): void
    {
        $texts = ['مرحبا بالعالم', 'أهلا وسهلا'];
        $queue = $this->completedTrackWithCues(2, fn (int $position): string => $texts[$position], ['include_translation' => false, 'include_romanization' => true]);
        LyricsAlignmentAgent::fake([['isMatch' => true, 'isComplete' => true, 'cues' => $queue['alignmentCues']]]);
        $response = $this->submitLyrics($queue['job'], $texts);

        $this->runCorrectionRevisions($queue['job'], $response->json('attemptId'));

        $row = $this->correctionRow($queue['job'], $response->json('attemptId'));
        $this->assertSame('completed', $row->status);
        $this->assertSame(1, $this->translationAnalysis->romanizationCalls);
        $this->assertStringStartsWith('romanized ', (string) $row->track->cues[0]['romanization']);
    }

    public function test_final_publication_is_atomic_and_stale_attempts_cannot_publish(): void
    {
        $queue = $this->completedTrackWithCues(3, fn (int $position): string => 'Lyrics line '.($position + 1));
        LyricsAlignmentAgent::fake([['isMatch' => true, 'isComplete' => true, 'cues' => $queue['alignmentCues']]]);
        $response = $this->submitLyrics($queue['job'], $queue['texts']);
        $service = app(LyricsCorrectionService::class);

        $this->runCorrectionRevisions($queue['job'], $response->json('attemptId'));

        $this->assertSame(2, $this->correctionRow($queue['job'], $response->json('attemptId'))->work_revision);
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
        LyricsAlignmentAgent::fake([['isMatch' => true, 'isComplete' => true, 'cues' => $queue['alignmentCues']]]);
        $response = $this->submitLyrics($queue['job'], $queue['texts']);
        $service = app(LyricsCorrectionService::class);

        (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $response->json('attemptId'), 0))->handle($service);

        $raw = DB::table('subtitle_track_lyrics_corrections')
            ->where('attempt_id', $response->json('attemptId'))
            ->first();
        $this->assertStringNotContainsString('Lyrics line 1', (string) $raw->lyrics);
        $this->assertStringNotContainsString('Lyrics line 1', (string) $raw->work_state);
        $this->assertStringNotContainsString('analyzing', (string) $raw->work_state);

        $row = $this->correctionRow($queue['job'], $response->json('attemptId'));
        $this->assertSame("Lyrics line 1\nLyrics line 2", $row->lyrics);
        $this->assertSame('analyzing', $row->work_state['stage']);
    }

    public function test_completed_and_failed_attempts_clear_lyrics_and_work_state(): void
    {
        $queue = $this->completedTrackWithCues(2, fn (int $position): string => 'Lyrics line '.($position + 1));
        LyricsAlignmentAgent::fake([['isMatch' => true, 'isComplete' => true, 'cues' => $queue['alignmentCues']]]);
        $response = $this->submitLyrics($queue['job'], $queue['texts']);

        $this->runCorrectionRevisions($queue['job'], $response->json('attemptId'));

        $completed = $this->correctionRow($queue['job'], $response->json('attemptId'));
        $this->assertSame('completed', $completed->status);
        $this->assertNull($completed->lyrics);
        $this->assertNull($completed->work_state);

        LyricsAlignmentAgent::fake([['isMatch' => false, 'isComplete' => false, 'cues' => []]])->preventStrayPrompts();
        $failedResponse = $this->submitLyrics($queue['job'], $queue['texts']);
        (new LyricsCorrectionJob($queue['job']->track->id, $queue['job']->id, $failedResponse->json('attemptId'), 0))->handle(app(LyricsCorrectionService::class));

        $failed = $this->correctionRow($queue['job'], $failedResponse->json('attemptId'));
        $this->assertSame('failed', $failed->status);
        $this->assertNull($failed->lyrics);
        $this->assertNull($failed->work_state);
    }

    public function test_the_per_unit_job_timeout_models_one_provider_call(): void
    {
        config([
            'subtitles.queue.connection' => 'database',
            'subtitles.enrichment.timeout_seconds' => 120,
            'subtitles.queue.worker_timeout_seconds' => 1200,
            'queue.connections.database.retry_after' => 1260,
        ]);

        $job = new LyricsCorrectionJob(1, 1, (string) Str::uuid(), 0);

        $this->assertSame(180, $job->timeout);
        $this->assertLessThan(config('subtitles.queue.worker_timeout_seconds'), $job->timeout);
        $this->assertLessThan(config('queue.connections.database.retry_after'), $job->timeout);
    }

    /**
     * @param  callable(int): string  $textAt
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
            'alignmentCues' => $this->pastedAlignmentCues($track->cues),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $cues
     * @return array<int, array<string, mixed>>
     */
    private function pastedAlignmentCues(array $cues): array
    {
        $partCursor = 0;
        $aligned = [];

        foreach (array_values($cues) as $cue) {
            $partCount = count(preg_split('/\s+/u', trim((string) $cue['sourceText'])) ?: []);
            $aligned[] = [
                'cueId' => $cue['cueId'],
                'index' => $cue['index'],
                'segments' => [[
                    'source' => 'pasted',
                    'startPartIndex' => $partCursor,
                    'endPartIndex' => $partCursor + $partCount - 1,
                    'separator' => '',
                ]],
            ];
            $partCursor += $partCount;
        }

        return $aligned;
    }

    public function test_invalid_alignment_fails_without_feedback_or_a_second_request(): void
    {
        $queue = $this->completedTrackWithCues(2, fn (int $position): string => 'Private line '.($position + 1));
        $prompts = [];
        LyricsAlignmentAgent::fake(function ($prompt) use (&$prompts, $queue): array {
            $prompts[] = json_decode($prompt, true);

            return count($prompts) === 1
                ? ['isMatch' => true, 'isComplete' => true, 'cues' => [['cueId' => 'bad-id', 'index' => 0, 'segments' => [['source' => 'pasted', 'startPartIndex' => 0, 'endPartIndex' => 2, 'separator' => '']]]]]
                : ['isMatch' => true, 'isComplete' => true, 'cues' => $queue['alignmentCues']];
        })->preventStrayPrompts();
        Log::spy();
        $response = $this->submitLyrics($queue['job'], ['[Chorus]', ...$queue['texts']]);
        $this->runCorrectionRevisions($queue['job'], $response->json('attemptId'));

        $this->assertSame("Private line 1\nPrivate line 2", implode('', array_column($prompts[0]['lyricsParts'], 'text')));
        $this->assertCount(1, $prompts);
        $this->assertArrayNotHasKey('validationFeedback', $prompts[0]);
        $this->assertSame('failed', $this->correctionRow($queue['job'], $response->json('attemptId'))->status);
        $this->assertSame('Private line 1', $queue['job']->track->fresh()->cues[0]['sourceText']);
    }

    public function test_invalid_alignment_retains_the_reason_and_preserves_the_track(): void
    {
        $queue = $this->completedTrackWithCues(2, fn (int $position): string => 'Private line '.($position + 1));
        LyricsAlignmentAgent::fake(fn (): array => ['isMatch' => true, 'isComplete' => true, 'cues' => [
            ['cueId' => 'cue-0001', 'index' => 0, 'segments' => [['source' => 'pasted', 'startPartIndex' => 0, 'endPartIndex' => 1000, 'separator' => '']]],
        ]])->preventStrayPrompts();
        Log::spy();
        $response = $this->submitLyrics($queue['job'], $queue['texts']);
        $this->runCorrectionRevisions($queue['job'], $response->json('attemptId'));

        $row = $this->correctionRow($queue['job'], $response->json('attemptId'));
        $this->assertSame('failed', $row->status);
        $this->assertSame(0, $row->work_revision);
        $this->assertNull($row->lyrics);
        $this->assertNull($row->work_state);
        $this->assertSame($queue['texts'], array_column($row->track->cues, 'sourceText'));
        Log::shouldHaveReceived('warning')->with('backend.lyrics_correction_unit_failed', \Mockery::on(fn (array $context): bool => $context['reason'] === 'invalid_part_boundary' && $context['stage'] === 'aligning'
        ))->once();
    }

    public function test_overlong_punjabi_alignment_splits_inside_original_timing_without_another_ai_call(): void
    {
        $queue = $this->completedTrackWithCues(3, fn (): string => 'Timing evidence only');
        $lyrics = trim(str_repeat('ਕਿਤਾਬ ', 30));
        $calls = 0;
        LyricsAlignmentAgent::fake(function ($prompt) use (&$calls): array {
            $calls++;
            $input = json_decode($prompt, true);

            return ['isMatch' => true, 'isComplete' => true, 'cues' => array_map(
                fn (array $range, int $index): array => [
                    'cueId' => $input['cues'][$index]['cueId'],
                    'segments' => [['source' => 'pasted', 'endPartIndex' => $range[1]]],
                ],
                [[0, 19], [20, 29]],
                [0, 1],
            )];
        })->preventStrayPrompts();
        $response = $this->submitLyrics($queue['job'], [$lyrics]);
        $this->runCorrectionRevisions($queue['job'], $response->json('attemptId'));

        $row = $this->correctionRow($queue['job'], $response->json('attemptId'));
        $cues = $row->track->cues;
        $this->assertSame('completed', $row->status);
        $this->assertSame(1, $calls);
        $this->assertSame($lyrics, implode(' ', array_column($cues, 'sourceText')));
        $this->assertSame([83, 35, 59], array_map(fn (array $cue): int => mb_strlen($cue['sourceText'], 'UTF-8'), $cues));
        $this->assertSame(0, $cues[0]['startMs']);
        $this->assertGreaterThan(0, $cues[0]['endMs']);
        $this->assertSame($cues[0]['endMs'], $cues[1]['startMs']);
        $this->assertLessThan(4000, $cues[1]['startMs']);
        $this->assertSame(4000, $cues[1]['endMs']);
        $this->assertSame(4000, $cues[2]['startMs']);
        $this->assertSame(8000, $cues[2]['endMs']);
        $this->assertSame([0, 1, 2], array_column($cues, 'index'));
        $this->assertCount(3, array_unique(array_column($cues, 'cueId')));
    }

    public function test_overlong_alignment_with_insufficient_time_preserves_original_track(): void
    {
        $queue = $this->completedTrackWithCues(2, fn (): string => 'Timing evidence only');
        $originalCues = $queue['job']->track->cues;
        $originalCues[0]['endMs'] = 1;
        $queue['job']->track->update(['cues' => $originalCues]);
        LyricsAlignmentAgent::fake(fn (): array => ['isMatch' => true, 'isComplete' => true, 'cues' => [
            ['cueId' => 'cue-0001', 'index' => 0, 'segments' => [['source' => 'pasted', 'startPartIndex' => 0, 'endPartIndex' => 119, 'separator' => '']]],
        ]])->preventStrayPrompts();
        $response = $this->submitLyrics($queue['job'], [str_repeat('字', 120)]);
        $this->runCorrectionRevisions($queue['job'], $response->json('attemptId'));

        $row = $this->correctionRow($queue['job'], $response->json('attemptId'));
        $this->assertSame('failed', $row->status);
        $this->assertSame($originalCues, $row->track->cues);
        $this->assertNull($row->lyrics);
        $this->assertNull($row->work_state);
    }

    public function test_complete_alignment_derives_starts_even_when_partial_replacement_is_allowed(): void
    {
        $queue = $this->completedTrackWithCues(2, fn (int $position): string => 'Lyrics line '.($position + 1));
        $alignment = $queue['alignmentCues'];
        foreach ($alignment as &$cue) {
            unset($cue['index']);
            $cue['segments'][0]['startPartIndex'] = 999;
            $cue['segments'][0]['separator'] = ' ';
        }
        unset($cue);
        LyricsAlignmentAgent::fake([['isMatch' => true, 'isComplete' => true, 'cues' => $alignment]])->preventStrayPrompts();
        $response = $this->submitLyrics($queue['job'], $queue['texts'], allowPartial: true);
        $this->runCorrectionRevisions($queue['job'], $response->json('attemptId'));

        $row = $this->correctionRow($queue['job'], $response->json('attemptId'));
        $this->assertSame('completed', $row->status);
        $this->assertSame($queue['texts'], array_column($row->track->cues, 'sourceText'));
    }

    public function test_alignment_copies_every_hindi_word_and_punctuation_from_the_paste(): void
    {
        $queue = $this->completedTrackWithCues(2, fn (): string => 'Timing evidence only');
        $lines = ['“मन की बात,” फिर मन की बात।', 'और वही बात — फिर से!'];
        LyricsAlignmentAgent::fake([['isMatch' => true, 'isComplete' => true, 'cues' => [
            ['cueId' => 'cue-0001', 'index' => 0, 'segments' => [['source' => 'pasted', 'startPartIndex' => 0, 'endPartIndex' => 6, 'separator' => '']]],
            ['cueId' => 'cue-0002', 'index' => 1, 'segments' => [['source' => 'pasted', 'startPartIndex' => 7, 'endPartIndex' => 12, 'separator' => '']]],
        ]]])->preventStrayPrompts();
        $response = $this->submitLyrics($queue['job'], $lines);
        $this->runCorrectionRevisions($queue['job'], $response->json('attemptId'));
        $row = $this->correctionRow($queue['job'], $response->json('attemptId'));
        $this->assertSame('completed', $row->status);
        $this->assertSame($lines, array_column($row->track->cues, 'sourceText'));
    }

    public function test_alignment_rejects_reversed_out_of_range_and_unconsumed_part_boundaries(): void
    {
        foreach ([[2, 1], [2, 8], [1, 4]] as [$first, $last]) {
            $queue = $this->completedTrackWithCues(2, fn (int $position): string => 'Lyrics line '.($position + 1));
            LyricsAlignmentAgent::fake(fn (): array => ['isMatch' => true, 'isComplete' => true, 'cues' => [
                ['cueId' => 'cue-0001', 'index' => 0, 'segments' => [['source' => 'pasted', 'startPartIndex' => 0, 'endPartIndex' => $first, 'separator' => '']]],
                ['cueId' => 'cue-0002', 'index' => 1, 'segments' => [['source' => 'pasted', 'startPartIndex' => $first + 1, 'endPartIndex' => $last, 'separator' => '']]],
            ]])->preventStrayPrompts();
            $response = $this->submitLyrics($queue['job'], $queue['texts']);
            $this->runCorrectionRevisions($queue['job'], $response->json('attemptId'));
            $this->assertSame('failed', $this->correctionRow($queue['job'], $response->json('attemptId'))->status);
            $this->assertSame('cue-0001', $queue['job']->track->fresh()->cues[0]['cueId']);
        }
    }

    public function test_unspaced_lyrics_split_only_between_grapheme_clusters(): void
    {
        $queue = $this->completedTrackWithCues(2, fn (): string => 'Timing evidence');
        $grapheme = 'ก้';
        $lyrics = str_repeat($grapheme, 60);
        LyricsAlignmentAgent::fake([['isMatch' => true, 'isComplete' => true, 'cues' => [
            ['cueId' => 'cue-0001', 'index' => 0, 'segments' => [['source' => 'pasted', 'startPartIndex' => 0, 'endPartIndex' => 59, 'separator' => '']]],
        ]]])->preventStrayPrompts();
        $response = $this->submitLyrics($queue['job'], [$lyrics]);
        $this->runCorrectionRevisions($queue['job'], $response->json('attemptId'));
        $row = $this->correctionRow($queue['job'], $response->json('attemptId'));
        $this->assertSame('completed', $row->status);
        $this->assertSame([str_repeat($grapheme, 42), str_repeat($grapheme, 18)], array_column($row->track->cues, 'sourceText'));
    }

    public function test_submission_requires_current_track_identity_and_rejects_impossible_capacity(): void
    {
        $queue = $this->completedTrackWithCues(1, fn (): string => 'Original');
        $url = '/v1/subtitle-jobs/'.$queue['job']->public_id.'/lyrics';
        $this->withExtensionAuth($this->installId(), $queue['job']->user);
        $this->postJson($url, ['lyrics' => 'New lyrics'])->assertUnprocessable();
        $this->postJson($url, ['expectedTrackId' => (string) Str::uuid(), 'lyrics' => 'New lyrics'])->assertConflict();
        $this->postJson($url, ['expectedTrackId' => $queue['job']->track->public_id, 'lyrics' => str_repeat('字', 85)])->assertUnprocessable();
        $this->assertDatabaseCount('subtitle_track_lyrics_corrections', 0);
        Queue::assertNotPushed(LyricsCorrectionJob::class);
    }

    public function test_final_publication_rechecks_entitlement_expiry_and_source_identity(): void
    {
        foreach (['entitlement', 'expiry', 'track', 'run'] as $change) {
            $queue = $this->completedTrackWithCues(2, fn (int $position): string => 'Lyrics line '.($position + 1));
            LyricsAlignmentAgent::fake([['isMatch' => true, 'isComplete' => true, 'cues' => $queue['alignmentCues']]])->preventStrayPrompts();
            $response = $this->submitLyrics($queue['job'], $queue['texts']);
            $service = app(LyricsCorrectionService::class);
            $track = $queue['job']->track;
            $attempt = $response->json('attemptId');
            $service->process($track->id, $attempt, 0);
            $this->assertSame('analyzing', $this->correctionRow($queue['job'], $attempt)->work_state['stage']);
            match ($change) {
                'entitlement' => $queue['job']->user->update(['billing_subscription_status' => 'past_due']),
                'expiry' => $track->update(['expires_at' => now()->subSecond()]),
                'track' => $track->update(['public_id' => (string) Str::uuid()]),
                'run' => $queue['job']->update(['run_id' => (string) Str::uuid()]),
            };
            $service->process($track->id, $attempt, 1, 0);
            $this->assertSame('failed', $this->correctionRow($queue['job'], $attempt)->status, $change);
            $this->assertSame('cue-0001', $track->fresh()->cues[0]['cueId'], $change);
        }
    }

    public function test_a_track_change_during_provider_work_discards_the_response(): void
    {
        $queue = $this->completedTrackWithCues(2, fn (int $position): string => 'Lyrics line '.($position + 1));
        LyricsAlignmentAgent::fake([['isMatch' => true, 'isComplete' => true, 'cues' => $queue['alignmentCues']]])->preventStrayPrompts();
        $response = $this->submitLyrics($queue['job'], $queue['texts']);
        $track = $queue['job']->track;
        $newIdentity = (string) Str::uuid();
        $this->translationAnalysis->beforeTokenizationResult = function () use ($track, $newIdentity): void {
            $track->update(['public_id' => $newIdentity]);
        };
        $this->runCorrectionRevisions($queue['job'], $response->json('attemptId'));

        $row = $this->correctionRow($queue['job'], $response->json('attemptId'));
        $this->assertSame('failed', $row->status);
        $this->assertSame(1, $row->work_revision);
        $this->assertSame($newIdentity, $track->fresh()->public_id);
        $this->assertSame('cue-0001', $track->fresh()->cues[0]['cueId']);
        $this->assertNull($row->work_state);
    }

    public function test_missing_continuation_is_recovered_and_duplicate_delivery_is_harmless(): void
    {
        $queue = $this->completedTrackWithCues(2, fn (int $position): string => 'Lyrics line '.($position + 1));
        LyricsAlignmentAgent::fake([['isMatch' => true, 'isComplete' => true, 'cues' => $queue['alignmentCues']]])->preventStrayPrompts();
        $response = $this->submitLyrics($queue['job'], $queue['texts']);
        $service = app(LyricsCorrectionService::class);
        $attempt = $response->json('attemptId');
        $service->process($queue['job']->track->id, $attempt, 0);
        Queue::fake(); // Discard the continuation, as if delivery was lost after commit.
        $row = $this->correctionRow($queue['job'], $attempt);
        $row->forceFill(['updated_at' => now()->subHours(2)])->saveQuietly();
        $this->artisan('subtitles:fail-stalled-jobs')->assertSuccessful();
        Queue::assertPushed(LyricsCorrectionJob::class, fn ($job): bool => $job->attemptId === $attempt && $job->expectedRevision === 1);
        $this->assertSame(1, $row->fresh()->work_revision);
        $this->runCorrectionRevisions($queue['job'], $attempt, 1);
        $service->process($queue['job']->track->id, $attempt, 1);
        $this->assertSame('completed', $row->fresh()->status);
        $this->assertSame(1, $this->translationAnalysis->tokenizationCalls);
    }

    /**
     * @param  array<int, int>  $positions
     */
    private function alignmentForPositions(array $queue, array $positions): array
    {
        $partCursor = 0;
        $aligned = [];
        foreach ($positions as $position) {
            $partCount = count(preg_split('/\s+/u', trim($queue['texts'][$position])) ?: []);
            $aligned[] = [
                ...$queue['alignmentCues'][$position],
                'segments' => [[
                    'source' => 'pasted',
                    'startPartIndex' => $partCursor,
                    'endPartIndex' => $partCursor + $partCount - 1,
                    'separator' => '',
                ]],
            ];
            $partCursor += $partCount;
        }

        return $aligned;
    }

    /**
     * @param  array<int, string>  $texts
     */
    private function submitLyrics(SubtitleJob $job, array $texts, bool $allowPartial = false): TestResponse
    {
        $job->refresh()->load('track');

        return $this->withExtensionAuth($this->installId(), $job->user)
            ->postJson('/v1/subtitle-jobs/'.$job->public_id.'/lyrics', [
                'expectedTrackId' => $job->track->public_id,
                'lyrics' => implode("\n", $texts),
                'allowPartial' => $allowPartial,
            ]);
    }

    private function runCorrectionRevisions(SubtitleJob $job, string $attemptId, int $startRevision = 0, int $maxRevisions = 60): void
    {
        $service = app(LyricsCorrectionService::class);

        for ($revision = $startRevision; $revision < $maxRevisions; $revision++) {
            $row = $this->correctionRow($job, $attemptId);

            if (in_array($row->status, ['completed', 'failed', 'cancelled'], true)) {
                return;
            }

            (new LyricsCorrectionJob($job->track->id, $job->id, $attemptId, $row->work_revision, ($row->work_state['stage'] ?? null) === 'analyzing' ? $row->work_state['batchIndex'] : null))->handle($service);
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
