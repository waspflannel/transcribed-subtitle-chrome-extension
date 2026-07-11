<?php

namespace App\Services\Audio;

use Illuminate\Support\Facades\File;

/**
 * Per-run scratch directory for a subtitle job's audio files. The generation
 * stages run as separate queue jobs, so the source download, the
 * Scribe-ready FLAC, and the transcription chunk files must live at a path
 * every stage (and the failure handler) can derive from the run id alone.
 */
final class SubtitleAudioWorkspace
{
    public static function directory(string $runId): string
    {
        return rtrim((string) config('subtitles.youtube.temp_directory'), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.'run-'.$runId;
    }

    public static function delete(string $runId): void
    {
        $directory = self::directory($runId);

        if (File::isDirectory($directory)) {
            File::deleteDirectory($directory);
        }
    }
}
