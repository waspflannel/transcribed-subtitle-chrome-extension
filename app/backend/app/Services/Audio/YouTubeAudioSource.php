<?php

namespace App\Services\Audio;

use App\Exceptions\SubtitleProcessingException;
use App\Support\ChildProcessEnvironment;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

class YouTubeAudioSource
{
    /** Window + metadata (60s) + direct (60s) + download (600s) stays inside AcquireSubtitleAudio's 900s. */
    private const CACHED_MEDIA_RETRY_WINDOW_SECONDS = 60;

    public function acquire(string $youtubeUrl, string $workDirectory, ?string $videoId = null): TemporaryAudioFile
    {
        File::ensureDirectoryExists($workDirectory, 0700);

        try {
            $cached = $this->prefetchedMetadata($videoId);
            if ($cached !== null) {
                $durationSeconds = $this->durationSeconds($cached);
                $metadata = $cached;
            } else {
                [$metadata, $durationSeconds] = $this->validatedMetadata($youtubeUrl);
            }
            Log::info('backend.youtube_metadata_reused', ['hit' => $cached !== null]);
            $startedAt = now();
            try {
                $realPath = $this->directAudio($workDirectory, $metadata) ?? $this->downloadAudio($workDirectory, $metadata);
            } catch (SubtitleProcessingException $exception) {
                // Retry only fast failures (stale cached media): a full second attempt
                // after a slow one would outlive the acquisition job and skip cleanup.
                if ($cached === null || $startedAt->diffInSeconds(now()) > self::CACHED_MEDIA_RETRY_WINDOW_SECONDS) {
                    throw $exception;
                }
                Cache::forget($this->prefetchKey($videoId));
                [$metadata, $durationSeconds] = $this->validatedMetadata($youtubeUrl);
                $realPath = $this->directAudio($workDirectory, $metadata) ?? $this->downloadAudio($workDirectory, $metadata);
            }
            $sizeBytes = File::size($realPath);

            if ($sizeBytes < 1) {
                throw SubtitleProcessingException::audioAcquisitionFailed('Audio acquisition produced an empty file.');
            }

            return new TemporaryAudioFile(
                path: $realPath,
                directory: $workDirectory,
                durationSeconds: $durationSeconds,
                sizeBytes: $sizeBytes,
                mimeType: $this->mimeType($realPath),
            );
        } catch (SubtitleProcessingException $exception) {
            File::deleteDirectory($workDirectory);

            throw $exception;
        } catch (Throwable $exception) {
            File::deleteDirectory($workDirectory);

            throw SubtitleProcessingException::audioAcquisitionFailed(
                context: ['exception' => $exception::class],
                previous: $exception,
            );
        }
    }

    public function prefetch(string $videoId): void
    {
        if (! config('subtitles.youtube.metadata_prefetch', false)) {
            return;
        }
        $key = $this->prefetchKey($videoId);
        if ($this->prefetchedMetadata($videoId) !== null) {
            return;
        }
        try {
            $metadata = $this->metadata('https://www.youtube.com/watch?v='.$videoId, 8);
            $this->assertSupportedVideo($metadata);
            $this->durationSeconds($metadata);
            if (($metadata['id'] ?? null) !== $videoId) {
                return;
            }
            Cache::put($key, Crypt::encryptString(json_encode($metadata, JSON_THROW_ON_ERROR)), 60);
        } catch (Throwable) {
            Log::info('backend.youtube_prefetch_failed');
        }
    }

    private function prefetchKey(string $videoId): string
    {
        return 'youtube-prefetch:v2:'.$videoId;
    }

    private function prefetchedMetadata(?string $videoId): ?array
    {
        if (! config('subtitles.youtube.metadata_prefetch', false) || $videoId === null) {
            return null;
        }
        try {
            $encrypted = Cache::get($this->prefetchKey($videoId));
            if (! is_string($encrypted)) {
                return null;
            }
            $metadata = json_decode(Crypt::decryptString($encrypted), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($metadata) || ($metadata['id'] ?? null) !== $videoId) {
                return null;
            }
            $this->assertSupportedVideo($metadata);
            $this->durationSeconds($metadata);

            return $metadata;
        } catch (Throwable) {
            return null;
        }
    }

