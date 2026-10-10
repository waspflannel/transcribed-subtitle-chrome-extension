<?php

namespace App\Services\Subtitles;

use App\Exceptions\SubtitleProcessingException;
use App\Models\SubtitleJob;
use App\Services\InstanceSettings;
use Closure;
use Illuminate\Cache\Limiters\LimiterTimeoutException;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/** Bounds actual provider calls across HTTP and queue workers. */
final class ProviderAdmission
{
    public function run(string $provider, ?SubtitleJob $job, Closure $request): mixed
    {
        app(InstanceSettings::class)->requireProviderKey($provider);
        $cache = Cache::store(SubtitleQueue::concurrencyCacheStore());
        $lease = max((int) config('subtitles.providers.lease_seconds', 660),
            (int) config('subtitles.enrichment.timeout_seconds', 120) + 60,
            (int) config('subtitles.transcription.timeout_seconds', 600) + 60);
        $limit = max(1, (int) config('subtitles.providers.global_concurrency', 30));
        if ($provider === 'codex') {
            // Every Codex process shares one login file, so keep parallel sessions few.
            $limit = min($limit, max(1, (int) config('subtitles.providers.codex_concurrency', 3)));
        }
        if ($provider === 'claude') {
            // Each Claude call is a separate local CLI process sharing one config directory.
            $limit = min($limit, max(1, (int) config('subtitles.providers.claude_concurrency', 3)));
        }
        try {
            return $cache->funnel('subtitle-provider:concurrency:'.$provider.':')
                ->limit($limit)
                ->releaseAfter($lease)->block(0)->then(function () use ($cache, $provider, $job, $request): mixed {
                    $cache->lock('subtitle-provider:rate-lock', 10)->block(1, function () use ($cache, $provider): void {
                        $limiter = new RateLimiter($cache);
                        $key = 'subtitle-provider:provider:'.$provider;
                        $limit = max(1, (int) config('subtitles.enrichment.global_rate_limit_per_minute', 300));
                        if ($limiter->tooManyAttempts($key, $limit)) {
                            throw SubtitleProcessingException::rateLimited(context: ['reason' => 'provider_admission', 'retry_after_seconds' => $limiter->availableIn($key)]);
                        }
                        $limiter->hit($key, 60);
                    });
                    if ($job !== null && ! SubtitleJob::query()->whereKey($job->id)->where('run_id', $job->run_id)
                        ->whereIn('status', ['running', 'completed'])->exists()) {
                        throw new SubtitleProcessingException('generation_cancelled', 'Generation is no longer active.', 409, ['reason' => 'inactive_run']);
                    }

                    return $request();
                });
        } catch (LimiterTimeoutException|LockTimeoutException) {
            throw SubtitleProcessingException::rateLimited(context: ['reason' => 'provider_admission', 'retry_after_seconds' => max(1, (int) config('subtitles.providers.release_delay_seconds', 2))]);
        }
    }
}
