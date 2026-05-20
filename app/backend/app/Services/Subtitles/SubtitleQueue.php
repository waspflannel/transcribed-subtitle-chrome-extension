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

    public static function nameForTier(string $tier): string
    {
        return SubtitleTier::queue($tier);
    }

    public static function nameForJob(object $job): string
    {
        $tier = property_exists($job, 'generation_tier') ? $job->generation_tier : null;

        return self::nameForTier(is_string($tier) ? $tier : SubtitleTier::default());
    }

    /**
     * @return array<int, string>
     */
    public static function names(): array
    {
        return SubtitleTier::queuesInPriorityOrder();
    }

    public static function workerQueueList(): string
    {
        return SubtitleTier::workerQueueList();
    }
}
