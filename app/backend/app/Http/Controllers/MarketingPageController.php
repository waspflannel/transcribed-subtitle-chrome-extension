<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Analytics\FunnelAnalytics;
use App\Services\Billing\BillingEntitlementService;
use App\Services\Billing\BillingPlanCatalog;
use App\Services\Languages\LanguageCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class MarketingPageController extends Controller
{
    public function home(
        Request $request,
        FunnelAnalytics $analytics,
        BillingPlanCatalog $plans,
        BillingEntitlementService $billing,
    ): View {
        return $this->marketingView($request, $analytics, 'home', 'marketing.home', [
            'plans' => $plans->publicPlans(),
            'languageGroups' => $this->languageGroups(),
            'checkoutBlocked' => $this->checkoutBlocked($request, $billing),
        ]);
    }

    public function pricing(
        Request $request,
        FunnelAnalytics $analytics,
        BillingPlanCatalog $plans,
        BillingEntitlementService $billing,
    ): View {
        return $this->marketingView($request, $analytics, 'pricing', 'marketing.pricing', [
            'plans' => $plans->publicPlans(),
            'checkoutBlocked' => $this->checkoutBlocked($request, $billing),
        ]);
    }

    public function privacy(Request $request, FunnelAnalytics $analytics): View
    {
        return $this->marketingView($request, $analytics, 'privacy', 'marketing.privacy');
    }

    public function terms(Request $request, FunnelAnalytics $analytics): View
    {
        return $this->marketingView($request, $analytics, 'terms', 'marketing.terms');
    }

    public function support(Request $request, FunnelAnalytics $analytics): View
    {
        return $this->marketingView($request, $analytics, 'support', 'marketing.support', [
            'supportEmail' => (string) config('marketing.support_email'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function marketingView(
        Request $request,
        FunnelAnalytics $analytics,
        string $page,
        string $view,
        array $data = [],
    ): View {
        $analytics->marketingPageViewed($request, $page);

        return view($view, [
            ...$this->metadata($page),
            ...$data,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function metadata(string $page): array
    {
        $productName = (string) config('marketing.product_name');

        $metadata = [
            'home' => [
                'title' => $productName,
                'description' => 'AI subtitles, translations, romanization, and word cards for public YouTube language study.',
                'route' => 'marketing.home',
            ],
            'pricing' => [
                'title' => 'Pricing for '.$productName,
                'description' => 'Compare generated-video-minute plans, queue speed, concurrency, and learning features for the paid beta.',
                'route' => 'marketing.pricing',
            ],
            'privacy' => [
                'title' => 'Privacy for '.$productName,
                'description' => 'How public YouTube audio, transcripts, generated tracks, providers, retention, and analytics are handled.',
                'route' => 'marketing.privacy',
            ],
            'terms' => [
                'title' => 'Terms for '.$productName,
                'description' => 'Beta terms covering subscriptions, refunds, support, acceptable use, AI limitations, and service availability.',
                'route' => 'marketing.terms',
            ],
            'support' => [
                'title' => 'Support for '.$productName,
                'description' => 'Get help with beta access, extension setup, billing, refunds, generation failures, and language coverage.',
                'route' => 'marketing.support',
            ],
        ][$page];

        return [
            'pageTitle' => $metadata['title'],
            'metaDescription' => $metadata['description'],
            'canonicalUrl' => route($metadata['route']),
        ];
    }

    private function checkoutBlocked(Request $request, BillingEntitlementService $billing): bool
    {
        $user = $request->user();

        return $user instanceof User && $billing->subscriptionRequiresPortal($user);
    }

    /**
     * @return array<string, array{label: string, description: string, languages: Collection<int, array{code: string, label: string, tier: string}>}>
     */
    private function languageGroups(): array
    {
        $labels = [
            'excellent' => [
                'label' => 'Excellent',
                'description' => 'Best current fit for transcription quality and beta expectations.',
            ],
            'high' => [
                'label' => 'High accuracy',
                'description' => 'Strong coverage with normal AI transcription caveats.',
            ],
            'good' => [
                'label' => 'Good',
                'description' => 'Useful for study workflows, with more room for correction.',
            ],
            'moderate' => [
                'label' => 'Moderate',
                'description' => 'Available for beta feedback, but accuracy can vary more by speaker and audio.',
            ],
        ];

        $languages = collect(LanguageCatalog::supportedLanguages())
            ->groupBy('tier')
            ->map(fn (Collection $items): Collection => $items->sortBy('label')->values());

        $groups = [];

        foreach ($labels as $tier => $copy) {
            $groups[$tier] = [
                ...$copy,
                'languages' => $languages->get($tier, collect()),
            ];
        }

        return $groups;
    }
}
