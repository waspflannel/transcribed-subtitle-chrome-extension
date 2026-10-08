<?php

namespace App\Services\Audio;

use App\Exceptions\SubtitleProcessingException;
use App\Support\ChildProcessEnvironment;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Splits source or Scribe-prepared audio into a short opening chunk and bounded later chunks with symmetric
 * overlap so long videos can be transcribed in parallel. Every chunk hears
 * `overlap_seconds` past each nominal boundary on both sides, so a word cut
 * by one chunk edge is heard whole by its neighbour; the merger keeps each
 * word from exactly one chunk by timestamp midpoint.
 */
class ScribeAudioChunker
{
    /**
     * Chunk boundaries for the given duration, or an empty list when the
     * audio is too short to benefit from chunking.
     *
     * @return array<int, array{nominalStart: float, nominalEnd: float, audioStart: float, audioEnd: float}>
     */
    public function plan(int $durationSeconds): array
    {
        $minAudioSeconds = (int) config('subtitles.transcription.chunking.min_audio_seconds', 45);
        $targetSeconds = max(10, (int) config('subtitles.transcription.chunking.target_seconds', 60));
        $overlapSeconds = max(0.0, (float) config('subtitles.transcription.chunking.overlap_seconds', 2.0));
        $maxChunks = max(1, (int) config('subtitles.transcription.chunking.max_chunks', 8));

        if ($minAudioSeconds <= 0 || $durationSeconds < $minAudioSeconds) {
            return [];
        }

        $firstSeconds = max(0, (int) config('subtitles.transcription.chunking.first_seconds', 15));
        $firstSeconds = min($firstSeconds, $targetSeconds, $durationSeconds / 2);
        $secondSeconds = $firstSeconds > 0 && $maxChunks > 2
            ? min(max(0, (int) config('subtitles.transcription.chunking.second_seconds', 20)), $targetSeconds, ($durationSeconds - $firstSeconds) / 2)
            : 0;
        $openingCount = (int) ($firstSeconds > 0) + (int) ($secondSeconds > 0);
        $chunkCount = min($maxChunks, $firstSeconds > 0
            ? $openingCount + (int) ceil(($durationSeconds - $firstSeconds - $secondSeconds) / $targetSeconds)
            : (int) ceil($durationSeconds / $targetSeconds));

        if ($chunkCount < 2) {
            return [];
        }

        $chunkLength = $firstSeconds > 0
            ? ($durationSeconds - $firstSeconds - $secondSeconds) / ($chunkCount - $openingCount)
            : (float) $durationSeconds / $chunkCount;
        $plan = [];

        for ($index = 0; $index < $chunkCount; $index++) {
            $nominalStart = $index === 0 ? 0.0 : (float) $plan[$index - 1]['nominalEnd'];
            $length = $index === 0 && $firstSeconds > 0 ? $firstSeconds : $chunkLength;
            if ($index === 1 && $secondSeconds > 0) {
                $length = $secondSeconds;
            }
            $nominalEnd = $index === $chunkCount - 1 ? (float) $durationSeconds : $nominalStart + $length;

            $plan[] = [
                'nominalStart' => $nominalStart,
                'nominalEnd' => $nominalEnd,
                'audioStart' => max(0.0, $nominalStart - $overlapSeconds),
                'audioEnd' => min((float) $durationSeconds, $nominalEnd + $overlapSeconds),
            ];
        }

        return $plan;
    }

    /**
     * Extract one slice inside its transcription job so the opening upload
     * does not wait for later slices. The run owns the shared workspace.
     */
    public function extractChunk(TemporaryAudioFile $audio, int $index, float $startSeconds, float $endSeconds): TemporaryAudioFile
    {
        $chunkPath = $audio->directory.DIRECTORY_SEPARATOR.sprintf('transcribe-chunk-%03d.flac', $index);
        $this->runFfmpeg([
            $this->ffmpegBinary(),
            '-hide_banner',
            '-nostdin',
            '-y',
            '-ss',
            $this->formatSeconds($startSeconds),
            '-t',
            $this->formatSeconds($endSeconds - $startSeconds),
            '-i',
            $audio->path,
            '-vn',
            '-ac',
            '1',
            '-ar',
            '16000',
            '-c:a',
            'flac',
            $chunkPath,
        ], $index, $endSeconds - $startSeconds, $audio->directory);

        if (! File::isFile($chunkPath) || File::size($chunkPath) < 1) {
            throw $this->failure('Transcription audio chunk is empty.', [
                'reason' => 'empty_chunk',
                'chunk_index' => $index,
            ]);
        }

        return new TemporaryAudioFile(
            path: $chunkPath,
            directory: $audio->directory,
            durationSeconds: (int) round($endSeconds - $startSeconds),
            sizeBytes: File::size($chunkPath),
            mimeType: 'audio/flac',
        );
    }

    /**
     * @param  array<int, string>  $command
     */
    private function runFfmpeg(array $command, int $chunkIndex, float $chunkSeconds, string $workDirectory): void
    {
        // ffmpeg runs far faster than 10x real time; scale so long chunks are not cut off.
        $timeoutSeconds = min($this->ffmpegTimeoutSeconds(), max(60, (int) ceil($chunkSeconds / 10)));

        try {
            $result = Process::timeout($timeoutSeconds)
                ->env(ChildProcessEnvironment::isolated($workDirectory.DIRECTORY_SEPARATOR.'process-temp'))
                ->run($command);
        } catch (Throwable $exception) {
            throw $this->failure('Transcription audio chunking could not run.', [
                'reason' => 'process_exception',
                'chunk_index' => $chunkIndex,
                'exception' => $exception::class,
            ], $exception);
        }

        if ($result->failed()) {
            $this->throwFfmpegFailure($result, $chunkIndex);
        }
    }

    private function throwFfmpegFailure(ProcessResult $result, int $chunkIndex): never
    {
        throw $this->failure('Transcription audio chunking failed.', [
            'reason' => 'ffmpeg_failure',
            'chunk_index' => $chunkIndex,
            'exit_code' => $result->exitCode(),
        ]);
    }

    private function formatSeconds(float $seconds): string
    {
        return number_format($seconds, 3, '.', '');
    }

    private function ffmpegBinary(): string
    {
        $binary = config('subtitles.audio_preparation.ffmpeg_binary', 'ffmpeg');

        if (! is_string($binary) || trim($binary) === '') {
            return 'ffmpeg';
        }

        return trim($binary);
    }

    private function ffmpegTimeoutSeconds(): int
    {
        return max(1, (int) config('subtitles.audio_preparation.ffmpeg_timeout_seconds', 600));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function failure(string $message, array $context, ?Throwable $previous = null): SubtitleProcessingException
    {
        return SubtitleProcessingException::transcriptionFailed($message, [
            'adapter' => 'scribe-audio-chunker',
            ...$context,
        ], $previous);
    }
}
