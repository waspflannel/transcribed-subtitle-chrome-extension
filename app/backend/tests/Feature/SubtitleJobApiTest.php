<?php

namespace Tests\Feature;

use App\Exceptions\SubtitleProcessingException;
use App\Models\SubtitleJob;
use App\Models\SubtitleTrack;
use App\Services\Audio\TemporaryAudioFile;
use App\Services\Audio\YouTubeAudioSource;
use App\Services\Transcription\OpenAiWebVttTranscriptionService;
use App\Services\Transcription\TimestampedTranscript;
use App\Services\Transcription\TimestampedTranscriptSegment;
use App\Services\TranslationAnalysis\CueEnrichmentResult;
use App\Services\TranslationAnalysis\TranslationAnalysisProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
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
        $this->app->instance(OpenAiWebVttTranscriptionService::class, $this->transcriptionService);
        $this->app->instance(TranslationAnalysisProvider::class, $this->translationAnalysis);
    }

    public function test_create_subtitle_job_returns_completed_track(): void
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
            ->assertJsonPath('track.cues.0.translatedText', 'Translated first transcript segment')
            ->assertJsonPath('track.cues.0.tokens.0.gloss', 'first')
            ->assertJsonPath('track.webVtt', $this->sampleWebVtt())
            ->assertJsonStructure($this->completedJobShape());

        $this->assertSame(1, SubtitleJob::count());
        $this->assertSame(1, SubtitleTrack::count());
        $this->assertDatabaseHas('subtitle_jobs', [
            'youtube_video_id' => 'dQw4w9WgXcQ',
            'source_language' => 'ar',
            'target_language' => 'en',
        ]);
        $this->assertDatabaseHas('subtitle_tracks', [
            'youtube_video_id' => 'dQw4w9WgXcQ',
            'source_dialect' => 'unknown',
        ]);
        $this->assertFalse(File::exists($this->audioSource->lastAudioPath));
    }

    public function test_duplicate_subtitle_job_request_reuses_existing_completed_job(): void
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

        $this->assertSame(1, SubtitleJob::count());
        $this->assertSame(1, SubtitleTrack::count());
        $this->assertSame(1, $this->audioSource->calls);
        $this->assertSame(1, $this->translationAnalysis->calls);
    }

    public function test_compatible_completed_track_is_reused_without_audio_acquisition(): void
    {
        $job = SubtitleJob::factory()->create([
            'youtube_video_id' => 'dQw4w9WgXcQ',
            'source_language' => 'ar',
            'target_language' => 'en',
            'expires_at' => now()->addDays(30),
        ]);

        $track = SubtitleTrack::factory()
            ->for($job, 'job')
            ->create([
                'youtube_video_id' => 'dQw4w9WgXcQ',
                'source_language' => 'ar',
                'target_language' => 'en',
                'expires_at' => now()->addDays(30),
            ]);

        $response = $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload());

        $response
            ->assertOk()
            ->assertJsonPath('jobId', $job->public_id)
            ->assertJsonPath('track.trackId', $track->public_id)
            ->assertJsonPath('track.webVtt', "WEBVTT\n\n00:00:01.200 --> 00:00:04.200\nmock source text\n")
            ->assertJsonPath('track.cues.0.sourceText', 'mock source text');

        $this->assertSame(0, $this->audioSource->calls);
        $this->assertSame(1, SubtitleJob::count());
        $this->assertSame(1, SubtitleTrack::count());
    }

    public function test_incomplete_compatible_job_is_reused_for_retry(): void
    {
        $job = SubtitleJob::factory()->create([
            'youtube_video_id' => 'dQw4w9WgXcQ',
            'source_language' => 'ar',
            'target_language' => 'en',
            'expires_at' => null,
        ]);

        $response = $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload());

        $response
            ->assertOk()
            ->assertJsonPath('jobId', $job->public_id)
            ->assertJsonStructure($this->completedJobShape());

        $this->assertSame(1, SubtitleJob::count());
        $this->assertSame(1, SubtitleTrack::count());
        $this->assertSame(1, $this->audioSource->calls);
    }

    public function test_expired_subtitle_job_request_regenerates_existing_job(): void
    {
        $job = SubtitleJob::factory()->create([
            'youtube_video_id' => 'dQw4w9WgXcQ',
            'source_language' => 'ar',
            'target_language' => 'en',
            'expires_at' => now()->subMinute(),
        ]);

        $expiredTrack = SubtitleTrack::factory()
            ->for($job, 'job')
            ->create([
                'youtube_video_id' => 'dQw4w9WgXcQ',
                'source_language' => 'ar',
                'target_language' => 'en',
                'expires_at' => now()->subMinute(),
            ]);

        $response = $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload());

        $response
            ->assertOk()
            ->assertJsonPath('jobId', $job->public_id)
            ->assertJsonStructure($this->completedJobShape());

        $this->assertSame(1, SubtitleJob::count());
        $this->assertSame(1, SubtitleTrack::count());
        $this->assertDatabaseMissing('subtitle_tracks', ['id' => $expiredTrack->id]);
        $this->assertTrue($job->refresh()->expires_at->isFuture());
    }

    public function test_transcription_failure_returns_stable_error_and_deletes_raw_audio(): void
    {
        $this->transcriptionService->shouldFail = true;

        $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload())
            ->assertStatus(502)
            ->assertJsonPath('error.code', 'transcription_failed');

        $this->assertSame(1, SubtitleJob::count());
        $this->assertSame(0, SubtitleTrack::count());
        $this->assertFalse(File::exists($this->audioSource->lastAudioPath));
    }

    public function test_enrichment_failure_returns_stable_error_and_deletes_raw_audio(): void
    {
        $this->translationAnalysis->shouldFail = true;

        $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload())
            ->assertStatus(502)
            ->assertJsonPath('error.code', 'enrichment_failed');

        $this->assertSame(1, SubtitleJob::count());
        $this->assertSame(0, SubtitleTrack::count());
        $this->assertFalse(File::exists($this->audioSource->lastAudioPath));
    }

    public function test_create_subtitle_job_returns_stable_validation_errors(): void
    {
        $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', [
                'youtubeVideoId' => 'bad',
                'sourceLanguage' => 'fr',
                'targetLanguage' => 'en',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['code', 'message', 'details'], 'requestId']);
    }

    public function test_create_subtitle_job_rejects_unsupported_youtube_url(): void
    {
        $payload = $this->validPayload();
        $payload['youtubeUrl'] = 'https://example.com/watch?v=dQw4w9WgXcQ';

        $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', $payload)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');

        $this->assertSame(0, $this->audioSource->calls);
    }

    public function test_create_subtitle_job_rejects_too_long_duration_before_audio_acquisition(): void
    {
        $payload = $this->validPayload();
        $payload['videoDurationSeconds'] = 3601;

        $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', $payload)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');

        $this->assertSame(0, $this->audioSource->calls);
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

    public function test_api_rate_limits_by_extension_install_id(): void
    {
        config([
            'subtitles.rate_limits.per_install_per_minute' => 1,
            'subtitles.rate_limits.per_ip_per_minute' => 100,
        ]);

        $installId = $this->installId('b');

        $this
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->withHeader('X-Extension-Install-Id', $installId)
            ->postJson('/v1/subtitle-jobs', $this->validPayload())
            ->assertOk();

        $this
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->withHeader('X-Extension-Install-Id', $installId)
            ->postJson('/v1/subtitle-jobs', $this->validPayload())
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'rate_limited')
            ->assertJsonStructure(['requestId']);
    }

    public function test_api_rate_limits_by_ip_address(): void
    {
        config([
            'subtitles.rate_limits.per_install_per_minute' => 100,
            'subtitles.rate_limits.per_ip_per_minute' => 1,
        ]);

        $this
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.20'])
            ->withHeader('X-Extension-Install-Id', $this->installId('c'))
            ->postJson('/v1/subtitle-jobs', $this->validPayload())
            ->assertOk();

        $this
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.20'])
            ->withHeader('X-Extension-Install-Id', $this->installId('d'))
            ->postJson('/v1/subtitle-jobs', $this->validPayload())
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'rate_limited')
            ->assertJsonStructure(['requestId']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(): array
    {
        return [
            'youtubeVideoId' => 'dQw4w9WgXcQ',
            'youtubeUrl' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'videoDurationSeconds' => 213,
            'sourceLanguage' => 'ar',
            'targetLanguage' => 'en',
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
}

class RecordingYouTubeAudioSource extends YouTubeAudioSource
{
    public int $calls = 0;

    public ?string $lastAudioPath = null;

    public function acquire(string $videoId, ?string $youtubeUrl, ?int $requestDurationSeconds): TemporaryAudioFile
    {
        $this->calls++;

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

class RecordingTranscriptionService extends OpenAiWebVttTranscriptionService
{
    public bool $shouldFail = false;

    public function __construct() {}

    public function transcribe(TemporaryAudioFile $audio, string $sourceLanguage): TimestampedTranscript
    {
        if ($this->shouldFail) {
            throw SubtitleProcessingException::transcriptionFailed();
        }

        return new TimestampedTranscript(
            language: $sourceLanguage,
            durationSeconds: 42.0,
            segments: [
                new TimestampedTranscriptSegment(0.5, 2.1, 'first transcript segment'),
                new TimestampedTranscriptSegment(2.4, 4.0, 'second transcript segment'),
            ],
            webVtt: "WEBVTT\n\n00:00:00.500 --> 00:00:02.100\nfirst transcript segment\n\n00:00:02.400 --> 00:00:04.000\nsecond transcript segment\n",
        );
    }
}

class RecordingTranslationAnalysisProvider implements TranslationAnalysisProvider
{
    public int $calls = 0;

    public bool $shouldFail = false;

    /**
     * @param  array<int, array<string, mixed>>  $cues
     */
    public function enrich(array $cues, string $sourceLanguage, string $targetLanguage): CueEnrichmentResult
    {
        $this->calls++;

        if ($this->shouldFail) {
            throw SubtitleProcessingException::enrichmentFailed();
        }

        return new CueEnrichmentResult(
            array_map(
                function (array $cue): array {
                    $firstToken = strtok((string) $cue['sourceText'], ' ') ?: (string) $cue['sourceText'];

                    return [
                        ...$cue,
                        'translatedText' => 'Translated '.$cue['sourceText'],
                        'tokens' => [
                            [
                                'index' => 0,
                                'text' => $firstToken,
                                'gloss' => $firstToken,
                                'romanization' => $firstToken,
                            ],
                        ],
                    ];
                },
                $cues,
            ),
            'unknown',
        );
    }
}
