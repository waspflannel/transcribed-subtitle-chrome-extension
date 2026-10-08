<?php

namespace Tests\Feature;

use App\Exceptions\SubtitleProcessingException;
use App\Jobs\PrefetchSubtitleAudio;
use App\Services\Audio\YouTubeAudioSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
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
        (new YouTubeAudioSource)->acquire('https://www.youtube.com/watch?v=dQw4w9WgXcQ', 42, $path, 'dQw4w9WgXcQ');
        $this->assertFileExists($path.'/direct-audio.m4a');
    }

    private function fakeAcquisition(): void
    {
        config(['subtitles.youtube.direct_download' => true, 'subtitles.youtube.metadata_prefetch' => true]);
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

    public function test_prefetch_is_encrypted_deduplicated_and_expires(): void
    {
        $this->fakeAcquisition();
        $source = new YouTubeAudioSource;
        $source->prefetch('dQw4w9WgXcQ');
        $source->prefetch('dQw4w9WgXcQ');
        $this->assertStringNotContainsString('googlevideo', Cache::get('youtube-prefetch:v2:dQw4w9WgXcQ'));
        $this->acquire();
        Process::assertRanTimes(fn ($p) => in_array('--dump-single-json', $p->command, true), 1);
        $this->acquire();
        Process::assertRanTimes(fn ($p) => in_array('--dump-single-json', $p->command, true), 1);
        $this->travel(61)->seconds();
        $this->acquire();
        Process::assertRanTimes(fn ($p) => in_array('--dump-single-json', $p->command, true), 2);
    }

    public function test_corrupt_or_disabled_prefetch_does_not_block_generation(): void
    {
        $this->fakeAcquisition();
        Cache::put('youtube-prefetch:v2:dQw4w9WgXcQ', 'corrupt', 60);
        $this->acquire();
        (new YouTubeAudioSource)->prefetch('dQw4w9WgXcQ');
        config(['subtitles.youtube.metadata_prefetch' => false]);
        $this->acquire();
        Process::assertRanTimes(fn ($p) => in_array('--dump-single-json', $p->command, true), 3);
    }

    public function test_private_metadata_is_not_cached(): void
    {
        config(['subtitles.youtube.metadata_prefetch' => true]);
        Process::fake(fn () => Process::result(json_encode([...$this->metadata(), 'availability' => 'private'])));
        (new YouTubeAudioSource)->prefetch('dQw4w9WgXcQ');
        $this->assertNull(Cache::get('youtube-prefetch:v2:dQw4w9WgXcQ'));
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
        $result = (new YouTubeAudioSource)->acquire('https://www.youtube.com/watch?v=dQw4w9WgXcQ', 42, $path);
        $this->assertSame(realpath($path.'/fallback.m4a'), $result->path);
    }

    public function test_direct_download_rejects_private_network_url(): void
    {
        config(['subtitles.youtube.direct_download' => true]);
        Process::fake(fn () => Process::result(json_encode([...$this->metadata(), 'url' => 'http://127.0.0.1/private'])));
        $this->expectException(SubtitleProcessingException::class);
        $this->acquire();
    }

    public function test_failed_cached_media_resolves_fresh_metadata_once(): void
    {
        $this->fakeAcquisition();
        (new YouTubeAudioSource)->prefetch('dQw4w9WgXcQ');
        $fresh = false;
        Process::fake(function (PendingProcess $process) use (&$fresh) {
            if (in_array('--dump-single-json', $process->command, true)) {
                $fresh = true;

                return Process::result(json_encode($this->metadata()));
            }
            if (! $fresh) {
                return Process::result(errorOutput: 'expired secret=hidden', exitCode: 1);
            }
            File::put($process->command[array_key_last($process->command)], 'audio');

            return Process::result();
        });
        $this->acquire();
        $this->assertTrue($fresh);
        $this->assertNull(Cache::get('youtube-prefetch:v2:dQw4w9WgXcQ'));
    }

    public function test_slow_cached_media_failure_is_not_retried_past_the_job_budget(): void
    {
        $this->fakeAcquisition();
        (new YouTubeAudioSource)->prefetch('dQw4w9WgXcQ');
        $fresh = false;
        Process::fake(function (PendingProcess $process) use (&$fresh) {
            $fresh = $fresh || in_array('--dump-single-json', $process->command, true);
            $this->travel(61)->seconds();

            return Process::result(errorOutput: 'slow failure', exitCode: 1);
        });

        $this->assertThrows(fn () => $this->acquire(), SubtitleProcessingException::class);
        $this->assertFalse($fresh);
    }

    public function test_enabled_prefetch_is_queued_without_resolving_metadata_in_http_request(): void
    {
        config(['subtitles.youtube.metadata_prefetch' => true]);
        Queue::fake();
        Process::preventStrayProcesses();
        $this->withExtensionInstall((string) Str::uuid());
        $this->postJson('/v1/subtitle-audio/prefetch', ['youtubeVideoId' => 'dQw4w9WgXcQ'])->assertExactJson(['ok' => true]);
        Queue::assertPushed(PrefetchSubtitleAudio::class, fn ($job) => $job->videoId === 'dQw4w9WgXcQ');
        Process::assertNothingRan();
        $this->assertDatabaseCount('subtitle_jobs', 0);
    }

    public function test_prefetch_route_validates_id_and_does_not_create_jobs(): void
    {
        Process::preventStrayProcesses();
        $install = (string) Str::uuid();
        $this->withExtensionInstall($install);
        $this->postJson('/v1/subtitle-audio/prefetch', ['youtubeVideoId' => 'http://localhost'])->assertUnprocessable();
        $this->postJson('/v1/subtitle-audio/prefetch', ['youtubeVideoId' => 'dQw4w9WgXcQ'])->assertExactJson(['ok' => true]);
        Process::assertNothingRan();
        $this->assertDatabaseCount('subtitle_jobs', 0);
    }
}
