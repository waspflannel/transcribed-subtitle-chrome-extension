<?php

namespace App\Services\Analytics;

use App\Models\SubtitleJob;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class FunnelAnalytics
{
    public function marketingPageViewed(Request $request, string $page): void
    {
        $user = $request->user();

        Log::info('analytics.marketing_page_view', $this->withoutNulls([
            'page' => $page,
            'route' => $request->route()?->getName(),
            'path' => $request->path(),
            'interface_locale' => app()->getLocale(),
            'visitor_type' => $user instanceof User ? 'authenticated' : 'anonymous',
            'user_hash' => $user instanceof User ? $this->userHash($user) : null,
        ]));
    }

    public function signupCompleted(User $user): void
    {
        Log::info('analytics.signup_completed', [
            'user_hash' => $this->userHash($user),
            'interface_locale' => app()->getLocale(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    public function checkoutStarted(User $user, array $plan): void
    {
        Log::info('analytics.checkout_started', $this->withoutNulls([
            'user_hash' => $this->userHash($user),
            'plan_code' => $plan['code'] ?? null,
            'price_cents' => $plan['price_cents'] ?? null,
            'monthly_minutes' => $plan['monthly_minutes'] ?? null,
            'subscription_status' => $user->billing_subscription_status,
        ]));
    }

    /**
     * @param  array<string, mixed>  $account
     */
    public function extensionConnected(User $user, string $installId, array $account): void
    {
        Log::info('analytics.extension_connected', $this->withoutNulls([
            'user_hash' => $this->userHash($user),
            'install_hash' => $this->installHash($installId),
            'plan_name' => $account['planName'] ?? null,
            'email_verified' => $account['emailVerified'] ?? null,
        ]));
    }

    public function generationStarted(SubtitleJob $job, int $previousJobCount): void
    {
        $context = $this->withoutNulls([
            'user_hash' => $job->user instanceof User ? $this->userHash($job->user) : null,
            'job_id' => $job->public_id,
            'plan_code' => $job->user?->billing_plan_code,
            'generation_tier' => $job->generation_tier,
            'source_language' => $job->source_language,
            'target_language' => $job->target_language,
            'video_duration_seconds' => $job->video_duration_seconds,
            'include_romanization' => $job->include_romanization,
            'include_translation' => $job->include_translation,
            'previous_job_count' => $previousJobCount,
        ]);

        Log::info('analytics.subtitle_generation_started', $context);

        if ($previousJobCount === 0) {
            Log::info('analytics.first_generation_started', $context);

            return;
        }

        Log::info('analytics.retention_generation_started', $context);
    }

    private function userHash(User $user): string
    {
        return $this->logHash((string) $user->getKey());
    }

    private function installHash(string $installId): string
    {
        return $this->logHash($installId);
    }

    private function logHash(string $value): string
    {
        return substr(hash_hmac('sha256', $value, (string) config('app.key')), 0, 16);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function withoutNulls(array $context): array
    {
        return array_filter($context, static fn (mixed $value): bool => $value !== null);
    }
}
