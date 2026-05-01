<?php

namespace Tests\Unit;

use App\Exceptions\SubtitleProcessingException;
use App\Services\Audio\TemporaryAudioFile;
use App\Services\Transcription\OpenAiVerboseTranscriptionProvider;
use App\Services\Transcription\TimestampedTranscriptNormalizer;
use App\Services\Transcription\TranscriptionOptions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiVerboseTranscriptionProviderTest extends TestCase
{
    private string $directory;

    private TemporaryAudioFile $audio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/openai-transcription');
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
            'ai.providers.openai.url' => 'https://api.openai.com/v1',
            'subtitles.transcription.model' => 'whisper-1',
            'subtitles.transcription.timeout_seconds' => 30,
            'subtitles.transcription.connect_timeout_seconds' => 5,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_it_requests_verbose_json_and_normalizes_segments(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.openai.com/v1/audio/transcriptions' => Http::response([
                'language' => 'ar',
                'duration' => 12.0,
                'text' => 'full text',
                'segments' => [
                    ['start' => 2.0, 'end' => 3.0, 'text' => 'second'],
                    ['start' => 0.25, 'end' => 1.25, 'text' => 'first'],
                ],
            ]),
        ]);

        $transcript = $this->provider()->transcribe($this->audio, new TranscriptionOptions('ar'));

        $this->assertSame('ar', $transcript->language);
        $this->assertSame(12.0, $transcript->durationSeconds);
        $this->assertSame('first', $transcript->segments[0]->text);
        $this->assertSame('second', $transcript->segments[1]->text);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.openai.com/v1/audio/transcriptions'
                && $request->hasHeader('Authorization', 'Bearer test-key');
        });
    }

    public function test_it_maps_provider_failure_to_stable_error(): void
    {
        Http::fake([
            'https://api.openai.com/v1/audio/transcriptions' => Http::response(['error' => 'bad'], 500),
        ]);

        try {
            $this->provider()->transcribe($this->audio, new TranscriptionOptions('ar'));
            $this->fail('Expected provider failure to throw a stable transcription exception.');
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('transcription_failed', $exception->publicCode);
            $this->assertSame(502, $exception->status);
        }
    }

    public function test_it_requires_backend_provider_configuration(): void
    {
        config(['ai.providers.openai.key' => null]);
        Http::preventStrayRequests();

        try {
            $this->provider()->transcribe($this->audio, new TranscriptionOptions('ar'));
            $this->fail('Expected missing provider configuration to throw a stable transcription exception.');
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('transcription_failed', $exception->publicCode);
            $this->assertSame('Transcription provider is not configured.', $exception->getMessage());
        }
    }

    private function provider(): OpenAiVerboseTranscriptionProvider
    {
        return new OpenAiVerboseTranscriptionProvider(new TimestampedTranscriptNormalizer);
    }
}
