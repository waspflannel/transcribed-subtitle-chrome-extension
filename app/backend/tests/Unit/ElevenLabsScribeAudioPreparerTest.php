<?php

namespace Tests\Unit;

use App\Exceptions\SubtitleProcessingException;
use App\Services\Audio\ElevenLabsScribeAudioPreparer;
use App\Services\Audio\TemporaryAudioFile;
use Illuminate\Http\Client\Request;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class ElevenLabsScribeAudioPreparerTest extends TestCase
{
    private string $directory;

    private TemporaryAudioFile $audio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/audio-preparer/'.(string) Str::uuid());
        File::ensureDirectoryExists($this->directory);

        $path = $this->directory.DIRECTORY_SEPARATOR.'source.m4a';
        File::put($path, 'source-audio');

        $this->audio = new TemporaryAudioFile(
            path: $path,
            directory: $this->directory,
            durationSeconds: 42,
            sizeBytes: File::size($path),
            mimeType: 'audio/mp4',
        );

        config([
            'ai.providers.eleven.key' => 'test-key',
            'ai.providers.eleven.url' => 'https://api.elevenlabs.test/v1',
            'subtitles.audio_preparation.ffmpeg_binary' => 'ffmpeg-test',
            'subtitles.audio_preparation.ffmpeg_timeout_seconds' => 45,
            'subtitles.audio_preparation.voice_isolation.enabled' => false,
            'subtitles.audio_preparation.voice_isolation.timeout_seconds' => 55,
            'subtitles.audio_preparation.voice_isolation.fail_open' => true,
        ]);

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_it_normalizes_source_audio_to_scribe_wav_when_voice_isolation_is_disabled(): void
    {
        $commands = [];

        Process::preventStrayProcesses();
        Process::fake(function (PendingProcess $process) use (&$commands) {
            $commands[] = $process->command;
            File::put($this->lastCommandArgument($process), 'normalized-wav');

            return Process::result();
        });

        $preparedAudio = (new ElevenLabsScribeAudioPreparer)->prepare($this->audio);

        $this->assertSame($this->directory.DIRECTORY_SEPARATOR.'scribe-ready.wav', $preparedAudio->path);
        $this->assertSame($this->directory, $preparedAudio->directory);
        $this->assertSame(42, $preparedAudio->durationSeconds);
        $this->assertSame(strlen('normalized-wav'), $preparedAudio->sizeBytes);
        $this->assertSame('audio/wav', $preparedAudio->mimeType);
        $this->assertSame('normalized-wav', File::get($preparedAudio->path));
        $this->assertCount(1, $commands);
        $this->assertFfmpegNormalizesToWav($commands[0], $this->audio->path);

        Http::assertNothingSent();
    }

    public function test_it_calls_audio_isolation_with_raw_pcm_and_converts_the_response_to_wav(): void
    {
        config(['subtitles.audio_preparation.voice_isolation.enabled' => true]);
        $commands = [];
        $isolationRequestMatched = false;

        Http::fake(function (Request $request) use (&$isolationRequestMatched) {
            $body = $request->body();
            $isolationRequestMatched = $request->url() === 'https://api.elevenlabs.test/v1/audio-isolation'
                && $request->hasHeader('xi-api-key', 'test-key')
                && str_contains($body, 'name="audio"; filename="isolation-input.pcm"')
                && str_contains($body, 'raw-pcm')
                && str_contains($body, 'name="file_format"')
                && str_contains($body, 'pcm_s16le_16');

            return Http::response('isolated-provider-audio', 200, ['Content-Type' => 'audio/mpeg']);
        });

        Process::preventStrayProcesses();
        Process::fake(function (PendingProcess $process) use (&$commands) {
            $commands[] = $process->command;
            $outputPath = $this->lastCommandArgument($process);

            if (str_ends_with($outputPath, 'isolation-input.pcm')) {
                File::put($outputPath, 'raw-pcm');

                return Process::result();
            }

            $this->assertSame($this->directory.DIRECTORY_SEPARATOR.'scribe-ready.wav', $outputPath);
            $this->assertFileExists($this->directory.DIRECTORY_SEPARATOR.'isolated-output.bin');
            File::put($outputPath, 'isolated-wav');

            return Process::result();
        });

        $preparedAudio = (new ElevenLabsScribeAudioPreparer)->prepare($this->audio);

        $this->assertSame('isolated-wav', File::get($preparedAudio->path));
        $this->assertSame('audio/wav', $preparedAudio->mimeType);
        $this->assertCount(2, $commands);
        $this->assertFfmpegCreatesRawPcmForIsolation($commands[0]);
        $this->assertFfmpegNormalizesToWav($commands[1], $this->directory.DIRECTORY_SEPARATOR.'isolated-output.bin');

        $this->assertTrue($isolationRequestMatched);
    }

    public function test_it_accepts_base64_json_audio_isolation_output_when_provider_returns_structured_audio(): void
    {
        config(['subtitles.audio_preparation.voice_isolation.enabled' => true]);
        $commands = [];

        Http::fake([
            'api.elevenlabs.test/v1/audio-isolation' => Http::response([
                'audio' => base64_encode('isolated-json-audio'),
            ], 200),
        ]);

        Process::preventStrayProcesses();
        Process::fake(function (PendingProcess $process) use (&$commands) {
            $commands[] = $process->command;
            $outputPath = $this->lastCommandArgument($process);

            if (str_ends_with($outputPath, 'isolation-input.pcm')) {
                File::put($outputPath, 'raw-pcm');

                return Process::result();
            }

            $this->assertSame('isolated-json-audio', File::get($this->directory.DIRECTORY_SEPARATOR.'isolated-output.bin'));
            File::put($outputPath, 'json-decoded-wav');

            return Process::result();
        });

        $preparedAudio = (new ElevenLabsScribeAudioPreparer)->prepare($this->audio);

        $this->assertSame('json-decoded-wav', File::get($preparedAudio->path));
        $this->assertCount(2, $commands);
    }

    public function test_it_falls_back_when_audio_isolation_returns_json_without_audio(): void
    {
        config(['subtitles.audio_preparation.voice_isolation.enabled' => true]);
        $commands = [];

        Log::spy();
        Http::fake([
            'api.elevenlabs.test/v1/audio-isolation' => Http::response([], 200),
        ]);

        Process::preventStrayProcesses();
        Process::fake(function (PendingProcess $process) use (&$commands) {
            $commands[] = $process->command;
            $outputPath = $this->lastCommandArgument($process);

            File::put($outputPath, str_ends_with($outputPath, '.pcm') ? 'raw-pcm' : 'fallback-wav');

            return Process::result();
        });

        $preparedAudio = (new ElevenLabsScribeAudioPreparer)->prepare($this->audio);

        $this->assertSame('fallback-wav', File::get($preparedAudio->path));
        $this->assertCount(2, $commands);

        Log::shouldHaveReceived('warning')
            ->with('backend.audio_preparation_fallback_used', Mockery::on(
                fn (array $context): bool => $context['reason'] === 'json_without_audio'
                    && $context['failure_stage'] === 'audio_isolation',
            ));
    }

    public function test_it_retries_isolated_output_as_raw_pcm_when_container_decode_fails(): void
    {
        config(['subtitles.audio_preparation.voice_isolation.enabled' => true]);
        $commands = [];

        Http::fake([
            'api.elevenlabs.test/v1/audio-isolation' => Http::response('raw-isolated-pcm', 200, ['Content-Type' => 'audio/wav']),
        ]);

        Process::preventStrayProcesses();
        Process::fake(function (PendingProcess $process) use (&$commands) {
            $commands[] = $process->command;
            $outputPath = $this->lastCommandArgument($process);

            if (str_ends_with($outputPath, 'isolation-input.pcm')) {
                File::put($outputPath, 'raw-pcm');

                return Process::result();
            }

            if (count($commands) === 2) {
                return Process::result(errorOutput: 'container decode failed', exitCode: 1);
            }

            File::put($outputPath, 'raw-decoded-wav');

            return Process::result();
        });

        $preparedAudio = (new ElevenLabsScribeAudioPreparer)->prepare($this->audio);

        $this->assertSame('raw-decoded-wav', File::get($preparedAudio->path));
        $this->assertCount(3, $commands);
        $this->assertFfmpegNormalizesToWav($commands[1], $this->directory.DIRECTORY_SEPARATOR.'isolated-output.bin');
        $this->assertFfmpegReadsRawPcmForPreparedWav($commands[2]);
    }

    public function test_it_falls_back_to_normalized_source_audio_when_audio_isolation_fails_open(): void
    {
        config(['subtitles.audio_preparation.voice_isolation.enabled' => true]);
        $commands = [];

        Log::spy();
        Http::fake([
            'api.elevenlabs.test/v1/audio-isolation' => Http::response('provider failure body', 500),
        ]);

        Process::preventStrayProcesses();
        Process::fake(function (PendingProcess $process) use (&$commands) {
            $commands[] = $process->command;
            $outputPath = $this->lastCommandArgument($process);

            File::put($outputPath, str_ends_with($outputPath, '.pcm') ? 'raw-pcm' : 'fallback-wav');

            return Process::result();
        });

        $preparedAudio = (new ElevenLabsScribeAudioPreparer)->prepare($this->audio);

        $this->assertSame('fallback-wav', File::get($preparedAudio->path));
        $this->assertCount(2, $commands);
        $this->assertFfmpegCreatesRawPcmForIsolation($commands[0]);
        $this->assertFfmpegNormalizesToWav($commands[1], $this->audio->path);

        Log::shouldHaveReceived('warning')
            ->with('backend.audio_preparation_fallback_used', Mockery::on(function (array $context): bool {
                $encodedContext = json_encode($context) ?: '';

                return $context['stage'] === 'audio_isolation'
                    && $context['provider'] === 'eleven'
                    && $context['reason'] === 'http_failure'
                    && $context['status'] === 500
                    && ! str_contains($encodedContext, 'test-key')
                    && ! str_contains($encodedContext, 'provider failure body')
                    && ! str_contains($encodedContext, $this->directory)
                    && ! str_contains($encodedContext, $this->audio->path);
            }));
    }

    public function test_it_fails_closed_when_audio_isolation_fails_and_fail_open_is_disabled(): void
    {
        config([
            'subtitles.audio_preparation.voice_isolation.enabled' => true,
            'subtitles.audio_preparation.voice_isolation.fail_open' => false,
        ]);

        Http::fake([
            'api.elevenlabs.test/v1/audio-isolation' => Http::response('provider failure body', 500),
        ]);

        Process::preventStrayProcesses();
        Process::fake(function (PendingProcess $process) {
            File::put($this->lastCommandArgument($process), 'raw-pcm');

            return Process::result();
        });

        try {
            (new ElevenLabsScribeAudioPreparer)->prepare($this->audio);
            $this->fail('Expected fail-closed audio isolation to throw.');
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('transcription_failed', $exception->publicCode);
            $this->assertSame('http_failure', $exception->context['reason']);
            $this->assertSame(500, $exception->context['status']);
            $this->assertSame('elevenlabs-audio-preparer', $exception->context['adapter']);
        }

        $this->assertFileDoesNotExist($this->directory.DIRECTORY_SEPARATOR.'scribe-ready.wav');
    }

    public function test_temporary_audio_delete_removes_prepared_and_intermediate_files(): void
    {
        config(['subtitles.audio_preparation.voice_isolation.enabled' => true]);

        Http::fake([
            'api.elevenlabs.test/v1/audio-isolation' => Http::response('isolated-provider-audio', 200, ['Content-Type' => 'audio/mpeg']),
        ]);

        Process::preventStrayProcesses();
        Process::fake(function (PendingProcess $process) {
            $outputPath = $this->lastCommandArgument($process);
            File::put($outputPath, str_ends_with($outputPath, '.pcm') ? 'raw-pcm' : 'prepared-wav');

            return Process::result();
        });

        $preparedAudio = (new ElevenLabsScribeAudioPreparer)->prepare($this->audio);

        $this->assertFileExists($this->directory.DIRECTORY_SEPARATOR.'isolation-input.pcm');
        $this->assertFileExists($this->directory.DIRECTORY_SEPARATOR.'isolated-output.bin');
        $this->assertFileExists($preparedAudio->path);

        $this->audio->delete();

        $this->assertDirectoryDoesNotExist($this->directory);
    }

    /**
     * @param  array<int, string>  $command
     */
    private function assertFfmpegNormalizesToWav(array $command, string $inputPath): void
    {
        $this->assertSame('ffmpeg-test', $command[0]);
        $this->assertCommandOption($command, '-i', $inputPath);
        $this->assertContains('-vn', $command);
        $this->assertCommandOption($command, '-ac', '1');
        $this->assertCommandOption($command, '-ar', '16000');
        $this->assertCommandOption($command, '-c:a', 'pcm_s16le');
        $this->assertSame($this->directory.DIRECTORY_SEPARATOR.'scribe-ready.wav', $command[array_key_last($command)]);
    }

    /**
     * @param  array<int, string>  $command
     */
    private function assertFfmpegCreatesRawPcmForIsolation(array $command): void
    {
        $this->assertSame('ffmpeg-test', $command[0]);
        $this->assertCommandOption($command, '-i', $this->audio->path);
        $this->assertContains('-vn', $command);
        $this->assertCommandOption($command, '-ac', '1');
        $this->assertCommandOption($command, '-ar', '16000');
        $this->assertCommandOption($command, '-c:a', 'pcm_s16le');
        $this->assertCommandOption($command, '-f', 's16le');
        $this->assertSame($this->directory.DIRECTORY_SEPARATOR.'isolation-input.pcm', $command[array_key_last($command)]);
    }

    /**
     * @param  array<int, string>  $command
     */
    private function assertFfmpegReadsRawPcmForPreparedWav(array $command): void
    {
        $this->assertSame('ffmpeg-test', $command[0]);
        $this->assertCommandOption($command, '-f', 's16le');
        $this->assertCommandOption($command, '-ar', '16000');
        $this->assertCommandOption($command, '-ac', '1');
        $this->assertCommandOption($command, '-i', $this->directory.DIRECTORY_SEPARATOR.'isolated-output.bin');
        $this->assertCommandOption($command, '-c:a', 'pcm_s16le');
        $this->assertSame($this->directory.DIRECTORY_SEPARATOR.'scribe-ready.wav', $command[array_key_last($command)]);
    }

    /**
     * @param  array<int, string>  $command
     */
    private function assertCommandOption(array $command, string $option, string $expectedValue): void
    {
        $index = array_search($option, $command, true);

        $this->assertIsInt($index);
        $this->assertSame($expectedValue, $command[$index + 1]);
    }

    private function lastCommandArgument(PendingProcess $process): string
    {
        $command = $process->command;

        $this->assertIsArray($command);

        return $command[array_key_last($command)];
    }
}
