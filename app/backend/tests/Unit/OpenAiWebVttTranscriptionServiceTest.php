<?php

namespace Tests\Unit;

use App\Exceptions\SubtitleProcessingException;
use App\Services\Audio\TemporaryAudioFile;
use App\Services\Transcription\OpenAiWebVttTranscriptionService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiWebVttTranscriptionServiceTest extends TestCase
{
    private string $directory;

    private TemporaryAudioFile $audio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/openai-webvtt-transcription');
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
            'ai.providers.openai.key' => 'test-key',
            'ai.providers.openai.url' => 'https://api.openai.test/v1',
            'ai.providers.openai.models.transcription.default' => 'whisper-1',
            'subtitles.transcription.timeout_seconds' => 30,
        ]);

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_it_requests_whisper_webvtt_and_normalizes_segments(): void
    {
        Http::fake([
            'api.openai.test/v1/audio/transcriptions' => Http::response($this->sampleWebVtt(), 200, [
                'Content-Type' => 'text/vtt',
            ]),
        ]);

        $transcript = $this->service()->transcribe($this->audio, 'ar');

        $this->assertSame('ar', $transcript->language);
        $this->assertSame(12.0, $transcript->durationSeconds);
        $this->assertSame($this->sampleWebVtt(), $transcript->webVtt);
        $this->assertCount(2, $transcript->segments);
        $this->assertSame(0.5, $transcript->segments[0]->startSeconds);
        $this->assertSame(2.1, $transcript->segments[0]->endSeconds);
        $this->assertSame('first transcript segment', $transcript->segments[0]->text);
        $this->assertSame('second transcript segment', $transcript->segments[1]->text);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.openai.test/v1/audio/transcriptions'
                && $request->hasHeader('Authorization', 'Bearer test-key')
                && $request->hasFile('file', 'fake-audio', 'audio.m4a')
                && str_contains($request->body(), 'name="model"')
                && str_contains($request->body(), 'whisper-1')
                && str_contains($request->body(), 'name="response_format"')
                && str_contains($request->body(), 'vtt')
                && str_contains($request->body(), 'name="language"')
                && str_contains($request->body(), 'ar');
        });
    }

    public function test_it_omits_language_for_auto_detection(): void
    {
        Http::fake([
            'api.openai.test/v1/audio/transcriptions' => Http::response($this->sampleWebVtt()),
        ]);

        $this->service()->transcribe($this->audio, 'auto');

        Http::assertSent(fn (Request $request): bool => ! str_contains($request->body(), 'name="language"'));
    }

    public function test_it_maps_provider_http_failures_to_stable_errors(): void
    {
        Http::fake([
            'api.openai.test/v1/audio/transcriptions' => Http::response('rate limited', 429),
        ]);

        try {
            $this->service()->transcribe($this->audio, 'ar');
            $this->fail('Expected provider failure to throw a stable transcription exception.');
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('transcription_failed', $exception->publicCode);
            $this->assertSame(502, $exception->status);
            $this->assertSame('openai-http', $exception->context['sdk']);
            $this->assertSame(429, $exception->context['status']);
        }
    }

    public function test_it_rejects_transcripts_without_valid_webvtt_cues(): void
    {
        Http::fake([
            'api.openai.test/v1/audio/transcriptions' => Http::response("WEBVTT\n\nbad block\n", 200),
        ]);

        try {
            $this->service()->transcribe($this->audio, 'ar');
            $this->fail('Expected invalid WebVTT to throw a stable transcription exception.');
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('transcription_failed', $exception->publicCode);
            $this->assertSame('missing_timing_line', $exception->context['reason'] ?? null);
        }
    }

    public function test_it_requires_backend_provider_configuration(): void
    {
        config(['ai.providers.openai.key' => null]);

        try {
            $this->service()->transcribe($this->audio, 'ar');
            $this->fail('Expected missing provider configuration to throw a stable transcription exception.');
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('transcription_failed', $exception->publicCode);
            $this->assertSame('Transcription provider is not configured.', $exception->getMessage());
        }
    }

    private function service(): OpenAiWebVttTranscriptionService
    {
        return new OpenAiWebVttTranscriptionService;
    }

    private function sampleWebVtt(): string
    {
        return "WEBVTT\n\ncue-1\n00:00:00.500 --> 00:00:02.100 align:start\nfirst transcript segment\n\ncue-2\n00:00:02.400 --> 00:00:04.000\nsecond transcript segment\n";
    }
}
