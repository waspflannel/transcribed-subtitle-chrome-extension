<?php

namespace Tests\Unit;

use App\Exceptions\SubtitleProcessingException;
use App\Services\Audio\YouTubeAudioSource;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\TestCase;

class YouTubeAudioSourceTest extends TestCase
{
    private string $tempDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDirectory = storage_path('framework/testing/youtube-audio/'.(string) Str::uuid());
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

    private function workDirectory(): string
    {
        return $this->tempDirectory.DIRECTORY_SEPARATOR.'run-'.(string) Str::uuid();
    }

    public function test_metadata_only_validation_rejects_live_videos_without_download(): void
    {
        Process::preventStrayProcesses();
        Process::fake(function (PendingProcess $process) {
            $this->assertContains('--skip-download', $process->command);

            return Process::result(json_encode(['duration' => 42, 'availability' => 'public', 'is_live' => true]));
        });

        $this->expectException(SubtitleProcessingException::class);
        (new YouTubeAudioSource)->validatedDuration('https://www.youtube.com/watch?v=dQw4w9WgXcQ', 42);
    }

    public function test_it_acquires_audio_from_public_video_metadata(): void
    {
        $processEnvironments = [];
        Process::preventStrayProcesses();
        Process::fake(function (PendingProcess $process) use (&$processEnvironments) {
            $processEnvironments[] = $process->environment;

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
            youtubeUrl: 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            requestDurationSeconds: 42,
            workDirectory: $this->workDirectory(),
        );

        $this->assertFileExists($audio->path);
        $this->assertSame(43, $audio->durationSeconds);
        $this->assertSame('audio/mp4', $audio->mimeType);

        foreach ($processEnvironments as $environment) {
            $this->assertSame($this->tempDirectory.DIRECTORY_SEPARATOR.'process-temp', $environment['TEMP']);
            $this->assertSame($this->tempDirectory.DIRECTORY_SEPARATOR.'process-temp', $environment['TMP']);
            $this->assertSame($this->tempDirectory.DIRECTORY_SEPARATOR.'process-temp', $environment['TMPDIR']);
        }

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
            (new YouTubeAudioSource)->acquire('https://www.youtube.com/watch?v=dQw4w9WgXcQ', 42, $this->workDirectory());
            $this->fail('Expected audio acquisition to reject private video metadata.');
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('audio_unavailable', $exception->publicCode);
        }
    }

    public function test_it_reports_missing_downloader_as_configuration_failure(): void
    {
        Process::fake([
            '*' => Process::result(
                output: '',
                errorOutput: "'yt-dlp' is not recognized as an internal or external command,\r\noperable program or batch file.",
                exitCode: 1,
            ),
        ]);

        try {
            (new YouTubeAudioSource)->acquire('https://www.youtube.com/watch?v=dQw4w9WgXcQ', 42, $this->workDirectory());
            $this->fail('Expected audio acquisition to report missing downloader configuration.');
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('audio_acquisition_failed', $exception->publicCode);
            $this->assertSame('Audio downloader is not installed or not available on PATH.', $exception->getMessage());
            $this->assertSame('youtube_downloader_missing', $exception->context['reason']);
            $this->assertSame('metadata', $exception->context['stage']);
        }
    }

    public function test_it_rejects_downloaded_non_audio_files(): void
    {
        Process::preventStrayProcesses();
        Process::fake(function (PendingProcess $process) {
            if (in_array('--dump-single-json', $process->command, true)) {
                return Process::result(json_encode([
                    'duration' => 42,
                    'availability' => 'public',
                    'is_live' => false,
                ]));
            }

            $pathsIndex = array_search('--paths', $process->command, true);
            $directory = $process->command[$pathsIndex + 1];
            $path = $directory.DIRECTORY_SEPARATOR.'dQw4w9WgXcQ.txt';
            File::put($path, 'not audio');

            return Process::result($path);
        });

        try {
            (new YouTubeAudioSource)->acquire('https://www.youtube.com/watch?v=dQw4w9WgXcQ', 42, $this->workDirectory());
            $this->fail('Expected non-audio download output to fail.');
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('audio_acquisition_failed', $exception->publicCode);
            $this->assertSame('Audio acquisition produced an unknown file type.', $exception->getMessage());
            $this->assertSame('txt', $exception->context['path_extension']);
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
            (new YouTubeAudioSource)->acquire('https://www.youtube.com/watch?v=dQw4w9WgXcQ', null, $this->workDirectory());
            $this->fail('Expected audio acquisition to reject long video metadata.');
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('video_too_long', $exception->publicCode);
        }
    }
}
