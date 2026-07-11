<?php

namespace App\Services\Subtitles;

final class SubtitleTier
{
    public const ULTIMATE = 'ultimate';

    public const BASE = 'base';

    private const PRIORITY_TIERS = [self::ULTIMATE, 'pro', 'plus', self::BASE];

    public static function default(): string
    {
        return self::normalize(config('subtitles.tiers.default'));
    }

    public static function normalize(mixed $tier): string
    {
        if (! is_string($tier) || $tier === '') {
            return self::BASE;
        }

        return array_key_exists($tier, self::plans()) ? $tier : self::BASE;
    }

    public static function generationQueue(string $tier): string
    {
        $tier = self::normalize($tier);
        $queue = self::plans()[$tier]['generation_queue'] ?? null;

        return is_string($queue) && $queue !== '' ? $queue : SubtitleQueue::DEFAULT_GENERATION_NAME;
    }

    public static function batchQueue(string $tier): string
    {
        $tier = self::normalize($tier);
        $queue = self::plans()[$tier]['batch_queue'] ?? null;

        return is_string($queue) && $queue !== '' ? $queue : SubtitleQueue::DEFAULT_BATCH_NAME;
    }

    /**
     * @return array<int, string>
     */
    public static function generationQueuesInPriorityOrder(): array
    {
        $queues = [];
        $plans = self::plans();

        foreach (self::priorityTiers() as $tier) {
            if (! array_key_exists($tier, $plans)) {
                continue;
            }

            $queues[] = self::generationQueue($tier);
        }

        foreach (array_keys($plans) as $tier) {
            if (in_array($tier, self::priorityTiers(), true)) {
                continue;
            }

            $queues[] = self::generationQueue($tier);
        }

        return array_values(array_unique($queues));
    }

    /**
     * @return array<int, string>
     */
    public static function batchQueuesInPriorityOrder(): array
    {
        $queues = [];
        $plans = self::plans();

        foreach (self::priorityTiers() as $tier) {
            if (! array_key_exists($tier, $plans)) {
                continue;
            }

            $queues[] = self::batchQueue($tier);
        }

        foreach (array_keys($plans) as $tier) {
            if (in_array($tier, self::priorityTiers(), true)) {
                continue;
            }

            $queues[] = self::batchQueue($tier);
        }

        return array_values(array_unique($queues));
    }

    /**
     * @return array<int, string>
     */
    public static function allQueuesInPriorityOrder(): array
    {
        return array_values(array_unique([
            ...self::generationQueuesInPriorityOrder(),
            ...self::batchQueuesInPriorityOrder(),
        ]));
    }

    public static function workerCount(): int
    {
        $count = 0;

        foreach (self::workerGroups() as $group) {
            $count += $group['worker_count'];
        }

        return $count;
    }

    public static function generationConcurrency(string $tier): int
    {
        $value = self::plans()[self::normalize($tier)]['generation_concurrency'] ?? 1;

        return max(1, (int) $value);
    }

    public static function batchConcurrency(string $tier): int
    {
        $value = self::plans()[self::normalize($tier)]['batch_concurrency'] ?? 1;

        return max(1, (int) $value);
    }

    /**
     * Total jobs a user may have waiting (running + queued). Never below the
     * processing concurrency, so a plan can always fill its running slots.
     */
    public static function submissionLimit(string $tier): int
    {
        $value = self::plans()[self::normalize($tier)]['submission_limit'] ?? 1;

        return max(self::generationConcurrency($tier), (int) $value);
    }

    public static function concurrencyReleaseDelaySeconds(): int
    {
        return max(1, (int) config('subtitles.tiers.release_delay_seconds', 10));
    }

    public static function concurrencyCacheStore(): string
    {
        $store = config('subtitles.tiers.concurrency_cache_store', 'subtitle_concurrency');

        return is_string($store) && $store !== '' ? $store : 'subtitle_concurrency';
    }

