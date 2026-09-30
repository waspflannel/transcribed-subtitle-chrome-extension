<?php

namespace App\Services\Subtitles;

final class SubtitleQueue
{
    public const FAMILY_GENERATION = 'generation';

    public const FAMILY_BATCH = 'batch';

    public const DEFAULT_GENERATION_NAME = 'subtitle-generation';

    public const DEFAULT_BATCH_NAME = 'subtitle-batch';

    public static function connection(): string
    {
        return (string) config('subtitles.queue.connection', 'redis');
    }

    public static function generationName(): string
    {
        return (string) config('subtitles.queue.generation_name', self::DEFAULT_GENERATION_NAME);
    }

    public static function batchName(): string
    {
        return (string) config('subtitles.queue.batch_name', self::DEFAULT_BATCH_NAME);
    }

    public static function generationNameForJob(object $job): string
    {
        return self::generationName();
    }

    public static function batchNameForJob(object $job): string
    {
        return self::batchName();
    }

    /**
     * @return array<int, string>
     */
    public static function names(): array
    {
        return [self::generationName(), self::batchName()];
    }

    /**
     * @return array<int, string>
     */
    public static function generationNames(): array
    {
        return [self::generationName()];
    }

    /**
     * @return array<int, string>
     */
    public static function batchNames(): array
    {
        return [self::batchName()];
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
        return array_map(fn (string $family): array => [
            'name' => $family,
            'queue_family' => $family,
            'queues' => [$family === self::FAMILY_GENERATION ? self::generationName() : self::batchName()],
            'worker_count' => max(1, (int) config('subtitles.queue.'.$family.'_workers', $family === self::FAMILY_GENERATION ? 9 : 22)),
        ], [self::FAMILY_GENERATION, self::FAMILY_BATCH]);
    }

    public static function workerCount(): int
    {
        return array_sum(array_column(self::workerGroups(), 'worker_count'));
    }

    public static function concurrencyCacheStore(): string
    {
        return (string) config('subtitles.providers.concurrency_cache_store', 'subtitle_concurrency');
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
