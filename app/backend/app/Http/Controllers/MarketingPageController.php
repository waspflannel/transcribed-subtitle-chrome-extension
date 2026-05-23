<?php

namespace App\Http\Controllers;

use App\Services\Analytics\FunnelAnalytics;
use App\Services\Billing\BillingPlanCatalog;
use App\Services\Languages\LanguageCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class MarketingPageController extends Controller
{
    public function home(Request $request, FunnelAnalytics $analytics, BillingPlanCatalog $plans): View
    {
        return $this->marketingView($request, $analytics, 'home', 'marketing.home', [
            'plans' => $plans->publicPlans(),
            'featuredLanguages' => $this->featuredLanguages(),
        ]);
    }

    public function pricing(Request $request, FunnelAnalytics $analytics, BillingPlanCatalog $plans): View
    {
        return $this->marketingView($request, $analytics, 'pricing', 'marketing.pricing', [
            'plans' => $plans->publicPlans(),
        ]);
    }

    public function languages(Request $request, FunnelAnalytics $analytics): View
    {
        return $this->marketingView($request, $analytics, 'languages', 'marketing.languages', [
            'languageGroups' => $this->languageGroups(),
        ]);
    }

    public function howItWorks(Request $request, FunnelAnalytics $analytics): View
    {
        return $this->marketingView($request, $analytics, 'how-it-works', 'marketing.how-it-works');
    }

    public function faq(Request $request, FunnelAnalytics $analytics): View
    {
        return $this->marketingView($request, $analytics, 'faq', 'marketing.faq');
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
        $metadata = [
            'home' => [
                'title' => 'AI Language Subtitles for YouTube language learners',
                'description' => 'Generate study-ready subtitles, translations, romanization, and word cards for public YouTube videos.',
                'route' => 'marketing.home',
            ],
            'pricing' => [
                'title' => 'Pricing for AI Language Subtitles',
                'description' => 'Compare generated-video-minute plans, queue speed, concurrency, and learning features for the paid beta.',
                'route' => 'marketing.pricing',
            ],
            'languages' => [
                'title' => 'Language coverage for AI subtitles',
                'description' => 'See supported subtitle and translation languages grouped by current transcription quality tier.',
                'route' => 'marketing.languages',
            ],
            'how-it-works' => [
                'title' => 'How AI Language Subtitles works',
                'description' => 'Learn how the Chrome extension sends public YouTube audio to the Laravel backend and returns synced subtitle tracks.',
                'route' => 'marketing.how-it-works',
            ],
            'faq' => [
                'title' => 'AI Language Subtitles FAQ',
                'description' => 'Answers about YouTube support, beta limits, billing, language coverage, generated tracks, and privacy.',
                'route' => 'marketing.faq',
            ],
            'privacy' => [
                'title' => 'Privacy for AI Language Subtitles',
                'description' => 'How public YouTube audio, transcripts, generated tracks, providers, retention, and analytics are handled.',
                'route' => 'marketing.privacy',
            ],
            'terms' => [
                'title' => 'Terms for AI Language Subtitles',
                'description' => 'Beta terms covering subscriptions, refunds, support, acceptable use, AI limitations, and service availability.',
                'route' => 'marketing.terms',
            ],
            'support' => [
                'title' => 'Support for AI Language Subtitles',
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

    /**
     * @return array<int, string>
     */
    private function featuredLanguages(): array
    {
        return array_map(
            static fn (string $code): string => LanguageCatalog::label($code),
            ['spa', 'jpn', 'fra', 'deu', 'kor', 'cmn', 'ara', 'por'],
        );
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
