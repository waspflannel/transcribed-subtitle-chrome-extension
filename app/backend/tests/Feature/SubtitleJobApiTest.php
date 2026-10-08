<?php

namespace Tests\Feature;

use App\Ai\Agents\CueAnalysisAgent;
use App\Ai\Agents\EditedCueAgent;
use App\Ai\Agents\LyricsAlignmentAgent;
use App\Exceptions\SubtitleProcessingException;
use App\Jobs\AcquireSubtitleAudio;
use App\Jobs\AnalyzeSubtitleCueBatch;
use App\Jobs\LyricsCorrectionJob;
use App\Jobs\OptimizeSubtitleAudio;
use App\Jobs\TranscribeSubtitleAudioChunk;
use App\Models\CachedVideoTranscript;
use App\Models\SubtitleJob;
use App\Models\SubtitleJobEvent;
use App\Models\SubtitleTrack;
use App\Models\SubtitleTrackLyricsCorrection;
use App\Services\Audio\ElevenLabsScribeAudioPreparer;
use App\Services\Audio\ScribeAudioChunker;
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
use App\Services\Transcription\VideoTranscriptCache;
use App\Services\TranslationAnalysis\CueEnrichmentResult;
use App\Services\TranslationAnalysis\LaravelAiTranslationAnalysisProvider;
use App\Services\TranslationAnalysis\LearningTokenEnrichmentService;
use App\Support\SubtitleProcessingVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\NullQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Ai\Exceptions\RateLimitedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SubtitleJobApiTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['auto'])]
    #[TestWith(['typesafe'])]
    public function test_generation_rejects_retired_model_routing(string $provider): void
    {
        $this->withExtensionInstall($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload(['aiProvider' => $provider]))
            ->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
        $this->assertDatabaseCount('subtitle_jobs', 0);
        $this->assertSame(0, $this->transcriptionService->chunkCalls);
        Http::assertNothingSent();
    }

    public function test_provider_and_exact_model_separate_jobs_but_share_transcription(): void
    {
        config(['ai.providers.openai.models.text.default' => 'luna-original', 'ai.providers.cerebras.models.text.default' => 'cerebras-original']);
        $payload = $this->validPayload(['aiProvider' => 'openai']);
        $luna = $this->withExtensionInstall($this->installId())->postJson('/v1/subtitle-jobs', $payload)->assertOk()
            ->assertJsonPath('aiProvider', 'openai')->assertJsonPath('aiModel', 'luna-original');
        $cerebras = $this->postJson('/v1/subtitle-jobs', [...$payload, 'aiProvider' => 'cerebras'])->assertOk()
            ->assertJsonPath('aiProvider', 'cerebras')->assertJsonPath('aiModel', 'cerebras-original');
        $this->assertNotSame($luna->json('jobId'), $cerebras->json('jobId'));
        $this->postJson('/v1/subtitle-jobs', $payload)->assertOk()->assertJsonPath('jobId', $luna->json('jobId'));
        $this->postJson('/v1/subtitle-jobs', [...$payload, 'aiProvider' => 'cerebras'])->assertOk()->assertJsonPath('jobId', $cerebras->json('jobId'));
        $this->assertSame([['openai', 'luna-original'], ['cerebras', 'cerebras-original']], $this->translationAnalysis->selections);

        config(['ai.default' => 'cerebras', 'ai.providers.openai.models.text.default' => 'luna-updated']);
        $this->postJson('/v1/subtitle-jobs', $payload)->assertOk()->assertJsonPath('aiModel', 'luna-updated');
        $this->assertSame(3, SubtitleJob::count());
        $this->assertSame(1, $this->transcriptionService->chunkCalls);
        $this->getJson('/v1/subtitle-jobs/'.$luna->json('jobId'))->assertOk()->assertJsonPath('aiModel', 'luna-original');
        $this->getJson('/v1/subtitle-jobs')->assertOk()->assertJsonCount(3, 'jobs');
    }

    public function test_queued_job_keeps_its_model_after_configuration_changes(): void
    {
        config(['subtitles.queue.connection' => 'database', 'ai.providers.cerebras.models.text.default' => 'pinned-model']);
        $response = $this->withExtensionInstall($this->installId())->postJson('/v1/subtitle-jobs', $this->validPayload(['aiProvider' => 'cerebras']))->assertAccepted();
        $job = SubtitleJob::where('public_id', $response->json('jobId'))->firstOrFail();
        config(['ai.default' => 'openai', 'ai.providers.cerebras.models.text.default' => 'changed-model', 'subtitles.queue.connection' => 'sync']);
        (new AcquireSubtitleAudio($job->id, $job->run_id))->handle(app(SubtitleGenerationPipeline::class));
        $this->assertSame('completed', $job->refresh()->status);
        $this->assertSame([['cerebras', 'pinned-model']], $this->translationAnalysis->selections);
        $costs = $job->events()->where('event', 'provider.cost_estimated')->where('stage', '!=', 'transcribing')->get();
        $this->assertNotEmpty($costs);
        foreach ($costs as $cost) {
            $this->assertSame('cerebras', $cost->context['provider']);
            $this->assertSame('pinned-model', $cost->context['model']);
        }
    }

    public function test_invalid_or_unconfigured_provider_cannot_create_jobs(): void
    {
        $this->withExtensionInstall($this->installId());
        foreach (['hybrid', 'unknown', null, 123] as $provider) {
            $this->postJson('/v1/subtitle-jobs', $this->validPayload(['aiProvider' => $provider]))->assertUnprocessable();
        }
        config(['ai.providers.cerebras.key' => '']);
        $this->postJson('/v1/subtitle-jobs', $this->validPayload(['aiProvider' => 'cerebras']))->assertUnprocessable();
        $this->assertSame(0, SubtitleJob::count());
        $this->assertSame(0, $this->audioSource->calls);
    }

    public function test_legacy_vocabulary_hints_are_ignored_without_changing_price_or_job_reuse(): void
    {
        config(['subtitles.costs.elevenlabs_scribe_microusd_per_minute' => 1000]);
        $payload = $this->validPayload(['youtubeVideoId' => 'hintstest01', 'vocabularyHints' => ['Marie Curie', 'ElevenLabs', 'Marie Curie']]);
        $created = $this->withExtensionInstall($this->installId())->postJson('/v1/subtitle-jobs', $payload)->assertOk();
        $this->assertArrayNotHasKey('vocabulary_hints', SubtitleJob::query()->firstOrFail()->getAttributes());
        $transcriptionCost = SubtitleJobEvent::query()->where('event', 'provider.cost_estimated')->where('stage', 'transcribing')->firstOrFail();
        $this->assertSame(1000, $transcriptionCost->context['unit_price_microusd']);
        $this->assertStringNotContainsString('Marie Curie', json_encode($transcriptionCost->context));
        $this->postJson('/v1/subtitle-jobs', [...$payload, 'vocabularyHints' => ['another name']])
            ->assertOk()->assertJsonPath('jobId', $created->json('jobId'));
        unset($payload['vocabularyHints']);
        $this->postJson('/v1/subtitle-jobs', $payload)->assertOk()->assertJsonPath('jobId', $created->json('jobId'));
        $this->assertSame(1, $this->transcriptionService->chunkCalls);
        $this->assertSame(1, SubtitleJob::count());
    }

    public function test_url_mode_validates_metadata_and_completes_without_download_or_encoding(): void
    {
        config(['subtitles.transcription.ingestion_mode' => 'youtube_url']);
        $this->withExtensionInstall($this->installId())->postJson('/v1/subtitle-jobs', $this->validPayload())->assertOk();

        $this->assertSame(1, $this->audioSource->metadataCalls);
        $this->assertSame(0, $this->audioSource->calls);
        $this->assertSame(0, $this->transcriptionService->prepareCalls);
        $this->assertSame(1, $this->transcriptionService->urlCalls);
        $this->assertSame('completed', SubtitleJob::query()->firstOrFail()->status);
    }

    public function test_url_mode_stops_before_provider_call_when_metadata_is_rejected(): void
    {
        config(['subtitles.transcription.ingestion_mode' => 'youtube_url']);
        $this->audioSource->rejectMetadata = true;
        $this->withExtensionInstall($this->installId())->postJson('/v1/subtitle-jobs', $this->validPayload());

        $this->assertSame(0, $this->transcriptionService->urlCalls);
        $this->assertSame('failed', SubtitleJob::query()->firstOrFail()->status);
    }

    public function test_jobs_reuse_only_the_same_transcription_ingestion_mode(): void
    {
        $payload = $this->validPayload();
        $upload = $this->withExtensionInstall($this->installId())->postJson('/v1/subtitle-jobs', $payload)->assertOk();
        config(['subtitles.transcription.ingestion_mode' => 'youtube_url']);
        $url = $this->postJson('/v1/subtitle-jobs', $payload)->assertOk();

        $this->assertNotSame($upload->json('jobId'), $url->json('jobId'));
        $this->postJson('/v1/subtitle-jobs', $payload)->assertOk()->assertJsonPath('jobId', $url->json('jobId'));
        config(['subtitles.transcription.ingestion_mode' => 'upload']);
        $this->postJson('/v1/subtitle-jobs', $payload)->assertOk()->assertJsonPath('jobId', $upload->json('jobId'));
        $this->assertSame(2, SubtitleJob::count());
        $this->assertSame(1, $this->transcriptionService->chunkCalls);
        $this->assertSame(1, $this->transcriptionService->urlCalls);
    }

    public function test_transcript_cache_separates_ingestion_modes_with_keys_that_fit_the_database_column(): void
    {
        config(['ai.providers.eleven.models.transcription.default' => 'scribe_v2']);
        $cache = app(VideoTranscriptCache::class);
        $transcript = $this->transcriptionService->transcriptFromChunkPayloads([], 'spa', 42);
        $cache->store('dQw4w9WgXcQ', 'spa', $transcript, 42);

        $upload = $cache->find('dQw4w9WgXcQ', 'spa');
        $this->assertNotNull($upload);
        $this->assertNull($cache->find('dQw4w9WgXcQ', 'spa', 'youtube_url'));
        $cache->store('dQw4w9WgXcQ', 'spa', $transcript, 42, 'youtube_url');
        $url = $cache->find('dQw4w9WgXcQ', 'spa', 'youtube_url');
        $this->assertNotNull($url);
        $this->assertNotSame($upload->id, $url->id);
        $this->assertSame($upload->id, $cache->find('dQw4w9WgXcQ', 'spa')->id);
        $this->assertSame(2, CachedVideoTranscript::count());
        foreach ([$upload, $url] as $entry) {
            $this->assertLessThanOrEqual(64, strlen($entry->transcription_model));
        }
    }

    public function test_generation_accepts_long_videos_without_paid_tiers_or_a_subscription(): void
    {
        Queue::fake();
        $this->withExtensionInstall($this->installId());
        for ($index = 0; $index < 12; $index++) {
            $response = $this->postJson('/v1/subtitle-jobs', $this->validPayload([
                'youtubeVideoId' => sprintf('longvid%04d', $index),
                'videoDurationSeconds' => 7200,
            ]))->assertAccepted()->assertJsonPath('status', 'running');
            $response->assertJsonMissingPath('generationTier');
        }
        Queue::assertPushed(AcquireSubtitleAudio::class, 12);
    }

    public function test_generation_requires_elevenlabs_and_only_the_selected_analysis_key(): void
    {
        $this->withExtensionInstall($this->installId());
        config(['ai.providers.eleven.key' => '']);
        $this->postJson('/v1/subtitle-jobs', $this->validPayload())->assertUnprocessable();
        $this->assertDatabaseCount('subtitle_jobs', 0);
        $this->assertSame(0, $this->audioSource->calls);
        config(['ai.providers.eleven.key' => 'test-elevenlabs-key', 'ai.providers.openai.key' => '']);
        $this->postJson('/v1/subtitle-jobs', $this->validPayload(['aiProvider' => 'cerebras']))->assertOk();
    }

    public function test_saved_tracks_have_no_expiry_by_default_and_optional_retention_is_applied(): void
    {
        $this->withExtensionInstall($this->installId());
        $saved = $this->postJson('/v1/subtitle-jobs', $this->validPayload())->assertOk()
            ->assertJsonPath('expiresAt', null)->assertJsonPath('track.expiresAt', null);
        $this->getJson('/v1/subtitle-jobs')->assertOk()->assertJsonPath('jobs.0.expiresAt', null);
        $this->putJson('/v1/settings', ['retentionDays' => 7])->assertOk();
        $next = $this->postJson('/v1/subtitle-jobs', $this->validPayload(['youtubeVideoId' => 'retention01']))->assertOk();
        $this->assertNotNull($next->json('expiresAt'));
        $this->getJson('/v1/subtitle-jobs/'.$saved->json('jobId'))->assertOk();
    }

    private RecordingYouTubeAudioSource $audioSource;

    private RecordingTranscriptionService $transcriptionService;

    private RecordingTranslationAnalysisProvider $translationAnalysis;

    private ?\Closure $duringQuickFix = null;

    protected function setUp(): void
    {
        parent::setUp();

        EditedCueAgent::fake(function ($prompt): array {
            ($this->duringQuickFix)?->__invoke();
            $input = json_decode($prompt, true);
            $cue = $input['cues'][0];

            return [
                'dialect' => 'unknown',
                'translatedText' => 'Refreshed translation',
                'cues' => [[...$cue, 'romanization' => 'refreshed pronunciation', 'tokens' => array_map(
                    fn (array $token): array => [...$token, 'translation' => 'new meaning', 'gloss' => 'new gloss', 'romanization' => 'new reading'],
                    $cue['tokens'],
                )]],
            ];
        })->preventStrayPrompts();

        $this->audioSource = new RecordingYouTubeAudioSource;
        $this->transcriptionService = new RecordingTranscriptionService;
        $this->translationAnalysis = new RecordingTranslationAnalysisProvider;

        $this->app->instance(YouTubeAudioSource::class, $this->audioSource);
        $this->app->instance(ElevenLabsScribeTranscriptionService::class, $this->transcriptionService);
        $this->app->instance(LaravelAiTranslationAnalysisProvider::class, $this->translationAnalysis);
        config([
            'queue.default' => 'sync',
            'subtitles.queue.connection' => 'sync',
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
            ->withExtensionInstall($this->installId())
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
            ->withExtensionInstall($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload())
            ->assertAccepted();

        $second = $this
            ->withExtensionInstall($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload())
            ->assertAccepted();

        $this->assertSame($first->json('jobId'), $second->json('jobId'));
        Queue::assertPushed(AcquireSubtitleAudio::class, 1);
    }

    public function test_cancelling_before_worker_pickup_prevents_provider_calls(): void
    {
        $this->travelTo(now()->startOfSecond());
        Queue::fake();
        $installId = $this->installId('q');
        SubtitleJob::factory()->create(['status' => 'running']);

        $response = $this
            ->withExtensionInstall($installId)
            ->postJson('/v1/subtitle-jobs', $this->validPayload(['youtubeVideoId' => 'canq0000001']))
            ->assertAccepted()
            ->assertJsonPath('status', 'running');
        $job = SubtitleJob::query()->where('public_id', $response->json('jobId'))->firstOrFail();

        $this
            ->withExtensionInstall($installId)
            ->deleteJson('/v1/subtitle-jobs/'.$job->public_id)
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');

        $this->assertSame('cancelled', $job->fresh()->status);
        $this->assertTrue($job->fresh()->expires_at->equalTo(now()->addDays(30)));
        Queue::assertPushed(AcquireSubtitleAudio::class, 1);
        (new AcquireSubtitleAudio($job->id, $job->run_id))->handle(app(SubtitleGenerationPipeline::class));
        $this->assertSame(0, $this->audioSource->calls);
    }

    public function test_generation_cancellation_is_shared_and_rejects_terminal_jobs(): void
    {
        $ownerInstallId = $this->installId('o');
        $intruderInstallId = $this->installId('i');
        $ownedJob = SubtitleJob::factory()->create([
            'install_id' => $ownerInstallId,
            'status' => 'running',
            'stage' => 'preparing',
        ]);

        $this
            ->withExtensionInstall($intruderInstallId)
            ->deleteJson('/v1/subtitle-jobs/'.$ownedJob->public_id)
            ->assertOk();

        $this->assertSame('cancelled', $ownedJob->fresh()->status);

        $completedJob = SubtitleJob::factory()->create([
            'install_id' => $ownerInstallId,
            'status' => 'completed',
            'stage' => 'finalizing',
            'progress_percent' => 100,
        ]);

        $this
            ->withExtensionInstall($ownerInstallId)
            ->deleteJson('/v1/subtitle-jobs/'.$completedJob->public_id)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'generation_not_cancellable');
    }

    public function test_generation_publication_failure_settles_created_run_and_retry_dispatches_once(): void
    {
        $queue = $this->configureThrowingQueue();
        $installId = $this->installId('p');
        $payload = $this->validPayload(['youtubeVideoId' => 'pubfail0001']);

        $failedResponse = $this
            ->withExtensionInstall($installId)
            ->postJson('/v1/subtitle-jobs', $payload)
            ->assertAccepted()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('errorCode', 'queue_publication_failed')
            ->assertJsonPath('message', 'Generation could not be queued. Try again.');

        $job = SubtitleJob::query()->where('public_id', $failedResponse->json('jobId'))->firstOrFail();

        $this->assertSame('failed', $job->status);
        $this->assertSame(0, (int) SubtitleJob::query()
            ->where('status', 'running')
            ->count());
        $this->assertSame(1, $queue->pushes);

        $failedRunId = $job->run_id;
        $queue->shouldThrow = false;

        $retryResponse = $this
            ->withExtensionInstall($installId)
            ->postJson('/v1/subtitle-jobs', $payload)
            ->assertAccepted()
            ->assertJsonPath('jobId', $job->public_id)
            ->assertJsonPath('status', 'running');

        $retryJob = $job->fresh();
        $this->assertNotSame($failedRunId, $retryJob->run_id);
        $this->assertSame($retryJob->public_id, $retryResponse->json('jobId'));
        $this->assertSame(1, (int) SubtitleJob::query()
            ->where('status', 'running')
            ->count());
        $this->assertSame(2, $queue->pushes);
    }

    public function test_new_subtitle_request_uses_configured_subtitle_queue_connection(): void
    {
        config([
            'queue.default' => 'database',
            'subtitles.queue.connection' => 'background',
        ]);
        Queue::fake();

        $this
            ->withExtensionInstall($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload(['youtubeVideoId' => 'bgqueue0001']))
            ->assertAccepted();

        Queue::assertPushed(AcquireSubtitleAudio::class, function (AcquireSubtitleAudio $job): bool {
            return $job->connection === 'background'
                && $job->queue === SubtitleQueue::generationName();
        });
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
            'processing_version' => SubtitleJobService::processingVersionFor(true, false),
            'include_romanization' => true,
            'include_translation' => false,
            'status' => 'running',
            'stage' => 'preparing',
            'progress_percent' => 5,
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);

        $response = $this
            ->withExtensionInstall($installId)
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
            ->withExtensionInstall($this->installId())
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
        $this->assertSame(1, DB::table('jobs')->where('queue', SubtitleQueue::batchName())->count());
    }

    public function test_cancelled_tokenization_batch_skips_provider_calls(): void
    {
        $job = $this->runningSubtitleJob('tokenizing');
        $this->artifacts()->putCueCollection($job, SubtitleJobArtifactStore::DRAFT_CUES, [$this->sampleCue()]);

        $this->dispatchCancelledBatch(new AnalyzeSubtitleCueBatch($job->id, 0, $job->run_id));

        $this->assertSame(0, $this->translationAnalysis->tokenizationCalls);
    }

    public function test_luna_annotations_preserve_source_cues_in_the_final_track_and_webvtt(): void
    {
        $this->app->forgetInstance(LaravelAiTranslationAnalysisProvider::class);
        CueAnalysisAgent::fake([['dialect' => 'unknown', 'cues' => array_map(fn (string $prefix, int $index): array => [
            'cueId' => sprintf('cue-%04d', $index + 1), 'index' => $index,
            'tokens' => array_map(fn (string $text, int $i): array => ['index' => $i, 'text' => $text],
                [$prefix, 'transcript', 'segment'], range(0, 2)),
        ], ['first', 'second'], [0, 1])]])->preventStrayPrompts();
        $response = $this->withExtensionInstall($this->installId())->postJson('/v1/subtitle-jobs', $this->validPayload())
            ->assertOk()->assertJsonCount(2, 'track.cues')
            ->assertJsonPath('track.cues.0.startMs', 500)->assertJsonPath('track.cues.1.endMs', 4000)
            ->assertJsonPath('track.cues.0.sourceText', 'first transcript segment')
            ->assertJsonPath('track.cues.1.tokens.0.text', 'second');
        $track = SubtitleTrack::where('public_id', $response->json('track.trackId'))->firstOrFail();
        $this->assertStringContainsString('first transcript segment', $track->web_vtt);
        $this->assertStringContainsString('second transcript segment', $track->web_vtt);
        $this->assertStringNotContainsString('first transcript segment second', $track->web_vtt);
    }

    public function test_completed_analysis_batch_is_not_prompted_again_on_redelivery(): void
    {
        $job = $this->runningSubtitleJob('tokenizing');
        $this->artifacts()->putCueCollection($job, SubtitleJobArtifactStore::DRAFT_CUES, [$this->sampleCue()]);
        $processor = app(SubtitleCueBatchProcessor::class);
        $processor->analyzeCueBatch($job->id, 0, $job->run_id);
        $processor->analyzeCueBatch($job->id, 0, $job->run_id);
        $this->assertSame(1, $this->translationAnalysis->tokenizationCalls);
        $this->assertDatabaseCount('subtitle_tracks', 0);
        $this->assertTrue($this->artifacts()->hasArtifact($job, SubtitleJobArtifactStore::ANALYZED_CUES, 0));
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_malformed_analysis_gets_one_identical_retry_without_substitute_tokens(bool $retrySucceeds): void
    {
        $this->app->forgetInstance(LaravelAiTranslationAnalysisProvider::class);
        $job = $this->runningSubtitleJob('tokenizing');
        $cue = $this->sampleCue();
        $this->artifacts()->putCueCollection($job, SubtitleJobArtifactStore::DRAFT_CUES, [$cue]);
        $prompts = [];
        CueAnalysisAgent::fake(function (string $prompt) use ($cue, $retrySucceeds, &$prompts): array {
            $prompts[] = $prompt;

            return ['dialect' => 'unknown', 'cues' => [[
                ...$cue,
                'translatedText' => 'Meaning',
                'romanization' => 'Reading',
                'tokens' => count($prompts) === 2 && $retrySucceeds ? [['index' => 0, 'text' => $cue['sourceText'], 'romanization' => 'Reading']] : [],
            ]]];
        })->preventStrayPrompts();

        try {
            app(SubtitleCueBatchProcessor::class)->analyzeCueBatch($job->id, 0, $job->run_id);
        } catch (SubtitleProcessingException $exception) {
            $this->assertFalse($retrySucceeds);
            $this->assertSame('enrichment_failed', $exception->publicCode);
        }

        $this->assertCount(2, $prompts);
        $this->assertSame($prompts[0], $prompts[1]);
        if (! $retrySucceeds) {
            $this->assertFalse($this->artifacts()->hasArtifact($job, SubtitleJobArtifactStore::ANALYZED_CUES, 0));

            return;
        }
        $this->assertTrue($this->artifacts()->hasArtifact($job, SubtitleJobArtifactStore::ANALYZED_CUES, 0));
        $this->assertSame('running', $job->fresh()->status);
        $stored = $this->artifacts()->cueBatchResult($job, SubtitleJobArtifactStore::ANALYZED_CUES, 0)->cues[0];
        $this->assertSame($cue['sourceText'], $stored['sourceText']);
        $this->assertNotContains('invented', array_column($stored['tokens'], 'text'));
    }

    public function test_cancelled_analysis_batch_skips_provider_calls(): void
    {
        $job = $this->runningSubtitleJob('tokenizing');
        $this->artifacts()->putCueCollection($job, SubtitleJobArtifactStore::DRAFT_CUES, [$this->sampleCue()]);

        $this->dispatchCancelledBatch(new AnalyzeSubtitleCueBatch($job->id, 0, $job->run_id));

        $this->assertSame(0, $this->translationAnalysis->translationCalls);
        $this->assertSame(0, $this->translationAnalysis->tokenizationCalls);
    }

    #[DataProvider('inactiveAnalysisRuns')]
    public function test_late_invalid_analysis_cannot_change_a_stopped_or_replaced_run(string $status, bool $replaceRun, bool $multipleCues): void
    {
        $this->app->forgetInstance(LaravelAiTranslationAnalysisProvider::class);
        $job = $this->runningSubtitleJob('tokenizing');
        $runId = $job->run_id;
        $nextRunId = $replaceRun ? (string) Str::uuid() : $runId;
        $cues = [$this->sampleCue()];
        if ($multipleCues) {
            $cues[] = [...$this->sampleCue(), 'cueId' => 'cue-0002', 'index' => 1, 'sourceText' => 'hello'];
        }
        $this->artifacts()->putCueCollection($job, SubtitleJobArtifactStore::DRAFT_CUES, $cues);
        $calls = 0;
        CueAnalysisAgent::fake(function () use ($job, $status, $nextRunId, $cues, &$calls): array {
            $calls++;
            $job->forceFill([
                'status' => $status,
                'run_id' => $nextRunId,
                'error_code' => $status === 'failed' ? 'sibling_batch_failed' : null,
            ])->save();

            return ['dialect' => 'unknown', 'cues' => array_map(fn (array $cue): array => [
                ...$cue,
                'tokens' => $cue['index'] === 0 ? [] : [['index' => 0, 'text' => $cue['sourceText']]],
            ], $cues)];
        })->preventStrayPrompts();

        app(SubtitleCueBatchProcessor::class)->analyzeCueBatch($job->id, 0, $runId);

        $this->assertSame(1, $calls);
        $this->assertSame($status, $job->fresh()->status);
        $this->assertSame($nextRunId, $job->fresh()->run_id);
        $this->assertSame($status === 'failed' ? 'sibling_batch_failed' : null, $job->fresh()->error_code);
        $this->assertSame(0, DB::table('subtitle_job_artifacts')->where('subtitle_job_id', $job->id)->whereIn('artifact_type', [
            SubtitleJobArtifactStore::ANALYZED_CUES,
        ])->count());
        $this->assertSame(0, SubtitleJobEvent::query()->where('subtitle_job_id', $job->id)->where('event', 'provider.cost_estimated')->count());
    }

    public static function inactiveAnalysisRuns(): array
    {
        return [
            'cancelled single cue' => ['cancelled', false, false],
            'cancelled subset' => ['cancelled', false, true],
            'sibling failed single cue' => ['failed', false, false],
            'sibling failed subset' => ['failed', false, true],
            'run replaced single cue' => ['running', true, false],
            'run replaced subset' => ['running', true, true],
        ];
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
            ->withExtensionInstall($this->installId())
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
            ->withExtensionInstall($this->installId())
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

    #[TestWith(['audio/mp4', 0])]
    #[TestWith(['audio/webm', 1])]
    public function test_direct_chunk_experiment_keeps_webm_on_the_normalized_path(string $mimeType, int $prepareCalls): void
    {
        config([
            'subtitles.audio_preparation.direct_chunks' => true,
            'subtitles.transcription.chunking.first_seconds' => 0,
            'subtitles.transcription.chunking.min_audio_seconds' => 240,
            'subtitles.transcription.chunking.target_seconds' => 120,
            'ai.providers.eleven.key' => 'fake-key',
            'ai.providers.eleven.models.transcription.default' => 'scribe_v2',
        ]);
        Bus::fake();
        $job = $this->runningSubtitleJob('optimizing-audio');
        $audio = new TemporaryAudioFile('unused-source', 'unused-directory', 300, 1, $mimeType);
        $this->partialMock(ScribeAudioChunker::class, function ($mock): void {
            $mock->shouldNotReceive('extractChunk');
        });

        app(SubtitleGenerationPipeline::class)->optimizeAudioAndDispatchTranscription($job->id, $job->run_id, $audio);

        $this->assertSame($prepareCalls, $this->transcriptionService->prepareCalls);
        $this->assertSame('transcribing', $job->fresh()->stage);
        $this->assertSame(0, $this->transcriptionService->chunkCalls);
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

        app(SubtitleCueBatchProcessor::class)->analyzeCueBatch($job->id, 0, $job->run_id);

        $this->assertDatabaseMissing('subtitle_job_artifacts', [
            'subtitle_job_id' => $job->id,
        ]);
    }

    public function test_default_generation_returns_transcript_first_track_without_full_card_enrichment(): void
    {
        $response = $this
            ->withExtensionInstall($this->installId())
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
        $this->assertSame(0, $this->translationAnalysis->romanizationCalls);
        $this->assertSame(0, $this->translationAnalysis->translationCalls);
    }

    public function test_completed_track_accepts_pasted_lyrics_and_queues_one_correction(): void
    {
        config([
            'queue.default' => 'sync',
            'subtitles.queue.connection' => 'sync',
        ]);
        $jobResponse = $this->withExtensionInstall($this->installId())->postJson('/v1/subtitle-jobs', $this->validPayload())->assertOk();
        $job = SubtitleJob::query()->where('public_id', $jobResponse->json('jobId'))->firstOrFail();
        Queue::fake();

        $response = $this->withExtensionInstall($this->installId())->postJson('/v1/subtitle-jobs/'.$job->public_id.'/lyrics', ['expectedTrackId' => $job->track->public_id,
            'lyrics' => "first transcript segment\nsecond transcript segment",
        ]);

        $response->assertAccepted()->assertJsonPath('status', 'queued')->assertJsonStructure(['attemptId', 'status', 'updatedAt']);
        Queue::assertPushed(LyricsCorrectionJob::class, fn (LyricsCorrectionJob $queued): bool => $queued->trackId === $job->track->id && $queued->subtitleJobId === $job->id && $queued->attemptId === $response->json('attemptId') && $queued->expectedRevision === 0);
        $this->assertDatabaseMissing('subtitle_track_lyrics_corrections', ['lyrics' => 'first transcript segment second transcript segment']);
    }

    public function test_correction_rebuilds_track_atomically_and_clears_lyrics(): void
    {
        config(['ai.default' => 'cerebras', 'ai.providers.cerebras.models.text.default' => 'saved-cerebras']);
        LyricsAlignmentAgent::fake([
            ['isMatch' => true, 'isComplete' => true, 'cues' => [
                ['cueId' => 'cue-0001', 'index' => 0, 'endPartIndex' => 2],
                ['cueId' => 'cue-0002', 'index' => 1, 'endPartIndex' => 5],
            ]],
        ]);
        $jobResponse = $this->withExtensionInstall($this->installId())->postJson('/v1/subtitle-jobs', $this->validPayload())->assertOk();
        $job = SubtitleJob::query()->where('public_id', $jobResponse->json('jobId'))->firstOrFail();
        config(['ai.default' => 'openai', 'ai.providers.openai.models.text.default' => 'alignment-luna', 'ai.providers.cerebras.models.text.default' => 'changed-cerebras']);
        $this->translationAnalysis->selections = [];
        $oldTrackId = $job->track->public_id;
        $correction = $this->withExtensionInstall($this->installId())->postJson('/v1/subtitle-jobs/'.$job->public_id.'/lyrics', ['expectedTrackId' => $job->track->public_id,
            'lyrics' => "First lyric line\nSecond lyric line",
        ])->assertAccepted();

        $this->runCorrectionToCompletion($job, $correction->json('attemptId'));

        $job->refresh()->load('track');
        $this->assertNotSame($oldTrackId, $job->track->public_id);
        $this->assertSame('First lyric line', $job->track->cues[0]['sourceText']);
        $this->assertSame('lyrics-'.substr(str_replace('-', '', $correction->json('attemptId')), 0, 8).'-0001', $job->track->cues[0]['cueId']);
        $this->assertStringContainsString('First lyric line', $job->track->web_vtt);
        $this->assertNull($job->track->lyricsCorrection->lyrics);
        $this->assertSame('completed', $job->track->lyricsCorrection->status);
        LyricsAlignmentAgent::assertPrompted(fn ($prompt): bool => $prompt->provider->name() === 'cerebras' && $prompt->model === 'saved-cerebras');
        foreach ($this->translationAnalysis->selections as $selection) {
            $this->assertSame(['cerebras', 'saved-cerebras'], $selection);
        }
    }

    public function test_quick_fix_replaces_one_token_and_refreshes_derived_cue_data(): void
    {
        config(['ai.default' => 'cerebras', 'ai.providers.cerebras.models.text.default' => 'saved-cerebras']);
        $jobResponse = $this->withExtensionInstall($this->installId())->postJson('/v1/subtitle-jobs', $this->validPayload())->assertOk();
        $job = SubtitleJob::query()->where('public_id', $jobResponse->json('jobId'))->firstOrFail();
        config(['ai.default' => 'openai', 'ai.providers.cerebras.models.text.default' => 'changed-cerebras']);
        $oldTrackId = $job->track->public_id;
        $oldCueId = $job->track->cues[0]['cueId'];
        $oldGeneratedAt = $job->track->generated_at->toJSON();
        $oldExpiresAt = $job->track->expires_at?->toJSON();
        $unchangedCue = $job->track->cues[1];
        $tokenizationCalls = $this->translationAnalysis->tokenizationCalls;

        $response = $this->withExtensionInstall($this->installId())->patchJson(
            '/v1/subtitle-jobs/'.$job->public_id.'/cues/'.$oldCueId.'/tokens/0',
            ['expectedTrackId' => $oldTrackId, 'text' => 'updated'],
        );

        $response
            ->assertOk()
            ->assertJsonPath('cues.0.sourceText', 'updated transcript segment')
            ->assertJsonPath('cues.0.translatedText', 'updated transcript segment')
            ->assertJsonPath('cues.0.romanization', 'refreshed pronunciation')
            ->assertJsonPath('cues.0.tokens.0.gloss', 'new gloss')
            ->assertJsonPath('cues.0.tokens.0.translation', 'new meaning')
            ->assertJsonPath('cues.0.tokens.0.text', 'updated')
            ->assertJsonPath('cues.0.tokens.0.normalizedText', 'updated');

        $job->refresh()->load('track');
        $this->assertNotSame($oldTrackId, $job->track->public_id);
        $this->assertNotSame($oldCueId, $job->track->cues[0]['cueId']);
        $this->assertSame($oldGeneratedAt, $job->track->generated_at->toJSON());
        $this->assertSame($oldExpiresAt, $job->track->expires_at?->toJSON());
        $this->assertSame($unchangedCue, $job->track->cues[1]);
        $this->assertSame($tokenizationCalls, $this->translationAnalysis->tokenizationCalls);
        EditedCueAgent::assertPrompted(fn ($prompt): bool => $prompt->provider->name() === 'cerebras' && $prompt->model === 'saved-cerebras');
    }

    public function test_quick_fix_refreshes_translation_and_preserves_a_replacement_phrase(): void
    {
        $response = $this->withExtensionInstall($this->installId())->postJson('/v1/subtitle-jobs', $this->validPayload([
            'includeTranslation' => true,
        ]))->assertOk();
        $job = SubtitleJob::where('public_id', $response->json('jobId'))->firstOrFail();
        $job->update(['detected_source_language' => 'eng']);
        $track = $job->track;
        $cue = $track->cues[0];

        $this->patchJson('/v1/subtitle-jobs/'.$job->public_id.'/cues/'.$cue['cueId'].'/tokens/0', [
            'expectedTrackId' => $track->public_id, 'text' => 'new phrase',
        ])->assertOk()
            ->assertJsonPath('cues.0.sourceText', 'new phrase transcript segment')
            ->assertJsonPath('cues.0.translatedText', 'Refreshed translation')
            ->assertJsonPath('cues.0.romanization', 'refreshed pronunciation')
            ->assertJsonPath('cues.0.tokens.0.text', 'new phrase')
            ->assertJsonPath('cues.0.tokens.0.romanization', 'new reading')
            ->assertJsonPath('cues.0.tokens.0.translation', 'new meaning')
            ->assertJsonPath('cues.0.tokens.0.gloss', 'new gloss')
            ->assertJsonCount(count($cue['tokens']), 'cues.0.tokens');
        $this->assertSame($cue['startMs'], $track->fresh()->cues[0]['startMs']);
        $this->assertSame($cue['endMs'], $track->fresh()->cues[0]['endMs']);
        $this->assertStringContainsString('new phrase transcript segment', $track->fresh()->web_vtt);
    }

    public function test_quick_fix_provider_failure_keeps_the_entire_original_track(): void
    {
        $response = $this->withExtensionInstall($this->installId())->postJson('/v1/subtitle-jobs', $this->validPayload())->assertOk();
        $job = SubtitleJob::where('public_id', $response->json('jobId'))->firstOrFail();
        $track = $job->track;
        $original = $track->getAttributes();
        EditedCueAgent::fake(function (): never {
            throw new \RuntimeException('Provider unavailable');
        });

        $this->patchJson('/v1/subtitle-jobs/'.$job->public_id.'/cues/'.$track->cues[0]['cueId'].'/tokens/0', [
            'expectedTrackId' => $track->public_id, 'text' => 'updated',
        ])->assertStatus(502)->assertJsonPath('error.code', 'enrichment_failed');
        $this->assertSame($original, $track->fresh()->getAttributes());
    }

    public function test_quick_fix_rechecks_track_identity_after_provider_work(): void
    {
        $response = $this->withExtensionInstall($this->installId())->postJson('/v1/subtitle-jobs', $this->validPayload())->assertOk();
        $job = SubtitleJob::where('public_id', $response->json('jobId'))->firstOrFail();
        $track = $job->track;
        $originalCues = $track->cues;
        $newTrackId = (string) Str::uuid();
        $transactionLevel = DB::transactionLevel();
        EditedCueAgent::fake(function (string $prompt) use ($track, $newTrackId, $transactionLevel): array {
            $this->assertSame($transactionLevel, DB::transactionLevel(), 'Provider must run outside mutation locks.');
            $track->update(['public_id' => $newTrackId]);
            $cue = json_decode($prompt, true)['cues'][0];

            return ['dialect' => 'unknown', 'translatedText' => 'New translation', 'cues' => [[
                ...$cue, 'tokens' => array_map(fn ($token) => [...$token, 'translation' => 'new', 'gloss' => 'new'], $cue['tokens']),
            ]]];
        });

        $this->patchJson('/v1/subtitle-jobs/'.$job->public_id.'/cues/'.$track->cues[0]['cueId'].'/tokens/0', [
            'expectedTrackId' => $track->public_id, 'text' => 'updated',
        ])->assertStatus(409)->assertJsonPath('error.details.reason', 'stale_track');
        $this->assertSame($newTrackId, $track->fresh()->public_id);
        $this->assertSame($originalCues, $track->fresh()->cues);
    }

    public function test_quick_fix_rechecks_replacement_before_publication(): void
    {
        $response = $this->withExtensionInstall($this->installId())->postJson('/v1/subtitle-jobs', $this->validPayload())->assertOk();
        $job = SubtitleJob::where('public_id', $response->json('jobId'))->firstOrFail();
        $track = $job->track;
        $original = $track->getAttributes();
        $url = '/v1/subtitle-jobs/'.$job->public_id.'/cues/'.$track->cues[0]['cueId'].'/tokens/0';
        $payload = ['expectedTrackId' => $track->public_id, 'text' => 'updated'];
        $this->duringQuickFix = fn () => $track->lyricsCorrection()->create([
            'attempt_id' => (string) Str::uuid(), 'status' => 'queued', 'lyrics' => 'full replacement',
            'work_state' => ['stage' => 'aligning'],
        ]);
        $this->patchJson($url, $payload)->assertStatus(409);
        $this->assertSame($original, $track->fresh()->getAttributes());
    }

    public function test_quick_fix_preserves_concurrent_word_card_updates_to_other_cues(): void
    {
        $response = $this->withExtensionInstall($this->installId())->postJson('/v1/subtitle-jobs', $this->validPayload())->assertOk();
        $job = SubtitleJob::where('public_id', $response->json('jobId'))->firstOrFail();
        $track = $job->track;
        $this->duringQuickFix = function () use ($track): void {
            $cues = $track->cues;
            $cues[1]['tokens'][0]['gloss'] = 'concurrent word card';
            $track->update(['cues' => $cues]);
        };
        $this->patchJson('/v1/subtitle-jobs/'.$job->public_id.'/cues/'.$track->cues[0]['cueId'].'/tokens/0', [
            'expectedTrackId' => $track->public_id, 'text' => 'updated',
        ])->assertOk()->assertJsonPath('cues.1.tokens.0.gloss', 'concurrent word card');
    }

    public function test_overlapping_quick_fix_and_full_replacement_are_rejected_before_provider_work(): void
    {
        $response = $this->withExtensionInstall($this->installId())->postJson('/v1/subtitle-jobs', $this->validPayload())->assertOk();
        $job = SubtitleJob::where('public_id', $response->json('jobId'))->firstOrFail();
        $track = $job->track;
        $payload = ['expectedTrackId' => $track->public_id, 'text' => 'updated'];
        $this->duringQuickFix = function () use ($job, $track, $payload): void {
            $corrections = app(LyricsCorrectionService::class);
            foreach ([
                fn () => $corrections->quickFix($job, $track->cues[0]['cueId'], 0, $payload),
                fn () => $corrections->submit($job, 'New lyric words', $track->public_id),
            ] as $overlap) {
                try {
                    $overlap();
                    $this->fail('Expected overlap rejection before a second provider call.');
                } catch (SubtitleProcessingException $exception) {
                    $this->assertSame('track_edit_in_progress', $exception->context['reason']);
                }
            }
        };

        $this->patchJson('/v1/subtitle-jobs/'.$job->public_id.'/cues/'.$track->cues[0]['cueId'].'/tokens/0', $payload)->assertOk();
        EditedCueAgent::assertPrompted(fn () => true);
        $this->assertDatabaseCount('subtitle_track_lyrics_corrections', 0);
    }

    public function test_quick_fix_uses_the_selected_repeated_token_occurrence(): void
    {
        $jobResponse = $this->withExtensionInstall($this->installId())->postJson('/v1/subtitle-jobs', $this->validPayload())->assertOk();
        $job = SubtitleJob::query()->where('public_id', $jobResponse->json('jobId'))->firstOrFail();
        $cue = $job->track->cues[0];
        $cue['sourceText'] = 'one two one';
        $cue['translatedText'] = 'one two one';
        $cue['tokens'] = [
            ['index' => 0, 'text' => 'one', 'normalizedText' => 'one'],
            ['index' => 1, 'text' => 'two', 'normalizedText' => 'two'],
            ['index' => 2, 'text' => 'one', 'normalizedText' => 'one'],
        ];
        $job->track->update(['cues' => [$cue]]);

        $this->withExtensionInstall($this->installId())->patchJson(
            '/v1/subtitle-jobs/'.$job->public_id.'/cues/'.$cue['cueId'].'/tokens/2',
            ['expectedTrackId' => $job->track->public_id, 'text' => 'last'],
        )->assertOk()->assertJsonPath('cues.0.sourceText', 'one two last');
    }

    public function test_quick_fix_rejects_stale_track_and_active_replacement(): void
    {
        $jobResponse = $this->withExtensionInstall($this->installId())->postJson('/v1/subtitle-jobs', $this->validPayload())->assertOk();
        $job = SubtitleJob::query()->where('public_id', $jobResponse->json('jobId'))->firstOrFail();
        $cue = $job->track->cues[0];

        $this->withExtensionInstall($this->installId())->patchJson(
            '/v1/subtitle-jobs/'.$job->public_id.'/cues/'.$cue['cueId'].'/tokens/0',
            ['expectedTrackId' => (string) Str::uuid(), 'text' => 'updated'],
        )->assertStatus(409)
            ->assertJsonPath('error.code', 'lyrics_correction_in_progress')
            ->assertJsonPath('error.details.reason', 'stale_track');

        Queue::fake();
        $this->withExtensionInstall($this->installId())->postJson('/v1/subtitle-jobs/'.$job->public_id.'/lyrics', ['expectedTrackId' => $job->track->public_id,
            'lyrics' => 'first transcript segment second transcript segment',
        ])->assertAccepted();

        $this->withExtensionInstall($this->installId())->patchJson(
            '/v1/subtitle-jobs/'.$job->public_id.'/cues/'.$cue['cueId'].'/tokens/0',
            ['expectedTrackId' => $job->track->public_id, 'text' => 'updated'],
        )->assertStatus(409)
            ->assertJsonPath('error.code', 'lyrics_correction_in_progress')
            ->assertJsonMissingPath('error.details.reason');
    }

    public function test_quick_fix_rejects_a_replacement_with_the_same_normalized_token(): void
    {
        $jobResponse = $this->withExtensionInstall($this->installId())->postJson('/v1/subtitle-jobs', $this->validPayload())->assertOk();
        $job = SubtitleJob::query()->where('public_id', $jobResponse->json('jobId'))->firstOrFail();
        $trackId = $job->track->public_id;
        $cue = $job->track->cues[0];

        $this->withExtensionInstall($this->installId())->patchJson(
            '/v1/subtitle-jobs/'.$job->public_id.'/cues/'.$cue['cueId'].'/tokens/0',
            ['expectedTrackId' => $trackId, 'text' => ' FIRST '],
        )->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');

        $this->assertSame($trackId, $job->track->fresh()->public_id);
    }

    public function test_quick_fix_matches_case_normalized_persisted_token_spans(): void
    {
        $jobResponse = $this->withExtensionInstall($this->installId())->postJson('/v1/subtitle-jobs', $this->validPayload())->assertOk();
        $job = SubtitleJob::query()->where('public_id', $jobResponse->json('jobId'))->firstOrFail();
        $cue = $job->track->cues[0];
        $cue['sourceText'] = 'Hello world';
        $cue['translatedText'] = 'Hello world';
        $cue['tokens'] = [
            ['index' => 0, 'text' => 'hello', 'normalizedText' => 'hello'],
            ['index' => 1, 'text' => 'world', 'normalizedText' => 'world'],
        ];
        $job->track->update(['cues' => [$cue]]);

        $this->withExtensionInstall($this->installId())->patchJson(
            '/v1/subtitle-jobs/'.$job->public_id.'/cues/'.$cue['cueId'].'/tokens/0',
            ['expectedTrackId' => $job->track->public_id, 'text' => 'Hi'],
        )->assertOk()->assertJsonPath('cues.0.sourceText', 'Hi world');
    }

    public function test_quick_fix_returns_validation_failure_when_the_resulting_cue_overflows(): void
    {
        $jobResponse = $this->withExtensionInstall($this->installId())->postJson('/v1/subtitle-jobs', $this->validPayload())->assertOk();
        $job = SubtitleJob::query()->where('public_id', $jobResponse->json('jobId'))->firstOrFail();
        $cue = $job->track->cues[0];
        $cue['sourceText'] = str_repeat('a', 40).' '.str_repeat('b', 40);
        $cue['translatedText'] = $cue['sourceText'];
        $cue['tokens'] = [
            ['index' => 0, 'text' => str_repeat('a', 40), 'normalizedText' => str_repeat('a', 40)],
            ['index' => 1, 'text' => str_repeat('b', 40), 'normalizedText' => str_repeat('b', 40)],
        ];
        $job->track->update(['cues' => [$cue]]);
        $trackId = $job->track->public_id;

        $this->withExtensionInstall($this->installId())->patchJson(
            '/v1/subtitle-jobs/'.$job->public_id.'/cues/'.$cue['cueId'].'/tokens/0',
            ['expectedTrackId' => $trackId, 'text' => str_repeat('c', 50)],
        )->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.details.errors.text.0', 'Replacement text makes this subtitle line longer than 84 characters.');

        $this->assertSame($trackId, $job->track->fresh()->public_id);
    }

    public function test_quick_fix_rejects_an_out_of_range_token_index_at_the_route_boundary(): void
    {
        $jobResponse = $this->withExtensionInstall($this->installId())->postJson('/v1/subtitle-jobs', $this->validPayload())->assertOk();
        $job = SubtitleJob::query()->where('public_id', $jobResponse->json('jobId'))->firstOrFail();
        $cue = $job->track->cues[0];

        $this->withExtensionInstall($this->installId())->patchJson(
            '/v1/subtitle-jobs/'.$job->public_id.'/cues/'.$cue['cueId'].'/tokens/123456789',
            ['expectedTrackId' => $job->track->public_id, 'text' => 'updated'],
        )->assertNotFound();
        $this->withExtensionInstall($this->installId())->patchJson(
            '/v1/subtitle-jobs/'.$job->public_id.'/cues/'.$cue['cueId'].'/tokens/9999',
            ['expectedTrackId' => $job->track->public_id, 'text' => 'updated'],
        )->assertNotFound();
        $this->withExtensionInstall($this->installId())->patchJson(
            '/v1/subtitle-jobs/'.$job->public_id.'/cues/'.$cue['cueId'].'/tokens/-1',
            ['expectedTrackId' => $job->track->public_id, 'text' => 'updated'],
        )->assertNotFound();
    }

    public function test_quick_fix_rejects_empty_missing_and_expired_targets(): void
    {
        $jobResponse = $this->withExtensionInstall($this->installId())->postJson('/v1/subtitle-jobs', $this->validPayload())->assertOk();
        $job = SubtitleJob::query()->where('public_id', $jobResponse->json('jobId'))->firstOrFail();
        $cue = $job->track->cues[0];

        $this->withExtensionInstall($this->installId())->patchJson(
            '/v1/subtitle-jobs/'.$job->public_id.'/cues/'.$cue['cueId'].'/tokens/0',
            ['expectedTrackId' => $job->track->public_id, 'text' => ''],
        )->assertUnprocessable();

        $this->withExtensionInstall($this->installId())->patchJson(
            '/v1/subtitle-jobs/'.$job->public_id.'/cues/'.$cue['cueId'].'/tokens/0',
            ['expectedTrackId' => $job->track->public_id, 'text' => " \n\t"],
        )->assertUnprocessable();

        $this->withExtensionInstall($this->installId())->patchJson(
            '/v1/subtitle-jobs/'.$job->public_id.'/cues/missing-cue/tokens/0',
            ['expectedTrackId' => $job->track->public_id, 'text' => 'updated'],
        )->assertNotFound();

        $job->track->update(['expires_at' => now()->subMinute()]);
        $this->withExtensionInstall($this->installId())->patchJson(
            '/v1/subtitle-jobs/'.$job->public_id.'/cues/'.$cue['cueId'].'/tokens/0',
            ['expectedTrackId' => $job->track->public_id, 'text' => 'updated'],
        )->assertNotFound();
    }

    public function test_correction_can_be_cancelled_and_exposes_safe_stage(): void
    {
        $jobResponse = $this->withExtensionInstall($this->installId())->postJson('/v1/subtitle-jobs', $this->validPayload())->assertOk();
        $job = SubtitleJob::query()->where('public_id', $jobResponse->json('jobId'))->firstOrFail();
        Queue::fake();
        $correction = $this->withExtensionInstall($this->installId())->postJson('/v1/subtitle-jobs/'.$job->public_id.'/lyrics', ['expectedTrackId' => $job->track->public_id,
            'lyrics' => 'first transcript segment second transcript segment',
        ])->assertAccepted();

        $this->withExtensionInstall($this->installId())
            ->deleteJson('/v1/subtitle-jobs/'.$job->public_id.'/lyrics', ['attemptId' => $correction->json('attemptId')])
            ->assertOk()
            ->assertJsonPath('attemptId', $correction->json('attemptId'))
            ->assertJsonPath('status', 'cancelled')
            ->assertJsonPath('stage', 'cancelled')
            ->assertJsonMissingPath('track')
            ->assertJsonMissingPath('message');
    }

    public function test_cancellation_rejects_invalid_attempts_and_wrong_or_expired_tracks(): void
    {
        $jobResponse = $this->withExtensionInstall($this->installId())->postJson('/v1/subtitle-jobs', $this->validPayload())->assertOk();
        $job = SubtitleJob::query()->where('public_id', $jobResponse->json('jobId'))->firstOrFail();
        Queue::fake();
        $correction = $this->withExtensionInstall($this->installId())->postJson('/v1/subtitle-jobs/'.$job->public_id.'/lyrics', ['expectedTrackId' => $job->track->public_id,
            'lyrics' => 'first transcript segment second transcript segment',
        ])->assertAccepted();

        $this->withExtensionInstall($this->installId())
            ->deleteJson('/v1/subtitle-jobs/'.$job->public_id.'/lyrics')
            ->assertUnprocessable();
        $this->withExtensionInstall($this->installId())
            ->deleteJson('/v1/subtitle-jobs/'.$job->public_id.'/lyrics', ['attemptId' => 'not-a-uuid'])
            ->assertUnprocessable();

        $job->track->update(['expires_at' => now()->subMinute()]);
        $this->withExtensionInstall($this->installId())
            ->deleteJson('/v1/subtitle-jobs/'.$job->public_id.'/lyrics', ['attemptId' => $correction->json('attemptId')])
            ->assertNotFound();
    }

    public function test_transient_correction_provider_failure_requeues_and_retry_completes(): void
    {
        $jobResponse = $this->withExtensionInstall($this->installId())->postJson('/v1/subtitle-jobs', $this->validPayload())->assertOk();
        $job = SubtitleJob::query()->where('public_id', $jobResponse->json('jobId'))->firstOrFail();
        $calls = 0;
        Queue::fake();

        LyricsAlignmentAgent::fake(function () use (&$calls): array {
            $calls++;

            if ($calls === 1) {
                throw RateLimitedException::forProvider('openai', 429);
            }

            return ['isMatch' => true, 'isComplete' => true, 'cues' => [
                ['cueId' => 'cue-0001', 'index' => 0, 'endPartIndex' => 2],
                ['cueId' => 'cue-0002', 'index' => 1, 'endPartIndex' => 5],
            ]];
        })->preventStrayPrompts();

        $correction = $this->withExtensionInstall($this->installId())->postJson('/v1/subtitle-jobs/'.$job->public_id.'/lyrics', ['expectedTrackId' => $job->track->public_id,
            'lyrics' => "First lyric line\nSecond lyric line",
        ])->assertAccepted();
        $queuedJob = new LyricsCorrectionJob($job->track->id, $job->id, $correction->json('attemptId'), 0);

        try {
            $queuedJob->handle(app(LyricsCorrectionService::class));
            $this->fail('Expected the first provider attempt to be transient.');
        } catch (SubtitleProcessingException $exception) {
            $this->assertTrue($exception->isTransient());
        }

        $this->assertDatabaseHas('subtitle_track_lyrics_corrections', [
            'attempt_id' => $correction->json('attemptId'),
            'status' => 'running',
            'work_revision' => 0,
        ]);

        $queuedJob->handle(app(LyricsCorrectionService::class));
        $this->runCorrectionToCompletion($job, $correction->json('attemptId'), startRevision: 1);

        $this->assertSame(2, $calls);
        $this->assertDatabaseHas('subtitle_track_lyrics_corrections', [
            'attempt_id' => $correction->json('attemptId'),
            'status' => 'completed',
        ]);
    }

    public function test_correction_timeout_is_bounded_below_real_queue_retry_after(): void
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

    public function test_correction_status_is_shared_without_exposing_lyrics(): void
    {
        $jobResponse = $this->withExtensionInstall($this->installId())->postJson('/v1/subtitle-jobs', $this->validPayload())->assertOk();
        $job = SubtitleJob::query()->where('public_id', $jobResponse->json('jobId'))->firstOrFail();
        Queue::fake();
        $correction = $this->withExtensionInstall($this->installId())->postJson('/v1/subtitle-jobs/'.$job->public_id.'/lyrics', ['expectedTrackId' => $job->track->public_id,
            'lyrics' => 'first transcript segment second transcript segment',
        ])->assertAccepted();

        $this->withExtensionInstall($this->installId())
            ->getJson('/v1/subtitle-jobs/'.$job->public_id.'/lyrics')
            ->assertOk()
            ->assertJsonPath('attemptId', $correction->json('attemptId'))
            ->assertJsonMissingPath('lyrics');

        $this->withExtensionInstall($this->installId('b'))
            ->getJson('/v1/subtitle-jobs/'.$job->public_id.'/lyrics')
            ->assertOk();
    }

    public function test_correction_rejects_invalid_input_expired_tracks_and_concurrent_attempts(): void
    {
        $installId = $this->installId();
        $jobResponse = $this->withExtensionInstall($installId)->postJson('/v1/subtitle-jobs', $this->validPayload())->assertOk();
        $job = SubtitleJob::query()->where('public_id', $jobResponse->json('jobId'))->firstOrFail();
        config([
            'queue.default' => 'database',
            'subtitles.queue.connection' => 'database',
        ]);
        Queue::fake();

        $this->withExtensionInstall($installId)
            ->postJson('/v1/subtitle-jobs/'.$job->public_id.'/lyrics', ['expectedTrackId' => $job->track->public_id, 'lyrics' => ''])
            ->assertUnprocessable();

        $first = $this->withExtensionInstall($installId)
            ->postJson('/v1/subtitle-jobs/'.$job->public_id.'/lyrics', ['expectedTrackId' => $job->track->public_id, 'lyrics' => 'first transcript segment second transcript segment'])
            ->assertAccepted();
        $this->withExtensionInstall($installId)
            ->postJson('/v1/subtitle-jobs/'.$job->public_id.'/lyrics', ['expectedTrackId' => $job->track->public_id, 'lyrics' => 'first transcript segment second transcript segment'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'lyrics_correction_in_progress');

        $job->track->update(['expires_at' => now()->subMinute()]);
        $this->withExtensionInstall($installId)
            ->postJson('/v1/subtitle-jobs/'.$job->public_id.'/lyrics', ['expectedTrackId' => $job->track->public_id, 'lyrics' => 'first transcript segment second transcript segment'])
            ->assertNotFound();
        $this->assertNotSame('', (string) $first->json('attemptId'));
    }

    public function test_transcript_first_generation_adds_requested_translation(): void
    {
        $response = $this
            ->withExtensionInstall($this->installId())
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
        $this->assertSame(['auto'], $this->translationAnalysis->sourceLanguages);
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
            ->withExtensionInstall($this->installId())
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
            ->withExtensionInstall($this->installId())
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
            ->withExtensionInstall($this->installId())
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
            ->withExtensionInstall($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload(['youtubeVideoId' => 'nonlatin001']))
            ->assertAccepted()
            ->assertJsonPath('status', 'running');

        $this->runQueuedSubtitleJobs();

        $this
            ->withExtensionInstall($this->installId())
            ->getJson('/v1/subtitle-jobs/'.$response->json('jobId'))
            ->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('message', 'Subtitle enrichment failed.');

        $this->assertSame(1, $this->translationAnalysis->tokenizationCalls);
        $this->assertSame(1, $this->translationAnalysis->romanizationCalls);
        $this->assertDatabaseHas('subtitle_jobs', [
            'youtube_video_id' => 'nonlatin001',
            'status' => 'failed',
            'stage' => 'tokenizing',
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
            ->withExtensionInstall($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload(['youtubeVideoId' => 'tokfail0001']))
            ->assertAccepted()
            ->assertJsonPath('status', 'running');

        $this->runQueuedSubtitleJobs();

        $this
            ->withExtensionInstall($this->installId())
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
            ->withExtensionInstall($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload([
                'youtubeVideoId' => 'trnfail0001',
                'includeTranslation' => true,
            ]))
            ->assertAccepted()
            ->assertJsonPath('status', 'running');

        $this->runQueuedSubtitleJobs();

        $this
            ->withExtensionInstall($this->installId())
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
            ->withExtensionInstall($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload([
                'sourceLanguage' => 'jpn',
                'youtubeVideoId' => 'jpnfail0001',
            ]))
            ->assertAccepted()
            ->assertJsonPath('status', 'running');

        $this->runQueuedSubtitleJobs();

        $this
            ->withExtensionInstall($this->installId())
            ->getJson('/v1/subtitle-jobs/'.$response->json('jobId'))
            ->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('message', 'Subtitle enrichment failed.');

        $this->assertSame(1, $this->translationAnalysis->tokenizationCalls);
        $this->assertSame(1, $this->translationAnalysis->romanizationCalls);
        $this->assertDatabaseHas('subtitle_jobs', [
            'youtube_video_id' => 'jpnfail0001',
            'status' => 'failed',
            'stage' => 'tokenizing',
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
            ->withExtensionInstall($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload([
                'sourceLanguage' => 'jpn',
                'youtubeVideoId' => 'jpnfail0002',
            ]))
            ->assertAccepted()
            ->assertJsonPath('status', 'running');

        $this->runQueuedSubtitleJobs();

        $this
            ->withExtensionInstall($this->installId())
            ->getJson('/v1/subtitle-jobs/'.$response->json('jobId'))
            ->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('message', 'Subtitle enrichment failed.');

        $this->assertSame(1, $this->translationAnalysis->tokenizationCalls);
        $this->assertSame(1, $this->translationAnalysis->romanizationCalls);
        $this->assertDatabaseHas('subtitle_jobs', [
            'youtube_video_id' => 'jpnfail0002',
            'status' => 'failed',
            'stage' => 'tokenizing',
        ]);
    }

    public function test_auto_detected_english_still_translates_and_enriches_other_languages(): void
    {
        $sourceText = 'ਸਤ ਸ੍ਰੀ ਅਕਾਲ';
        $this->transcriptionService->transcript = new TimestampedTranscript(
            language: 'eng',
            durationSeconds: 4.0,
            segments: [
                new TimestampedTranscriptSegment(0.0, 2.0, 'Hello there'),
                new TimestampedTranscriptSegment(2.0, 4.0, $sourceText),
            ],
            webVtt: "WEBVTT\n\n",
        );

        $response = $this->withExtensionInstall($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload([
                'sourceLanguage' => 'auto',
                'targetLanguage' => 'eng',
                'includeTranslation' => true,
            ]))->assertOk()
            ->assertJsonPath('detectedSourceLanguage', 'eng')
            ->assertJsonPath('track.cues.1.translatedText', 'Translated '.$sourceText);

        $this->assertSame(1, $this->translationAnalysis->translationCalls);
        $this->postJson('/v1/learning-tokens', [
            'trackId' => $response->json('track.trackId'),
            'cueId' => $response->json('track.cues.1.cueId'),
            'tokenIndex' => 0,
        ])->assertOk();

        $this->assertSame(1, $this->translationAnalysis->tokenCalls);
        $this->assertSame(['auto', 'auto'], $this->translationAnalysis->sourceLanguages);
    }

    public function test_translated_and_untranslated_tracks_are_cached_separately(): void
    {
        $plainResponse = $this
            ->withExtensionInstall($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload([
                'youtubeVideoId' => 'transmode01',
                'includeTranslation' => false,
            ]));

        $translatedResponse = $this
            ->withExtensionInstall($this->installId())
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

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_regeneration_rebuilds_edited_lyrics_and_learning_data_from_original_transcription(bool $cached): void
    {
        $source = $this->arabicGreeting();
        $this->transcriptionService->transcript = new TimestampedTranscript(
            language: 'ara', durationSeconds: 42,
            segments: [new TimestampedTranscriptSegment(0.5, 2.1, $source)], webVtt: "WEBVTT\n",
        );
        $payload = $this->validPayload(['sourceLanguage' => 'ara', 'includeTranslation' => true]);
        $original = $this->withExtensionInstall($this->installId())->postJson('/v1/subtitle-jobs', $payload)->assertOk();
        $job = SubtitleJob::where('public_id', $original->json('jobId'))->firstOrFail();
        $oldRun = $job->run_id;
        $cues = $job->track->cues;
        $cues[0] = [...$cues[0], 'sourceText' => 'broken pasted lyrics', 'translatedText' => 'broken translation', 'romanization' => 'broken reading',
            'tokens' => [['index' => 0, 'text' => 'broken', 'normalizedText' => 'broken']]];
        $job->track->update(['cues' => $cues, 'web_vtt' => "WEBVTT\n\nbroken pasted lyrics\n"]);
        $this->postJson('/v1/subtitle-jobs', [...$payload, 'forceRegenerate' => false])->assertOk()
            ->assertJsonPath('track.cues.0.sourceText', 'broken pasted lyrics');
        if (! $cached) {
            CachedVideoTranscript::query()->update(['expires_at' => now()->subDay()]);
        }
        $fresh = $this->postJson('/v1/subtitle-jobs', [...$payload, 'forceRegenerate' => true])->assertOk()
            ->assertJsonPath('track.cues.0.sourceText', $source);
        $this->assertNotSame($original->json('track.trackId'), $fresh->json('track.trackId'));
        $this->assertNotSame($oldRun, $job->fresh()->run_id);
        $this->assertSame($original->json('track.cues.0.translatedText'), $fresh->json('track.cues.0.translatedText'));
        $this->assertSame($original->json('track.cues.0.romanization'), $fresh->json('track.cues.0.romanization'));
        $this->assertSame($original->json('track.cues.0.tokens'), $fresh->json('track.cues.0.tokens'));
        $this->assertSame($cached ? 1 : 2, $this->audioSource->calls);
        $this->assertSame($cached ? 1 : 2, $this->transcriptionService->chunkCalls);
        $this->assertSame(2, $this->translationAnalysis->tokenizationCalls);
        $this->assertSame(2, $this->translationAnalysis->romanizationCalls);
        $this->assertSame(2, $this->translationAnalysis->translationCalls);
    }

    public function test_duplicate_regeneration_reuses_the_active_run(): void
    {
        $original = $this->withExtensionInstall($this->installId())->postJson('/v1/subtitle-jobs', $this->validPayload())->assertOk();
        $job = SubtitleJob::where('public_id', $original->json('jobId'))->firstOrFail();
        $oldRun = $job->run_id;
        config(['queue.default' => 'database', 'subtitles.queue.connection' => 'database']);
        Queue::fake();
        $payload = $this->validPayload(['forceRegenerate' => true]);
        $first = $this->postJson('/v1/subtitle-jobs', $payload)->assertAccepted();
        $run = $job->fresh()->run_id;
        $this->postJson('/v1/subtitle-jobs', $payload)->assertAccepted()->assertJsonPath('jobId', $first->json('jobId'));
        $this->assertSame($run, $job->fresh()->run_id);
        Queue::assertPushed(AcquireSubtitleAudio::class, 1);
        (new AcquireSubtitleAudio($job->id, $oldRun))->handle(app(SubtitleGenerationPipeline::class));
        $this->assertSame($run, $job->fresh()->run_id);
        $this->assertSame(1, $this->audioSource->calls);
        $this->assertNull($job->fresh()->track);
    }

    #[TestWith(['correction', 409])]
    public function test_regeneration_rejection_preserves_the_existing_track(string $reason, int $status): void
    {
        $original = $this->withExtensionInstall($this->installId())->postJson('/v1/subtitle-jobs', $this->validPayload())->assertOk();
        $job = SubtitleJob::where('public_id', $original->json('jobId'))->firstOrFail();
        $oldRun = $job->run_id;
        $job->track->lyricsCorrection()->create(['attempt_id' => (string) Str::uuid(), 'status' => 'queued', 'lyrics' => 'Replacement in progress']);
        $this->postJson('/v1/subtitle-jobs', $this->validPayload(['forceRegenerate' => true]))->assertStatus($status);
        $this->assertSame($oldRun, $job->fresh()->run_id);
        $this->assertSame($original->json('track.trackId'), $job->fresh()->track->public_id);
        $this->assertSame(1, $this->translationAnalysis->tokenizationCalls);
    }

    #[TestWith(['true'])]
    #[TestWith([1])]
    #[TestWith([null])]
    public function test_regeneration_flag_requires_a_json_boolean(mixed $value): void
    {
        $this->withExtensionInstall($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload(['forceRegenerate' => $value]))->assertUnprocessable();
        $this->assertSame(0, SubtitleJob::count());
    }

    public function test_repeat_generation_for_the_same_video_reuses_the_cached_transcript(): void
    {
        config(['ai.providers.eleven.models.transcription.default' => 'scribe-test']);

        $this
            ->withExtensionInstall($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload(['youtubeVideoId' => 'cachehit001']))
            ->assertOk();

        $this->assertDatabaseHas('cached_video_transcripts', [
            'youtube_video_id' => 'cachehit001',
            'requested_source_language' => 'auto',
            'transcription_model' => SubtitleProcessingVersion::transcriptCacheModel('scribe-test'),
            'audio_duration_seconds' => 42,
        ]);

        // A different target language forces a new job while the transcript
        // cache key (video + requested source language + model) is unchanged.
        $secondResponse = $this
            ->withExtensionInstall($this->installId())
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
            ->withExtensionInstall($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload(['youtubeVideoId' => 'cacheoff001']))
            ->assertOk();

        $this->assertSame(0, CachedVideoTranscript::count());

        $this
            ->withExtensionInstall($this->installId())
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
            'transcription_model' => SubtitleProcessingVersion::transcriptCacheModel('scribe-test'),
            'audio_duration_seconds' => 999,
            'payload' => ['language' => 'spa', 'durationSeconds' => 999.0, 'webVtt' => 'WEBVTT', 'segments' => []],
            'expires_at' => now()->subDay(),
        ]);

        $this
            ->withExtensionInstall($this->installId())
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
            ->withExtensionInstall($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload([
                'youtubeVideoId' => 'romanmode01',
                'includeRomanization' => false,
            ]));

        $romanizedResponse = $this
            ->withExtensionInstall($this->installId())
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
                ->withExtensionInstall($this->installId(chr(97 + $index)))
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
                ->withExtensionInstall($this->installId(chr(97 + $index)))
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
            webVtt: "WEBVTT\n\ncue-0001\n00:00:00.500 --> 00:00:02.100\nkonnichiwa\n",
        );

        $response = $this
            ->withExtensionInstall($this->installId())
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
            ->withExtensionInstall($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload());

        $secondResponse = $this
            ->withExtensionInstall($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload());

        $firstResponse->assertOk();
        $secondResponse
            ->assertOk()
            ->assertJsonPath('jobId', $firstResponse->json('jobId'))
            ->assertJsonPath('track.trackId', $firstResponse->json('track.trackId'));

        $this->assertSame(1, $this->audioSource->calls);
        $this->assertSame(1, $this->translationAnalysis->tokenizationCalls);
    }

    public function test_generation_records_estimated_provider_cost_without_exposing_internal_payload(): void
    {
        config([
            'subtitles.costs.elevenlabs_scribe_microusd_per_minute' => 100,
            'subtitles.costs.openai_tokenization_microusd_per_cue' => 10,
        ]);

        $response = $this
            ->withExtensionInstall($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload(['youtubeVideoId' => 'costtrace01']));

        $response
            ->assertOk()
            ->assertJsonPath('videoDurationSeconds', 42)
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
            ->withExtensionInstall($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload());

        $response->assertOk();

        $this->assertSame(2, SubtitleJob::count());
        $this->assertSame(2, SubtitleTrack::count());
        $this->assertSame(1, $this->audioSource->calls);
        $this->assertNotSame($oldJob->public_id, $response->json('jobId'));
    }

    public function test_completed_tracks_are_shared_across_installs_on_the_instance(): void
    {
        $firstResponse = $this
            ->withExtensionInstall($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload());

        $secondResponse = $this
            ->withExtensionInstall($this->installId('b'))
            ->postJson('/v1/subtitle-jobs', $this->validPayload());

        $firstResponse->assertOk();
        $secondResponse->assertOk();

        $this->assertSame($firstResponse->json('jobId'), $secondResponse->json('jobId'));
        $this->assertSame($firstResponse->json('track.trackId'), $secondResponse->json('track.trackId'));
        $this->assertSame(1, SubtitleJob::count());
        $this->assertSame(1, SubtitleTrack::count());
        $this->assertSame(1, $this->audioSource->calls);
    }

    public function test_partial_tracks_preview_source_then_overlay_out_of_order_analysis_with_stable_indexes(): void
    {
        config(['subtitles.enrichment.cue_batch_max_cues' => 1]);
        $job = SubtitleJob::factory()->create(['status' => 'running', 'stage' => 'tokenizing']);
        $installId = $this->installId();
        $url = "/v1/subtitle-jobs/{$job->public_id}";
        $cues = [
            ['cueId' => 'cue-0001', 'index' => 0, 'startMs' => 500, 'endMs' => 2100, 'sourceText' => 'first part', 'translatedText' => 'First translation', 'tokens' => [['index' => 0, 'text' => 'first']]],
            ['cueId' => 'cue-0002', 'index' => 1, 'startMs' => 2400, 'endMs' => 4000, 'sourceText' => 'second part', 'translatedText' => 'Second translation', 'romanization' => 'second', 'tokens' => [['index' => 0, 'text' => 'second']]],
        ];
        $this->withExtensionInstall($installId)->getJson($url)->assertOk()->assertJsonMissingPath('partialTrack');
        $this->artifacts()->putCueCollection($job, SubtitleJobArtifactStore::DRAFT_CUES, $cues);
        $this->withExtensionInstall($installId)->getJson($url)->assertOk()
            ->assertJsonPath('partialTrack.revision', 1)->assertJsonCount(2, 'partialTrack.cues')
            ->assertJsonMissingPath('partialTrack.cues.0.translatedText')->assertJsonMissingPath('partialTrack.cues.0.tokens');
        $this->artifacts()->putCueBatchResult($job, SubtitleJobArtifactStore::ANALYZED_CUES, 1, new CueEnrichmentResult([$cues[1]]));
        $this->withExtensionInstall($installId)->getJson($url)->assertOk()
            ->assertJsonPath('partialTrack.revision', 2)->assertJsonCount(2, 'partialTrack.cues')
            ->assertJsonMissingPath('partialTrack.cues.0.translatedText')
            ->assertJsonPath('partialTrack.cues.1.translatedText', 'Second translation');
        $this->artifacts()->putCueBatchResult($job, SubtitleJobArtifactStore::ANALYZED_CUES, 0, new CueEnrichmentResult([$cues[0]]));
        $this->withExtensionInstall($installId)->getJson($url)->assertOk()
            ->assertJsonPath('partialTrack.revision', 3)->assertJsonCount(2, 'partialTrack.cues')
            ->assertJsonPath('partialTrack.cues.1.cueId', 'cue-0002')->assertJsonPath('partialTrack.cues.1.index', 1)
            ->assertJsonPath('partialTrack.cues.0.translatedText', 'First translation')
            ->assertJsonPath('partialTrack.cues.1.romanization', 'second')->assertJsonMissingPath('partialTrack.cues.0.tokens');
        $merged = $this->artifacts()->cueResultFromBatchArtifacts($job, SubtitleJobArtifactStore::ANALYZED_CUES)->cues;
        $this->assertSame([0, 1], array_column($merged, 'index'));
        $this->assertSame(['cue-0001', 'cue-0002'], array_column($merged, 'cueId'));
        $job->update(['run_id' => (string) Str::uuid()]);
        $this->withExtensionInstall($installId)->getJson($url)->assertOk()->assertJsonMissingPath('partialTrack');
    }

    public function test_partial_tracks_are_shared_but_omitted_for_completed_jobs(): void
    {
        $completedResponse = $this
            ->withExtensionInstall($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload(['youtubeVideoId' => 'partdone001']))
            ->assertOk();

        $this
            ->withExtensionInstall($this->installId())
            ->getJson('/v1/subtitle-jobs/'.$completedResponse->json('jobId'))
            ->assertOk()->assertJsonMissingPath('partialTrack');

        $otherUsersJob = SubtitleJob::factory()->create([
            'status' => 'running',
            'stage' => 'tokenizing',
            'progress_percent' => 65,
        ]);
        $this->artifacts()->putCueCollection($otherUsersJob, SubtitleJobArtifactStore::DRAFT_CUES, [$this->sampleCue()]);

        $this
            ->withExtensionInstall($this->installId('b'))
            ->getJson("/v1/subtitle-jobs/{$otherUsersJob->public_id}")
            ->assertOk();
    }

    public function test_generation_records_time_to_first_cue(): void
    {
        $response = $this
            ->withExtensionInstall($this->installId())
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

    public function test_video_generations_include_both_providers_beyond_the_history_limit(): void
    {
        $ids = [];
        for ($index = 0; $index < 27; $index++) {
            $job = SubtitleJob::factory()->create([
                'youtube_video_id' => 'dQw4w9WgXcQ',
                'status' => 'completed',
                'transcription_options_hash' => hash('sha256', (string) $index),
                'ai_provider' => $index % 2 ? 'cerebras' : 'openai',
                'ai_model' => $index % 2 ? 'gpt-oss-120b' : 'gpt-6-luna',
                'updated_at' => now()->subDays(2),
            ]);
            SubtitleTrack::factory()->for($job, 'job')->create(['expires_at' => now()->addDay()]);
            $ids[] = $job->public_id;
        }
        SubtitleJob::factory()->count(25)->create();
        $this->withExtensionInstall($this->installId());
        $this->getJson('/v1/subtitle-jobs')->assertOk()->assertJsonCount(25, 'jobs');
        $response = $this->getJson('/v1/subtitle-jobs?youtubeVideoId=dQw4w9WgXcQ')
            ->assertOk()->assertJsonCount(27, 'jobs');
        $this->assertEqualsCanonicalizing($ids, array_column($response->json('jobs'), 'jobId'));
        $this->assertEqualsCanonicalizing(['openai', 'cerebras'], array_unique(array_column($response->json('jobs'), 'aiProvider')));
    }

    public function test_video_generations_exclude_unavailable_jobs_and_validate_filter(): void
    {
        foreach (['expired', 'missing', 'running', 'old-version', 'other-video'] as $index => $kind) {
            $job = SubtitleJob::factory()->create([
                'youtube_video_id' => $kind === 'other-video' ? 'M7lc1UVf-VE' : 'dQw4w9WgXcQ',
                'status' => $kind === 'running' ? 'running' : 'completed',
                'transcription_options_hash' => hash('sha256', (string) $index),
                ...($kind === 'old-version' ? ['processing_version' => 'old'] : []),
            ]);
            if (! in_array($kind, ['missing', 'running'], true)) {
                SubtitleTrack::factory()->for($job, 'job')->create([
                    'expires_at' => $kind === 'expired' ? now()->subMinute() : now()->addDay(),
                ]);
            }
        }
        $this->withExtensionInstall($this->installId());
        $this->getJson('/v1/subtitle-jobs?youtubeVideoId=dQw4w9WgXcQ')->assertOk()->assertJsonCount(0, 'jobs');
        $this->getJson('/v1/subtitle-jobs?youtubeVideoId=unknown0001')->assertOk()->assertJsonCount(0, 'jobs');
        foreach (['', 'bad', '%3Cscript%3E', '%5B%5D'] as $value) {
            $this->getJson('/v1/subtitle-jobs?youtubeVideoId='.$value)->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
        }
        $this->getJson('/v1/subtitle-jobs?youtubeVideoId[]=dQw4w9WgXcQ')->assertUnprocessable();
    }

    public function test_list_subtitle_jobs_returns_current_install_history(): void
    {
        $installId = $this->installId();
        $job = SubtitleJob::factory()->create([
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
            'youtube_video_id' => 'run00000001',
            'install_id' => $installId,
            'expires_at' => null,
            'stage' => 'transcribing',
            'progress_percent' => 45,
            'updated_at' => now()->subMinute(),
        ]);
        $failedJob = SubtitleJob::factory()->create([
            'youtube_video_id' => 'fail0000001',
            'install_id' => $installId,
            'expires_at' => null,
            'status' => 'failed',
            'stage' => 'finalizing',
            'progress_percent' => 75,
            'error_code' => 'rate_limited',
            'error_message' => 'Subtitle enrichment is temporarily rate limited.',
            'updated_at' => now()->subMinutes(2),
        ]);

        $response = $this
            ->withExtensionInstall($installId)
            ->getJson('/v1/subtitle-jobs');

        $response
            ->assertOk()
            ->assertJsonCount(3, 'jobs')
            ->assertJsonPath('jobs.0.youtubeVideoId', 'run00000001')
            ->assertJsonPath('jobs.0.status', 'running')
            ->assertJsonPath('jobs.0.stage', 'transcribing')
            ->assertJsonPath('jobs.0.progressPercent', 45)
            ->assertJsonPath('jobs.0.jobId', $runningJob->public_id)
            ->assertJsonPath('jobs.0.targetLanguage', 'eng')
            ->assertJsonPath('jobs.1.youtubeVideoId', 'dQw4w9WgXcQ')
            ->assertJsonPath('jobs.1.status', 'completed')
            ->assertJsonPath('jobs.1.trackId', $track->public_id)
            ->assertJsonPath('jobs.1.sourceLanguage', 'spa')
            ->assertJsonPath('jobs.1.detectedSourceLanguage', 'spa')
            ->assertJsonPath('jobs.1.targetLanguage', 'eng')
            ->assertJsonPath('jobs.1.videoDurationSeconds', 213)
            ->assertJsonPath('jobs.1.includeRomanization', true)
            ->assertJsonPath('jobs.1.includeTranslation', false)
            ->assertJsonPath('jobs.2.youtubeVideoId', 'fail0000001')
            ->assertJsonPath('jobs.2.status', 'failed')
            ->assertJsonPath('jobs.2.stage', 'finalizing')
            ->assertJsonPath('jobs.2.progressPercent', 75)
            ->assertJsonPath('jobs.2.errorCode', 'rate_limited')
            ->assertJsonPath('jobs.2.message', 'Subtitle enrichment is temporarily rate limited.')
            ->assertJsonPath('jobs.2.jobId', $failedJob->public_id);
    }

    public function test_history_keeps_old_active_jobs_and_applies_terminal_retention(): void
    {
        $installId = $this->installId('h');
        $oldVersion = 'retired-processing-version';
        $oldRunning = SubtitleJob::factory()->create([
            'youtube_video_id' => 'oldrun00001',
            'install_id' => $installId,
            'processing_version' => $oldVersion,
            'status' => 'running',
            'updated_at' => now()->subHours(6),
            'created_at' => now()->subHours(6),
        ]);
        $recentFailed = SubtitleJob::factory()->create([
            'youtube_video_id' => 'olderr00001',
            'install_id' => $installId,
            'processing_version' => $oldVersion,
            'status' => 'failed',
            'error_code' => 'transcription_failed',
            'error_message' => 'Transcription failed.',
            'updated_at' => now()->subDays(2),
        ]);
        SubtitleJob::factory()->create([
            'youtube_video_id' => 'expir000001',
            'install_id' => $installId,
            'processing_version' => $oldVersion,
            'status' => 'failed',
            'error_code' => 'transcription_failed',
            'error_message' => 'Transcription failed.',
            'updated_at' => now()->subDays(31),
        ]);

        $this->withExtensionInstall($installId)
            ->getJson('/v1/subtitle-jobs')
            ->assertOk()
            ->assertJsonCount(2, 'jobs')
            ->assertJsonPath('jobs.0.jobId', $oldRunning->public_id)
            ->assertJsonPath('jobs.0.status', 'running')
            ->assertJsonPath('jobs.1.jobId', $recentFailed->public_id)
            ->assertJsonPath('jobs.1.errorCode', 'transcription_failed');

        $this->withExtensionInstall($installId)
            ->getJson('/v1/subtitle-jobs/'.$oldRunning->public_id)
            ->assertOk()
            ->assertJsonPath('status', 'running');
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
            ->withExtensionInstall($installId)
            ->postJson('/v1/subtitle-jobs', $this->validPayload(['youtubeVideoId' => 'ratelimit01']))
            ->assertAccepted();

        $this
            ->withExtensionInstall($installId)
            ->getJson('/v1/subtitle-jobs/'.$job->public_id)
            ->assertOk()
            ->assertJsonPath('jobId', $job->public_id);

        $this
            ->withExtensionInstall($installId)
            ->getJson('/v1/subtitle-jobs/'.$job->public_id)
            ->assertOk()
            ->assertJsonPath('jobId', $job->public_id);

        $this
            ->withExtensionInstall($installId)
            ->getJson('/v1/subtitle-jobs/'.$job->public_id)
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'rate_limited');
    }

    public function test_word_card_cache_is_separate_for_each_provider_even_with_the_same_model(): void
    {
        config(['ai.default' => 'openai', 'ai.providers.openai.models.text.default' => 'same-model', 'ai.providers.cerebras.models.text.default' => 'same-model']);
        $response = $this->withExtensionInstall($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload())->assertOk();
        $payload = ['trackId' => $response->json('track.trackId'), 'cueId' => 'cue-0001', 'tokenIndex' => 0];
        $track = SubtitleTrack::where('public_id', $payload['trackId'])->firstOrFail();
        $originalCues = $track->cues;
        $this->withExtensionInstall($this->installId())->postJson('/v1/learning-tokens', $payload)->assertOk();
        $track->refresh()->update(['cues' => $originalCues]);
        config([
            'ai.default' => 'cerebras',
        ]);
        $this->withExtensionInstall($this->installId())->postJson('/v1/learning-tokens', $payload)->assertOk();
        $this->assertSame(1, $this->translationAnalysis->tokenCalls);
        $other = $this->postJson('/v1/subtitle-jobs', $this->validPayload(['aiProvider' => 'cerebras']))->assertOk();
        $this->postJson('/v1/learning-tokens', [...$payload, 'trackId' => $other->json('track.trackId')])->assertOk();
        $this->assertSame(2, $this->translationAnalysis->tokenCalls);
        $this->assertSame(['cerebras', 'same-model'], end($this->translationAnalysis->selections));
    }

    public function test_learning_token_enrichment_updates_track_and_skips_duplicate_provider_calls(): void
    {
        $jobResponse = $this
            ->withExtensionInstall($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload())
            ->assertOk();

        $payload = [
            'trackId' => $jobResponse->json('track.trackId'),
            'cueId' => 'cue-0001',
            'tokenIndex' => 0,
        ];

        $track = SubtitleTrack::where('public_id', $payload['trackId'])->firstOrFail();
        $cues = $track->cues;
        $cues[0]['tokens'][0]['lemma'] = 'first';
        $cues[0]['tokens'][0]['partOfSpeech'] = 'adjective';
        $track->update(['cues' => $cues]);

        $this
            ->withExtensionInstall($this->installId())
            ->postJson('/v1/learning-tokens', $payload)
            ->assertOk()
            ->assertJsonPath('trackId', $payload['trackId'])
            ->assertJsonPath('cueId', 'cue-0001')
            ->assertJsonPath('token.index', 0)
            ->assertJsonPath('token.text', 'first')
            ->assertJsonPath('token.gloss', 'first gloss')
            ->assertJsonPath('token.romanization', 'first romanized');

        $this
            ->withExtensionInstall($this->installId())
            ->postJson('/v1/learning-tokens', $payload)
            ->assertOk()
            ->assertJsonPath('token.gloss', 'first gloss');

        $track = SubtitleTrack::where('public_id', $payload['trackId'])->firstOrFail();

        $this->assertSame('first gloss', $track->cues[0]['tokens'][0]['gloss']);
        $this->assertSame(1, $this->translationAnalysis->tokenCalls);
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
            ->withExtensionInstall($this->installId())
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

    public function test_overlapping_same_card_misses_make_one_provider_call_and_then_reuse_result(): void
    {
        $response = $this->withExtensionInstall($this->installId())->postJson('/v1/subtitle-jobs', $this->validPayload())->assertOk();
        $job = SubtitleJob::where('public_id', $response->json('jobId'))->firstOrFail();
        $payload = ['trackId' => $job->track->public_id, 'cueId' => $job->track->cues[0]['cueId'], 'tokenIndex' => 0];
        $this->translationAnalysis->beforeTokenResult = function () use ($payload): void {
            try {
                app(LearningTokenEnrichmentService::class)->enrich($payload);
                $this->fail('Expected identical in-flight lookup to be rejected.');
            } catch (SubtitleProcessingException $exception) {
                $this->assertSame('learning_token_in_progress', $exception->context['reason']);
            }
        };
        $this->postJson('/v1/learning-tokens', $payload)->assertOk();
        $this->postJson('/v1/learning-tokens', $payload)->assertOk();
        $this->assertSame(1, $this->translationAnalysis->tokenCalls);
    }

    public function test_learning_token_enrichment_generates_cards_for_same_language_track(): void
    {
        $jobResponse = $this
            ->withExtensionInstall($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload([
                'sourceLanguage' => 'eng',
                'targetLanguage' => 'eng',
            ]))
            ->assertOk();

        $this
            ->withExtensionInstall($this->installId())
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

    public function test_learning_token_enrichment_is_shared_across_installs(): void
    {
        $jobResponse = $this
            ->withExtensionInstall($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload())
            ->assertOk();

        $this
            ->withExtensionInstall($this->installId('b'))
            ->postJson('/v1/learning-tokens', [
                'trackId' => $jobResponse->json('track.trackId'),
                'cueId' => 'cue-0001',
                'tokenIndex' => 0,
            ])
            ->assertOk()->assertJsonStructure(['token']);
    }

    public function test_transcription_failure_returns_stable_error_and_status(): void
    {
        $this->transcriptionService->shouldFail = true;

        $this
            ->withExtensionInstall($this->installId())
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

    public function test_create_subtitle_job_returns_stable_validation_errors(): void
    {
        $this
            ->withExtensionInstall($this->installId())
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
            ->withExtensionInstall($this->installId())
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
            ->withExtensionInstall($this->installId('b'))
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
                ->withExtensionInstall($this->installId())
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
        unset($payload['includeRomanization'], $payload['includeTranslation']);

        $this
            ->withExtensionInstall($this->installId())
            ->postJson('/v1/subtitle-jobs', $payload)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
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
            ->withExtensionInstall($this->installId())
            ->postJson('/v1/subtitle-jobs/'.(string) Str::uuid().'/cancel')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    }

    private function runCorrectionToCompletion(SubtitleJob $job, string $attemptId, int $startRevision = 0, int $maxRevisions = 60): void
    {
        $service = app(LyricsCorrectionService::class);

        for ($revision = $startRevision; $revision < $maxRevisions; $revision++) {
            $row = SubtitleTrackLyricsCorrection::query()
                ->where('subtitle_track_id', $job->track->id)
                ->where('attempt_id', $attemptId)
                ->firstOrFail();

            if (in_array($row->status, ['completed', 'failed'], true)) {
                return;
            }

            (new LyricsCorrectionJob($job->track->id, $job->id, $attemptId, $row->work_revision, ($row->work_state['stage'] ?? null) === 'analyzing' ? $row->work_state['batchIndex'] : null))->handle($service);
        }

        $this->fail('Lyrics correction did not reach a terminal state within the expected revisions.');
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
            'includeRomanization' => true,
            'includeTranslation' => false,
            ...$overrides,
        ];
    }

    private function configureThrowingQueue(): ThrowingSubtitleQueue
    {
        $queue = new ThrowingSubtitleQueue;
        $connector = new class($queue) implements ConnectorInterface
        {
            public function __construct(private readonly ThrowingSubtitleQueue $queue) {}

            public function connect(array $config): ThrowingSubtitleQueue
            {
                return $this->queue;
            }
        };
        Queue::extend('throwing', fn (): ConnectorInterface => $connector);
        config([
            'queue.default' => 'throwing',
            'queue.connections.throwing' => ['driver' => 'throwing'],
            'subtitles.queue.connection' => 'throwing',
        ]);

        return $queue;
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
        return "WEBVTT\n\ncue-0001\n00:00:00.500 --> 00:00:02.100\nfirst transcript segment\n\ncue-0002\n00:00:02.400 --> 00:00:04.000\nsecond transcript segment\n";
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

final class ThrowingSubtitleQueue extends NullQueue
{
    public bool $shouldThrow = true;

    public int $pushes = 0;

    public function push($job, $data = '', $queue = null)
    {
        $this->pushes++;

        if ($this->shouldThrow) {
            throw new \RuntimeException('Queue publication unavailable.');
        }

        return null;
    }
}

class RecordingYouTubeAudioSource extends YouTubeAudioSource
{
    public int $metadataCalls = 0;

    public bool $rejectMetadata = false;

    public function validatedDuration(string $youtubeUrl, ?int $requestDurationSeconds): int
    {
        $this->metadataCalls++;
        if ($this->rejectMetadata) {
            throw SubtitleProcessingException::audioUnavailable('Only public YouTube videos are supported.');
        }

        return 42;
    }

    public int $calls = 0;

    public ?string $lastAudioPath = null;

    public ?\Closure $beforeAcquireResult = null;

    public function acquire(string $youtubeUrl, ?int $requestDurationSeconds, string $workDirectory, ?string $videoId = null): TemporaryAudioFile
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
    public int $urlCalls = 0;

    public function transcribeYouTube(string $videoId, string $sourceLanguage, ?SubtitleJob $job = null): array
    {
        $this->urlCalls++;

        return ['words' => [], 'language_code' => $sourceLanguage];
    }

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

    public function transcribeChunk(TemporaryAudioFile $audio, string $sourceLanguage, ?SubtitleJob $job = null): array
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
        ?string $jobId = null,
        ?string $runId = null,
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
            webVtt: "WEBVTT\n\ncue-0001\n00:00:00.500 --> 00:00:02.100\nfirst transcript segment\n\ncue-0002\n00:00:02.400 --> 00:00:04.000\nsecond transcript segment\n",
        );
    }

    #[DataProvider('correctedTokenEdits')]
    public function test_quick_fix_rejects_model_tokens_that_differ_from_the_transcript(
        string $sourceText, array $tokenTexts, int $tokenIndex, string $replacement, ?string $expectedText,
    ): void {
        $response = $this->withExtensionInstall($this->installId())->postJson('/v1/subtitle-jobs', $this->validPayload())->assertOk();
        $job = SubtitleJob::where('public_id', $response->json('jobId'))->firstOrFail();
        $cue = $job->track->cues[0];
        $cue['sourceText'] = $sourceText;
        $cue['tokens'] = array_map(fn (string $text, int $index): array => [
            'index' => $index, 'text' => $text, 'normalizedText' => mb_strtolower($text),
        ], $tokenTexts, array_keys($tokenTexts));
        $job->track->update(['cues' => [$cue]]);
        $original = $job->track->fresh()->getAttributes();

        $response = $this->patchJson('/v1/subtitle-jobs/'.$job->public_id.'/cues/'.$cue['cueId'].'/tokens/'.$tokenIndex, [
            'expectedTrackId' => $job->track->public_id, 'text' => $replacement,
        ]);

        if ($expectedText === null) {
            $response->assertUnprocessable()->assertJsonPath('error.code', 'lyrics_correction_failed')
                ->assertJsonPath('error.message', 'This word cannot be edited because the line text does not match its words. Your subtitles are unchanged.');
            $this->assertSame($original, $job->track->fresh()->getAttributes());
            EditedCueAgent::assertNeverPrompted();

            return;
        }
        $response->assertOk()
            ->assertJsonPath('cues.0.sourceText', $expectedText)
            ->assertJsonPath('cues.0.tokens.'.$tokenIndex.'.text', $replacement);
        $this->assertStringContainsString($expectedText, $job->track->fresh()->web_vtt);
    }

    public static function correctedTokenEdits(): array
    {
        return [
            'mixed script correction' => ['غصن يديנו النجسة.', ['غصن', 'يدينو', 'النجسة'], 1, 'يديه', null],
            'token after correction' => ['غصن يديנו النجسة.', ['غصن', 'يدينو', 'النجسة'], 2, 'الجديدة', null],
            'misleading repetition' => ['their there', ['there', 'there'], 0, 'here', null],
            'exact punctuation' => ['hello, world!', ['hello', 'world'], 1, 'everyone', 'hello, everyone!'],
            'attached punctuation' => ['helo world!', ['hello', 'world!'], 0, 'hi', null],
            'no-space script' => ['日夲語勉強', ['日本語', '勉強'], 1, '学習', null],
        ];
    }

    public function test_quick_fix_ignores_invalid_timing_in_other_cues_and_keeps_the_previous_translation_out_of_the_prompt(): void
    {
        $response = $this->withExtensionInstall($this->installId())->postJson('/v1/subtitle-jobs', $this->validPayload())->assertOk();
        $job = SubtitleJob::where('public_id', $response->json('jobId'))->firstOrFail();
        $cues = $job->track->cues;
        $cues[0]['translatedText'] = 'Old translation of the old word';
        // A neighbor that is too long and overlaps must not block an edit to another cue.
        $cues[1]['sourceText'] = str_repeat('long ', 20);
        $cues[1]['startMs'] = $cues[0]['startMs'];
        $job->track->update(['cues' => $cues]);
        $prompted = null;
        EditedCueAgent::fake(function (string $prompt) use (&$prompted): array {
            $prompted = json_decode($prompt, true)['cues'][0];

            return ['translatedText' => 'Refreshed', 'cues' => [[...$prompted, 'romanization' => null, 'tokens' => array_map(
                fn (array $token): array => [...$token, 'translation' => 'new', 'gloss' => null, 'romanization' => null], $prompted['tokens'],
            )]]];
        })->preventStrayPrompts();

        $this->patchJson('/v1/subtitle-jobs/'.$job->public_id.'/cues/'.$cues[0]['cueId'].'/tokens/0', [
            'expectedTrackId' => $job->track->public_id, 'text' => 'updated',
        ])->assertOk()->assertJsonPath('cues.0.sourceText', 'updated transcript segment');
        $this->assertArrayNotHasKey('translatedText', $prompted);
    }

    public function test_quick_fix_rejects_invalid_edited_cue_timing_before_the_provider_call(): void
    {
        $response = $this->withExtensionInstall($this->installId())->postJson('/v1/subtitle-jobs', $this->validPayload())->assertOk();
        $job = SubtitleJob::where('public_id', $response->json('jobId'))->firstOrFail();
        $cues = $job->track->cues;
        $cues[0]['endMs'] = $cues[0]['startMs'];
        $job->track->update(['cues' => $cues]);

        $this->patchJson('/v1/subtitle-jobs/'.$job->public_id.'/cues/'.$cues[0]['cueId'].'/tokens/0', [
            'expectedTrackId' => $job->track->public_id, 'text' => 'updated',
        ])->assertUnprocessable()->assertJsonPath('error.code', 'lyrics_correction_failed');
        EditedCueAgent::assertNeverPrompted();
    }

    public function test_quick_fix_records_cost_for_each_refreshed_layer(): void
    {
        config([
            'subtitles.costs.openai_enrichment_microusd_per_cue' => 7,
            'subtitles.costs.openai_translation_microusd_per_cue' => 5,
            'subtitles.costs.openai_romanization_microusd_per_cue' => 3,
        ]);
        $response = $this->withExtensionInstall($this->installId())->postJson('/v1/subtitle-jobs', $this->validPayload(['includeTranslation' => true]))->assertOk();
        $job = SubtitleJob::where('public_id', $response->json('jobId'))->firstOrFail();
        $job->update(['ai_provider' => 'openai', 'source_language' => 'jpn', 'target_language' => 'eng', 'include_romanization' => true]);
        $cues = $job->track->cues;
        $cues[0] = [...$cues[0], 'sourceText' => '猫です', 'tokens' => [
            ['index' => 0, 'text' => '猫', 'normalizedText' => '猫'], ['index' => 1, 'text' => 'です', 'normalizedText' => 'です'],
        ]];
        $job->track->update(['cues' => $cues]);
        $before = $job->fresh()->estimated_provider_cost_microusd;

        $this->patchJson('/v1/subtitle-jobs/'.$job->public_id.'/cues/'.$cues[0]['cueId'].'/tokens/0', [
            'expectedTrackId' => $job->track->public_id, 'text' => '犬',
        ])->assertOk();

        $this->assertSame($before + 15, $job->fresh()->estimated_provider_cost_microusd);
    }
}
