<?php

namespace Tests\Feature;

use App\Exceptions\SubtitleProcessingException;
use App\Services\Audio\YouTubeAudioSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\TestCase;

class AudioAcquisitionExperimentTest extends TestCase
{
    use RefreshDatabase;

    private array $workspaces = [];

    private function metadata(): array
    {
        return ['id' => 'dQw4w9WgXcQ', 'duration' => 42, 'availability' => 'public', 'is_live' => false,
            'ext' => 'm4a', 'vcodec' => 'none', 'url' => 'https://r1.googlevideo.com/audio?secret=hidden',
            'http_headers' => ['User-Agent' => 'test']];
    }

    protected function tearDown(): void
    {
        foreach ($this->workspaces as $path) {
            File::deleteDirectory($path);
        }
        parent::tearDown();
    }

    private function acquire(): void
    {
        $path = storage_path('framework/testing/acquisition-'.Str::uuid());
        $this->workspaces[] = $path;
        (new YouTubeAudioSource)->acquire('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $path, 'dQw4w9WgXcQ');
        $this->assertFileExists($path.'/direct-audio.m4a');
    }

    private function fakeAcquisition(): void
    {
        config(['subtitles.youtube.direct_download' => true]);
        Process::preventStrayProcesses();
        Process::fake(function (PendingProcess $process) {
            if (in_array('--dump-single-json', $process->command, true)) {
                return Process::result(json_encode($this->metadata()));
            }
            $this->assertContains('-tls_verify', $process->command);
            File::put($process->command[array_key_last($process->command)], 'audio');

            return Process::result();
        });
    }

    public function test_direct_failure_uses_existing_downloader_and_deletes_partial_file(): void
    {
        config(['subtitles.youtube.direct_download' => true]);
        Process::fake(function (PendingProcess $process) {
            if (in_array('--dump-single-json', $process->command, true)) {
                return Process::result(json_encode($this->metadata()));
            }
            if (in_array('-c:a', $process->command, true)) {
                File::put($process->command[array_key_last($process->command)], 'partial');

                return Process::result(errorOutput: 'secret=hidden', exitCode: 1);
            }
            $path = $process->command[array_search('--paths', $process->command, true) + 1];
            $this->assertFileDoesNotExist($path.'/direct-audio.m4a');
            File::put($path.'/fallback.m4a', 'audio');

            return Process::result($path.'/fallback.m4a');
        });
        $path = storage_path('framework/testing/acquisition-'.Str::uuid());
        $this->workspaces[] = $path;
        $result = (new YouTubeAudioSource)->acquire('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $path);
        $this->assertSame(realpath($path.'/fallback.m4a'), $result->path);
    }

    public function test_direct_download_rejects_private_network_url(): void
    {
        config(['subtitles.youtube.direct_download' => true]);
        Process::fake(fn () => Process::result(json_encode([...$this->metadata(), 'url' => 'http://127.0.0.1/private'])));
        $this->expectException(SubtitleProcessingException::class);
        $this->acquire();
    }
}
