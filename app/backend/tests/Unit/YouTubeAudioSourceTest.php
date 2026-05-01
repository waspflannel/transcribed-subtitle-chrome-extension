<?php

namespace Tests\Unit;

use App\Exceptions\SubtitleProcessingException;
use App\Services\Audio\YouTubeAudioSource;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class YouTubeAudioSourceTest extends TestCase
{
    private string $tempDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDirectory = storage_path('framework/testing/youtube-audio');
        File::deleteDirectory($this->tempDirectory);
        config([
            'subtitles.youtube.temp_directory' => $this->tempDirectory,
            'subtitles.max_video_duration_seconds' => 3600,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempDirectory);

        parent::tearDown();
    }

    public function test_it_acquires_audio_from_public_video_metadata(): void
    {
        Process::preventStrayProcesses();
        Process::fake(function (PendingProcess $process) {
            if (in_array('--dump-single-json', $process->command, true)) {
                return Process::result(json_encode([
                    'duration' => 42.3,
                    'availability' => 'public',
                    'is_live' => false,
                ]));
            }

            $pathsIndex = array_search('--paths', $process->command, true);
            $directory = $process->command[$pathsIndex + 1];
            $path = $directory.DIRECTORY_SEPARATOR.'dQw4w9WgXcQ.m4a';
            File::put($path, 'fake-audio');

            return Process::result($path);
        });

        $audio = (new YouTubeAudioSource)->acquire(
            videoId: 'dQw4w9WgXcQ',
            youtubeUrl: 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            requestDurationSeconds: 42,
        );

        $this->assertFileExists($audio->path);
        $this->assertSame(43, $audio->durationSeconds);
        $this->assertSame('audio/mp4', $audio->mimeType);

        $audio->delete();

        $this->assertFileDoesNotExist($audio->path);
    }

    public function test_it_rejects_non_public_video_metadata(): void
    {
        Process::fake([
            '*' => Process::result(json_encode([
                'duration' => 42,
                'availability' => 'private',
                'is_live' => false,
            ])),
        ]);

        try {
            (new YouTubeAudioSource)->acquire('dQw4w9WgXcQ', null, 42);
            $this->fail('Expected audio acquisition to reject private video metadata.');
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('audio_unavailable', $exception->publicCode);
        }
    }

    public function test_it_rejects_metadata_over_the_duration_limit(): void
    {
        Process::fake([
            '*' => Process::result(json_encode([
                'duration' => 3600.1,
                'availability' => 'public',
                'is_live' => false,
            ])),
        ]);

        try {
            (new YouTubeAudioSource)->acquire('dQw4w9WgXcQ', null, null);
            $this->fail('Expected audio acquisition to reject long video metadata.');
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('video_too_long', $exception->publicCode);
        }
    }
}
