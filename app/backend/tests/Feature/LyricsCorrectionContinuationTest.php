<?php

namespace Tests\Feature;

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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Ai\Exceptions\RateLimitedException;
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
            'subtitles.costs.openai_tokenization_microusd_per_cue' => 10,
            'subtitles.costs.openai_translation_microusd_per_cue' => 5,
            'subtitles.costs.openai_romanization_microusd_per_cue' => 5,
            'subtitles.costs.openai_enrichment_microusd_per_cue' => 5,
        ]);
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
        $this->assertSame(30, $queue['job']->refresh()->estimated_provider_cost_microusd, 'A duplicate delivery must not record cost twice.');
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

            if (in_array($row->status, ['completed', 'failed'], true)) {
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
}
