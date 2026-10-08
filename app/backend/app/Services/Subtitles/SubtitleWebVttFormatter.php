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
                self::cueText((string) $cue['sourceText']),
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
                self::cueText($segment->text),
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

    /**
     * WebVTT reads `&`, `<` and `-->` in cue text as markup, and a blank line
     * ends the cue, so lyrics such as "<3" or "-->" are escaped.
     */
    private static function cueText(string $text): string
    {
        return str_replace(['&', '<', '>', "\r", "\n"], ['&amp;', '&lt;', '&gt;', ' ', ' '], $text);
    }

    private static function timestampFromSeconds(float $seconds): string
    {
        return self::timestampFromMilliseconds((int) round($seconds * 1000));
    }
}
