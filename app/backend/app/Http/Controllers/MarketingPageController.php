<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Analytics\FunnelAnalytics;
use App\Services\Billing\BillingEntitlementService;
use App\Services\Billing\BillingPlanCatalog;
use App\Services\Languages\LanguageCatalog;
use App\Support\WebsiteLocale;
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
            'supportedLanguages' => $this->supportedLanguages(),
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

    public function howToUse(Request $request, FunnelAnalytics $analytics): View
    {
        return $this->marketingView($request, $analytics, 'how-to-use', 'marketing.how-to-use');
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
                'title' => __('Learn with YouTube videos and music | :product', ['product' => $productName]),
                'description' => __('Learn languages with AI subtitles for YouTube. Choose Transcriber or Transcriber-Spark, bring your own lyrics, and explore translations, pronunciation, and word cards.'),
                'route' => 'marketing.home',
            ],
            'pricing' => [
                'title' => __('Pricing for :product', ['product' => $productName]),
                'description' => __('Compare monthly video minutes and generation capacity. Every plan includes model choice, interactive word cards, and lyrics correction.'),
                'route' => 'marketing.pricing',
            ],
            'privacy' => [
                'title' => __('Privacy for :product', ['product' => $productName]),
                'description' => __('How we handle account data, public YouTube audio, pasted lyrics, AI providers, cookies, and saved tracks, plus your account deletion and privacy choices.'),
                'route' => 'marketing.privacy',
            ],
            'how-to-use' => [
                'title' => __('How To Use | :product', ['product' => $productName]),
                'description' => __('Install the Chrome extension, generate subtitles, replace lyrics, fix a word, and use translations, word cards, study tools, and saved generations.'),
                'route' => 'marketing.how-to-use',
            ],
            'terms' => [
                'title' => __('Terms for :product', ['product' => $productName]),
                'description' => __('Terms for monthly subscriptions, video minutes, cancellation, refunds, lyrics correction, content rights, and AI limitations.'),
                'route' => 'marketing.terms',
            ],
            'support' => [
                'title' => __('Support for :product', ['product' => $productName]),
                'description' => __('Report bugs or get help with extension setup, billing, refunds, generation failures, and language coverage.'),
                'route' => 'marketing.support',
            ],
        ][$page];

        return [
            'pageTitle' => $metadata['title'],
            'metaDescription' => $metadata['description'],
            'canonicalUrl' => WebsiteLocale::route($metadata['route']),
        ];
    }

    private function checkoutBlocked(Request $request, BillingEntitlementService $billing): bool
    {
        $user = $request->user();

        return $user instanceof User && $billing->subscriptionRequiresPortal($user);
    }

    /**
     * @return Collection<int, array{code: string, label: string, tier: string}>
     */
    private function supportedLanguages(): Collection
    {
        return collect(LanguageCatalog::supportedLanguages())
            ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }
}
