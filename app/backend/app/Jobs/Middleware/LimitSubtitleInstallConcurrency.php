<?php

namespace App\Jobs\Middleware;

use App\Models\SubtitleJob;
use App\Services\Subtitles\SubtitleRuntimeTracer;
use App\Services\Subtitles\SubtitleTier;
use Closure;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

final class LimitSubtitleInstallConcurrency
{
    public function handle(object $job, Closure $next): mixed
    {
        if ((string) config('queue.connections.'.(string) config('subtitles.queue.connection').'.driver') === 'sync') {
            return $next($job);
        }

        $subtitleJob = $this->subtitleJob($job);

        if ($subtitleJob === null) {
            return $next($job);
        }

        $tier = SubtitleTier::normalize($subtitleJob->generation_tier);
        $limit = SubtitleTier::perInstallConcurrency($tier);
        $counterKey = $this->counterKey($subtitleJob);
        $claim = $this->claimSlot($counterKey, $limit);

        if (! $claim['claimed']) {
            $this->releaseQueuedJob($job);
            $this->traceDelay($subtitleJob, $tier, $limit, $claim);

            return null;
        }

        try {
            return $next($job);
        } finally {
            $this->releaseSlot($counterKey);
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
     * @return array{claimed: bool, delay_reason: string|null, observed_active_count: int|null}
     */
    private function claimSlot(string $counterKey, int $limit): array
    {
        $cache = $this->cache();
        $claimed = false;
        $activeCount = null;

        try {
            $cache->lock($counterKey.':lock', SubtitleTier::concurrencyLockSeconds())
                ->block(1, function () use ($cache, $counterKey, $limit, &$claimed, &$activeCount): void {
                    $activeCount = max(0, (int) $cache->get($counterKey, 0));

                    if ($activeCount >= $limit) {
                        return;
                    }

                    $cache->put(
                        $counterKey,
                        $activeCount + 1,
                        now()->addSeconds(SubtitleTier::concurrencyCounterSeconds()),
                    );
                    $claimed = true;
                });
        } catch (LockTimeoutException) {
            return [
                'claimed' => false,
                'delay_reason' => 'lock_timeout',
                'observed_active_count' => $activeCount,
            ];
        }

        return [
            'claimed' => $claimed,
            'delay_reason' => $claimed ? null : 'limit_reached',
            'observed_active_count' => $activeCount,
        ];
    }

    private function releaseSlot(string $counterKey): void
    {
        $cache = $this->cache();

        try {
            $cache->lock($counterKey.':lock', SubtitleTier::concurrencyLockSeconds())
                ->block(1, function () use ($cache, $counterKey): void {
                    $active = max(0, (int) $cache->get($counterKey, 0) - 1);

                    if ($active === 0) {
                        $cache->forget($counterKey);

                        return;
                    }

                    $cache->put(
                        $counterKey,
                        $active,
                        now()->addSeconds(SubtitleTier::concurrencyCounterSeconds()),
                    );
                });
        } catch (LockTimeoutException) {
            $cache->forget($counterKey);
        }
    }

    private function releaseQueuedJob(object $job): void
    {
        if (method_exists($job, 'release')) {
            $job->release(SubtitleTier::concurrencyReleaseDelaySeconds());
        }
    }

    /**
     * @param  array{claimed: bool, delay_reason: string|null, observed_active_count: int|null}  $claim
     */
    private function traceDelay(SubtitleJob $job, string $tier, int $limit, array $claim): void
    {
        app(SubtitleRuntimeTracer::class)->jobEvent($job, 'queue.concurrency_delayed', [
            'stage' => $job->stage,
            'status' => $job->status,
            'generation_tier' => $tier,
            'delay_reason' => $claim['delay_reason'] ?? 'limit_reached',
            'cache_store' => SubtitleTier::concurrencyCacheStore(),
            'concurrency_limit' => $limit,
            'observed_active_count' => $claim['observed_active_count'],
            'release_delay_seconds' => SubtitleTier::concurrencyReleaseDelaySeconds(),
        ], 'warning');
    }

    private function counterKey(SubtitleJob $job): string
    {
        return 'subtitle-install-concurrency:'.hash('sha256', $job->install_id).':'.SubtitleTier::normalize($job->generation_tier);
    }

    private function cache(): Repository
    {
        return Cache::store(SubtitleTier::concurrencyCacheStore());
    }
}