    public static function concurrencyLockSeconds(): int
    {
        return max(1, (int) config('subtitles.tiers.lock_seconds', 10));
    }

    public static function concurrencyCounterSeconds(): int
    {
        return max(60, (int) config('subtitles.tiers.counter_seconds', 1800));
    }

    public static function budgetBucket(?int $durationSeconds): string
    {
        if ($durationSeconds === null || $durationSeconds <= 300) {
            return 'short';
        }

        if ($durationSeconds <= 1800) {
            return 'medium';
        }

        return 'near_limit';
    }

    public static function budgetSeconds(string $tier, ?int $durationSeconds): int
    {
        $tier = self::normalize($tier);
        $bucket = self::budgetBucket($durationSeconds);
        $value = self::plans()[$tier]['budgets_seconds'][$bucket] ?? 0;

        return max(0, (int) $value);
    }

    /**
     * @return array<int, array{name: string, queue_family: string, queues: array<int, string>, worker_count: int}>
     */
    public static function workerGroups(): array
    {
        $configuredGroups = config('subtitles.queue.worker_groups', []);

        if (! is_array($configuredGroups)) {
            return [];
        }

        $groups = [];

        foreach ($configuredGroups as $name => $configuredGroup) {
            if (! is_string($name) || $name === '' || ! is_array($configuredGroup)) {
                continue;
            }

            $family = $configuredGroup['queue_family'] ?? '';

            if (! in_array($family, [SubtitleQueue::FAMILY_GENERATION, SubtitleQueue::FAMILY_BATCH], true)) {
                continue;
            }

            $queues = self::workerGroupQueues($configuredGroup, $family);

            if ($queues === []) {
                continue;
            }

            $groups[] = [
                'name' => $name,
                'queue_family' => $family,
                'queues' => $queues,
                'worker_count' => max(0, (int) ($configuredGroup['worker_count'] ?? 0)),
            ];
        }

        return $groups;
    }

    /**
     * @param  array<string, mixed>  $configuredGroup
     * @return array<int, string>
     */
    private static function workerGroupQueues(array $configuredGroup, string $family): array
    {
        $configuredQueues = $configuredGroup['queues'] ?? null;

        if (is_string($configuredQueues)) {
            return array_values(array_filter(
                array_map('trim', explode(',', $configuredQueues)),
                fn (string $queue): bool => $queue !== '',
            ));
        }

        if (is_array($configuredQueues)) {
            return array_values(array_filter(
                $configuredQueues,
                fn (mixed $queue): bool => is_string($queue) && $queue !== '',
            ));
        }

        $tiers = self::workerGroupTiers($configuredGroup);

        return array_values(array_unique(array_map(
            fn (string $tier): string => $family === SubtitleQueue::FAMILY_BATCH
                ? self::batchQueue($tier)
                : self::generationQueue($tier),
            $tiers,
        )));
    }

    /**
     * @param  array<string, mixed>  $configuredGroup
     * @return array<int, string>
     */
    private static function workerGroupTiers(array $configuredGroup): array
    {
        $configuredTiers = $configuredGroup['tiers'] ?? self::priorityTiers();

        if (is_string($configuredTiers)) {
            $configuredTiers = array_map('trim', explode(',', $configuredTiers));
        }

        if (! is_array($configuredTiers)) {
            return self::priorityTiers();
        }

        $tiers = [];

        foreach ($configuredTiers as $tier) {
            if (! is_string($tier) || $tier === '') {
                continue;
            }

            $tiers[] = self::normalize($tier);
        }

        return $tiers === [] ? self::priorityTiers() : array_values(array_unique($tiers));
    }

    /**
     * @return array<int, string>
     */
    private static function priorityTiers(): array
    {
        return self::PRIORITY_TIERS;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function plans(): array
    {
        $plans = config('subtitles.tiers.plans', []);

        return is_array($plans) ? $plans : [];
    }
}
