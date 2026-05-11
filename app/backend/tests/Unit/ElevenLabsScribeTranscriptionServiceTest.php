<?php

namespace Tests\Unit;

use App\Exceptions\SubtitleProcessingException;
use App\Services\Audio\TemporaryAudioFile;
use App\Services\Transcription\ElevenLabsScribeTranscriptionService;
use App\Services\Transcription\ScribeTranscriptNormalizer;
use App\Services\Transcription\WebVttTranscriptParser;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
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
            'subtitles.transcription.timeout_seconds' => 30,
        ]);

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_it_requests_scribe_word_timestamps_and_normalizes_segments(): void
    {
        Http::fake([
            'api.elevenlabs.test/v1/speech-to-text' => Http::response($this->sampleScribePayload(), 200),
        ]);

        $transcript = $this->service()->transcribe($this->audio, 'es');

        $this->assertSame('es', $transcript->language);
        $this->assertSame(12.0, $transcript->durationSeconds);
        $this->assertStringStartsWith('WEBVTT', $transcript->webVtt);
        $this->assertCount(2, $transcript->segments);
        $this->assertSame('Hola mundo.', $transcript->segments[0]->text);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.elevenlabs.test/v1/speech-to-text'
                && $request->hasHeader('xi-api-key', 'test-key')
                && $request->hasFile('file', 'fake-audio', 'audio.m4a')
                && str_contains($request->body(), 'name="model_id"')
                && str_contains($request->body(), 'scribe_v2')
                && str_contains($request->body(), 'name="timestamps_granularity"')
                && str_contains($request->body(), 'word')
                && str_contains($request->body(), 'name="language_code"')
                && str_contains($request->body(), 'es');
        });
    }

    public function test_it_passes_catalog_language_codes_directly_to_scribe(): void
    {
        Http::fake([
            'api.elevenlabs.test/v1/speech-to-text' => Http::response([
                ...$this->sampleScribePayload(),
                'language_code' => 'jpn',
            ], 200),
        ]);

        $transcript = $this->service()->transcribe($this->audio, 'ja');

        $this->assertSame('ja', $transcript->language);

        Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'name="language_code"')
            && str_contains($request->body(), 'ja'));
    }

    public function test_it_omits_language_for_auto_detection(): void
    {
        Http::fake([
            'api.elevenlabs.test/v1/speech-to-text' => Http::response($this->sampleScribePayload()),
        ]);

        $transcript = $this->service()->transcribe($this->audio, 'auto');

        $this->assertSame('es', $transcript->language);

        Http::assertSent(fn (Request $request): bool => ! str_contains($request->body(), 'name="language_code"'));
    }

    public function test_it_maps_provider_http_failures_to_stable_errors(): void
    {
        Http::fake([
            'api.elevenlabs.test/v1/speech-to-text' => Http::response('rate limited', 429),
        ]);

        try {
            $this->service()->transcribe($this->audio, 'es');
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
            $this->service()->transcribe($this->audio, 'es');
            $this->fail('Expected missing provider configuration to throw a stable transcription exception.');
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('transcription_failed', $exception->publicCode);
            $this->assertSame('Transcription provider is not configured.', $exception->getMessage());
            $this->assertSame('eleven', $exception->context['provider']);
        }

        Http::assertNothingSent();
    }

    private function service(): ElevenLabsScribeTranscriptionService
    {
        return new ElevenLabsScribeTranscriptionService(
            new ScribeTranscriptNormalizer(new WebVttTranscriptParser),
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
