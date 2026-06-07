<?php

namespace Tests\Unit;

use App\Exceptions\SubtitleProcessingException;
use App\Services\Audio\ElevenLabsScribeAudioPreparer;
use App\Services\Audio\TemporaryAudioFile;
use App\Services\Transcription\ElevenLabsScribeTranscriptionService;
use App\Services\Transcription\ScribeTranscriptNormalizer;
use Illuminate\Http\Client\Request;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\TestCase;

class ElevenLabsScribeTranscriptionServiceTest extends TestCase
{
    private string $directory;

    private TemporaryAudioFile $audio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/elevenlabs-scribe-transcription/'.(string) Str::uuid());
        File::ensureDirectoryExists($this->directory);
        $path = $this->directory.DIRECTORY_SEPARATOR.'audio.m4a';
        File::put($path, 'fake-audio');

        $this->audio = new TemporaryAudioFile(
            path: $path,
            directory: $this->directory,
            durationSeconds: 12,
            sizeBytes: File::size($path),
            mimeType: 'audio/mp4',
        );

        config([
            'ai.providers.eleven.key' => 'test-key',
            'ai.providers.eleven.url' => 'https://api.elevenlabs.test/v1',
            'ai.providers.eleven.models.transcription.default' => 'scribe_v2',
            'subtitles.audio_preparation.ffmpeg_binary' => 'ffmpeg-test',
            'subtitles.audio_preparation.ffmpeg_timeout_seconds' => 45,
            'subtitles.audio_preparation.voice_isolation.enabled' => false,
            'subtitles.audio_preparation.voice_isolation.timeout_seconds' => 55,
            'subtitles.audio_preparation.voice_isolation.fail_open' => true,
            'subtitles.transcription.timeout_seconds' => 30,
        ]);

