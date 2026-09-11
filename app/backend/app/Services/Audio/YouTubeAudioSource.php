<?php

namespace App\Services\Audio;

use App\Exceptions\SubtitleProcessingException;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

class YouTubeAudioSource
{
    public function acquire(string $youtubeUrl, ?int $requestDurationSeconds, string $workDirectory): TemporaryAudioFile
    {
        File::ensureDirectoryExists($workDirectory, 0700);

        try {
            [$metadata, $durationSeconds] = $this->validatedMetadata($youtubeUrl, $requestDurationSeconds);

            $realPath = $this->downloadAudio($youtubeUrl, $workDirectory, $metadata);
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

    public function validatedDuration(string $youtubeUrl, ?int $requestDurationSeconds): int
    {
        return $this->validatedMetadata($youtubeUrl, $requestDurationSeconds)[1];
    }

    /** @return array{array<string, mixed>, int} */
    private function validatedMetadata(string $youtubeUrl, ?int $requestDurationSeconds): array
    {
        $maxDurationSeconds = (int) config('subtitles.max_video_duration_seconds');
        if ($requestDurationSeconds !== null && $requestDurationSeconds > $maxDurationSeconds) {
            throw SubtitleProcessingException::videoTooLong($requestDurationSeconds, $maxDurationSeconds);
        }
        $metadata = $this->metadata($youtubeUrl);
        $duration = $this->durationSeconds($metadata, $maxDurationSeconds);
        $this->assertSupportedVideo($metadata);

        return [$metadata, $duration];
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(string $url): array
    {
        $result = $this->runProcess([
            (string) config('subtitles.youtube.binary'),
            '--dump-single-json',
            '--no-warnings',
            '--no-playlist',
            '--skip-download',
            $url,
        ], (int) config('subtitles.youtube.metadata_timeout_seconds'));

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
    private function durationSeconds(array $metadata, int $maxDurationSeconds): int
    {
        $duration = $metadata['duration'] ?? null;

        if (! is_int($duration) && ! is_float($duration)) {
            throw SubtitleProcessingException::audioUnavailable('Video duration could not be determined.');
        }

        $durationSeconds = (int) ceil($duration);

        if ($durationSeconds < 1) {
            throw SubtitleProcessingException::audioUnavailable('Video duration could not be determined.');
        }

        if ($durationSeconds > $maxDurationSeconds) {
            throw SubtitleProcessingException::videoTooLong($durationSeconds, $maxDurationSeconds);
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
    private function downloadAudio(string $url, string $workDirectory, array $metadata): string
    {
        $metadataPath = null;
        $sourceArguments = [$url];

        if (config('subtitles.youtube.reuse_metadata', false)) {
            $metadataPath = $workDirectory.DIRECTORY_SEPARATOR.'youtube-info.json';
            // yt-dlp retries extraction through webpage_url on download failure.
            // This snapshot is valid only for the already validated acquisition.
            unset($metadata['webpage_url']);
            File::put($metadataPath, json_encode($metadata, JSON_THROW_ON_ERROR));
            $sourceArguments = ['--load-info-json', $metadataPath];
        }

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
                ...$sourceArguments,
            ], (int) config('subtitles.youtube.download_timeout_seconds'));
        } finally {
            if ($metadataPath !== null) {
                File::delete($metadataPath);
            }
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
    private function runProcess(array $command, int $timeoutSeconds): ProcessResult
    {
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
        }
    }

    /**
     * @return array<string, string>
     */
    private function processEnvironment(): array
    {
        $tempDirectory = rtrim((string) config('subtitles.youtube.temp_directory'), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.'process-temp';

        File::ensureDirectoryExists($tempDirectory, 0700);

        $environment = [];

        foreach (['SystemRoot', 'WINDIR', 'COMSPEC', 'Path', 'PATH', 'PATHEXT', 'USERPROFILE', 'LOCALAPPDATA', 'APPDATA', 'PROGRAMDATA'] as $name) {
            $value = getenv($name);

            if (is_string($value) && $value !== '') {
                $environment[$name] = $value;
            }
        }

        return [
            ...$environment,
            'TEMP' => $tempDirectory,
            'TMP' => $tempDirectory,
            'TMPDIR' => $tempDirectory,
        ];
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
