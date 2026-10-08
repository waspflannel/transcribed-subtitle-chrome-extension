<?php

namespace App\Services\Audio;

use App\Exceptions\SubtitleProcessingException;
use App\Support\ChildProcessEnvironment;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Throwable;

class ElevenLabsScribeAudioPreparer
{
    private const ADAPTER = 'elevenlabs-audio-preparer';

    public function prepare(TemporaryAudioFile $audio): TemporaryAudioFile
    {
        Log::info('backend.audio_preparation_started', [
            'input_mime_type' => $audio->mimeType,
            'source_duration_seconds' => $audio->durationSeconds,
            'source_audio_bytes' => $audio->sizeBytes,
        ]);

        $preparedAudio = $this->normalizeSourceToFlac($audio);

        Log::info('backend.audio_preparation_completed', [
            'prepared_audio_bytes' => $preparedAudio->sizeBytes,
            'prepared_mime_type' => $preparedAudio->mimeType,
        ]);

        return $preparedAudio;
    }

    private function normalizeSourceToFlac(TemporaryAudioFile $audio): TemporaryAudioFile
    {
        $preparedFlacPath = $audio->directory.DIRECTORY_SEPARATOR.'scribe-ready.flac';

        $this->runFfmpeg([
            $this->ffmpegBinary(),
            '-hide_banner',
            '-nostdin',
            '-y',
            '-i',
            $audio->path,
            '-vn',
            '-ac',
            '1',
            '-ar',
            '16000',
            '-c:a',
            'flac',
            $preparedFlacPath,
        ], $audio->directory);

        $this->assertUsableFile($preparedFlacPath);

        return new TemporaryAudioFile(
            path: $preparedFlacPath,
            directory: $audio->directory,
            durationSeconds: $audio->durationSeconds,
            sizeBytes: File::size($preparedFlacPath),
            mimeType: 'audio/flac',
        );
    }

    /**
     * @param  list<string>  $command
     */
    private function runFfmpeg(array $command, string $workDirectory): void
    {
        $startedAt = microtime(true);

        try {
            $result = Process::timeout($this->ffmpegTimeoutSeconds())
                ->env(ChildProcessEnvironment::isolated($workDirectory.DIRECTORY_SEPARATOR.'process-temp'))
                ->run($command);
        } catch (Throwable $exception) {
            throw $this->failure('Audio preparation command could not run.', [
                'stage' => 'source_to_flac',
                'reason' => $this->isTimeoutException($exception) ? 'timeout' : 'process_exception',
                'exception' => $exception::class,
            ], $exception);
        }

        if ($result->failed()) {
            $this->throwFfmpegFailure($result);
        }

        Log::info('backend.audio_preparation_ffmpeg_completed', [
            'stage' => 'source_to_flac',
            'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
    }

    private function throwFfmpegFailure(ProcessResult $result): never
    {
        throw $this->failure('Audio preparation command failed.', [
            'stage' => 'source_to_flac',
            'reason' => $this->isMissingFfmpegFailure($result) ? 'ffmpeg_missing' : 'ffmpeg_failure',
            'exit_code' => $result->exitCode(),
        ]);
    }

    private function assertUsableFile(string $path): void
    {
        if (! File::isFile($path) || File::size($path) < 1) {
            throw $this->failure('Audio preparation produced an empty file.', [
                'stage' => 'source_to_flac',
                'reason' => 'empty_output',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function failure(string $message, array $context, ?Throwable $previous = null): SubtitleProcessingException
    {
        return SubtitleProcessingException::transcriptionFailed($message, [
            'adapter' => self::ADAPTER,
            ...$context,
        ], $previous);
    }

    private function ffmpegBinary(): string
    {
        $binary = config('subtitles.audio_preparation.ffmpeg_binary', 'ffmpeg');

        return is_string($binary) && trim($binary) !== '' ? trim($binary) : 'ffmpeg';
    }

    private function ffmpegTimeoutSeconds(): int
    {
        return max(1, (int) config('subtitles.audio_preparation.ffmpeg_timeout_seconds', 600));
    }

    private function isMissingFfmpegFailure(ProcessResult $result): bool
    {
        $errorOutput = strtolower($result->errorOutput());

        return str_contains($errorOutput, 'not recognized as an internal or external command')
            || str_contains($errorOutput, 'command not found')
            || str_contains($errorOutput, 'no such file or directory')
            || str_contains($errorOutput, 'the system cannot find the file specified');
    }

    private function isTimeoutException(Throwable $exception): bool
    {
        return $exception instanceof ProcessTimedOutException
            || str_contains($exception::class, 'Timeout')
            || str_contains($exception::class, 'TimedOut');
    }
}
