<?php

namespace App\Services\Audio;

use App\Exceptions\SubtitleProcessingException;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Splits source or Scribe-prepared audio into fixed-interval chunks with symmetric
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
        $minAudioSeconds = (int) config('subtitles.transcription.chunking.min_audio_seconds', 240);
        $targetSeconds = max(30, (int) config('subtitles.transcription.chunking.target_seconds', 120));
        $overlapSeconds = max(0.0, (float) config('subtitles.transcription.chunking.overlap_seconds', 2.0));
        $maxChunks = max(1, (int) config('subtitles.transcription.chunking.max_chunks', 8));

        if ($minAudioSeconds <= 0 || $durationSeconds < $minAudioSeconds) {
            return [];
        }

        $chunkCount = min($maxChunks, (int) ceil($durationSeconds / $targetSeconds));

        if ($chunkCount < 2) {
            return [];
        }

        $chunkLength = (float) $durationSeconds / $chunkCount;
        $plan = [];

        for ($index = 0; $index < $chunkCount; $index++) {
            $nominalStart = $index * $chunkLength;
            $nominalEnd = $index === $chunkCount - 1 ? (float) $durationSeconds : ($index + 1) * $chunkLength;

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
     * Extracts one FLAC file per plan entry into the audio's work directory.
     * The chunk files live inside the parent audio's directory and are removed
     * with it when the pipeline deletes the temporary audio.
     *
     * @param  array<int, array{nominalStart: float, nominalEnd: float, audioStart: float, audioEnd: float}>  $plan
     * @return array<int, TemporaryAudioFile>
     */
    public function split(TemporaryAudioFile $audio, array $plan): array
    {
        $chunks = [];

        foreach ($plan as $index => $bounds) {
            $chunkPath = $audio->directory.DIRECTORY_SEPARATOR.sprintf('transcribe-chunk-%03d.flac', $index);
            $this->runFfmpeg([
                $this->ffmpegBinary(),
                '-hide_banner',
                '-nostdin',
                '-y',
                '-ss',
                $this->formatSeconds($bounds['audioStart']),
                '-t',
                $this->formatSeconds($bounds['audioEnd'] - $bounds['audioStart']),
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
            ], $index);

            if (! File::isFile($chunkPath) || File::size($chunkPath) < 1) {
                throw $this->failure('Transcription audio chunk is empty.', [
                    'reason' => 'empty_chunk',
                    'chunk_index' => $index,
                ]);
            }

            $chunks[] = new TemporaryAudioFile(
                path: $chunkPath,
                directory: $audio->directory,
                durationSeconds: (int) round($bounds['audioEnd'] - $bounds['audioStart']),
                sizeBytes: File::size($chunkPath),
                mimeType: 'audio/flac',
            );
        }

        return $chunks;
    }

    /**
     * @param  array<int, string>  $command
     */
    private function runFfmpeg(array $command, int $chunkIndex): void
    {
        try {
            $result = Process::timeout($this->ffmpegTimeoutSeconds())->run($command);
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