        Http::preventStrayRequests();
        Process::preventStrayProcesses();
        Process::fake(function (PendingProcess $process) {
            $command = $process->command;
            $this->assertIsArray($command);
            File::put($command[array_key_last($command)], 'prepared-wav');

            return Process::result();
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_it_requests_scribe_word_timestamps_and_normalizes_segments(): void
    {
        $requestMatched = false;

        Http::fake(function (Request $request) use (&$requestMatched) {
            $body = $request->body();
            $requestMatched = $request->url() === 'https://api.elevenlabs.test/v1/speech-to-text'
                && $request->hasHeader('xi-api-key', 'test-key')
                && str_contains($body, 'name="file"; filename="audio.wav"')
                && str_contains($body, 'prepared-wav')
                && str_contains($body, 'name="model_id"')
                && str_contains($body, 'scribe_v2')
                && str_contains($body, 'name="timestamps_granularity"')
                && str_contains($body, 'word')
                && str_contains($body, 'name="diarize"')
                && str_contains($body, 'false')
                && str_contains($body, 'name="tag_audio_events"')
                && str_contains($body, 'name="no_verbatim"')
                && str_contains($body, 'name="language_code"')
                && str_contains($body, 'spa');

            return Http::response($this->sampleScribePayload(), 200);
        });

        $transcript = $this->service()->transcribe($this->audio, 'spa');

        $this->assertSame('spa', $transcript->language);
        $this->assertSame(12.0, $transcript->durationSeconds);
        $this->assertStringStartsWith('WEBVTT', $transcript->webVtt);
        $this->assertCount(2, $transcript->segments);
        $this->assertSame('Hola mundo.', $transcript->segments[0]->text);
        $this->assertTrue($requestMatched);
    }

    public function test_it_uploads_voice_isolated_prepared_wav_when_audio_isolation_is_enabled(): void
    {
        config(['subtitles.audio_preparation.voice_isolation.enabled' => true]);
        $scribeRequestMatched = false;

        Process::fake(function (PendingProcess $process) {
            $command = $process->command;
            $this->assertIsArray($command);
            $outputPath = $command[array_key_last($command)];

            File::put($outputPath, str_ends_with($outputPath, '.pcm') ? 'raw-pcm' : 'isolated-wav');

            return Process::result();
        });

        Http::fake(function (Request $request) use (&$scribeRequestMatched) {
            if (str_ends_with($request->url(), '/audio-isolation')) {
                return Http::response('isolated-provider-audio', 200);
            }

            $body = $request->body();
            $scribeRequestMatched = $request->url() === 'https://api.elevenlabs.test/v1/speech-to-text'
                && str_contains($body, 'name="file"; filename="audio.wav"')
                && str_contains($body, 'isolated-wav')
                && str_contains($body, 'name="diarize"')
                && str_contains($body, 'false');

            return Http::response($this->sampleScribePayload(), 200);
        });

        $transcript = $this->service()->transcribe($this->audio, 'spa');

        $this->assertSame('spa', $transcript->language);
        $this->assertTrue($scribeRequestMatched);
    }

    public function test_it_passes_catalog_language_codes_directly_to_scribe(): void
    {
        $requestMatched = false;

        Http::fake(function (Request $request) use (&$requestMatched) {
            $body = $request->body();
            $requestMatched = str_contains($body, 'name="language_code"')
                && str_contains($body, 'jpn');

            return Http::response([
                ...$this->sampleScribePayload(),
                'language_code' => 'jpn',
            ], 200);
        });

        $transcript = $this->service()->transcribe($this->audio, 'jpn');

        $this->assertSame('jpn', $transcript->language);
        $this->assertTrue($requestMatched);
    }

    public function test_it_omits_language_for_auto_detection(): void
    {
        $requestMatched = false;

        Http::fake(function (Request $request) use (&$requestMatched) {
            $requestMatched = ! str_contains($request->body(), 'name="language_code"');

            return Http::response($this->sampleScribePayload());
        });

        $transcript = $this->service()->transcribe($this->audio, 'auto');

        $this->assertSame('spa', $transcript->language);
        $this->assertTrue($requestMatched);
    }

    public function test_it_maps_provider_http_failures_to_stable_errors(): void
    {
        Http::fake([
            'api.elevenlabs.test/v1/speech-to-text' => Http::response('rate limited', 429),
        ]);

        try {
            $this->service()->transcribe($this->audio, 'spa');
            $this->fail('Expected provider failure to throw a stable transcription exception.');
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('transcription_failed', $exception->publicCode);
            $this->assertSame(502, $exception->status);
            $this->assertSame('eleven', $exception->context['provider']);
            $this->assertSame('elevenlabs-http', $exception->context['adapter']);
            $this->assertSame(429, $exception->context['status']);
        }
    }

    public function test_it_requires_backend_provider_configuration(): void
    {
        config(['ai.providers.eleven.key' => null]);

        try {
            $this->service()->transcribe($this->audio, 'spa');
            $this->fail('Expected missing provider configuration to throw a stable transcription exception.');
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('transcription_failed', $exception->publicCode);
            $this->assertSame('Transcription provider is not configured.', $exception->getMessage());
            $this->assertSame('eleven', $exception->context['provider']);
        }

        Http::assertNothingSent();
    }

    public function test_it_requires_configured_transcription_model(): void
    {
        config(['ai.providers.eleven.models.transcription.default' => null]);

        try {
            $this->service()->transcribe($this->audio, 'spa');
            $this->fail('Expected missing model configuration to throw a stable transcription exception.');
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('transcription_failed', $exception->publicCode);
            $this->assertSame('Transcription model is not configured.', $exception->getMessage());
            $this->assertSame('eleven', $exception->context['provider']);
        }

        Http::assertNothingSent();
    }

    public function test_it_requires_configured_transcription_provider_url(): void
    {
        config(['ai.providers.eleven.url' => null]);

        try {
            $this->service()->transcribe($this->audio, 'spa');
            $this->fail('Expected missing provider URL to throw a stable transcription exception.');
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('transcription_failed', $exception->publicCode);
            $this->assertSame('Transcription provider URL is not configured.', $exception->getMessage());
            $this->assertSame('eleven', $exception->context['provider']);
        }

        Http::assertNothingSent();
    }

    public function test_it_rejects_unsupported_audio_mime_types(): void
    {
        $audio = new TemporaryAudioFile(
            path: $this->audio->path,
            directory: $this->audio->directory,
            durationSeconds: $this->audio->durationSeconds,
            sizeBytes: $this->audio->sizeBytes,
            mimeType: 'application/octet-stream',
        );

        try {
            $this->service()->transcribe($audio, 'spa');
            $this->fail('Expected unsupported audio MIME type to fail before provider request.');
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('transcription_failed', $exception->publicCode);
            $this->assertSame('Transcription audio type is not supported.', $exception->getMessage());
            $this->assertSame('application/octet-stream', $exception->context['mime_type']);
        }

        Http::assertNothingSent();
    }

    private function service(): ElevenLabsScribeTranscriptionService
    {
        return new ElevenLabsScribeTranscriptionService(
            new ScribeTranscriptNormalizer,
            new ElevenLabsScribeAudioPreparer,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function sampleScribePayload(): array
    {
        return [
            'language_code' => 'es',
            'language_probability' => 0.99,
            'text' => 'Hola mundo. Otra frase.',
            'words' => [
                ['text' => 'Hola', 'start' => 0.5, 'end' => 0.9, 'type' => 'word', 'speaker_id' => 'speaker_0'],
                ['text' => ' ', 'start' => 0.9, 'end' => 1.0, 'type' => 'spacing', 'speaker_id' => 'speaker_0'],
                ['text' => 'mundo.', 'start' => 1.0, 'end' => 1.4, 'type' => 'word', 'speaker_id' => 'speaker_0'],
                ['text' => 'Otra', 'start' => 2.4, 'end' => 2.9, 'type' => 'word', 'speaker_id' => 'speaker_0'],
                ['text' => 'frase.', 'start' => 3.0, 'end' => 3.4, 'type' => 'word', 'speaker_id' => 'speaker_0'],
            ],
        ];
    }
}
