<?php

namespace App\Services\Subtitles;

final class SubtitleQueue
{
    public const FAMILY_GENERATION = 'generation';

    public const FAMILY_BATCH = 'batch';

    public const DEFAULT_GENERATION_NAME = 'subtitle-generation-base';

    public const DEFAULT_BATCH_NAME = 'subtitle-batch-base';

    public const DEFAULT_NAME = self::DEFAULT_GENERATION_NAME;

    public static function connection(): string
    {
        return (string) config('subtitles.queue.connection', 'database');
    }

    public static function name(): string
    {
        return self::generationName();
    }

    public static function generationName(): string
    {
        return self::generationNameForTier(SubtitleTier::default());
    }

    public static function batchName(): string
    {
        return self::batchNameForTier(SubtitleTier::default());
    }

    public static function nameForTier(string $tier): string
    {
        return self::generationNameForTier($tier);
    }

    public static function generationNameForTier(string $tier): string
    {
        return SubtitleTier::generationQueue($tier);
    }

    public static function batchNameForTier(string $tier): string
    {
        return SubtitleTier::batchQueue($tier);
    }

    public static function nameForJob(object $job): string
    {
        return self::generationNameForJob($job);
    }

    public static function generationNameForJob(object $job): string
    {
        $tier = data_get($job, 'generation_tier');

        return self::generationNameForTier(is_string($tier) ? $tier : SubtitleTier::default());
    }

    public static function batchNameForJob(object $job): string
    {
        $tier = data_get($job, 'generation_tier');

        return self::batchNameForTier(is_string($tier) ? $tier : SubtitleTier::default());
    }

    /**
     * @return array<int, string>
     */
    public static function names(): array
    {
        return SubtitleTier::allQueuesInPriorityOrder();
    }

    /**
     * @return array<int, string>
     */
    public static function generationNames(): array
    {
        return SubtitleTier::generationQueuesInPriorityOrder();
    }

    /**
     * @return array<int, string>
     */
    public static function batchNames(): array
    {
        return SubtitleTier::batchQueuesInPriorityOrder();
    }

    public static function familyForQueue(string $queue): ?string
    {
        if (in_array($queue, self::generationNames(), true)) {
            return self::FAMILY_GENERATION;
        }

        if (in_array($queue, self::batchNames(), true)) {
            return self::FAMILY_BATCH;
        }

        return null;
    }

    public static function workerQueueList(): string
    {
        return implode(',', self::workerQueues());
    }

    /**
     * @return array<int, array{name: string, queue_family: string, queues: array<int, string>, worker_count: int}>
     */
    public static function workerGroups(): array
    {
        return SubtitleTier::workerGroups();
    }

    /**
     * @return array<int, string>
     */
    public static function workerQueues(): array
    {
        $queues = [];

        foreach (self::workerGroups() as $group) {
            foreach ($group['queues'] as $queue) {
                $queues[] = $queue;
            }
        }

        return array_values(array_unique($queues));
    }
}
