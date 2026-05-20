<?php

namespace App\Services\Subtitles;

final class SubtitleTier
{
    public const ULTIMATE = 'ultimate';

    public const BASE = 'base';

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

    public static function queue(string $tier): string
    {
        $tier = self::normalize($tier);
        $queue = self::plans()[$tier]['queue'] ?? SubtitleQueue::DEFAULT_NAME;

        return is_string($queue) && $queue !== '' ? $queue : SubtitleQueue::DEFAULT_NAME;
    }

    /**
     * @return array<int, string>
     */
    public static function queuesInPriorityOrder(): array
    {
        $queues = [];
        $plans = self::plans();

        foreach ([self::ULTIMATE, 'pro', 'plus', self::BASE] as $tier) {
            if (! array_key_exists($tier, $plans)) {
                continue;
            }

            $queues[] = self::queue($tier);
        }

        foreach (array_keys($plans) as $tier) {
            if (in_array($tier, [self::ULTIMATE, 'pro', 'plus', self::BASE], true)) {
                continue;
            }

            $queues[] = self::queue($tier);
        }

        $queues[] = SubtitleQueue::DEFAULT_NAME;

        return array_values(array_unique($queues));
    }

    public static function workerQueueList(): string
    {
        return implode(',', self::queuesInPriorityOrder());
    }

    public static function workerCount(): int
    {
        $count = 0;

        foreach (self::plans() as $plan) {
            $count += max(0, (int) ($plan['worker_count'] ?? 0));
        }

        return max(1, $count);
    }

    public static function perInstallConcurrency(string $tier): int
    {
        $value = self::plans()[self::normalize($tier)]['per_install_concurrency'] ?? 1;

        return max(1, (int) $value);
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
     * @return array<string, array<string, mixed>>
     */
    private static function plans(): array
    {
        $plans = config('subtitles.tiers.plans', []);

        return is_array($plans) ? $plans : [];
    }
}
