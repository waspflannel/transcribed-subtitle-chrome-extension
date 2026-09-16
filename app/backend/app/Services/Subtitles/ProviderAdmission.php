<?php

namespace App\Services\Subtitles;

use App\Exceptions\BillingEntitlementException;
use App\Exceptions\SubtitleProcessingException;
use App\Models\SubtitleJob;
use App\Models\User;
use App\Services\Billing\BillingEntitlementService;
use App\Services\Billing\BillingPlanCatalog;
use App\Services\Billing\UsageLedger;
use Closure;
use Illuminate\Cache\Limiters\LimiterTimeoutException;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/** Limits actual outbound calls, including retries, across HTTP and queue workers. */
final class ProviderAdmission
{
    public function run(string $provider, ?SubtitleJob $job, Closure $request): mixed
    {
        $accountLimit = $job?->user_id === null ? null : $this->accountLimit($job);
        $cache = Cache::store(SubtitleTier::concurrencyCacheStore());
        $lease = max(SubtitleTier::concurrencyCounterSeconds(), (int) config('subtitles.enrichment.timeout_seconds', 120) + 60,
            (int) config('subtitles.transcription.timeout_seconds', 600) + 60);
        $perform = function () use ($cache, $provider, $job, $request): mixed {
            $limits = ['provider:'.$provider => max(1, (int) config('subtitles.enrichment.global_rate_limit_per_minute', 300))];
            if ($job?->user_id !== null) {
                $limits['account:'.$job->user_id] = max(1, (int) config('subtitles.providers.account_requests_per_minute', 60));
            }
            $cache->lock('subtitle-provider:rate-lock', 10)->block(1, function () use ($cache, $limits): void {
                $limiter = new RateLimiter($cache);
                foreach ($limits as $key => $limit) {
                    if ($limiter->tooManyAttempts('subtitle-provider:'.$key, $limit)) {
                        throw SubtitleProcessingException::rateLimited(context: ['reason' => 'provider_admission', 'retry_after_seconds' => $limiter->availableIn('subtitle-provider:'.$key)]);
                    }
                }
                foreach ($limits as $key => $limit) {
                    $limiter->hit('subtitle-provider:'.$key, 60);
                }
            });

            if ($job !== null && $job->status !== 'completed') {
                app(UsageLedger::class)->startPaidGenerationWork($job);
            }

            return $request();
        };

        try {
            return $cache->funnel('subtitle-provider:concurrency:'.$provider.':')
                ->limit(max(1, (int) config('subtitles.providers.global_concurrency', 30)))
                ->releaseAfter($lease)->block(0)
                ->then(function () use ($cache, $job, $lease, $perform, $accountLimit): mixed {
                    if ($accountLimit === null) {
                        return $perform();
                    }

                    return $cache->funnel('subtitle-provider:account:'.$job->user_id.':')
                        ->limit($accountLimit)
                        ->releaseAfter($lease)->block(0)->then($perform);
                });
        } catch (LimiterTimeoutException|LockTimeoutException) {
            throw SubtitleProcessingException::rateLimited(context: ['reason' => 'provider_admission', 'retry_after_seconds' => SubtitleTier::concurrencyReleaseDelaySeconds()]);
        }
    }

    private function accountLimit(SubtitleJob $job): int
    {
        if ($job->status !== 'completed') {
            // Generation retains the tier admitted with its minute reservation.
            return SubtitleTier::batchConcurrency($job->generation_tier);
        }

        // New work on saved tracks follows the current account, including downgrades.
        $user = User::query()->find($job->user_id);
        $plan = $user === null ? null : app(BillingEntitlementService::class)->activePlan($user);
        if ($plan === null) {
            throw BillingEntitlementException::paymentRequired();
        }

        return SubtitleTier::batchConcurrency(app(BillingPlanCatalog::class)->generationTier($plan));
    }
}
