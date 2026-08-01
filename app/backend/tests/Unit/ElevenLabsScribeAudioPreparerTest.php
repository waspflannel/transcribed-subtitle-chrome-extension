<?php

namespace Tests\Unit;

use App\Services\Audio\ElevenLabsScribeAudioPreparer;
use App\Services\Audio\TemporaryAudioFile;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class ElevenLabsScribeAudioPreparerTest extends TestCase
{
    private string $directory;

    private TemporaryAudioFile $audio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/audio-preparer-'.uniqid('', true));
        File::ensureDirectoryExists($this->directory);

        $sourcePath = $this->directory.DIRECTORY_SEPARATOR.'source.webm';
        File::put($sourcePath, 'source-audio');

        $this->audio = new TemporaryAudioFile(
            path: $sourcePath,
            directory: $this->directory,
            durationSeconds: 42,
            sizeBytes: File::size($sourcePath),
            mimeType: 'audio/webm',
        );

        config([
            'subtitles.audio_preparation.ffmpeg_binary' => 'ffmpeg-test',
            'subtitles.audio_preparation.ffmpeg_timeout_seconds' => 600,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_it_normalizes_source_audio_to_scribe_flac(): void
    {
        $commands = [];

        Process::fake(function (PendingProcess $process) use (&$commands) {
            $command = $process->command;
            $commands[] = $command;
            File::put($command[array_key_last($command)], 'normalized-flac');

            return Process::result();
        });

        $preparedAudio = app(ElevenLabsScribeAudioPreparer::class)->prepare($this->audio);

        $this->assertSame($this->directory.DIRECTORY_SEPARATOR.'scribe-ready.flac', $preparedAudio->path);
        $this->assertSame($this->directory, $preparedAudio->directory);
        $this->assertSame(42, $preparedAudio->durationSeconds);
        $this->assertSame(strlen('normalized-flac'), $preparedAudio->sizeBytes);
        $this->assertSame('audio/flac', $preparedAudio->mimeType);
        $this->assertCount(1, $commands);
        $this->assertSame('ffmpeg-test', $commands[0][0]);
        $this->assertCommandOption($commands[0], '-i', $this->audio->path);
        $this->assertCommandOption($commands[0], '-ac', '1');
        $this->assertCommandOption($commands[0], '-ar', '16000');
        $this->assertCommandOption($commands[0], '-c:a', 'flac');
    }

    public function test_temporary_audio_delete_removes_prepared_audio(): void
    {
        Process::fake(function (PendingProcess $process) {
            $command = $process->command;
            File::put($command[array_key_last($command)], 'prepared-flac');

            return Process::result();
        });

        $preparedAudio = app(ElevenLabsScribeAudioPreparer::class)->prepare($this->audio);

        $this->assertFileExists($preparedAudio->path);

        $preparedAudio->delete();

        $this->assertDirectoryDoesNotExist($this->directory);
    }

    /**
     * @param  list<string>  $command
     */
    private function assertCommandOption(array $command, string $option, string $expectedValue): void
    {
        $index = array_search($option, $command, true);

        $this->assertIsInt($index);
        $this->assertSame($expectedValue, $command[$index + 1]);
    }
}
