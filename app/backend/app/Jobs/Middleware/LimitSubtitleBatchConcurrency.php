<?php

namespace App\Jobs\Middleware;

use App\Models\SubtitleJob;
use App\Services\Subtitles\SubtitleQueue;
use App\Services\Subtitles\SubtitleRuntimeTracer;
use App\Services\Subtitles\SubtitleTier;
use Closure;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class LimitSubtitleBatchConcurrency
{
    public function handle(object $job, Closure $next): mixed
    {
        if ((string) config('queue.connections.'.(string) config('subtitles.queue.connection').'.driver') === 'sync') {
            return $next($job);
        }

        $subtitleJob = $this->subtitleJob($job);

        if ($subtitleJob === null || $subtitleJob->user_id === null) {
            return $next($job);
        }

        $tier = SubtitleTier::normalize($subtitleJob->generation_tier);
        $limit = SubtitleTier::batchConcurrency($tier);
        $counterKey = $this->counterKey($subtitleJob, $tier);
        $claim = $this->claimSlot($counterKey, $limit);

        if (! $claim['claimed']) {
            $this->releaseQueuedJob($job);
            $this->traceDelay($subtitleJob, $tier, $limit, $claim);

            return null;
        }

        try {
            return $next($job);
        } finally {
            $this->releaseSlot($counterKey, $claim['token']);
        }
    }

    private function subtitleJob(object $job): ?SubtitleJob
    {
        if (! property_exists($job, 'subtitleJobId') || ! is_int($job->subtitleJobId)) {
            return null;
        }

        return SubtitleJob::query()->find($job->subtitleJobId);
    }

    /**
     * @return array{claimed: bool, token: string|null, delay_reason: string|null, observed_active_count: int|null}
     */
    private function claimSlot(string $counterKey, int $limit): array
    {
        $cache = $this->cache();
        $claimed = false;
        $token = null;
        $activeCount = null;

        try {
            $cache->lock($counterKey.':lock', SubtitleTier::concurrencyLockSeconds())
                ->block(1, function () use ($cache, $counterKey, $limit, &$claimed, &$token, &$activeCount): void {
                    $slots = $this->evictExpired($this->readSlots($cache, $counterKey));
                    $activeCount = count($slots);

                    if ($activeCount >= $limit) {
                        return;
                    }

                    $token = (string) Str::uuid();
                    $slots[] = [
                        'token' => $token,
                        'expiresAt' => time() + SubtitleTier::concurrencyCounterSeconds(),
                    ];
                    $this->writeSlots($cache, $counterKey, $slots);
                    $claimed = true;
                });
        } catch (LockTimeoutException) {
            return [
                'claimed' => false,
                'token' => null,
                'delay_reason' => 'lock_timeout',
                'observed_active_count' => $activeCount,
            ];
        }

        return [
            'claimed' => $claimed,
            'token' => $token,
            'delay_reason' => $claimed ? null : 'limit_reached',
            'observed_active_count' => $activeCount,
        ];
    }

    private function releaseSlot(string $counterKey, string $token): void
    {
        $cache = $this->cache();

        try {
            $cache->lock($counterKey.':lock', SubtitleTier::concurrencyLockSeconds())
                ->block(1, function () use ($cache, $counterKey, $token): void {
                    $slots = $this->readSlots($cache, $counterKey);

                    if ($slots === []) {
                        $cache->forget($counterKey);

                        return;
                    }

                    $remaining = array_values(array_filter(
                        $slots,
                        fn (array $slot): bool => $slot['token'] !== $token,
                    ));

                    // Token not present (already evicted by deadline or never
                    // claimed): treat as a no-op so a dead worker's late
                    // release cannot refresh or corrupt other live slots.
                    if (count($remaining) === count($slots)) {
                        return;
                    }

                    $this->writeSlots($cache, $counterKey, $remaining);
                });
        } catch (LockTimeoutException) {
            // Best-effort release; the leaked token ages out at its own
            // absolute deadline regardless of other activity.
        }
    }

    private function releaseQueuedJob(object $job): void
    {
        if (method_exists($job, 'release')) {
            $job->release(SubtitleTier::concurrencyReleaseDelaySeconds());
        }
    }

    /**
     * @param  array{claimed: bool, token: string|null, delay_reason: string|null, observed_active_count: int|null}  $claim
     */
    private function traceDelay(SubtitleJob $job, string $tier, int $limit, array $claim): void
    {
        app(SubtitleRuntimeTracer::class)->jobEvent($job, 'queue.concurrency_delayed', [
            'stage' => $job->stage,
            'status' => $job->status,
            'queue_family' => SubtitleQueue::FAMILY_BATCH,
            'limiter_type' => 'ai_batch',
            'generation_tier' => $tier,
            'delay_reason' => $claim['delay_reason'] ?? 'limit_reached',
            'cache_store' => SubtitleTier::concurrencyCacheStore(),
            'concurrency_limit' => $limit,
            'observed_active_count' => $claim['observed_active_count'],
            'release_delay_seconds' => SubtitleTier::concurrencyReleaseDelaySeconds(),
        ], 'warning');
    }

    private function counterKey(SubtitleJob $job, string $tier): string
    {
        return 'subtitle-concurrency:ai-batch:'.hash('sha256', (string) $job->user_id).':'.$tier;
    }

    /**
     * @return array<int, array{token: string, expiresAt: int}>
     */
    private function readSlots(Repository $cache, string $counterKey): array
    {
        $raw = $cache->get($counterKey);

        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return [];
        }

        $slots = [];

        foreach ($decoded as $slot) {
            if (! is_array($slot) || ! array_key_exists('token', $slot) || ! array_key_exists('expiresAt', $slot)) {
                continue;
            }

            $slots[] = [
                'token' => (string) $slot['token'],
                'expiresAt' => (int) $slot['expiresAt'],
            ];
        }

        return $slots;
    }

    /**
     * @param  array<int, array{token: string, expiresAt: int}>  $slots
     */
    private function writeSlots(Repository $cache, string $counterKey, array $slots): void
    {
        if ($slots === []) {
            $cache->forget($counterKey);

            return;
        }

        $cache->put(
            $counterKey,
            json_encode($slots, JSON_THROW_ON_ERROR),
            now()->addSeconds(SubtitleTier::concurrencyCounterSeconds()),
        );
    }

    /**
     * @param  array<int, array{token: string, expiresAt: int}>  $slots
     * @return array<int, array{token: string, expiresAt: int}>
     */
    private function evictExpired(array $slots): array
    {
        $now = time();

        return array_values(array_filter(
            $slots,
            fn (array $slot): bool => $slot['expiresAt'] > $now,
        ));
    }

    private function cache(): Repository
    {
        return Cache::store(SubtitleTier::concurrencyCacheStore());
    }
}
