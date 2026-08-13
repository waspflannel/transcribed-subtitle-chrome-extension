<?php

namespace Tests\Feature;

use App\Ai\Agents\LyricsAlignmentAgent;
use App\Exceptions\SubtitleProcessingException;
use App\Jobs\AcquireSubtitleAudio;
use App\Jobs\AnalyzeSubtitleCueBatch;
use App\Jobs\EnrichSubtitleCueBatch;
use App\Jobs\LyricsCorrectionJob;
use App\Jobs\OptimizeSubtitleAudio;
use App\Jobs\RomanizeSubtitleCueBatch;
use App\Jobs\TokenizeSubtitleCueBatch;
use App\Jobs\TranscribeSubtitleAudioChunk;
use App\Models\CachedVideoTranscript;
use App\Models\SubtitleJob;
use App\Models\SubtitleJobEvent;
use App\Models\SubtitleTrack;
use App\Models\User;
use App\Services\Audio\ElevenLabsScribeAudioPreparer;
use App\Services\Audio\SubtitleAudioWorkspace;
use App\Services\Audio\TemporaryAudioFile;
use App\Services\Audio\YouTubeAudioSource;
use App\Services\Subtitles\LyricsCorrectionService;
use App\Services\Subtitles\SubtitleCueBatchProcessor;
use App\Services\Subtitles\SubtitleGenerationPipeline;
use App\Services\Subtitles\SubtitleJobArtifactStore;
use App\Services\Subtitles\SubtitleJobFailureHandler;
use App\Services\Subtitles\SubtitleJobService;
use App\Services\Subtitles\SubtitleQueue;
use App\Services\Transcription\ElevenLabsScribeTranscriptionService;
use App\Services\Transcription\ScribeChunkPayloadMerger;
use App\Services\Transcription\ScribeTranscriptNormalizer;
use App\Services\Transcription\TimestampedTranscript;
use App\Services\Transcription\TimestampedTranscriptSegment;
use App\Services\TranslationAnalysis\CueAnalysisBatchResult;
use App\Services\TranslationAnalysis\CueEnrichmentResult;
use App\Services\TranslationAnalysis\LaravelAiTranslationAnalysisProvider;
use App\Services\TranslationAnalysis\LearningTokenOutputValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class SubtitleJobApiTest extends TestCase
{
    use RefreshDatabase;

    private RecordingYouTubeAudioSource $audioSource;

    private RecordingTranscriptionService $transcriptionService;

    private RecordingTranslationAnalysisProvider $translationAnalysis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->audioSource = new RecordingYouTubeAudioSource;
        $this->transcriptionService = new RecordingTranscriptionService;
        $this->translationAnalysis = new RecordingTranslationAnalysisProvider;

        $this->app->instance(YouTubeAudioSource::class, $this->audioSource);
        $this->app->instance(ElevenLabsScribeTranscriptionService::class, $this->transcriptionService);
        $this->app->instance(LaravelAiTranslationAnalysisProvider::class, $this->translationAnalysis);
        config([
            'queue.default' => 'sync',
            'subtitles.queue.connection' => 'sync',
            'subtitles.tiers.default' => 'base',
            'subtitles.tiers.plans.base.generation_concurrency' => 20,
            'subtitles.tiers.plans.base.batch_concurrency' => 20,
            'billing.plans.base.features.full_word_cards' => true,
        ]);
    }

    public function test_new_subtitle_request_returns_running_job_and_dispatches_processing(): void
    {
        config([
            'queue.default' => 'sync',
            'subtitles.queue.connection' => 'sync',
        ]);
        Queue::fake();

        $response = $this
            ->withExtensionAuth($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload());

        $response
            ->assertAccepted()
            ->assertJsonPath('status', 'running')
            ->assertJsonPath('stage', 'preparing')
            ->assertJsonPath('progressPercent', 5)
            ->assertJsonMissingPath('track')
            ->assertJsonStructure(['jobId', 'status', 'stage', 'progressPercent', 'createdAt', 'updatedAt']);

        Queue::assertPushedOn(SubtitleQueue::generationName(), AcquireSubtitleAudio::class);
    }

    public function test_duplicate_running_request_reuses_job_without_dispatching_duplicate_work(): void
    {
        config([
            'queue.default' => 'database',
            'subtitles.queue.connection' => 'database',
        ]);
        Queue::fake();

        $first = $this
            ->withExtensionAuth($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload())
            ->assertAccepted();

        $second = $this
            ->withExtensionAuth($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload())
            ->assertAccepted();

        $this->assertSame($first->json('jobId'), $second->json('jobId'));
        Queue::assertPushed(AcquireSubtitleAudio::class, 1);
    }

    public function test_new_subtitle_request_uses_configured_subtitle_queue_connection(): void
    {
        config([
            'queue.default' => 'database',
            'subtitles.queue.connection' => 'background',
        ]);
        Queue::fake();

        $this
            ->withExtensionAuth($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload(['youtubeVideoId' => 'bgqueue0001']))
            ->assertAccepted();

        Queue::assertPushed(AcquireSubtitleAudio::class, function (AcquireSubtitleAudio $job): bool {
            return $job->connection === 'background'
                && $job->queue === SubtitleQueue::generationName();
        });
    }

    public function test_new_subtitle_request_uses_configured_generation_tier_queue(): void
    {
        config([
            'queue.default' => 'database',
            'subtitles.queue.connection' => 'database',
            'subtitles.tiers.default' => 'pro',
            'subtitles.tiers.plans.pro.generation_queue' => 'subtitle-generation-pro',
            'billing.plans.base.generation_tier' => 'pro',
        ]);
        Queue::fake();

        $response = $this
            ->withExtensionAuth($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload(['youtubeVideoId' => 'tierqueue01']))
            ->assertAccepted();

        $this->assertDatabaseHas('subtitle_jobs', [
            'public_id' => $response->json('jobId'),
            'generation_tier' => 'pro',
        ]);
        Queue::assertPushed(AcquireSubtitleAudio::class, function (AcquireSubtitleAudio $job): bool {
            return $job->connection === 'database'
                && $job->queue === 'subtitle-generation-pro';
        });
    }

    public function test_queue_name_uses_job_generation_tier_not_current_default(): void
    {
        config([
            'subtitles.tiers.default' => 'base',
            'subtitles.tiers.plans.pro.generation_queue' => 'subtitle-generation-pro',
        ]);

        $job = SubtitleJob::factory()->make(['generation_tier' => 'pro']);

        $this->assertSame('subtitle-generation-pro', SubtitleQueue::generationNameForJob($job));
    }

    public function test_queue_retry_after_defaults_exceed_subtitle_worker_timeout(): void
    {
        $runId = (string) Str::uuid();
        $audio = new TemporaryAudioFile('unused-path', 'unused-directory', 1, 1, 'audio/flac');
        $maxStageTimeout = max(
            (new AcquireSubtitleAudio(1, $runId))->timeout,
            (new OptimizeSubtitleAudio(1, $runId, $audio))->timeout,
            (new TranscribeSubtitleAudioChunk(1, 0, 1, $runId, $audio, 0.0, 0.0, null))->timeout,
        );

        $this->assertGreaterThan($maxStageTimeout, config('queue.connections.database.retry_after'));
        $this->assertGreaterThan($maxStageTimeout, config('queue.connections.redis.retry_after'));
    }

    public function test_stale_preparing_request_reuses_job_and_dispatches_processing_again(): void
    {
        config([
            'queue.default' => 'database',
            'subtitles.queue.connection' => 'database',
            'subtitles.queue.stale_preparing_seconds' => 60,
        ]);
        Queue::fake();

        $installId = $this->installId();
        $staleJob = SubtitleJob::factory()->create([
            'public_id' => (string) Str::uuid(),
            'youtube_video_id' => 'stalejob001',
            'youtube_url' => 'https://www.youtube.com/watch?v=stalejob001',
            'install_id' => $installId,
            'processing_version' => SubtitleJobService::processingVersionFor('on_demand', true, false),
            'enrichment_mode' => 'on_demand',
            'include_romanization' => true,
            'include_translation' => false,
            'status' => 'running',
            'stage' => 'preparing',
            'progress_percent' => 5,
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);

        $response = $this
            ->withExtensionAuth($installId)
            ->postJson('/v1/subtitle-jobs', $this->validPayload(['youtubeVideoId' => 'stalejob001']))
            ->assertAccepted();

        $this->assertSame($staleJob->public_id, $response->json('jobId'));
        $this->assertTrue($staleJob->fresh()->created_at->greaterThan($staleJob->created_at));
        Queue::assertPushed(AcquireSubtitleAudio::class, 1);
    }

    public function test_translation_enabled_jobs_dispatch_one_merged_analysis_batch_job(): void
    {
        config([
            'queue.default' => 'database',
            'subtitles.queue.connection' => 'database',
        ]);

        $this
            ->withExtensionAuth($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload([
                'youtubeVideoId' => 'translate01',
                'includeTranslation' => true,
            ]))
            ->assertAccepted();

        // Generation now runs as chained stage jobs (acquire -> optimize ->
        // chunk transcribe -> merge), so work the queue until the merge stage
        // has dispatched the analysis batch.
        $payloads = '';

        for ($iteration = 0; $iteration < 10; $iteration++) {
            Artisan::call('queue:work', [
                '--queue' => SubtitleQueue::workerQueueList().',default',
                '--once' => true,
                '--tries' => 1,
                '--sleep' => 0,
            ]);

            $payloads = DB::table('jobs')->pluck('payload')->implode("\n");

            if (str_contains($payloads, addslashes(AnalyzeSubtitleCueBatch::class))) {
                break;
            }
        }

        // One merged tokenize+translate job per batch replaces the former
        // separate tokenize and translate jobs.
        $this->assertStringContainsString(addslashes(AnalyzeSubtitleCueBatch::class), $payloads);
        $this->assertStringNotContainsString(addslashes(TokenizeSubtitleCueBatch::class), $payloads);
        $this->assertSame(1, DB::table('jobs')->where('queue', SubtitleQueue::batchName())->count());
    }

    public function test_cancelled_tokenization_batch_skips_provider_calls(): void
    {
        $job = $this->runningSubtitleJob('tokenizing');
        $this->artifacts()->putCueCollection($job, SubtitleJobArtifactStore::DRAFT_CUES, [$this->sampleCue()]);

        $this->dispatchCancelledBatch(new TokenizeSubtitleCueBatch($job->id, 0, $job->run_id));

        $this->assertSame(0, $this->translationAnalysis->tokenizationCalls);
    }

    public function test_cancelled_analysis_batch_skips_provider_calls(): void
    {
        $job = $this->runningSubtitleJob('tokenizing');
        $this->artifacts()->putCueCollection($job, SubtitleJobArtifactStore::DRAFT_CUES, [$this->sampleCue()]);

        $this->dispatchCancelledBatch(new AnalyzeSubtitleCueBatch($job->id, 0, $job->run_id));

        $this->assertSame(0, $this->translationAnalysis->translationCalls);
        $this->assertSame(0, $this->translationAnalysis->tokenizationCalls);
    }

    public function test_cancelled_romanization_batch_skips_provider_calls(): void
    {
        $job = $this->runningSubtitleJob('romanizing');
        $this->artifacts()->putCueBatchResult(
            $job,
            SubtitleJobArtifactStore::TOKENIZED_CUES,
            0,
            new CueEnrichmentResult([$this->sampleCue()], 'unknown'),
        );

        $this->dispatchCancelledBatch(new RomanizeSubtitleCueBatch($job->id, 0, $job->run_id));

        $this->assertSame(0, $this->translationAnalysis->romanizationCalls);
    }

    public function test_cancelled_enrichment_batch_skips_provider_calls(): void
    {
        $job = $this->runningSubtitleJob('enriching');
        $this->artifacts()->putCueCollection($job, SubtitleJobArtifactStore::MERGED_CUES, [$this->sampleCue()]);

        $this->dispatchCancelledBatch(new EnrichSubtitleCueBatch($job->id, 0, $job->run_id));

        $this->assertSame(0, $this->translationAnalysis->calls);
    }

    public function test_transcription_processor_ignores_jobs_already_claimed_by_another_worker(): void
    {
        $job = SubtitleJob::factory()->create([
            'stage' => 'acquiring-audio',
            'progress_percent' => 20,
        ]);

        app(SubtitleGenerationPipeline::class)->acquireAudioAndContinue($job->id, $job->run_id);

        $this->assertSame(0, $this->audioSource->calls);
    }

    public function test_generation_runs_audio_optimization_before_scribe_transcription(): void
    {
        $this->transcriptionService->beforePrepareResult = function (TemporaryAudioFile $audio): void {
            $this->assertSame('audio/mp4', $audio->mimeType);
            $this->assertDatabaseHas('subtitle_jobs', [
                'youtube_video_id' => 'dQw4w9WgXcQ',
                'status' => 'running',
                'stage' => 'optimizing-audio',
                'progress_percent' => 35,
            ]);
        };
        $this->transcriptionService->beforeTranscriptionResult = function (TemporaryAudioFile $audio): void {
            $this->assertSame('audio/mp4', $audio->mimeType);
            $this->assertDatabaseHas('subtitle_jobs', [
                'youtube_video_id' => 'dQw4w9WgXcQ',
                'status' => 'running',
                'stage' => 'transcribing',
                'progress_percent' => 50,
            ]);
        };

        $this
            ->withExtensionAuth($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload())
            ->assertOk()
            ->assertJsonPath('status', 'completed');

        $this->assertSame(1, $this->transcriptionService->prepareCalls);
        $this->assertSame(['auto'], $this->transcriptionService->sourceLanguages);
    }

    public function test_audio_acquisition_return_does_not_resurrect_a_deleted_job_or_dispatch_optimization(): void
    {
        config([
            'queue.default' => 'database',
            'subtitles.queue.connection' => 'database',
        ]);
        Queue::fake();
        $response = $this
            ->withExtensionAuth($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload(['youtubeVideoId' => 'deleted0001']))
            ->assertAccepted();
        $job = SubtitleJob::query()->where('public_id', $response->json('jobId'))->firstOrFail();
        $this->audioSource->beforeAcquireResult = function () use ($job): void {
            $job->delete();
        };

        app(SubtitleGenerationPipeline::class)->acquireAudioAndContinue($job->id, $job->run_id);

        $this->assertModelMissing($job);
        $this->assertFalse(File::exists((string) $this->audioSource->lastAudioPath));
        Queue::assertNotPushed(OptimizeSubtitleAudio::class);
    }

    public function test_audio_preparation_return_does_not_resurrect_a_failed_job_or_dispatch_chunks(): void
    {
        Bus::fake();
        $job = $this->runningSubtitleJob('optimizing-audio');
        $directory = SubtitleAudioWorkspace::directory($job->run_id);
        File::ensureDirectoryExists($directory);
        $path = $directory.DIRECTORY_SEPARATOR.'prepared.m4a';
        File::put($path, 'fake-audio');
        $audio = new TemporaryAudioFile($path, $directory, 42, File::size($path), 'audio/mp4');
        $this->transcriptionService->beforePrepareResult = function () use ($job): void {
            $job->forceFill(['status' => 'failed'])->save();
        };

        app(SubtitleGenerationPipeline::class)->optimizeAudioAndDispatchTranscription(
            $job->id,
            $job->run_id,
            $audio,
        );

        $this->assertSame('failed', $job->fresh()->status);
        $this->assertSame('optimizing-audio', $job->fresh()->stage);
        $this->assertFalse(File::exists($directory));
        Bus::assertNothingBatched();
    }

    public function test_transcription_return_cannot_write_an_artifact_after_failure_wins(): void
    {
        $job = $this->runningSubtitleJob('transcribing');
        $directory = SubtitleAudioWorkspace::directory($job->run_id);
        File::ensureDirectoryExists($directory);
        $path = $directory.DIRECTORY_SEPARATOR.'chunk.m4a';
        File::put($path, 'fake-audio');
        $audio = new TemporaryAudioFile($path, $directory, 42, File::size($path), 'audio/mp4');
        $this->transcriptionService->beforeTranscriptionResult = function () use ($job): void {
            app(SubtitleJobFailureHandler::class)->failJob(
                $job->id,
                'transcribing',
                SubtitleProcessingException::transcriptionFailed(),
                $job->run_id,
            );
        };

        app(SubtitleGenerationPipeline::class)->transcribeAudioChunk(
            subtitleJobId: $job->id,
            runId: $job->run_id,
            chunkIndex: 0,
            chunkCount: 1,
            chunkAudio: $audio,
            audioStartSeconds: 0,
            nominalStartSeconds: 0,
            nominalEndSeconds: null,
        );

        $this->assertSame('failed', $job->fresh()->status);
        $this->assertDatabaseMissing('subtitle_job_artifacts', [
            'subtitle_job_id' => $job->id,
        ]);
    }

    public function test_late_batch_result_does_not_recreate_artifacts_after_job_failure(): void
    {
        $job = $this->runningSubtitleJob('tokenizing');
        $this->artifacts()->putCueCollection($job, SubtitleJobArtifactStore::DRAFT_CUES, [$this->sampleCue()]);
        $this->translationAnalysis->beforeTokenizationResult = function () use ($job): void {
            app(SubtitleJobFailureHandler::class)->failJob(
                $job->id,
                'tokenizing',
                SubtitleProcessingException::enrichmentFailed(),
                $job->run_id,
            );
        };

        app(SubtitleCueBatchProcessor::class)->tokenizeCueBatch($job->id, 0, $job->run_id);

        $this->assertDatabaseMissing('subtitle_job_artifacts', [
            'subtitle_job_id' => $job->id,
        ]);
    }

    public function test_default_generation_returns_transcript_first_track_without_full_card_enrichment(): void
    {
        $response = $this
            ->withExtensionAuth($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload());

        $response
            ->assertOk()
            ->assertJsonPath('youtubeVideoId', 'dQw4w9WgXcQ')
            ->assertJsonPath('track.youtubeVideoId', 'dQw4w9WgXcQ')
            ->assertJsonPath('track.cues.0.startMs', 500)
            ->assertJsonPath('track.cues.0.endMs', 2100)
            ->assertJsonPath('track.cues.0.sourceText', 'first transcript segment')
            ->assertJsonPath('track.cues.0.translatedText', 'first transcript segment')
            ->assertJsonPath('track.cues.0.tokens.0.text', 'first')
            ->assertJsonPath('track.cues.0.tokens.0.normalizedText', 'first')
            ->assertJsonPath('track.webVtt', $this->sampleWebVtt())
            ->assertJsonStructure($this->completedJobShape());

        $this->assertSame(1, SubtitleJob::count());
        $this->assertSame(1, SubtitleTrack::count());
        $this->assertDatabaseHas('subtitle_jobs', [
            'youtube_video_id' => 'dQw4w9WgXcQ',
            'source_language' => 'auto',
            'detected_source_language' => 'spa',
            'target_language' => 'eng',
            'status' => 'completed',
            'stage' => 'finalizing',
            'progress_percent' => 100,
        ]);
        $this->assertFalse(File::exists($this->audioSource->lastAudioPath));
        $this->assertSame(1, $this->translationAnalysis->tokenizationCalls);
        $this->assertSame(0, $this->translationAnalysis->calls);
        $this->assertSame(0, $this->translationAnalysis->romanizationCalls);
        $this->assertSame(0, $this->translationAnalysis->translationCalls);
    }

    public function test_completed_track_accepts_pasted_lyrics_and_queues_one_correction(): void
    {
        config([
            'queue.default' => 'sync',
            'subtitles.queue.connection' => 'sync',
        ]);
        $jobResponse = $this->withExtensionAuth($this->installId())->postJson('/v1/subtitle-jobs', $this->validPayload())->assertOk();
        $job = SubtitleJob::query()->where('public_id', $jobResponse->json('jobId'))->firstOrFail();
        Queue::fake();

        $response = $this->withExtensionAuth($this->installId())->postJson('/v1/subtitle-jobs/'.$job->public_id.'/lyrics', [
            'lyrics' => "first transcript segment\nsecond transcript segment",
        ]);

        $response->assertAccepted()->assertJsonPath('status', 'queued')->assertJsonStructure(['attemptId', 'status', 'updatedAt']);
        Queue::assertPushed(LyricsCorrectionJob::class, fn (LyricsCorrectionJob $queued): bool => $queued->trackId === $job->track->id && $queued->subtitleJobId === $job->id && $queued->attemptId === $response->json('attemptId'));
        $this->assertDatabaseMissing('subtitle_track_lyrics_corrections', ['lyrics' => 'first transcript segment second transcript segment']);
    }

    public function test_correction_rebuilds_track_atomically_and_clears_lyrics(): void
    {
        LyricsAlignmentAgent::fake([
            ['isMatch' => true, 'cues' => [
                ['cueId' => 'cue-0001', 'index' => 0, 'sourceText' => 'First lyric line'],
                ['cueId' => 'cue-0002', 'index' => 1, 'sourceText' => 'Second lyric line'],
            ]],
        ]);
        $jobResponse = $this->withExtensionAuth($this->installId())->postJson('/v1/subtitle-jobs', $this->validPayload())->assertOk();
        $job = SubtitleJob::query()->where('public_id', $jobResponse->json('jobId'))->firstOrFail();
        $oldTrackId = $job->track->public_id;
        $correction = $this->withExtensionAuth($this->installId())->postJson('/v1/subtitle-jobs/'.$job->public_id.'/lyrics', [
            'lyrics' => "First lyric line\nSecond lyric line",
        ])->assertAccepted();

        (new LyricsCorrectionJob($job->track->id, $job->id, $correction->json('attemptId')))->handle(app(LyricsCorrectionService::class));

        $job->refresh()->load('track');
        $this->assertNotSame($oldTrackId, $job->track->public_id);
        $this->assertSame('First lyric line', $job->track->cues[0]['sourceText']);
        $this->assertSame('lyrics-'.substr(str_replace('-', '', $correction->json('attemptId')), 0, 8).'-0001', $job->track->cues[0]['cueId']);
        $this->assertStringContainsString('First lyric line', $job->track->web_vtt);
        $this->assertNull($job->track->lyricsCorrection->lyrics);
        $this->assertSame('completed', $job->track->lyricsCorrection->status);
    }

    public function test_correction_status_is_owner_scoped_and_does_not_expose_lyrics(): void
    {
        $jobResponse = $this->withExtensionAuth($this->installId())->postJson('/v1/subtitle-jobs', $this->validPayload())->assertOk();
        $job = SubtitleJob::query()->where('public_id', $jobResponse->json('jobId'))->firstOrFail();
        $correction = $this->withExtensionAuth($this->installId())->postJson('/v1/subtitle-jobs/'.$job->public_id.'/lyrics', [
            'lyrics' => 'first transcript segment second transcript segment',
        ])->assertAccepted();

        $this->withExtensionAuth($this->installId())
            ->getJson('/v1/subtitle-jobs/'.$job->public_id.'/lyrics')
            ->assertOk()
            ->assertJsonPath('attemptId', $correction->json('attemptId'))
            ->assertJsonMissingPath('lyrics');

        $otherUser = User::factory()->create();
        $this->withExtensionAuth($this->installId('b'), $otherUser)
            ->getJson('/v1/subtitle-jobs/'.$job->public_id.'/lyrics')
            ->assertNotFound();
    }

    public function test_correction_rejects_invalid_input_expired_tracks_inactive_plans_and_concurrent_attempts(): void
    {
        $installId = $this->installId();
        $jobResponse = $this->withExtensionAuth($installId)->postJson('/v1/subtitle-jobs', $this->validPayload())->assertOk();
        $job = SubtitleJob::query()->where('public_id', $jobResponse->json('jobId'))->firstOrFail();
        config([
            'queue.default' => 'database',
            'subtitles.queue.connection' => 'database',
        ]);
        Queue::fake();

        $this->withExtensionAuth($installId)
            ->postJson('/v1/subtitle-jobs/'.$job->public_id.'/lyrics', ['lyrics' => '!!!'])
            ->assertUnprocessable();

        $first = $this->withExtensionAuth($installId)
            ->postJson('/v1/subtitle-jobs/'.$job->public_id.'/lyrics', ['lyrics' => 'first transcript segment second transcript segment'])
            ->assertAccepted();
        $this->withExtensionAuth($installId)
            ->postJson('/v1/subtitle-jobs/'.$job->public_id.'/lyrics', ['lyrics' => 'first transcript segment second transcript segment'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'lyrics_correction_in_progress');

        $job->user->forceFill(['billing_subscription_status' => 'past_due'])->save();
        $job->track->lyricsCorrection()->update(['status' => 'failed', 'lyrics' => null]);
        $this->withExtensionAuth($installId)
            ->postJson('/v1/subtitle-jobs/'.$job->public_id.'/lyrics', ['lyrics' => 'first transcript segment second transcript segment'])
            ->assertStatus(402)
            ->assertJsonPath('error.code', 'payment_required');

        $job->user->forceFill(['billing_subscription_status' => 'active', 'billing_current_period_end' => now()->addDay()])->save();
        $job->track->update(['expires_at' => now()->subMinute()]);
        $this->withExtensionAuth($installId)
            ->postJson('/v1/subtitle-jobs/'.$job->public_id.'/lyrics', ['lyrics' => 'first transcript segment second transcript segment'])
            ->assertNotFound();
        $this->assertNotSame('', (string) $first->json('attemptId'));
    }

    public function test_transcript_first_generation_adds_requested_translation(): void
    {
        $response = $this
            ->withExtensionAuth($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload([
                'includeTranslation' => true,
            ]));

        $response
            ->assertOk()
            ->assertJsonPath('track.cues.0.sourceText', 'first transcript segment')
            ->assertJsonPath('track.cues.0.translatedText', 'Translated first transcript segment')
            ->assertJsonPath('track.cues.0.tokens.0.text', 'first')
            ->assertJsonMissingPath('track.cues.0.tokens.0.gloss');

        $this->assertSame(1, $this->translationAnalysis->tokenizationCalls);
        $this->assertSame(1, $this->translationAnalysis->translationCalls);
        $this->assertSame(0, $this->translationAnalysis->calls);
        $this->assertSame(['spa'], $this->translationAnalysis->sourceLanguages);
        $this->assertSame(['eng'], $this->translationAnalysis->targetLanguages);
    }

    public function test_non_latin_transcript_first_generation_adds_requested_romanization(): void
    {
        $sourceText = $this->arabicGreeting();
        [$firstToken, $secondToken] = explode(' ', $sourceText);

        $this->transcriptionService->transcript = new TimestampedTranscript(
            language: 'ara',
            durationSeconds: 2.0,
            segments: [new TimestampedTranscriptSegment(0.5, 2.1, $sourceText)],
            webVtt: "WEBVTT

00:00:00.500 --> 00:00:02.100
{$sourceText}
",
        );

        $response = $this
            ->withExtensionAuth($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload());

        $response
            ->assertOk()
            ->assertJsonPath('track.cues.0.sourceText', $sourceText)
            ->assertJsonPath('track.cues.0.translatedText', $sourceText)
            ->assertJsonPath('track.cues.0.romanization', 'romanized '.$sourceText)
            ->assertJsonPath('track.cues.0.tokens.0.text', $firstToken)
            ->assertJsonPath('track.cues.0.tokens.0.romanization', 'romanized '.$firstToken)
            ->assertJsonPath('track.cues.0.tokens.1.text', $secondToken)
            ->assertJsonPath('track.cues.0.tokens.1.romanization', 'romanized '.$secondToken);

        $this->assertNull($response->json('track.cues.0.tokens.0.gloss'));
        $this->assertSame(1, $this->translationAnalysis->tokenizationCalls);
        $this->assertSame(0, $this->translationAnalysis->calls);
        $this->assertSame(1, $this->translationAnalysis->romanizationCalls);
    }

    public function test_transcript_first_generation_skips_romanization_when_disabled(): void
    {
        $sourceText = $this->arabicGreeting();
        [$firstToken] = explode(' ', $sourceText);

        $this->transcriptionService->transcript = new TimestampedTranscript(
            language: 'ara',
            durationSeconds: 2.0,
            segments: [new TimestampedTranscriptSegment(0.5, 2.1, $sourceText)],
            webVtt: "WEBVTT

00:00:00.500 --> 00:00:02.100
{$sourceText}
",
        );

        $response = $this
            ->withExtensionAuth($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload([
                'youtubeVideoId' => 'noroman0001',
                'includeRomanization' => false,
            ]));

        $response
            ->assertOk()
            ->assertJsonPath('track.cues.0.sourceText', $sourceText)
            ->assertJsonPath('track.cues.0.tokens.0.text', $firstToken);

        $this->assertNull($response->json('track.cues.0.romanization'));
        $this->assertNull($response->json('track.cues.0.tokens.0.romanization'));
        $this->assertSame(1, $this->translationAnalysis->tokenizationCalls);
        $this->assertSame(0, $this->translationAnalysis->romanizationCalls);
    }

    public function test_japanese_transcript_first_generation_rebuilds_grouped_tokens_and_romanization(): void
    {
        $sourceText = $this->japaneseSentence();

        $this->transcriptionService->transcript = new TimestampedTranscript(
            language: 'jpn',
            durationSeconds: 2.0,
            segments: [new TimestampedTranscriptSegment(0.5, 2.1, $sourceText)],
            webVtt: "WEBVTT

00:00:00.500 --> 00:00:02.100
{$sourceText}
",
        );

        $response = $this
            ->withExtensionAuth($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload([
                'sourceLanguage' => 'jpn',
                'youtubeVideoId' => 'jpn00000001',
            ]));

        $response
            ->assertOk()
            ->assertJsonPath('track.cues.0.sourceText', $sourceText)
            ->assertJsonPath('track.cues.0.romanization', 'watashi wa nihongo o benkyo shite imasu')
            ->assertJsonPath('track.cues.0.tokens.0.text', "\u{79C1}")
            ->assertJsonPath('track.cues.0.tokens.2.text', "\u{65E5}\u{672C}\u{8A9E}")
            ->assertJsonPath('track.cues.0.tokens.2.romanization', 'nihongo')
            ->assertJsonPath('track.cues.0.tokens.4.text', "\u{52C9}\u{5F37}\u{3057}\u{3066}\u{3044}\u{307E}\u{3059}");

        $this->assertSame(1, $this->translationAnalysis->romanizationCalls);
        $this->assertSame(1, $this->translationAnalysis->tokenizationCalls);
    }

    public function test_non_latin_transcript_first_generation_fails_when_romanization_fails(): void
    {
        config([
            'queue.default' => 'database',
            'subtitles.queue.connection' => 'database',
        ]);
        $sourceText = $this->arabicGreeting();

        $this->translationAnalysis->romanizationShouldFail = true;
        $this->transcriptionService->transcript = new TimestampedTranscript(
            language: 'ara',
            durationSeconds: 2.0,
            segments: [new TimestampedTranscriptSegment(0.5, 2.1, $sourceText)],
            webVtt: "WEBVTT

00:00:00.500 --> 00:00:02.100
{$sourceText}
",
        );

        $response = $this
            ->withExtensionAuth($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload(['youtubeVideoId' => 'nonlatin001']))
            ->assertAccepted()
            ->assertJsonPath('status', 'running');

        $this->runQueuedSubtitleJobs();

        $this
            ->withExtensionAuth($this->installId())
            ->getJson('/v1/subtitle-jobs/'.$response->json('jobId'))
            ->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('message', 'Subtitle enrichment failed.');

        $this->assertSame(1, $this->translationAnalysis->tokenizationCalls);
        $this->assertSame(1, $this->translationAnalysis->romanizationCalls);
        $this->assertDatabaseHas('subtitle_jobs', [
            'youtube_video_id' => 'nonlatin001',
            'status' => 'failed',
            'stage' => 'romanizing',
        ]);
    }

    public function test_transcript_first_generation_fails_when_tokenization_fails(): void
    {
        config([
            'queue.default' => 'database',
            'subtitles.queue.connection' => 'database',
        ]);
        $this->translationAnalysis->tokenizationShouldFail = true;

        $response = $this
            ->withExtensionAuth($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload(['youtubeVideoId' => 'tokfail0001']))
            ->assertAccepted()
            ->assertJsonPath('status', 'running');

        $this->runQueuedSubtitleJobs();

        $this
            ->withExtensionAuth($this->installId())
            ->getJson('/v1/subtitle-jobs/'.$response->json('jobId'))
            ->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('message', 'Subtitle enrichment failed.');

        $this->assertSame(1, $this->translationAnalysis->tokenizationCalls);
        $this->assertDatabaseHas('subtitle_jobs', [
            'youtube_video_id' => 'tokfail0001',
            'status' => 'failed',
            'stage' => 'tokenizing',
        ]);
    }

    public function test_transcript_first_generation_fails_when_translation_fails(): void
    {
        config([
            'queue.default' => 'database',
            'subtitles.queue.connection' => 'database',
        ]);
        $this->translationAnalysis->translationShouldFail = true;

        $response = $this
            ->withExtensionAuth($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload([
                'youtubeVideoId' => 'trnfail0001',
                'includeTranslation' => true,
            ]))
            ->assertAccepted()
            ->assertJsonPath('status', 'running');

        $this->runQueuedSubtitleJobs();

        $this
            ->withExtensionAuth($this->installId())
            ->getJson('/v1/subtitle-jobs/'.$response->json('jobId'))
            ->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('message', 'Subtitle enrichment failed.');

        $this->assertSame(1, $this->translationAnalysis->tokenizationCalls);
        $this->assertSame(1, $this->translationAnalysis->translationCalls);
        // Translation now fails inside the merged analysis call, which runs
        // under the tokenizing stage.
        $this->assertDatabaseHas('subtitle_jobs', [
            'youtube_video_id' => 'trnfail0001',
            'status' => 'failed',
            'stage' => 'tokenizing',
        ]);
    }

    public function test_japanese_romanization_failure_fails_generation(): void
    {
        config([
            'queue.default' => 'database',
            'subtitles.queue.connection' => 'database',
        ]);
        $sourceText = $this->japaneseSentence();

        $this->translationAnalysis->romanizationShouldFail = true;
        $this->transcriptionService->transcript = new TimestampedTranscript(
            language: 'jpn',
            durationSeconds: 2.0,
            segments: [new TimestampedTranscriptSegment(0.5, 2.1, $sourceText)],
            webVtt: "WEBVTT

00:00:00.500 --> 00:00:02.100
{$sourceText}
",
        );

        $response = $this
            ->withExtensionAuth($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload([
                'sourceLanguage' => 'jpn',
                'youtubeVideoId' => 'jpnfail0001',
            ]))
            ->assertAccepted()
            ->assertJsonPath('status', 'running');

        $this->runQueuedSubtitleJobs();

        $this
            ->withExtensionAuth($this->installId())
            ->getJson('/v1/subtitle-jobs/'.$response->json('jobId'))
            ->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('message', 'Subtitle enrichment failed.');

        $this->assertSame(1, $this->translationAnalysis->tokenizationCalls);
        $this->assertSame(1, $this->translationAnalysis->romanizationCalls);
        $this->assertDatabaseHas('subtitle_jobs', [
            'youtube_video_id' => 'jpnfail0001',
            'status' => 'failed',
            'stage' => 'romanizing',
        ]);
    }

    public function test_japanese_no_space_romanization_failure_fails_generation(): void
    {
        config([
            'queue.default' => 'database',
            'subtitles.queue.connection' => 'database',
        ]);
        $sourceText = $this->japaneseNoSpaceSentence();

        $this->translationAnalysis->romanizationShouldFail = true;
        $this->transcriptionService->transcript = new TimestampedTranscript(
            language: 'jpn',
            durationSeconds: 2.0,
            segments: [new TimestampedTranscriptSegment(0.5, 2.1, $sourceText)],
            webVtt: "WEBVTT

00:00:00.500 --> 00:00:02.100
{$sourceText}
",
        );

        $response = $this
            ->withExtensionAuth($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload([
                'sourceLanguage' => 'jpn',
                'youtubeVideoId' => 'jpnfail0002',
            ]))
            ->assertAccepted()
            ->assertJsonPath('status', 'running');

        $this->runQueuedSubtitleJobs();

        $this
            ->withExtensionAuth($this->installId())
            ->getJson('/v1/subtitle-jobs/'.$response->json('jobId'))
            ->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('message', 'Subtitle enrichment failed.');

        $this->assertSame(1, $this->translationAnalysis->tokenizationCalls);
        $this->assertSame(1, $this->translationAnalysis->romanizationCalls);
        $this->assertDatabaseHas('subtitle_jobs', [
            'youtube_video_id' => 'jpnfail0002',
            'status' => 'failed',
            'stage' => 'romanizing',
        ]);
    }

    public function test_full_enrichment_mode_blocks_for_all_card_metadata(): void
    {
        $response = $this
            ->withExtensionAuth($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload(['enrichmentMode' => 'full']));

        $response
            ->assertOk()
            ->assertJsonPath('track.cues.0.translatedText', 'first transcript segment')
            ->assertJsonPath('track.cues.0.tokens.0.gloss', 'first');

        $this->assertSame(1, $this->translationAnalysis->calls);
        $this->assertSame(1, $this->translationAnalysis->tokenizationCalls);
        $this->assertSame(0, $this->translationAnalysis->translationCalls);
        $this->assertSame(['spa', 'spa'], $this->translationAnalysis->sourceLanguages);
        $this->assertSame(['eng'], $this->translationAnalysis->targetLanguages);
    }

    public function test_full_enrichment_with_translation_runs_both_ai_steps(): void
    {
        $response = $this
            ->withExtensionAuth($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload([
                'enrichmentMode' => 'full',
                'includeTranslation' => true,
            ]));

        $response
            ->assertOk()
            ->assertJsonPath('track.cues.0.translatedText', 'Translated first transcript segment')
            ->assertJsonPath('track.cues.0.tokens.0.gloss', 'first');

        $this->assertSame(1, $this->translationAnalysis->calls);
        $this->assertSame(1, $this->translationAnalysis->translationCalls);
        $this->assertSame(1, $this->translationAnalysis->tokenizationCalls);
        $this->assertSame(['spa', 'spa'], $this->translationAnalysis->sourceLanguages);
        $this->assertSame(['eng', 'eng'], $this->translationAnalysis->targetLanguages);
    }

    public function test_full_japanese_enrichment_uses_grouped_token_boundaries(): void
    {
        $sourceText = $this->japaneseSentence();

        $this->transcriptionService->transcript = new TimestampedTranscript(
            language: 'jpn',
            durationSeconds: 2.0,
            segments: [new TimestampedTranscriptSegment(0.5, 2.1, $sourceText)],
            webVtt: "WEBVTT

00:00:00.500 --> 00:00:02.100
{$sourceText}
",
        );

        $response = $this
            ->withExtensionAuth($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload([
                'sourceLanguage' => 'jpn',
                'youtubeVideoId' => 'jpnfull0001',
                'enrichmentMode' => 'full',
                'includeTranslation' => true,
            ]));

        $response
            ->assertOk()
            ->assertJsonPath('track.cues.0.translatedText', 'Translated '.$sourceText)
            ->assertJsonPath('track.cues.0.tokens.2.text', "\u{65E5}\u{672C}\u{8A9E}")
            ->assertJsonPath('track.cues.0.tokens.2.gloss', 'Japanese language')
            ->assertJsonPath('track.cues.0.tokens.4.text', "\u{52C9}\u{5F37}\u{3057}\u{3066}\u{3044}\u{307E}\u{3059}");

        $this->assertSame(1, $this->translationAnalysis->calls);
        $this->assertSame(1, $this->translationAnalysis->translationCalls);
        $this->assertSame(1, $this->translationAnalysis->tokenizationCalls);
    }

    public function test_full_same_language_generation_skips_translation_enrichment(): void
    {
        $response = $this
            ->withExtensionAuth($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload([
                'sourceLanguage' => 'eng',
                'targetLanguage' => 'eng',
                'enrichmentMode' => 'full',
                'includeTranslation' => true,
            ]));

        $response
            ->assertOk()
            ->assertJsonPath('track.cues.0.sourceText', 'first transcript segment')
            ->assertJsonPath('track.cues.0.translatedText', 'first transcript segment');

        $this->assertSame(0, $this->translationAnalysis->calls);
        $this->assertSame(0, $this->translationAnalysis->translationCalls);
        $this->assertSame(1, $this->translationAnalysis->tokenizationCalls);
        $this->assertSame(0, $this->translationAnalysis->tokenCalls);
    }

    public function test_full_enrichment_and_on_demand_tracks_are_cached_separately(): void
    {
        $onDemandResponse = $this
            ->withExtensionAuth($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload());

        $fullResponse = $this
            ->withExtensionAuth($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload(['enrichmentMode' => 'full']));

        $onDemandResponse->assertOk();
        $fullResponse->assertOk();

        $this->assertNotSame($onDemandResponse->json('jobId'), $fullResponse->json('jobId'));
        $this->assertNotSame($onDemandResponse->json('track.trackId'), $fullResponse->json('track.trackId'));
        $this->assertSame(2, SubtitleJob::count());
        $this->assertSame(2, SubtitleTrack::count());
    }

    public function test_translated_and_untranslated_tracks_are_cached_separately(): void
    {
        $plainResponse = $this
            ->withExtensionAuth($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload([
                'youtubeVideoId' => 'transmode01',
                'includeTranslation' => false,
            ]));

        $translatedResponse = $this
            ->withExtensionAuth($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload([
                'youtubeVideoId' => 'transmode01',
                'includeTranslation' => true,
            ]));

        $plainResponse->assertOk();
        $translatedResponse->assertOk();

        $this->assertNotSame($plainResponse->json('jobId'), $translatedResponse->json('jobId'));
        $this->assertNotSame($plainResponse->json('track.trackId'), $translatedResponse->json('track.trackId'));
        $this->assertSame('first transcript segment', $plainResponse->json('track.cues.0.translatedText'));
        $this->assertSame('Translated first transcript segment', $translatedResponse->json('track.cues.0.translatedText'));
        $this->assertSame(2, SubtitleJob::count());
        $this->assertSame(2, SubtitleTrack::count());
    }

    public function test_repeat_generation_for_the_same_video_reuses_the_cached_transcript(): void
    {
        config(['ai.providers.eleven.models.transcription.default' => 'scribe-test']);

        $this
            ->withExtensionAuth($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload(['youtubeVideoId' => 'cachehit001']))
            ->assertOk();

        $this->assertDatabaseHas('cached_video_transcripts', [
            'youtube_video_id' => 'cachehit001',
            'requested_source_language' => 'auto',
            'transcription_model' => 'scribe-test:transcript-chunks-v2',
            'audio_duration_seconds' => 42,
        ]);

        // A different target language forces a new job while the transcript
        // cache key (video + requested source language + model) is unchanged.
        $secondResponse = $this
            ->withExtensionAuth($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload([
                'youtubeVideoId' => 'cachehit001',
                'targetLanguage' => 'fra',
            ]));

        $secondResponse
            ->assertOk()
            ->assertJsonPath('track.cues.0.sourceText', 'first transcript segment')
            ->assertJsonPath('detectedSourceLanguage', 'spa')
            ->assertJsonPath('videoDurationSeconds', 42);

        // Acquire, optimize, and transcribe ran only for the first job.
        $this->assertSame(1, $this->audioSource->calls);
        $this->assertSame(1, $this->transcriptionService->prepareCalls);
        $this->assertCount(1, $this->transcriptionService->sourceLanguages);

        $secondJob = SubtitleJob::query()
            ->where('public_id', $secondResponse->json('jobId'))
            ->firstOrFail();

        $this->assertDatabaseHas('subtitle_job_events', [
            'subtitle_job_id' => $secondJob->id,
            'event' => 'transcript.cache_hit',
        ]);
        // The cache hit skips the provider call, so no transcription cost.
        $this->assertSame(0, SubtitleJobEvent::query()
            ->where('subtitle_job_id', $secondJob->id)
            ->where('event', 'provider.cost_estimated')
            ->where('stage', 'transcribing')
            ->count());
    }

    public function test_transcript_cache_is_disabled_when_ttl_is_zero(): void
    {
        config(['subtitles.transcript_cache.ttl_days' => 0]);

        $this
            ->withExtensionAuth($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload(['youtubeVideoId' => 'cacheoff001']))
            ->assertOk();

        $this->assertSame(0, CachedVideoTranscript::count());

        $this
            ->withExtensionAuth($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload([
                'youtubeVideoId' => 'cacheoff001',
                'targetLanguage' => 'fra',
            ]))
            ->assertOk();

        $this->assertSame(2, $this->audioSource->calls);
    }

    public function test_expired_cached_transcript_is_not_reused(): void
    {
        config(['ai.providers.eleven.models.transcription.default' => 'scribe-test']);

        CachedVideoTranscript::create([
            'youtube_video_id' => 'cacheexp001',
            'requested_source_language' => 'auto',
            'transcription_model' => 'scribe-test:transcript-chunks-v2',
            'audio_duration_seconds' => 999,
            'payload' => ['language' => 'spa', 'durationSeconds' => 999.0, 'webVtt' => 'WEBVTT', 'segments' => []],
            'expires_at' => now()->subDay(),
        ]);

        $this
            ->withExtensionAuth($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload(['youtubeVideoId' => 'cacheexp001']))
            ->assertOk();

        // The expired row was ignored, the video re-transcribed, and the row
        // replaced with a fresh transcript and expiry.
        $this->assertSame(1, $this->audioSource->calls);
        $this->assertSame(1, CachedVideoTranscript::count());
        $entry = CachedVideoTranscript::query()->firstOrFail();
        $this->assertSame(42, $entry->audio_duration_seconds);
        $this->assertTrue($entry->expires_at->isFuture());
    }

    public function test_romanized_and_non_romanized_tracks_are_cached_separately(): void
    {
        $sourceText = $this->arabicGreeting();

        $this->transcriptionService->transcript = new TimestampedTranscript(
            language: 'ara',
            durationSeconds: 2.0,
            segments: [new TimestampedTranscriptSegment(0.5, 2.1, $sourceText)],
            webVtt: "WEBVTT

00:00:00.500 --> 00:00:02.100
{$sourceText}
",
        );

        $plainResponse = $this
            ->withExtensionAuth($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload([
                'youtubeVideoId' => 'romanmode01',
                'includeRomanization' => false,
            ]));

        $romanizedResponse = $this
            ->withExtensionAuth($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload([
                'youtubeVideoId' => 'romanmode01',
                'includeRomanization' => true,
            ]));

        $plainResponse->assertOk();
        $romanizedResponse->assertOk();

        $this->assertNotSame($plainResponse->json('jobId'), $romanizedResponse->json('jobId'));
        $this->assertNotSame($plainResponse->json('track.trackId'), $romanizedResponse->json('track.trackId'));
        $this->assertNull($plainResponse->json('track.cues.0.romanization'));
        $this->assertSame('romanized '.$sourceText, $romanizedResponse->json('track.cues.0.romanization'));
        $this->assertSame(2, SubtitleJob::count());
        $this->assertSame(2, SubtitleTrack::count());
    }

    public function test_create_subtitle_job_accepts_catalog_source_languages(): void
    {
        $sourceLanguages = ['auto', 'eng', 'cmn', 'jpn', 'ara', 'por', 'ita', 'swa'];

        foreach ($sourceLanguages as $index => $sourceLanguage) {
            $videoId = 'vid'.str_pad((string) $index, 8, '0', STR_PAD_LEFT);

            $this
                ->withExtensionAuth($this->installId(chr(97 + $index)))
                ->postJson('/v1/subtitle-jobs', $this->validPayload([
                    'youtubeVideoId' => $videoId,
                    'sourceLanguage' => $sourceLanguage,
                ]))
                ->assertOk()
                ->assertJsonPath('sourceLanguage', $sourceLanguage)
                ->assertJsonPath('track.sourceLanguage', $sourceLanguage);
        }

        $this->assertSame($sourceLanguages, $this->transcriptionService->sourceLanguages);
    }

    public function test_create_subtitle_job_accepts_catalog_target_languages(): void
    {
        foreach (['eng', 'cmn', 'jpn', 'fra', 'swa'] as $index => $targetLanguage) {
            $videoId = 'tgt'.str_pad((string) $index, 8, '0', STR_PAD_LEFT);

            $this
                ->withExtensionAuth($this->installId(chr(97 + $index)))
                ->postJson('/v1/subtitle-jobs', $this->validPayload([
                    'youtubeVideoId' => $videoId,
                    'targetLanguage' => $targetLanguage,
                ]))
                ->assertOk()
                ->assertJsonPath('targetLanguage', $targetLanguage)
                ->assertJsonPath('track.targetLanguage', $targetLanguage);
        }
    }

    public function test_auto_source_persists_detected_source_language(): void
    {
        $this->transcriptionService->transcript = new TimestampedTranscript(
            language: 'jpn',
            durationSeconds: 2.0,
            segments: [new TimestampedTranscriptSegment(0.5, 2.1, 'konnichiwa')],
            webVtt: "WEBVTT\n\n00:00:00.500 --> 00:00:02.100\nkonnichiwa\n",
        );

        $response = $this
            ->withExtensionAuth($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload([
                'sourceLanguage' => 'auto',
                'targetLanguage' => 'eng',
            ]));

        $response
            ->assertOk()
            ->assertJsonPath('sourceLanguage', 'auto')
            ->assertJsonPath('detectedSourceLanguage', 'jpn')
            ->assertJsonPath('track.sourceLanguage', 'auto')
            ->assertJsonPath('track.detectedSourceLanguage', 'jpn');

        $this->assertDatabaseHas('subtitle_jobs', [
            'youtube_video_id' => 'dQw4w9WgXcQ',
            'source_language' => 'auto',
            'detected_source_language' => 'jpn',
            'target_language' => 'eng',
        ]);
        $this->assertDatabaseHas('subtitle_tracks', [
            'youtube_video_id' => 'dQw4w9WgXcQ',
            'source_language' => 'auto',
            'detected_source_language' => 'jpn',
            'target_language' => 'eng',
        ]);
    }

    public function test_duplicate_default_request_reuses_completed_on_demand_track(): void
    {
        $firstResponse = $this
            ->withExtensionAuth($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload());

        $secondResponse = $this
            ->withExtensionAuth($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload());

        $firstResponse->assertOk();
        $secondResponse
            ->assertOk()
            ->assertJsonPath('jobId', $firstResponse->json('jobId'))
            ->assertJsonPath('track.trackId', $firstResponse->json('track.trackId'));

        $this->assertSame(1, $this->audioSource->calls);
        $this->assertSame(0, $this->translationAnalysis->calls);
        $this->assertSame(1, $this->translationAnalysis->tokenizationCalls);
    }

    public function test_generation_records_estimated_provider_cost_without_exposing_internal_payload(): void
    {
        config([
            'subtitles.costs.elevenlabs_scribe_microusd_per_minute' => 100,
            'subtitles.costs.openai_tokenization_microusd_per_cue' => 10,
        ]);

        $response = $this
            ->withExtensionAuth($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload(['youtubeVideoId' => 'costtrace01']));

        $response
            ->assertOk()
            ->assertJsonPath('videoDurationSeconds', 42)
            ->assertJsonPath('enrichmentMode', 'on_demand')
            ->assertJsonPath('includeRomanization', true)
            ->assertJsonPath('includeTranslation', false)
            ->assertJsonMissingPath('estimatedProviderCostMicrousd')
            ->assertJsonMissingPath('generationTier');

        $job = SubtitleJob::query()
            ->where('public_id', $response->json('jobId'))
            ->sole();

        $this->assertSame(120, $job->estimated_provider_cost_microusd);
        $this->assertSame(2, SubtitleJobEvent::query()
            ->where('subtitle_job_id', $job->id)
            ->where('event', 'provider.cost_estimated')
            ->count());
    }

    public function test_old_tokenization_processing_versions_are_not_reused(): void
    {
        $oldJob = SubtitleJob::factory()->create([
            'youtube_video_id' => 'dQw4w9WgXcQ',
            'youtube_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'source_language' => 'auto',
            'detected_source_language' => 'spa',
            'target_language' => 'eng',
            'processing_version' => 'elevenlabs-scribe-v2-transcript-first-tokenizer-agent-v4',
            'status' => 'completed',
            'stage' => 'finalizing',
            'progress_percent' => 100,
            'install_id' => $this->installId(),
            'expires_at' => now()->addDays(30),
        ]);
        SubtitleTrack::factory()
            ->for($oldJob, 'job')
            ->create([
                'youtube_video_id' => 'dQw4w9WgXcQ',
                'source_language' => 'auto',
                'detected_source_language' => 'spa',
                'target_language' => 'eng',
                'processing_version' => 'elevenlabs-scribe-v2-transcript-first-tokenizer-agent-v4',
                'expires_at' => now()->addDays(30),
            ]);

        $response = $this
            ->withExtensionAuth($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload());

        $response->assertOk();

        $this->assertSame(2, SubtitleJob::count());
        $this->assertSame(2, SubtitleTrack::count());
        $this->assertSame(1, $this->audioSource->calls);
        $this->assertNotSame($oldJob->public_id, $response->json('jobId'));
    }

    public function test_completed_tracks_are_cached_per_install(): void
    {
        $firstResponse = $this
            ->withExtensionAuth($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload());

        $secondResponse = $this
            ->withExtensionAuth($this->installId('b'))
            ->postJson('/v1/subtitle-jobs', $this->validPayload());

        $firstResponse->assertOk();
        $secondResponse->assertOk();

        $this->assertNotSame($firstResponse->json('jobId'), $secondResponse->json('jobId'));
        $this->assertNotSame($firstResponse->json('track.trackId'), $secondResponse->json('track.trackId'));
        $this->assertSame(2, SubtitleJob::count());
        $this->assertSame(2, SubtitleTrack::count());
        // Each install gets its own job and track, but the transcript is
        // shared per video, so the second install skips audio acquisition.
        $this->assertSame(1, $this->audioSource->calls);
    }

    public function test_partial_track_serves_available_cues_while_running(): void
    {
        $user = User::factory()->create();
        $job = SubtitleJob::factory()->create([
            'user_id' => $user->id,
            'status' => 'running',
            'stage' => 'tokenizing',
            'progress_percent' => 65,
        ]);
        $installId = $this->installId();

        // Nothing to serve before transcription lands.
        $this->withExtensionAuth($installId, $user)
            ->getJson("/v1/subtitle-jobs/{$job->public_id}/partial-track")
            ->assertNotFound();

        $this->artifacts()->putCueCollection($job, SubtitleJobArtifactStore::DRAFT_CUES, [
            ['cueId' => 'cue-0001', 'index' => 0, 'startMs' => 500, 'endMs' => 2100, 'sourceText' => 'first transcript segment', 'translatedText' => '', 'tokens' => []],
            ['cueId' => 'cue-0002', 'index' => 1, 'startMs' => 2400, 'endMs' => 4000, 'sourceText' => 'second transcript segment', 'translatedText' => '', 'tokens' => []],
        ]);

        $this->withExtensionAuth($installId, $user)
            ->getJson("/v1/subtitle-jobs/{$job->public_id}/partial-track")
            ->assertOk()
            ->assertJsonPath('jobId', $job->public_id)
            ->assertJsonPath('youtubeVideoId', $job->youtube_video_id)
            ->assertJsonPath('revision', 1)
            ->assertJsonPath('cues.0.sourceText', 'first transcript segment')
            ->assertJsonMissingPath('cues.0.translatedText')
            ->assertJsonMissingPath('cues.0.tokens');

        $this->artifacts()->putCueBatchResult($job, SubtitleJobArtifactStore::TRANSLATED_CUES, 0, new CueEnrichmentResult([
            ['cueId' => 'cue-0001', 'index' => 0, 'translatedText' => 'Translated first transcript segment'],
        ], 'unknown'));
        $this->artifacts()->putCueBatchResult($job, SubtitleJobArtifactStore::ROMANIZED_CUES, 0, new CueEnrichmentResult([
            ['cueId' => 'cue-0001', 'index' => 0, 'romanization' => 'ro-man-ized'],
        ], 'unknown'));

        // Batches that landed patch their cues in; untouched cues stay
        // source-only. The revision counts consumed artifacts.
        $this->withExtensionAuth($installId, $user)
            ->getJson("/v1/subtitle-jobs/{$job->public_id}/partial-track")
            ->assertOk()
            ->assertJsonPath('revision', 3)
            ->assertJsonPath('cues.0.translatedText', 'Translated first transcript segment')
            ->assertJsonPath('cues.0.romanization', 'ro-man-ized')
            ->assertJsonMissingPath('cues.1.translatedText');
    }

    public function test_partial_track_is_not_served_for_completed_jobs_or_other_users(): void
    {
        $completedResponse = $this
            ->withExtensionAuth($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload(['youtubeVideoId' => 'partdone001']))
            ->assertOk();

        $this
            ->withExtensionAuth($this->installId())
            ->getJson('/v1/subtitle-jobs/'.$completedResponse->json('jobId').'/partial-track')
            ->assertNotFound();

        $otherUsersJob = SubtitleJob::factory()->create([
            'user_id' => User::factory()->create()->id,
            'status' => 'running',
            'stage' => 'tokenizing',
            'progress_percent' => 65,
        ]);
        $this->artifacts()->putCueCollection($otherUsersJob, SubtitleJobArtifactStore::DRAFT_CUES, [$this->sampleCue()]);

        $this
            ->withExtensionAuth($this->installId('b'))
            ->getJson("/v1/subtitle-jobs/{$otherUsersJob->public_id}/partial-track")
            ->assertNotFound();
    }

    public function test_generation_records_time_to_first_cue(): void
    {
        $response = $this
            ->withExtensionAuth($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload(['youtubeVideoId' => 'firstcue001']))
            ->assertOk();

        $job = SubtitleJob::query()
            ->where('public_id', $response->json('jobId'))
            ->firstOrFail();

        $this->assertDatabaseHas('subtitle_job_events', [
            'subtitle_job_id' => $job->id,
            'event' => 'delivery.first_cue_available',
        ]);
    }

    public function test_list_subtitle_jobs_returns_current_install_history(): void
    {
        $installId = $this->installId();
        $user = User::factory()->create();
        $job = SubtitleJob::factory()->create([
            'user_id' => $user->id,
            'youtube_video_id' => 'dQw4w9WgXcQ',
            'youtube_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'install_id' => $installId,
            'expires_at' => now()->addDays(30),
            'status' => 'completed',
            'stage' => 'finalizing',
            'progress_percent' => 100,
            'source_language' => 'spa',
            'detected_source_language' => 'spa',
            'updated_at' => now(),
        ]);
        $track = SubtitleTrack::factory()
            ->for($job, 'job')
            ->create([
                'youtube_video_id' => 'dQw4w9WgXcQ',
                'source_language' => 'spa',
                'detected_source_language' => 'spa',
                'expires_at' => now()->addDays(30),
            ]);
        $runningJob = SubtitleJob::factory()->create([
            'user_id' => $user->id,
            'youtube_video_id' => 'run00000001',
            'install_id' => $installId,
            'expires_at' => null,
            'stage' => 'transcribing',
            'progress_percent' => 45,
            'updated_at' => now()->subMinute(),
        ]);
        $failedJob = SubtitleJob::factory()->create([
            'user_id' => $user->id,
            'youtube_video_id' => 'fail0000001',
            'install_id' => $installId,
            'expires_at' => null,
            'status' => 'failed',
            'stage' => 'enriching',
            'progress_percent' => 75,
            'error_code' => 'rate_limited',
            'error_message' => 'Subtitle enrichment is temporarily rate limited.',
            'updated_at' => now()->subMinutes(2),
        ]);
        SubtitleJob::factory()->create([
            'youtube_video_id' => 'other000001',
            'install_id' => $this->installId('b'),
            'expires_at' => now()->addDays(30),
        ]);

        $response = $this
            ->withExtensionAuth($installId, $user)
            ->getJson('/v1/subtitle-jobs');

        $response
            ->assertOk()
            ->assertJsonCount(3, 'jobs')
            ->assertJsonPath('jobs.0.youtubeVideoId', 'dQw4w9WgXcQ')
            ->assertJsonPath('jobs.0.status', 'completed')
            ->assertJsonPath('jobs.0.trackId', $track->public_id)
            ->assertJsonPath('jobs.0.sourceLanguage', 'spa')
            ->assertJsonPath('jobs.0.detectedSourceLanguage', 'spa')
            ->assertJsonPath('jobs.0.targetLanguage', 'eng')
            ->assertJsonPath('jobs.0.videoDurationSeconds', 213)
            ->assertJsonPath('jobs.0.enrichmentMode', 'on_demand')
            ->assertJsonPath('jobs.0.includeRomanization', true)
            ->assertJsonPath('jobs.0.includeTranslation', false)
            ->assertJsonPath('jobs.1.youtubeVideoId', 'run00000001')
            ->assertJsonPath('jobs.1.status', 'running')
            ->assertJsonPath('jobs.1.stage', 'transcribing')
            ->assertJsonPath('jobs.1.progressPercent', 45)
            ->assertJsonPath('jobs.1.jobId', $runningJob->public_id)
            ->assertJsonPath('jobs.1.targetLanguage', 'eng')
            ->assertJsonPath('jobs.2.youtubeVideoId', 'fail0000001')
            ->assertJsonPath('jobs.2.status', 'failed')
            ->assertJsonPath('jobs.2.stage', 'enriching')
            ->assertJsonPath('jobs.2.progressPercent', 75)
            ->assertJsonPath('jobs.2.errorCode', 'rate_limited')
            ->assertJsonPath('jobs.2.message', 'Subtitle enrichment is temporarily rate limited.')
            ->assertJsonPath('jobs.2.jobId', $failedJob->public_id);
    }

    public function test_subtitle_job_status_polling_uses_separate_rate_limit_from_generation_requests(): void
    {
        config([
            'queue.default' => 'database',
            'subtitles.queue.connection' => 'database',
            'subtitles.rate_limits.per_install_per_minute' => 1,
            'subtitles.rate_limits.per_ip_per_minute' => 100,
            'subtitles.rate_limits.status_per_install_per_minute' => 2,
            'subtitles.rate_limits.status_per_ip_per_minute' => 100,
        ]);
        Queue::fake();

        $installId = $this->installId('r');
        $job = SubtitleJob::factory()->create([
            'install_id' => $installId,
            'youtube_video_id' => 'pollrate001',
            'youtube_url' => 'https://www.youtube.com/watch?v=pollrate001',
        ]);

        $this
            ->withExtensionAuth($installId)
            ->postJson('/v1/subtitle-jobs', $this->validPayload(['youtubeVideoId' => 'ratelimit01']))
            ->assertAccepted();

        $this
            ->withExtensionAuth($installId)
            ->getJson('/v1/subtitle-jobs/'.$job->public_id)
            ->assertOk()
            ->assertJsonPath('jobId', $job->public_id);

        $this
            ->withExtensionAuth($installId)
            ->getJson('/v1/subtitle-jobs/'.$job->public_id)
            ->assertOk()
            ->assertJsonPath('jobId', $job->public_id);

        $this
            ->withExtensionAuth($installId)
            ->getJson('/v1/subtitle-jobs/'.$job->public_id)
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'rate_limited');
    }

    public function test_learning_token_enrichment_updates_track_and_skips_duplicate_provider_calls(): void
    {
        $jobResponse = $this
            ->withExtensionAuth($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload())
            ->assertOk();

        $payload = [
            'trackId' => $jobResponse->json('track.trackId'),
            'cueId' => 'cue-0001',
            'tokenIndex' => 0,
        ];

        $this
            ->withExtensionAuth($this->installId())
            ->postJson('/v1/learning-tokens', $payload)
            ->assertOk()
            ->assertJsonPath('trackId', $payload['trackId'])
            ->assertJsonPath('cueId', 'cue-0001')
            ->assertJsonPath('token.index', 0)
            ->assertJsonPath('token.text', 'first')
            ->assertJsonPath('token.gloss', 'first gloss')
            ->assertJsonPath('token.romanization', 'first romanized');

        $this
            ->withExtensionAuth($this->installId())
            ->postJson('/v1/learning-tokens', $payload)
            ->assertOk()
            ->assertJsonPath('token.gloss', 'first gloss');

        $track = SubtitleTrack::where('public_id', $payload['trackId'])->firstOrFail();

        $this->assertSame('first gloss', $track->cues[0]['tokens'][0]['gloss']);
        $this->assertSame(1, $this->translationAnalysis->tokenCalls);
    }

    public function test_learning_token_enrichment_requires_an_active_plan_on_a_cache_miss(): void
    {
        $installId = $this->installId('p');
        $user = User::factory()->create();
        $this->withExtensionAuth($installId, $user);
        $job = SubtitleJob::factory()->for($user)->create([
            'install_id' => $installId,
            'youtube_video_id' => 'learnplan01',
            'youtube_url' => 'https://www.youtube.com/watch?v=learnplan01',
            'status' => 'completed',
            'stage' => 'finalizing',
            'progress_percent' => 100,
            'expires_at' => now()->addDays(30),
        ]);
        $track = SubtitleTrack::factory()->for($job, 'job')->create([
            'youtube_video_id' => 'learnplan01',
            'cues' => [$this->sampleCue()],
        ]);
        $user->forceFill([
            'billing_subscription_status' => 'canceled',
            'billing_current_period_end' => now()->subSecond(),
        ])->save();

        $this
            ->withExtensionAuth($installId, $user)
            ->postJson('/v1/learning-tokens', [
                'trackId' => $track->public_id,
                'cueId' => 'cue-0001',
                'tokenIndex' => 0,
            ])
            ->assertStatus(402)
            ->assertJsonPath('error.code', 'payment_required');

        $this->assertSame(0, $this->translationAnalysis->tokenCalls);
    }

    public function test_learning_token_enrichment_preserves_concurrent_token_updates(): void
    {
        $job = SubtitleJob::factory()->create([
            'install_id' => $this->installId(),
            'youtube_video_id' => 'learnmerge1',
            'youtube_url' => 'https://www.youtube.com/watch?v=learnmerge1',
            'source_language' => 'spa',
            'detected_source_language' => 'spa',
            'target_language' => 'eng',
            'status' => 'completed',
            'stage' => 'finalizing',
            'progress_percent' => 100,
            'expires_at' => now()->addDays(30),
        ]);
        $track = SubtitleTrack::factory()
            ->for($job, 'job')
            ->create([
                'youtube_video_id' => 'learnmerge1',
                'source_language' => 'spa',
                'detected_source_language' => 'spa',
                'target_language' => 'eng',
                'cues' => [
                    [
                        'cueId' => 'cue-0001',
                        'index' => 0,
                        'startMs' => 0,
                        'endMs' => 2000,
                        'sourceText' => 'alpha beta',
                        'translatedText' => 'alpha beta',
                        'tokens' => [
                            ['index' => 0, 'text' => 'alpha', 'normalizedText' => 'alpha'],
                            ['index' => 1, 'text' => 'beta', 'normalizedText' => 'beta'],
                        ],
                    ],
                ],
            ]);

        $this->translationAnalysis->beforeTokenResult = function () use ($track): void {
            $freshTrack = $track->refresh();
            $cues = $freshTrack->cues;
            $cues[0]['tokens'][1]['gloss'] = 'beta concurrent gloss';
            $freshTrack->update(['cues' => $cues]);
        };

        $this
            ->withExtensionAuth($this->installId())
            ->postJson('/v1/learning-tokens', [
                'trackId' => $track->public_id,
                'cueId' => 'cue-0001',
                'tokenIndex' => 0,
            ])
            ->assertOk()
            ->assertJsonPath('token.index', 0)
            ->assertJsonPath('token.gloss', 'alpha gloss');

        $track->refresh();

        $this->assertSame('alpha gloss', $track->cues[0]['tokens'][0]['gloss']);
        $this->assertSame('beta concurrent gloss', $track->cues[0]['tokens'][1]['gloss']);
    }

    public function test_learning_token_enrichment_generates_cards_for_same_language_track(): void
    {
        $jobResponse = $this
            ->withExtensionAuth($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload([
                'sourceLanguage' => 'eng',
                'targetLanguage' => 'eng',
            ]))
            ->assertOk();

        $this
            ->withExtensionAuth($this->installId())
            ->postJson('/v1/learning-tokens', [
                'trackId' => $jobResponse->json('track.trackId'),
                'cueId' => 'cue-0001',
                'tokenIndex' => 0,
            ])
            ->assertOk()
            ->assertJsonPath('token.text', 'first')
            ->assertJsonPath('token.gloss', 'first gloss');

        $this->assertSame(1, $this->translationAnalysis->tokenCalls);
    }

    public function test_learning_token_enrichment_requires_owning_install(): void
    {
        $jobResponse = $this
            ->withExtensionAuth($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload())
            ->assertOk();

        $this
            ->withExtensionAuth($this->installId('b'))
            ->postJson('/v1/learning-tokens', [
                'trackId' => $jobResponse->json('track.trackId'),
                'cueId' => 'cue-0001',
                'tokenIndex' => 0,
            ])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    }

    public function test_transcription_failure_returns_stable_error_and_status(): void
    {
        $this->transcriptionService->shouldFail = true;

        $this
            ->withExtensionAuth($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload())
            ->assertStatus(502)
            ->assertJsonPath('error.code', 'transcription_failed');

        $this->assertSame(1, SubtitleJob::count());
        $this->assertSame(0, SubtitleTrack::count());
        $this->assertDatabaseHas('subtitle_jobs', [
            'youtube_video_id' => 'dQw4w9WgXcQ',
            'status' => 'failed',
            'stage' => 'transcribing',
            'error_code' => 'transcription_failed',
        ]);
        $this->assertFalse(File::exists($this->audioSource->lastAudioPath));
    }

    public function test_full_enrichment_failure_returns_stable_error_and_status(): void
    {
        config([
            'queue.default' => 'database',
            'subtitles.queue.connection' => 'database',
        ]);
        $this->translationAnalysis->shouldFail = true;

        $response = $this
            ->withExtensionAuth($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload(['enrichmentMode' => 'full']))
            ->assertAccepted()
            ->assertJsonPath('status', 'running');

        $this->runQueuedSubtitleJobs();

        $this
            ->withExtensionAuth($this->installId())
            ->getJson('/v1/subtitle-jobs/'.$response->json('jobId'))
            ->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('message', 'Subtitle enrichment failed.');

        $this->assertDatabaseHas('subtitle_jobs', [
            'youtube_video_id' => 'dQw4w9WgXcQ',
            'status' => 'failed',
            'stage' => 'enriching',
            'error_code' => 'enrichment_failed',
        ]);
    }

    public function test_create_subtitle_job_returns_stable_validation_errors(): void
    {
        $this
            ->withExtensionAuth($this->installId())
            ->postJson('/v1/subtitle-jobs', [
                'youtubeVideoId' => 'dQw4w9WgXcQ',
                'sourceLanguage' => 'zz',
                'targetLanguage' => 'auto',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['code', 'message', 'details'], 'requestId']);
    }

    public function test_create_subtitle_job_accepts_supported_youtube_url_shapes(): void
    {
        config([
            'queue.default' => 'database',
            'subtitles.queue.connection' => 'database',
        ]);
        Queue::fake();

        $shortsPayload = $this->validPayload([
            'youtubeVideoId' => 'shorts00001',
            'youtubeUrl' => 'https://www.youtube.com/shorts/shorts00001?feature=share',
        ]);
        $shortsResponse = $this
            ->withExtensionAuth($this->installId())
            ->postJson('/v1/subtitle-jobs', $shortsPayload)
            ->assertAccepted();

        $this->assertDatabaseHas('subtitle_jobs', [
            'public_id' => $shortsResponse->json('jobId'),
            'youtube_video_id' => 'shorts00001',
            'youtube_url' => 'https://www.youtube.com/shorts/shorts00001?feature=share',
        ]);

        $shortUrlPayload = $this->validPayload([
            'youtubeVideoId' => 'youtu000001',
            'youtubeUrl' => 'https://youtu.be/youtu000001',
        ]);
        $shortUrlResponse = $this
            ->withExtensionAuth($this->installId('b'))
            ->postJson('/v1/subtitle-jobs', $shortUrlPayload)
            ->assertAccepted();

        $this->assertDatabaseHas('subtitle_jobs', [
            'public_id' => $shortUrlResponse->json('jobId'),
            'youtube_video_id' => 'youtu000001',
            'youtube_url' => 'https://youtu.be/youtu000001',
        ]);
    }

    public function test_create_subtitle_job_rejects_invalid_shorts_urls(): void
    {
        $invalidUrls = [
            'https://www.youtube.com/shorts/dQw4w9WgXcQ',
            'http://www.youtube.com/shorts/shorts00001',
            'https://www.youtube.com/shorts/bad',
        ];

        foreach ($invalidUrls as $url) {
            $this
                ->withExtensionAuth($this->installId())
                ->postJson('/v1/subtitle-jobs', $this->validPayload([
                    'youtubeVideoId' => 'shorts00001',
                    'youtubeUrl' => $url,
                ]))
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'validation_failed')
                ->assertJsonPath(
                    'error.details.errors.youtubeUrl.0',
                    'The YouTube URL must be a supported YouTube URL for the requested video ID.',
                );
        }
    }

    public function test_create_subtitle_job_requires_explicit_generation_controls(): void
    {
        $payload = $this->validPayload();
        unset($payload['enrichmentMode'], $payload['includeRomanization'], $payload['includeTranslation']);

        $this
            ->withExtensionAuth($this->installId())
            ->postJson('/v1/subtitle-jobs', $payload)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.details.errors.enrichmentMode.0', 'The enrichment mode field is required.')
            ->assertJsonPath('error.details.errors.includeRomanization.0', 'The include romanization field is required.')
            ->assertJsonPath('error.details.errors.includeTranslation.0', 'The include translation field is required.');
    }

    public function test_api_requires_extension_install_id(): void
    {
        Log::spy();

        $this
            ->postJson('/v1/subtitle-jobs', $this->validPayload())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['requestId']);

        Log::shouldHaveReceived('warning')
            ->with('backend.proxy_invalid_install_id', \Mockery::on(
                fn (array $context): bool => isset($context['request_id'], $context['ip']),
            ));
    }

    public function test_no_cancel_route_is_exposed(): void
    {
        $this
            ->withExtensionAuth($this->installId())
            ->postJson('/v1/subtitle-jobs/'.(string) Str::uuid().'/cancel')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    }

    private function arabicGreeting(): string
    {
        return "\u{0645}\u{0631}\u{062D}\u{0628}\u{0627} \u{0628}\u{0643}\u{0645}";
    }

    private function japaneseSentence(): string
    {
        return "\u{79C1}\u{306F}\u{65E5}\u{672C}\u{8A9E}\u{3092}\u{52C9}\u{5F37}\u{3057}\u{3066}\u{3044}\u{307E}\u{3059}";
    }

    private function japaneseNoSpaceSentence(): string
    {
        return "\u{306D}\u{3048}\u{4ECA}\u{601D}\u{3063}\u{3066}\u{3044}\u{3066}\u{3042}\u{305D}\u{3046}\u{3058}\u{3083}\u{306A}";
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        $videoId = (string) ($overrides['youtubeVideoId'] ?? 'dQw4w9WgXcQ');

        return [
            'youtubeVideoId' => $videoId,
            'youtubeUrl' => 'https://www.youtube.com/watch?v='.$videoId,
            'videoDurationSeconds' => 213,
            'sourceLanguage' => 'auto',
            'targetLanguage' => 'eng',
            'enrichmentMode' => 'on_demand',
            'includeRomanization' => true,
            'includeTranslation' => false,
            ...$overrides,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function completedJobShape(): array
    {
        return [
            'jobId',
            'youtubeVideoId',
            'sourceLanguage',
            'targetLanguage',
            'status',
            'stage',
            'progressPercent',
            'createdAt',
            'updatedAt',
            'expiresAt',
            'track' => [
                'trackId',
                'jobId',
                'youtubeVideoId',
                'sourceLanguage',
                'targetLanguage',
                'generatedAt',
                'expiresAt',
                'webVtt',
                'cues' => [
                    '*' => ['cueId', 'index', 'startMs', 'endMs', 'sourceText', 'translatedText', 'tokens'],
                ],
            ],
        ];
    }

    private function installId(string $character = 'a'): string
    {
        return 'install_'.str_repeat($character, 32);
    }

    private function sampleWebVtt(): string
    {
        return "WEBVTT\n\n00:00:00.500 --> 00:00:02.100\nfirst transcript segment\n\n00:00:02.400 --> 00:00:04.000\nsecond transcript segment\n";
    }

    private function runningSubtitleJob(string $stage): SubtitleJob
    {
        config([
            'queue.default' => 'database',
            'subtitles.queue.connection' => 'database',
        ]);

        return SubtitleJob::factory()->create([
            'status' => 'running',
            'stage' => $stage,
            'progress_percent' => 65,
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
            'startMs' => 500,
            'endMs' => 2100,
            'sourceText' => 'first transcript segment',
            'translatedText' => 'first transcript segment',
            'tokens' => [
                ['index' => 0, 'text' => 'first', 'normalizedText' => 'first'],
            ],
        ];
    }

    private function artifacts(): SubtitleJobArtifactStore
    {
        return app(SubtitleJobArtifactStore::class);
    }

    private function dispatchCancelledBatch(object $job): void
    {
        $batch = Bus::batch([$job])
            ->onConnection(SubtitleQueue::connection())
            ->onQueue(SubtitleQueue::batchName())
            ->dispatch();

        $batch->cancel();

        $this->runQueuedSubtitleJobs();
    }

    private function runQueuedSubtitleJobs(): void
    {
        for ($attempt = 0; $attempt < 50 && DB::table('jobs')->exists(); $attempt++) {
            Artisan::call('queue:work', [
                '--queue' => SubtitleQueue::workerQueueList().',default',
                '--once' => true,
                '--tries' => 1,
                '--sleep' => 0,
            ]);
        }

        $this->assertSame(0, DB::table('jobs')->count(), Artisan::output());
    }
}
class RecordingYouTubeAudioSource extends YouTubeAudioSource
{
    public int $calls = 0;

    public ?string $lastAudioPath = null;

    public ?\Closure $beforeAcquireResult = null;

    public function acquire(string $youtubeUrl, ?int $requestDurationSeconds, string $workDirectory): TemporaryAudioFile
    {
        $this->calls++;
        parse_str((string) parse_url($youtubeUrl, PHP_URL_QUERY), $query);
        $videoId = (string) $query['v'];

        File::ensureDirectoryExists($workDirectory);

        $path = $workDirectory.DIRECTORY_SEPARATOR.$videoId.'.m4a';
        File::put($path, 'fake-audio');
        $this->lastAudioPath = $path;

        $audio = new TemporaryAudioFile(
            path: $path,
            directory: $workDirectory,
            durationSeconds: 42,
            sizeBytes: File::size($path),
            mimeType: 'audio/mp4',
        );

        $this->beforeAcquireResult?->__invoke($audio);

        return $audio;
    }
}

class RecordingTranscriptionService extends ElevenLabsScribeTranscriptionService
{
    public function __construct()
    {
        parent::__construct(
            new ScribeTranscriptNormalizer,
            new ElevenLabsScribeAudioPreparer,
            new ScribeChunkPayloadMerger,
        );
    }

    public bool $shouldFail = false;

    public ?TimestampedTranscript $transcript = null;

    public int $prepareCalls = 0;

    public int $chunkCalls = 0;

    public ?\Closure $beforePrepareResult = null;

    public ?\Closure $beforeTranscriptionResult = null;

    /**
     * @var array<int, string>
     */
    public array $sourceLanguages = [];

    public function prepareAudio(TemporaryAudioFile $audio): TemporaryAudioFile
    {
        $this->prepareCalls++;
        $this->beforePrepareResult?->__invoke($audio);

        return $audio;
    }

    public function transcribeChunk(TemporaryAudioFile $audio, string $sourceLanguage): array
    {
        $this->chunkCalls++;
        $this->sourceLanguages[] = $sourceLanguage;
        $this->beforeTranscriptionResult?->__invoke($audio);

        if ($this->shouldFail) {
            throw SubtitleProcessingException::transcriptionFailed();
        }

        return ['words' => [], 'language_code' => $sourceLanguage === 'auto' ? 'spa' : $sourceLanguage];
    }

    public function transcriptFromChunkPayloads(
        array $chunks,
        string $sourceLanguage,
        ?int $durationSeconds,
    ): TimestampedTranscript {
        if ($this->transcript !== null) {
            return $this->transcript;
        }

        return new TimestampedTranscript(
            language: $sourceLanguage === 'auto' ? 'spa' : $sourceLanguage,
            durationSeconds: 42.0,
            segments: [
                new TimestampedTranscriptSegment(0.5, 2.1, 'first transcript segment'),
                new TimestampedTranscriptSegment(2.4, 4.0, 'second transcript segment'),
            ],
            webVtt: "WEBVTT\n\n00:00:00.500 --> 00:00:02.100\nfirst transcript segment\n\n00:00:02.400 --> 00:00:04.000\nsecond transcript segment\n",
        );
    }
}

class RecordingTranslationAnalysisProvider extends LaravelAiTranslationAnalysisProvider
{
    public function __construct()
    {
        parent::__construct(new LearningTokenOutputValidator);
    }

    public int $calls = 0;

    public int $tokenizationCalls = 0;

    public int $romanizationCalls = 0;

    public int $translationCalls = 0;

    public int $tokenCalls = 0;

    public bool $shouldFail = false;

    public bool $tokenizationShouldFail = false;

    public bool $romanizationShouldFail = false;

    public bool $translationShouldFail = false;

    public ?\Closure $beforeTokenizationResult = null;

    public ?\Closure $beforeTokenResult = null;

    /**
     * @var array<int, string>
     */
    public array $sourceLanguages = [];

    /**
     * @var array<int, string>
     */
    public array $targetLanguages = [];

    /**
     * @param  array<int, array<string, mixed>>  $batch
     * @param  array<int, array<string, mixed>>  $allCues
     */
    public function tokenizeCueBatch(array $batch, array $allCues, string $sourceLanguage): CueEnrichmentResult
    {
        $this->tokenizationCalls++;
        $this->sourceLanguages[] = $sourceLanguage;

        if ($this->tokenizationShouldFail) {
            throw SubtitleProcessingException::enrichmentFailed();
        }

        $result = new CueEnrichmentResult(
            array_map(
                fn (array $cue): array => [
                    ...$cue,
                    'translatedText' => (string) $cue['sourceText'],
                    'tokens' => $this->tokenizeCue((string) $cue['sourceText'], $sourceLanguage),
                ],
                $batch,
            ),
            'unknown',
        );

        if ($this->beforeTokenizationResult !== null) {
            ($this->beforeTokenizationResult)();
        }

        return $result;
    }

    /**
     * @param  array<int, array<string, mixed>>  $batch
     */
    public function enrichCueBatch(
        array $batch,
        string $sourceLanguage,
        string $targetLanguage,
        bool $includeRomanization = true,
    ): CueEnrichmentResult {
        $this->calls++;
        $this->sourceLanguages[] = $sourceLanguage;
        $this->targetLanguages[] = $targetLanguage;

        if ($this->shouldFail) {
            throw SubtitleProcessingException::enrichmentFailed();
        }

        return new CueEnrichmentResult(
            array_map(
                function (array $cue) use ($includeRomanization): array {
                    $enrichedCue = [
                        ...$cue,
                        'translatedText' => (string) ($cue['translatedText'] ?? $cue['sourceText']),
                        'tokens' => array_map(
                            fn (array $token): array => [
                                ...$token,
                                'gloss' => $this->glossForToken((string) $token['text']),
                                ...($includeRomanization && is_string($token['romanization'] ?? null)
                                    ? ['romanization' => $token['romanization']]
                                    : []),
                            ],
                            $cue['tokens'],
                        ),
                    ];

                    if ($includeRomanization && is_string($cue['romanization'] ?? null)) {
                        $enrichedCue['romanization'] = $cue['romanization'];
                    }

                    return $enrichedCue;
                },
                $batch,
            ),
            'unknown',
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $batch
     * @param  array<int, array<string, mixed>>  $allCues
     */
    public function analyzeCueBatch(
        array $batch,
        array $allCues,
        string $sourceLanguage,
        string $targetLanguage,
    ): CueAnalysisBatchResult {
        $this->tokenizationCalls++;
        $this->translationCalls++;
        $this->sourceLanguages[] = $sourceLanguage;
        $this->targetLanguages[] = $targetLanguage;

        if ($this->tokenizationShouldFail || $this->translationShouldFail) {
            throw SubtitleProcessingException::enrichmentFailed();
        }

        $result = new CueAnalysisBatchResult(
            new CueEnrichmentResult(
                array_map(
                    fn (array $cue): array => [
                        ...$cue,
                        'translatedText' => (string) $cue['sourceText'],
                        'tokens' => $this->tokenizeCue((string) $cue['sourceText'], $sourceLanguage),
                    ],
                    $batch,
                ),
                'unknown',
            ),
            new CueEnrichmentResult(
                array_map(
                    fn (array $cue): array => [
                        ...$cue,
                        'translatedText' => 'Translated '.$cue['sourceText'],
                    ],
                    $batch,
                ),
                'unknown',
            ),
        );

        if ($this->beforeTokenizationResult !== null) {
            ($this->beforeTokenizationResult)();
        }

        return $result;
    }

    /**
     * @param  array<int, array<string, mixed>>  $batch
     */
    public function romanizeCueBatch(array $batch, string $sourceLanguage): CueEnrichmentResult
    {
        $this->romanizationCalls++;

        if ($this->romanizationShouldFail) {
            throw SubtitleProcessingException::enrichmentFailed();
        }

        return new CueEnrichmentResult(
            array_map(
                fn (array $cue): array => [
                    ...$cue,
                    'translatedText' => (string) $cue['sourceText'],
                    'romanization' => $this->romanizationForCue((string) $cue['sourceText'], $sourceLanguage),
                    'tokens' => array_map(
                        fn (array $token): array => [
                            ...$token,
                            'romanization' => $this->romanizationForToken((string) $token['text']),
                        ],
                        $cue['tokens'],
                    ),
                ],
                $batch,
            ),
            'unknown',
        );
    }

    /**
     * @param  array<string, mixed>  $cue
     * @param  array<string, mixed>  $token
     * @return array<string, mixed>
     */
    public function enrichToken(array $cue, array $token, string $sourceLanguage, string $targetLanguage): array
    {
        $this->tokenCalls++;
        $this->sourceLanguages[] = $sourceLanguage;
        $this->targetLanguages[] = $targetLanguage;

        if ($this->shouldFail) {
            throw SubtitleProcessingException::enrichmentFailed();
        }

        $text = (string) $token['text'];

        if ($this->beforeTokenResult !== null) {
            ($this->beforeTokenResult)();
        }

        return [
            'index' => $token['index'],
            'text' => $text,
            'normalizedText' => $token['normalizedText'],
            'gloss' => $text.' gloss',
            'romanization' => $text.' romanized',
            'usageNote' => 'Clicked token from cue '.$cue['cueId'].'.',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function tokenizeCue(string $sourceText, string $sourceLanguage): array
    {
        if ($sourceLanguage === 'jpn') {
            $comparableText = $this->comparableText($sourceText);

            if ($comparableText === "\u{79C1}\u{306F}\u{65E5}\u{672C}\u{8A9E}\u{3092}\u{52C9}\u{5F37}\u{3057}\u{3066}\u{3044}\u{307E}\u{3059}") {
                return $this->japaneseLearningTokens();
            }

            if ($comparableText === "\u{306D}\u{3048}\u{4ECA}\u{601D}\u{3063}\u{3066}\u{3044}\u{3066}\u{3042}\u{305D}\u{3046}\u{3058}\u{3083}\u{306A}") {
                return [
                    ['index' => 0, 'text' => "\u{306D}\u{3048}", 'normalizedText' => "\u{306D}\u{3048}"],
                    ['index' => 1, 'text' => "\u{4ECA}", 'normalizedText' => "\u{4ECA}"],
                    ['index' => 2, 'text' => "\u{601D}\u{3063}\u{3066}\u{3044}\u{3066}", 'normalizedText' => "\u{601D}\u{3063}\u{3066}\u{3044}\u{3066}"],
                    ['index' => 3, 'text' => "\u{3042}\u{305D}\u{3046}\u{3058}\u{3083}\u{306A}", 'normalizedText' => "\u{3042}\u{305D}\u{3046}\u{3058}\u{3083}\u{306A}"],
                ];
            }
        }

        $words = array_values(array_filter(
            preg_split('/\s+/u', trim($sourceText)) ?: [],
            fn (string $word): bool => $word !== '',
        ));

        if ($words === []) {
            $words = [$sourceText];
        }

        return array_map(
            fn (string $word, int $index): array => [
                'index' => $index,
                'text' => $word,
                'normalizedText' => strtolower($word),
            ],
            $words,
            array_keys($words),
        );
    }

    private function glossForToken(string $token): string
    {
        return match ($token) {
            "\u{65E5}\u{672C}\u{8A9E}" => 'Japanese language',
            "\u{52C9}\u{5F37}\u{3057}\u{3066}\u{3044}\u{307E}\u{3059}" => 'am studying',
            default => $token,
        };
    }

    private function romanizationForCue(string $sourceText, string $sourceLanguage): string
    {
        return $sourceLanguage === 'jpn' && $this->comparableText($sourceText) === "\u{79C1}\u{306F}\u{65E5}\u{672C}\u{8A9E}\u{3092}\u{52C9}\u{5F37}\u{3057}\u{3066}\u{3044}\u{307E}\u{3059}"
            ? 'watashi wa nihongo o benkyo shite imasu'
            : 'romanized '.$sourceText;
    }

    private function romanizationForToken(string $token): string
    {
        return match ($token) {
            "\u{79C1}" => 'watashi',
            "\u{306F}" => 'wa',
            "\u{65E5}\u{672C}\u{8A9E}" => 'nihongo',
            "\u{3092}" => 'o',
            "\u{52C9}\u{5F37}\u{3057}\u{3066}\u{3044}\u{307E}\u{3059}" => 'benkyo shite imasu',
            default => 'romanized '.$token,
        };
    }

    private function comparableText(string $text): string
    {
        return (string) preg_replace('/\s+/u', '', $text);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function japaneseLearningTokens(): array
    {
        return [
            ['index' => 0, 'text' => "\u{79C1}", 'normalizedText' => "\u{79C1}"],
            ['index' => 1, 'text' => "\u{306F}", 'normalizedText' => "\u{306F}"],
            ['index' => 2, 'text' => "\u{65E5}\u{672C}\u{8A9E}", 'normalizedText' => "\u{65E5}\u{672C}\u{8A9E}"],
            ['index' => 3, 'text' => "\u{3092}", 'normalizedText' => "\u{3092}"],
            ['index' => 4, 'text' => "\u{52C9}\u{5F37}\u{3057}\u{3066}\u{3044}\u{307E}\u{3059}", 'normalizedText' => "\u{52C9}\u{5F37}\u{3057}\u{3066}\u{3044}\u{307E}\u{3059}"],
        ];
    }
}