    private function directAudio(string $workDirectory, array $metadata): ?string
    {
        if (! config('subtitles.youtube.direct_download', false) || ($metadata['ext'] ?? null) !== 'm4a'
            || ($metadata['vcodec'] ?? null) !== 'none') {
            return null;
        }
        $url = $metadata['url'] ?? '';
        $parts = is_string($url) ? parse_url($url) : false;
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || ! str_ends_with(strtolower($parts['host'] ?? ''), '.googlevideo.com')
            || isset($parts['user']) || isset($parts['pass']) || ($parts['port'] ?? 443) !== 443) {
            throw SubtitleProcessingException::audioAcquisitionFailed(context: ['stage' => 'download', 'reason' => 'unsupported_media_url']);
        }
        $headers = '';
        foreach (($metadata['http_headers'] ?? []) as $name => $value) {
            if (! in_array(strtolower($name), ['user-agent', 'referer', 'origin', 'accept', 'accept-language'], true)
                || ! is_string($value) || str_contains($value, "\r") || str_contains($value, "\n")) {
                continue;
            }
            $headers .= $name.': '.$value."\r\n";
        }
        $path = $workDirectory.DIRECTORY_SEPARATOR.'direct-audio.m4a';
        $started = hrtime(true);
        try {
            $result = Process::timeout(min(60, (int) config('subtitles.youtube.download_timeout_seconds', 600)))
                ->env($this->processEnvironment())->run([
                    (string) config('subtitles.audio_preparation.ffmpeg_binary', 'ffmpeg'),
                    '-hide_banner', '-nostdin', '-y', '-rw_timeout', '15000000',
                    '-protocol_whitelist', 'https,tls,tcp', '-tls_verify', '1',
                    '-headers', $headers, '-i', $url, '-vn', '-c:a', 'copy', $path,
                ]);
            if ($result->successful() && File::isFile($path) && File::size($path) > 0) {
                Log::info('backend.youtube_direct_download', ['success' => true, 'duration_ms' => (int) round((hrtime(true) - $started) / 1_000_000)]);

                return $path;
            }
        } catch (Throwable) {
            // Process exceptions contain signed URLs; never attach them to logs or public errors.
        }
        File::delete($path);
        Log::info('backend.youtube_direct_download', ['success' => false, 'duration_ms' => (int) round((hrtime(true) - $started) / 1_000_000)]);

