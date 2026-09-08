<?php

namespace App\Services\Subtitles;

use App\Services\Transcription\TimestampedTranscriptSegment;

final class SubtitleWebVttFormatter
{
    /**
     * @param  array<int, array<string, mixed>>  $cues
     */
    public static function fromCues(array $cues): string
    {
        $blocks = ['WEBVTT'];

        foreach ($cues as $cue) {
            $blocks[] = implode("\n", [
                (string) $cue['cueId'],
                self::timestampFromMilliseconds((int) $cue['startMs']).' --> '.self::timestampFromMilliseconds((int) $cue['endMs']),
                (string) $cue['sourceText'],
            ]);
        }

        return implode("\n\n", $blocks)."\n";
    }

    /**
     * @param  array<int, TimestampedTranscriptSegment>  $segments
     */
    public static function fromSegments(array $segments): string
    {
        $blocks = ['WEBVTT'];

        foreach ($segments as $index => $segment) {
            $blocks[] = implode("\n", [
                sprintf('cue-%04d', $index + 1),
                self::timestampFromSeconds($segment->startSeconds).' --> '.self::timestampFromSeconds($segment->endSeconds),
                $segment->text,
            ]);
        }

        return implode("\n\n", $blocks)."\n";
    }

    public static function timestampFromMilliseconds(int $milliseconds): string
    {
        $hours = intdiv($milliseconds, 3_600_000);
        $milliseconds %= 3_600_000;
        $minutes = intdiv($milliseconds, 60_000);
        $milliseconds %= 60_000;
        $seconds = intdiv($milliseconds, 1000);
        $milliseconds %= 1000;

        return sprintf('%02d:%02d:%02d.%03d', $hours, $minutes, $seconds, $milliseconds);
    }

    private static function timestampFromSeconds(float $seconds): string
    {
        return self::timestampFromMilliseconds((int) round($seconds * 1000));
    }
}
