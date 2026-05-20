<?php

namespace App\Services\Subtitles;

final class SubtitleQueue
{
    public const DEFAULT_NAME = 'subtitle-ai';

    public static function connection(): string
    {
        return (string) config('subtitles.queue.connection', 'database');
    }

    public static function name(): string
    {
        return (string) config('subtitles.queue.name', self::DEFAULT_NAME);
    }
}
