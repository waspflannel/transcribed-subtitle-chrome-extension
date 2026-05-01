<?php

namespace Tests\Feature;

use App\Exceptions\SubtitleProcessingException;
use App\Models\SubtitleJob;
use App\Models\SubtitleTrack;
use App\Services\Audio\AudioSource;
use App\Services\Audio\TemporaryAudioFile;
use App\Services\Transcription\TimestampedTranscript;
use App\Services\Transcription\TimestampedTranscriptSegment;
use App\Services\Transcription\TranscriptionOptions;
use App\Services\Transcription\TranscriptionProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class SubtitleJobApiTest extends TestCase
{
    use RefreshDatabase;

    private RecordingAudioSource $audioSource;

    private RecordingTranscriptionProvider $transcriptionProvider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->audioSource = new RecordingAudioSource;
        $this->transcriptionProvider = new RecordingTranscriptionProvider;

        $this->app->instance(AudioSource::class, $this->audioSource);
        $this->app->instance(TranscriptionProvider::class, $this->transcriptionProvider);
    }

    public function test_create_subtitle_job_returns_completed_track(): void
    {
        $response = $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload());

        $response
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('youtubeVideoId', 'dQw4w9WgXcQ')
            ->assertJsonPath('track.youtubeVideoId', 'dQw4w9WgXcQ')
            ->assertJsonPath('track.cues.0.startMs', 500)
            ->assertJsonPath('track.cues.0.endMs', 1750)
            ->assertJsonPath('track.cues.0.sourceText', 'first transcript segment')
            ->assertJsonStructure($this->completedJobShape());

        $this->assertSame(1, SubtitleJob::count());
        $this->assertSame(1, SubtitleTrack::count());
        $this->assertDatabaseHas('subtitle_jobs', [
            'youtube_video_id' => 'dQw4w9WgXcQ',
            'source_language' => 'ar',
            'target_language' => 'en',
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
            ->assertJsonPath('status', 'completed')
            ->assertJsonStructure($this->completedJobShape());

        $this->assertSame(1, SubtitleJob::count());
        $this->assertSame(1, SubtitleTrack::count());
        $this->assertDatabaseMissing('subtitle_tracks', ['id' => $expiredTrack->id]);
        $this->assertTrue($job->refresh()->expires_at->isFuture());
    }

    public function test_transcription_failure_returns_stable_error_and_deletes_raw_audio(): void
    {
        $this->transcriptionProvider->shouldFail = true;

        $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload())
            ->assertStatus(502)
            ->assertJsonPath('error.code', 'transcription_failed');

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
                'options' => [
                    'includeRomanization' => true,
                    'includeGloss' => true,
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['code', 'message', 'details']]);
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
        $this
            ->postJson('/v1/subtitle-jobs', $this->validPayload())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
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
            'options' => [
                'includeRomanization' => true,
                'includeGloss' => true,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function completedJobShape(): array
    {
        return [
            'jobId',
            'status',
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
                'cues' => [
                    '*' => ['cueId', 'index', 'startMs', 'endMs', 'sourceText', 'translatedText', 'tokens'],
                ],
            ],
        ];
    }

    private function installId(): string
    {
        return 'install_'.str_repeat('a', 32);
    }
}

class RecordingAudioSource implements AudioSource
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

class RecordingTranscriptionProvider implements TranscriptionProvider
{
    public bool $shouldFail = false;

    public function transcribe(TemporaryAudioFile $audio, TranscriptionOptions $options): TimestampedTranscript
    {
        if ($this->shouldFail) {
            throw SubtitleProcessingException::transcriptionFailed();
        }

        return new TimestampedTranscript(
            language: $options->sourceLanguage,
            durationSeconds: 42.0,
            segments: [
                new TimestampedTranscriptSegment(0.5, 1.75, 'first transcript segment'),
                new TimestampedTranscriptSegment(2.0, 3.25, 'second transcript segment'),
            ],
        );
    }
}
