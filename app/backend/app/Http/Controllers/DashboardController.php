<?php

namespace App\Http\Controllers;

use App\Models\SubtitleJob;
use App\Models\User;
use App\Services\Billing\BillingEntitlementService;
use App\Services\Billing\BillingPlanCatalog;
use App\Services\Billing\TestingPlanSwitcher;
use App\Services\Billing\UsageLedger;
use App\Services\Languages\LanguageCatalog;
use App\Services\Subtitles\SubtitleJobService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Laravel\Sanctum\PersonalAccessToken;

class DashboardController extends Controller
{
    public function __invoke(
        Request $request,
        BillingEntitlementService $billing,
        BillingPlanCatalog $plans,
        TestingPlanSwitcher $testingPlanSwitcher,
        UsageLedger $usage,
    ): View {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        return view('dashboard', [
            'user' => $user,
            'checkoutPlan' => $this->pendingCheckoutPlan($request, $user, $plans),
            'account' => $billing->accountSummary($user),
            'plans' => $plans->publicPlans(),
            'testingPlanSwitcherEnabled' => $testingPlanSwitcher->enabled(),
            'recentJobs' => $this->recentJobs($user, $usage),
            'extensionTokens' => $this->extensionTokens($user),
            'pageTitle' => 'Dashboard | '.config('marketing.product_name'),
            'metaDescription' => 'Account dashboard for '.config('marketing.product_name').'.',
            'canonicalUrl' => route('dashboard'),
            'robots' => 'noindex,nofollow',
            'bodyClass' => 'app-body',
        ]);
    }

    /**
     * Plan chosen on the marketing site before registration, pending Stripe checkout.
     *
     * @return array<string, mixed>|null
     */
    private function pendingCheckoutPlan(Request $request, User $user, BillingPlanCatalog $plans): ?array
    {
        if ($user->billing_subscription_status !== null) {
            $request->session()->forget('checkout_plan');

            return null;
        }

        return $plans->plan($request->session()->get('checkout_plan'));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function recentJobs(User $user, UsageLedger $usage): Collection
    {
        return SubtitleJob::query()
            ->with('track')
            ->whereBelongsTo($user)
            ->whereIn('processing_version', SubtitleJobService::currentProcessingVersions())
            ->latest('updated_at')
            ->limit(8)
            ->get()
            ->map(fn (SubtitleJob $job): array => [
                'jobId' => $job->public_id,
                'href' => route('dashboard.jobs.show', ['jobId' => $job->public_id]),
                'status' => (string) $job->status,
                'stage' => (string) $job->stage,
                'languagePair' => $this->languagePair($job),
                'minutes' => $usage->billableMinutes($job->video_duration_seconds),
                'updatedAt' => $job->updated_at->format('M j, Y H:i'),
            ]);
    }

    /**
     * @return Collection<int, array<string, string>>
     */
    private function extensionTokens(User $user): Collection
    {
        return $user->tokens()
            ->where('name', 'like', 'Chrome extension %')
            ->latest('created_at')
            ->limit(3)
            ->get(['id', 'name', 'last_used_at', 'expires_at', 'created_at'])
            ->map(fn (PersonalAccessToken $token): array => [
                'label' => $token->name,
                'createdAt' => $token->created_at->format('M j, Y H:i'),
                'lastUsedAt' => $token->last_used_at?->format('M j, Y H:i') ?? 'Not used yet',
                'expiresAt' => $token->expires_at?->format('M j, Y H:i') ?? 'No expiry',
            ]);
    }

    private function languagePair(SubtitleJob $job): string
    {
        return LanguageCatalog::label($job->effectiveSourceLanguage()).' to '.LanguageCatalog::label((string) $job->target_language);
    }
}
