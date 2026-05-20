<?php

namespace App\Jobs\Middleware;

use App\Models\SubtitleJob;
use App\Services\Subtitles\SubtitleRuntimeTracer;
use App\Services\Subtitles\SubtitleTier;
use Closure;
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

        if (! $this->claimSlot($counterKey, $limit)) {
            $this->releaseQueuedJob($job);
            $this->traceDelay($subtitleJob, $tier, $limit);

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

    private function claimSlot(string $counterKey, int $limit): bool
    {
        $claimed = false;

        try {
            Cache::lock($counterKey.':lock', SubtitleTier::concurrencyLockSeconds())
                ->block(1, function () use ($counterKey, $limit, &$claimed): void {
                    $active = max(0, (int) Cache::get($counterKey, 0));

                    if ($active >= $limit) {
                        return;
                    }

                    Cache::put(
                        $counterKey,
                        $active + 1,
                        now()->addSeconds(SubtitleTier::concurrencyCounterSeconds()),
                    );
                    $claimed = true;
                });
        } catch (LockTimeoutException) {
            return false;
        }

        return $claimed;
    }

    private function releaseSlot(string $counterKey): void
    {
        try {
            Cache::lock($counterKey.':lock', SubtitleTier::concurrencyLockSeconds())
                ->block(1, function () use ($counterKey): void {
                    $active = max(0, (int) Cache::get($counterKey, 0) - 1);

                    if ($active === 0) {
                        Cache::forget($counterKey);

                        return;
                    }

                    Cache::put(
                        $counterKey,
                        $active,
                        now()->addSeconds(SubtitleTier::concurrencyCounterSeconds()),
                    );
                });
        } catch (LockTimeoutException) {
            Cache::forget($counterKey);
        }
    }

    private function releaseQueuedJob(object $job): void
    {
        if (method_exists($job, 'release')) {
            $job->release(SubtitleTier::concurrencyReleaseDelaySeconds());
        }
    }

    private function traceDelay(SubtitleJob $job, string $tier, int $limit): void
    {
        app(SubtitleRuntimeTracer::class)->jobEvent($job, 'queue.concurrency_delayed', [
            'stage' => $job->stage,
            'status' => $job->status,
            'generation_tier' => $tier,
            'concurrency_limit' => $limit,
            'release_delay_seconds' => SubtitleTier::concurrencyReleaseDelaySeconds(),
        ], 'warning');
    }

    private function counterKey(SubtitleJob $job): string
    {
        return 'subtitle-install-concurrency:'.hash('sha256', $job->install_id).':'.SubtitleTier::normalize($job->generation_tier);
    }
}