        return null;
    }

    public function validatedDuration(string $youtubeUrl): int
    {
        return $this->validatedMetadata($youtubeUrl)[1];
    }

    /** @return array{array<string, mixed>, int} */
    private function validatedMetadata(string $youtubeUrl): array
    {
        $metadata = $this->metadata($youtubeUrl);
        $duration = $this->durationSeconds($metadata);
        $this->assertSupportedVideo($metadata);

        return [$metadata, $duration];
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(string $url, ?int $timeoutSeconds = null): array
    {
        $result = $this->runProcess([
            (string) config('subtitles.youtube.binary'),
            '--dump-single-json',
            '--no-warnings',
            '--no-playlist',
            '--skip-download',
            ...(config('subtitles.youtube.direct_download', false) ? ['--format', 'bestaudio[ext=m4a]/bestaudio[ext=webm]/bestaudio/best'] : []),
            $url,
        ], $timeoutSeconds ?? (int) config('subtitles.youtube.metadata_timeout_seconds'), 'metadata');

        if ($result->failed()) {
            $this->throwProcessFailure($result, 'metadata');
        }

        try {
            $metadata = json_decode($result->output(), associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw SubtitleProcessingException::audioAcquisitionFailed('Video metadata could not be parsed.', [
                'stage' => 'metadata',
            ], $exception);
        }

        if (! is_array($metadata)) {
            throw SubtitleProcessingException::audioAcquisitionFailed('Video metadata had an unexpected shape.', [
                'stage' => 'metadata',
            ]);
        }

        return $metadata;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function durationSeconds(array $metadata): int
    {
        $duration = $metadata['duration'] ?? null;

        if (! is_int($duration) && ! is_float($duration)) {
            throw SubtitleProcessingException::audioUnavailable('Video duration could not be determined.');
        }

        $durationSeconds = (int) ceil($duration);

        if ($durationSeconds < 1) {
            throw SubtitleProcessingException::audioUnavailable('Video duration could not be determined.');
        }

        return $durationSeconds;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function assertSupportedVideo(array $metadata): void
    {
        $availability = $metadata['availability'] ?? null;

        if ($availability !== 'public') {
            throw SubtitleProcessingException::audioUnavailable('Only public YouTube videos are supported.', [
                'availability' => $availability,
            ]);
        }

        if (($metadata['is_live'] ?? null) !== false) {
            throw SubtitleProcessingException::audioUnavailable('Live videos are not supported.', [
                'is_live' => $metadata['is_live'] ?? null,
            ]);
        }
    }

    /** @param array<string, mixed> $metadata */
    private function downloadAudio(string $workDirectory, array $metadata): string
    {
        $metadataPath = $workDirectory.DIRECTORY_SEPARATOR.'youtube-info.json';
        // Reuse this run's validated extraction. A silent re-extraction on
        // failure could bypass the public/non-live checks above.
        unset($metadata['webpage_url']);
        File::put($metadataPath, json_encode($metadata, JSON_THROW_ON_ERROR));

        try {
            $result = $this->runProcess([
                (string) config('subtitles.youtube.binary'),
                '--no-playlist',
                '--no-warnings',
                '--format',
                'bestaudio[ext=m4a]/bestaudio[ext=webm]/bestaudio/best',
                '--paths',
                $workDirectory,
                '--output',
                '%(id)s.%(ext)s',
                '--print',
                'after_move:filepath',
                '--load-info-json',
                $metadataPath,
            ], (int) config('subtitles.youtube.download_timeout_seconds'), 'download');
        } finally {
            File::delete($metadataPath);
        }

        if ($result->failed()) {
            $this->throwProcessFailure($result, 'download');
        }

        $reportedPath = trim($result->output());

        if ($reportedPath === '') {
            throw SubtitleProcessingException::audioAcquisitionFailed('Audio acquisition did not report an output file.', [
                'stage' => 'download',
            ]);
        }

        $realPath = realpath($reportedPath);
        $realWorkDirectory = realpath($workDirectory);

        if ($realPath === false || $realWorkDirectory === false || ! File::isFile($realPath) || ! Str::startsWith($realPath, $realWorkDirectory.DIRECTORY_SEPARATOR)) {
            throw SubtitleProcessingException::audioAcquisitionFailed('Audio acquisition produced an unexpected file path.', [
                'stage' => 'download',
            ]);
        }

        return $realPath;
    }

    /**
     * @param  array<int, string>  $command
     */
    private function runProcess(array $command, int $timeoutSeconds, string $stage): ProcessResult
    {
        $started = hrtime(true);
        try {
            return Process::timeout($timeoutSeconds)
                ->env($this->processEnvironment())
                ->run($command);
        } catch (Throwable $exception) {
            throw SubtitleProcessingException::audioAcquisitionFailed(
                'Audio acquisition command could not run.',
                ['exception' => $exception::class],
                $exception,
            );
        } finally {
            Log::info('backend.youtube_request_finished', [
                'stage' => $stage,
                'worker_pid' => getmypid(),
                'duration_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
            ]);
        }
    }

    /**
     * @return array<string, string|false>
     */
    private function processEnvironment(): array
    {
        return ChildProcessEnvironment::isolated(rtrim((string) config('subtitles.youtube.temp_directory'), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.'process-temp');
    }

    private function throwProcessFailure(ProcessResult $result, string $stage): never
    {
        $context = [
            'exit_code' => $result->exitCode(),
            'stage' => $stage,
            'command' => $result->command(),
            'stdout_excerpt' => $this->outputExcerpt($result->output()),
            'stderr_excerpt' => $this->outputExcerpt($result->errorOutput()),
        ];
        if ($stage === 'download') {
            $context = ['exit_code' => $result->exitCode(), 'stage' => $stage];
        }

        if ($this->isMissingBinaryFailure($result)) {
            throw SubtitleProcessingException::audioAcquisitionFailed(
                'Audio downloader is not installed or not available on PATH.',
                [
                    ...$context,
                    'reason' => 'youtube_downloader_missing',
                    'binary' => (string) config('subtitles.youtube.binary'),
                ],
            );
        }

        if ($stage === 'metadata') {
            throw SubtitleProcessingException::audioUnavailable(context: $context);
        }

        throw SubtitleProcessingException::audioAcquisitionFailed(context: $context);
    }

    private function isMissingBinaryFailure(ProcessResult $result): bool
    {
        $errorOutput = strtolower($result->errorOutput());

        return str_contains($errorOutput, 'not recognized as an internal or external command')
            || str_contains($errorOutput, 'command not found')
            || str_contains($errorOutput, 'no such file or directory')
            || str_contains($errorOutput, 'the system cannot find the file specified');
    }

    private function outputExcerpt(string $output): string
    {
        $cleaned = trim((string) preg_replace('/\s+/u', ' ', $output));

        return mb_substr($cleaned, 0, 500);
    }

    private function mimeType(string $path): string
    {
        $extensionMimeType = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'm4a', 'mp4' => 'audio/mp4',
            'webm' => 'audio/webm',
            'mp3', 'mpeg', 'mpga' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'ogg' => 'audio/ogg',
            default => null,
        };

        if ($extensionMimeType !== null) {
            return $extensionMimeType;
        }

        $mimeType = File::mimeType($path);

        if (is_string($mimeType) && str_starts_with($mimeType, 'audio/')) {
            return $mimeType;
        }

        throw SubtitleProcessingException::audioAcquisitionFailed('Audio acquisition produced an unknown file type.', [
            'path_extension' => pathinfo($path, PATHINFO_EXTENSION),
        ]);
    }
}
