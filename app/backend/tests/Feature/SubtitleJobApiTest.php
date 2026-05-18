<?php

namespace Tests\Feature;

use App\Exceptions\SubtitleProcessingException;
use App\Jobs\EnrichSubtitleCueBatch;
use App\Jobs\ProcessSubtitleJob;
use App\Jobs\RomanizeSubtitleCueBatch;
use App\Jobs\TokenizeSubtitleCueBatch;
use App\Jobs\TranslateSubtitleCueBatch;
use App\Models\SubtitleJob;
use App\Models\SubtitleTrack;
use App\Services\Audio\TemporaryAudioFile;
use App\Services\Audio\YouTubeAudioSource;
use App\Services\Subtitles\SubtitleGenerationPipeline;
use App\Services\Subtitles\SubtitleJobArtifactStore;
use App\Services\Subtitles\SubtitleJobService;
use App\Services\Subtitles\SubtitleQueueWorkerBootstrapper;
use App\Services\Transcription\ElevenLabsScribeTranscriptionService;
use App\Services\Transcription\ScribeTranscriptNormalizer;
use App\Services\Transcription\TimestampedTranscript;
use App\Services\Transcription\TimestampedTranscriptSegment;
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
use PDOException;
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
        ]);
    }

    public function test_new_subtitle_request_returns_running_job_and_dispatches_processing(): void
    {
        config([
            'queue.default' => 'database',
            'subtitles.queue.connection' => 'database',
        ]);
        Queue::fake();

        $response = $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload());

        $response
            ->assertAccepted()
            ->assertJsonPath('status', 'running')
            ->assertJsonPath('stage', 'preparing')
            ->assertJsonPath('progressPercent', 5)
            ->assertJsonMissingPath('track')
            ->assertJsonStructure(['jobId', 'status', 'stage', 'progressPercent', 'createdAt', 'updatedAt']);

        Queue::assertPushedOn(SubtitleGenerationPipeline::queue(), ProcessSubtitleJob::class);
    }

    public function test_duplicate_running_request_reuses_job_without_dispatching_duplicate_work(): void
    {
        config([
            'queue.default' => 'database',
            'subtitles.queue.connection' => 'database',
        ]);
        Queue::fake();

        $first = $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload())
            ->assertAccepted();

        $second = $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload())
            ->assertAccepted();

        $this->assertSame($first->json('jobId'), $second->json('jobId'));
        Queue::assertPushed(ProcessSubtitleJob::class, 1);
    }

    public function test_new_subtitle_request_uses_configured_subtitle_queue_connection(): void
    {
        config([
            'queue.default' => 'database',
            'subtitles.queue.connection' => 'background',
        ]);
        Queue::fake();

        $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload(['youtubeVideoId' => 'bgqueue0001']))
            ->assertAccepted();

        Queue::assertPushed(ProcessSubtitleJob::class, function (ProcessSubtitleJob $job): bool {
            return $job->connection === 'background'
                && $job->queue === SubtitleGenerationPipeline::queue();
        });
    }

    public function test_new_subtitle_request_bootstraps_local_workers_when_enabled(): void
    {
        config([
            'queue.default' => 'database',
            'subtitles.queue.connection' => 'database',
            'subtitles.queue.auto_start_workers' => true,
        ]);
        Queue::fake();
        $workerBootstrap = (object) ['startCalls' => 0];

        $this->app->instance(SubtitleQueueWorkerBootstrapper::class, new class($workerBootstrap) extends SubtitleQueueWorkerBootstrapper
        {
            public function __construct(private readonly object $workerBootstrap) {}

            public function startIfNeeded(): void
            {
                $this->workerBootstrap->startCalls++;
            }
        });

        $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload(['youtubeVideoId' => 'workerboot1']))
            ->assertAccepted();

        $this->assertSame(1, $workerBootstrap->startCalls);
    }

    public function test_auto_started_sqlite_queue_uses_one_worker(): void
    {
        config([
            'database.default' => 'sqlite',
            'queue.connections.database.connection' => null,
            'subtitles.queue.connection' => 'database',
            'subtitles.queue.auto_start_workers' => true,
            'subtitles.queue.auto_worker_count' => 3,
        ]);
        cache()->forget('subtitle-ai-worker-bootstrap-started');
        $workerBootstrap = (object) ['startCalls' => 0];

        $bootstrapper = new class($workerBootstrap) extends SubtitleQueueWorkerBootstrapper
        {
            public function __construct(private readonly object $workerBootstrap) {}

            protected function startWorkerProcess(): ?int
            {
                $this->workerBootstrap->startCalls++;

                return $this->workerBootstrap->startCalls;
            }
        };

        $bootstrapper->startIfNeeded();

        $this->assertSame(1, $workerBootstrap->startCalls);
    }

    public function test_auto_started_redis_queue_uses_configured_worker_count(): void
    {
        config([
            'subtitles.queue.connection' => 'redis',
            'subtitles.queue.auto_start_workers' => true,
            'subtitles.queue.auto_worker_count' => 3,
        ]);
        cache()->forget('subtitle-ai-worker-bootstrap-started');
        $workerBootstrap = (object) ['startCalls' => 0];

        $bootstrapper = new class($workerBootstrap) extends SubtitleQueueWorkerBootstrapper
        {
            public function __construct(private readonly object $workerBootstrap) {}

            protected function startWorkerProcess(): ?int
            {
                $this->workerBootstrap->startCalls++;

                return $this->workerBootstrap->startCalls;
            }
        };

        $bootstrapper->startIfNeeded();

        $this->assertSame(3, $workerBootstrap->startCalls);
    }

    public function test_auto_started_worker_lock_lasts_for_worker_lifetime(): void
    {
        config([
            'subtitles.queue.connection' => 'redis',
            'subtitles.queue.auto_start_workers' => true,
            'subtitles.queue.auto_worker_count' => 3,
            'subtitles.queue.auto_worker_max_time_seconds' => 120,
        ]);
        cache()->forget('subtitle-ai-worker-bootstrap-started');
        $workerBootstrap = (object) ['startCalls' => 0];

        $bootstrapper = new class($workerBootstrap) extends SubtitleQueueWorkerBootstrapper
        {
            public function __construct(private readonly object $workerBootstrap) {}

            protected function startWorkerProcess(): ?int
            {
                $this->workerBootstrap->startCalls++;

                return $this->workerBootstrap->startCalls;
            }
        };

        try {
            $bootstrapper->startIfNeeded();
            $this->travel(31)->seconds();
            $bootstrapper->startIfNeeded();
        } finally {
            $this->travelBack();
        }

        $this->assertSame(3, $workerBootstrap->startCalls);
    }

    public function test_queue_retry_after_defaults_exceed_subtitle_worker_timeout(): void
    {
        $processJobTimeout = (new ProcessSubtitleJob(1))->timeout;

        $this->assertGreaterThan($processJobTimeout, config('queue.connections.database.retry_after'));
        $this->assertGreaterThan($processJobTimeout, config('queue.connections.redis.retry_after'));
        $this->assertGreaterThan(
            config('subtitles.queue.auto_worker_timeout_seconds'),
            config('queue.connections.redis.retry_after'),
        );
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
            'processing_version' => SubtitleJobService::PROCESSING_VERSION_ON_DEMAND_ROMANIZED,
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
            ->withHeader('X-Extension-Install-Id', $installId)
            ->postJson('/v1/subtitle-jobs', $this->validPayload(['youtubeVideoId' => 'stalejob001']))
            ->assertAccepted();

        $this->assertSame($staleJob->public_id, $response->json('jobId'));
        $this->assertTrue($staleJob->fresh()->created_at->greaterThan($staleJob->created_at));
        Queue::assertPushed(ProcessSubtitleJob::class, 1);
    }

    public function test_tokenization_and_translation_batches_are_dispatched_together_when_translation_is_enabled(): void
    {
        config([
            'queue.default' => 'database',
            'subtitles.queue.connection' => 'database',
        ]);

        $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload([
                'youtubeVideoId' => 'translate01',
                'includeTranslation' => true,
            ]))
            ->assertAccepted();

        Artisan::call('queue:work', [
            '--queue' => SubtitleGenerationPipeline::queue().',default',
            '--once' => true,
            '--tries' => 1,
            '--sleep' => 0,
        ]);

        $payloads = DB::table('jobs')->pluck('payload')->implode("\n");

        $this->assertStringContainsString(addslashes(TokenizeSubtitleCueBatch::class), $payloads);
        $this->assertStringContainsString(addslashes(TranslateSubtitleCueBatch::class), $payloads);
    }

    public function test_cancelled_tokenization_batch_skips_provider_calls(): void
    {
        $job = $this->runningSubtitleJob('tokenizing');
        $this->artifacts()->putCueCollection($job, SubtitleJobArtifactStore::DRAFT_CUES, [$this->sampleCue()]);

        $this->dispatchCancelledBatch(new TokenizeSubtitleCueBatch($job->id, 0));

        $this->assertSame(0, $this->translationAnalysis->tokenizationCalls);
    }

    public function test_cancelled_translation_batch_skips_provider_calls(): void
    {
        $job = $this->runningSubtitleJob('translating');
        $this->artifacts()->putCueCollection($job, SubtitleJobArtifactStore::DRAFT_CUES, [$this->sampleCue()]);

        $this->dispatchCancelledBatch(new TranslateSubtitleCueBatch($job->id, 0));

        $this->assertSame(0, $this->translationAnalysis->translationCalls);
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

        $this->dispatchCancelledBatch(new RomanizeSubtitleCueBatch($job->id, 0));

        $this->assertSame(0, $this->translationAnalysis->romanizationCalls);
    }

    public function test_cancelled_enrichment_batch_skips_provider_calls(): void
    {
        $job = $this->runningSubtitleJob('enriching');
        $this->artifacts()->putCueCollection($job, SubtitleJobArtifactStore::MERGED_CUES, [$this->sampleCue()]);

        $this->dispatchCancelledBatch(new EnrichSubtitleCueBatch($job->id, 0));

        $this->assertSame(0, $this->translationAnalysis->calls);
    }

    public function test_transcription_processor_ignores_jobs_already_claimed_by_another_worker(): void
    {
        $job = SubtitleJob::factory()->create([
            'stage' => 'acquiring-audio',
            'progress_percent' => 20,
        ]);

        app(SubtitleGenerationPipeline::class)->processTranscription($job->id);

        $this->assertSame(0, $this->audioSource->calls);
    }

    public function test_queue_database_lock_failure_returns_specific_public_message(): void
    {
        $job = SubtitleJob::factory()->create([
            'stage' => 'tokenizing',
            'progress_percent' => 65,
        ]);

        app(SubtitleGenerationPipeline::class)->failJob(
            $job->id,
            'tokenizing',
            new PDOException('SQLSTATE[HY000]: General error: 5 database is locked'),
        );

        $this
            ->withHeader('X-Extension-Install-Id', $job->install_id)
            ->getJson('/v1/subtitle-jobs/'.$job->public_id)
            ->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('stage', 'tokenizing')
            ->assertJsonPath('message', 'Subtitle queue storage was busy while processing. Retry generation after the current job finishes.');

        $this->assertDatabaseHas('subtitle_jobs', [
            'id' => $job->id,
            'status' => 'failed',
            'error_code' => 'queue_unavailable',
            'error_message' => 'Subtitle queue storage was busy while processing. Retry generation after the current job finishes.',
        ]);
    }

    public function test_late_batch_result_does_not_recreate_artifacts_after_job_failure(): void
    {
        $job = $this->runningSubtitleJob('tokenizing');
        $this->artifacts()->putCueCollection($job, SubtitleJobArtifactStore::DRAFT_CUES, [$this->sampleCue()]);
        $this->translationAnalysis->beforeTokenizationResult = function () use ($job): void {
            app(SubtitleGenerationPipeline::class)->failJob(
                $job->id,
                'tokenizing',
                SubtitleProcessingException::enrichmentFailed(),
            );
        };

        app(SubtitleGenerationPipeline::class)->tokenizeBatch($job->id, 0);

        $this->assertDatabaseMissing('subtitle_job_artifacts', [
            'subtitle_job_id' => $job->id,
        ]);
    }

    public function test_default_generation_returns_transcript_first_track_without_full_card_enrichment(): void
    {
        $response = $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
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

    public function test_transcript_first_generation_adds_requested_translation(): void
    {
        $response = $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
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
        $this->assertSame(['spa', 'spa'], $this->translationAnalysis->sourceLanguages);
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
            ->withHeader('X-Extension-Install-Id', $this->installId())
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
            ->withHeader('X-Extension-Install-Id', $this->installId())
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
            ->withHeader('X-Extension-Install-Id', $this->installId())
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
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload(['youtubeVideoId' => 'nonlatin001']))
            ->assertAccepted()
            ->assertJsonPath('status', 'running');

        $this->runQueuedSubtitleJobs();

        $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
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
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload(['youtubeVideoId' => 'tokfail0001']))
            ->assertAccepted()
            ->assertJsonPath('status', 'running');

        $this->runQueuedSubtitleJobs();

        $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
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
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload([
                'youtubeVideoId' => 'trnfail0001',
                'includeTranslation' => true,
            ]))
            ->assertAccepted()
            ->assertJsonPath('status', 'running');

        $this->runQueuedSubtitleJobs();

        $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->getJson('/v1/subtitle-jobs/'.$response->json('jobId'))
            ->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('message', 'Subtitle enrichment failed.');

        $this->assertSame(1, $this->translationAnalysis->tokenizationCalls);
        $this->assertSame(1, $this->translationAnalysis->translationCalls);
        $this->assertDatabaseHas('subtitle_jobs', [
            'youtube_video_id' => 'trnfail0001',
            'status' => 'failed',
            'stage' => 'translating',
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
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload([
                'sourceLanguage' => 'jpn',
                'youtubeVideoId' => 'jpnfail0001',
            ]))
            ->assertAccepted()
            ->assertJsonPath('status', 'running');

        $this->runQueuedSubtitleJobs();

        $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
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
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload([
                'sourceLanguage' => 'jpn',
                'youtubeVideoId' => 'jpnfail0002',
            ]))
            ->assertAccepted()
            ->assertJsonPath('status', 'running');

        $this->runQueuedSubtitleJobs();

        $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
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
            ->withHeader('X-Extension-Install-Id', $this->installId())
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
            ->withHeader('X-Extension-Install-Id', $this->installId())
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
        $this->assertSame(['spa', 'spa', 'spa'], $this->translationAnalysis->sourceLanguages);
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
            ->withHeader('X-Extension-Install-Id', $this->installId())
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
            ->withHeader('X-Extension-Install-Id', $this->installId())
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
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload());

        $fullResponse = $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
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
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload([
                'youtubeVideoId' => 'transmode01',
                'includeTranslation' => false,
            ]));

        $translatedResponse = $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
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
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload([
                'youtubeVideoId' => 'romanmode01',
                'includeRomanization' => false,
            ]));

        $romanizedResponse = $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
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
                ->withHeader('X-Extension-Install-Id', $this->installId(chr(97 + $index)))
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
                ->withHeader('X-Extension-Install-Id', $this->installId(chr(97 + $index)))
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
            ->withHeader('X-Extension-Install-Id', $this->installId())
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
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload());

        $secondResponse = $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
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
            ->withHeader('X-Extension-Install-Id', $this->installId())
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
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload());

        $secondResponse = $this
            ->withHeader('X-Extension-Install-Id', $this->installId('b'))
            ->postJson('/v1/subtitle-jobs', $this->validPayload());

        $firstResponse->assertOk();
        $secondResponse->assertOk();

        $this->assertNotSame($firstResponse->json('jobId'), $secondResponse->json('jobId'));
        $this->assertNotSame($firstResponse->json('track.trackId'), $secondResponse->json('track.trackId'));
        $this->assertSame(2, SubtitleJob::count());
        $this->assertSame(2, SubtitleTrack::count());
        $this->assertSame(2, $this->audioSource->calls);
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
            'stage' => 'enriching',
            'progress_percent' => 75,
            'error_message' => 'Subtitle enrichment is temporarily rate limited.',
            'updated_at' => now()->subMinutes(2),
        ]);
        SubtitleJob::factory()->create([
            'youtube_video_id' => 'other000001',
            'install_id' => $this->installId('b'),
            'expires_at' => now()->addDays(30),
        ]);

        $response = $this
            ->withHeader('X-Extension-Install-Id', $installId)
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
            ->withHeader('X-Extension-Install-Id', $installId)
            ->postJson('/v1/subtitle-jobs', $this->validPayload(['youtubeVideoId' => 'ratelimit01']))
            ->assertAccepted();

        $this
            ->withHeader('X-Extension-Install-Id', $installId)
            ->getJson('/v1/subtitle-jobs/'.$job->public_id)
            ->assertOk()
            ->assertJsonPath('jobId', $job->public_id);

        $this
            ->withHeader('X-Extension-Install-Id', $installId)
            ->getJson('/v1/subtitle-jobs/'.$job->public_id)
            ->assertOk()
            ->assertJsonPath('jobId', $job->public_id);

        $this
            ->withHeader('X-Extension-Install-Id', $installId)
            ->getJson('/v1/subtitle-jobs/'.$job->public_id)
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'rate_limited');
    }

    public function test_learning_token_enrichment_updates_track_and_skips_duplicate_provider_calls(): void
    {
        $jobResponse = $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload())
            ->assertOk();

        $payload = [
            'trackId' => $jobResponse->json('track.trackId'),
            'cueId' => 'cue-0001',
            'tokenIndex' => 0,
        ];

        $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/learning-tokens', $payload)
            ->assertOk()
            ->assertJsonPath('trackId', $payload['trackId'])
            ->assertJsonPath('cueId', 'cue-0001')
            ->assertJsonPath('token.index', 0)
            ->assertJsonPath('token.text', 'first')
            ->assertJsonPath('token.gloss', 'first gloss')
            ->assertJsonPath('token.romanization', 'first romanized');

        $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/learning-tokens', $payload)
            ->assertOk()
            ->assertJsonPath('token.gloss', 'first gloss');

        $track = SubtitleTrack::where('public_id', $payload['trackId'])->firstOrFail();

        $this->assertSame('first gloss', $track->cues[0]['tokens'][0]['gloss']);
        $this->assertSame(1, $this->translationAnalysis->tokenCalls);
    }

    public function test_learning_token_enrichment_skips_provider_for_same_language_track(): void
    {
        $jobResponse = $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload([
                'sourceLanguage' => 'eng',
                'targetLanguage' => 'eng',
            ]))
            ->assertOk();

        $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/learning-tokens', [
                'trackId' => $jobResponse->json('track.trackId'),
                'cueId' => 'cue-0001',
                'tokenIndex' => 0,
            ])
            ->assertOk()
            ->assertJsonPath('token.text', 'first');

        $this->assertSame(0, $this->translationAnalysis->tokenCalls);
    }

    public function test_learning_token_enrichment_requires_owning_install(): void
    {
        $jobResponse = $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload())
            ->assertOk();

        $this
            ->withHeader('X-Extension-Install-Id', $this->installId('b'))
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
            ->withHeader('X-Extension-Install-Id', $this->installId())
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
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload(['enrichmentMode' => 'full']))
            ->assertAccepted()
            ->assertJsonPath('status', 'running');

        $this->runQueuedSubtitleJobs();

        $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
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
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', [
                'youtubeVideoId' => 'dQw4w9WgXcQ',
                'sourceLanguage' => 'zz',
                'targetLanguage' => 'auto',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['code', 'message', 'details'], 'requestId']);
    }

    public function test_create_subtitle_job_requires_explicit_generation_controls(): void
    {
        $payload = $this->validPayload();
        unset($payload['enrichmentMode'], $payload['includeRomanization'], $payload['includeTranslation']);

        $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
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
            ->withHeader('X-Extension-Install-Id', $this->installId())
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
            ->onConnection(SubtitleGenerationPipeline::connection())
            ->onQueue(SubtitleGenerationPipeline::queue())
            ->dispatch();

        $batch->cancel();

        $this->runQueuedSubtitleJobs();
    }

    private function runQueuedSubtitleJobs(): void
    {
        for ($attempt = 0; $attempt < 50 && DB::table('jobs')->exists(); $attempt++) {
            Artisan::call('queue:work', [
                '--queue' => SubtitleGenerationPipeline::queue().',default',
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

    public function acquire(string $youtubeUrl, ?int $requestDurationSeconds): TemporaryAudioFile
    {
        $this->calls++;
        parse_str((string) parse_url($youtubeUrl, PHP_URL_QUERY), $query);
        $videoId = (string) $query['v'];

        $directory = storage_path('framework/testing/audio-api/'.(string) Str::uuid());
        File::ensureDirectoryExists($directory);

        $path = $directory.DIRECTORY_SEPARATOR.$videoId.'.m4a';
        File::put($path, 'fake-audio');
        $this->lastAudioPath = $path;

        return new TemporaryAudioFile(
            path: $path,
            directory: $directory,
            durationSeconds: 42,
            sizeBytes: File::size($path),
            mimeType: 'audio/mp4',
        );
    }
}

class RecordingTranscriptionService extends ElevenLabsScribeTranscriptionService
{
    public function __construct()
    {
        parent::__construct(new ScribeTranscriptNormalizer);
    }

    public bool $shouldFail = false;

    public ?TimestampedTranscript $transcript = null;

    /**
     * @var array<int, string>
     */
    public array $sourceLanguages = [];

    public function transcribe(TemporaryAudioFile $audio, string $sourceLanguage): TimestampedTranscript
    {
        $this->sourceLanguages[] = $sourceLanguage;

        if ($this->shouldFail) {
            throw SubtitleProcessingException::transcriptionFailed();
        }

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

    /**
     * @var array<int, string>
     */
    public array $sourceLanguages = [];

    /**
     * @var array<int, string>
     */
    public array $targetLanguages = [];

    /**
     * @param  array<int, array<string, mixed>>  $cues
     */
    public function tokenize(array $cues, string $sourceLanguage): CueEnrichmentResult
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
                $cues,
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
     * @param  array<int, array<string, mixed>>  $allCues
     */
    public function tokenizeCueBatch(array $batch, array $allCues, string $sourceLanguage): CueEnrichmentResult
    {
        return $this->tokenize($batch, $sourceLanguage);
    }

    /**
     * @param  array<int, array<string, mixed>>  $cues
     */
    public function enrich(
        array $cues,
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
                $cues,
            ),
            'unknown',
        );
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
        return $this->enrich($batch, $sourceLanguage, $targetLanguage, $includeRomanization);
    }

    /**
     * @param  array<int, array<string, mixed>>  $cues
     */
    public function translate(array $cues, string $sourceLanguage, string $targetLanguage): CueEnrichmentResult
    {
        $this->translationCalls++;
        $this->sourceLanguages[] = $sourceLanguage;
        $this->targetLanguages[] = $targetLanguage;

        if ($this->translationShouldFail) {
            throw SubtitleProcessingException::enrichmentFailed();
        }

        return new CueEnrichmentResult(
            array_map(
                fn (array $cue): array => [
                    ...$cue,
                    'translatedText' => 'Translated '.$cue['sourceText'],
                ],
                $cues,
            ),
            'unknown',
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $batch
     */
    public function translateCueBatch(array $batch, string $sourceLanguage, string $targetLanguage): CueEnrichmentResult
    {
        return $this->translate($batch, $sourceLanguage, $targetLanguage);
    }

    /**
     * @param  array<int, array<string, mixed>>  $cues
     */
    public function romanize(array $cues, string $sourceLanguage): CueEnrichmentResult
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
                $cues,
            ),
            'unknown',
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $batch
     */
    public function romanizeCueBatch(array $batch, string $sourceLanguage): CueEnrichmentResult
    {
        return $this->romanize($batch, $sourceLanguage);
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
