<?php

namespace Tests\Unit;

use App\Exceptions\SubtitleProcessingException;
use App\Services\Audio\TemporaryAudioFile;
use App\Services\Transcription\LaravelAiTranscriptionService;
use App\Services\Transcription\TimestampedTranscriptNormalizer;
use App\Services\Transcription\TranscriptionOptions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Laravel\Ai\Prompts\TranscriptionPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TranscriptionSegment;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TranscriptionResponse;
use Laravel\Ai\Transcription;
use RuntimeException;
use Tests\TestCase;

class LaravelAiTranscriptionServiceTest extends TestCase
{
    private string $directory;

    private TemporaryAudioFile $audio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/laravel-ai-transcription');
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
            'subtitles.transcription.model' => 'gpt-4o-transcribe-diarize',
            'subtitles.transcription.timeout_seconds' => 30,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_it_uses_laravel_ai_transcription_and_normalizes_segments(): void
    {
        Transcription::fake([
            new TranscriptionResponse(
                'full text',
                new Collection([
                    new TranscriptionSegment('second', 'Speaker 1', 2.0, 3.0),
                    new TranscriptionSegment('first', 'Speaker 1', 0.25, 1.25),
                ]),
                new Usage,
                new Meta('openai', 'gpt-4o-transcribe-diarize'),
            ),
        ])->preventStrayTranscriptions();

        $transcript = $this->service()->transcribe($this->audio, new TranscriptionOptions('ar'));

        $this->assertSame('ar', $transcript->language);
        $this->assertSame(12.0, $transcript->durationSeconds);
        $this->assertSame('first', $transcript->segments[0]->text);
        $this->assertSame('second', $transcript->segments[1]->text);

        Transcription::assertGenerated(function (TranscriptionPrompt $prompt): bool {
            return $prompt->language === 'ar'
                && $prompt->isDiarized()
                && $prompt->provider->name() === 'openai'
                && $prompt->model === 'gpt-4o-transcribe-diarize';
        });
    }

    public function test_it_maps_sdk_failure_to_stable_error(): void
    {
        Transcription::fake(function (): never {
            throw new RuntimeException('provider unavailable');
        })->preventStrayTranscriptions();

        try {
            $this->service()->transcribe($this->audio, new TranscriptionOptions('ar'));
            $this->fail('Expected provider failure to throw a stable transcription exception.');
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('transcription_failed', $exception->publicCode);
            $this->assertSame(502, $exception->status);
            $this->assertSame('laravel-ai', $exception->context['sdk']);
        }
    }

    public function test_it_requires_backend_provider_configuration(): void
    {
        config(['ai.providers.openai.key' => null]);

        try {
            $this->service()->transcribe($this->audio, new TranscriptionOptions('ar'));
            $this->fail('Expected missing provider configuration to throw a stable transcription exception.');
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('transcription_failed', $exception->publicCode);
            $this->assertSame('Transcription provider is not configured.', $exception->getMessage());
        }
    }

    private function service(): LaravelAiTranscriptionService
    {
        return new LaravelAiTranscriptionService(new TimestampedTranscriptNormalizer);
    }
}
