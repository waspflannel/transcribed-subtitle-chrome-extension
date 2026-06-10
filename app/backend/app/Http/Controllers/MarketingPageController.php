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
    public function home(Request $request, FunnelAnalytics $analytics): View
    {
        return $this->marketingView($request, $analytics, 'home', 'marketing.home', [
            'landingFeatures' => $this->landingFeatures(),
        ]);
    }

    public function extension(Request $request, FunnelAnalytics $analytics): View
    {
        return $this->marketingView($request, $analytics, 'extension', 'marketing.extension');
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
        $productName = (string) config('marketing.product_name');

        $metadata = [
            'home' => [
                'title' => $productName,
                'description' => 'AI subtitles, translations, romanization, and word cards for public YouTube language study.',
                'route' => 'marketing.home',
            ],
            'extension' => [
                'title' => $productName.' Chrome extension',
                'description' => 'Download the beta Chrome extension for generated YouTube subtitles and language-learning word cards.',
                'route' => 'marketing.extension',
            ],
            'pricing' => [
                'title' => 'Pricing for '.$productName,
                'description' => 'Compare generated-video-minute plans, queue speed, concurrency, and learning features for the paid beta.',
                'route' => 'marketing.pricing',
            ],
            'languages' => [
                'title' => 'Language coverage for '.$productName,
                'description' => 'See supported subtitle and translation languages grouped by current transcription quality tier.',
                'route' => 'marketing.languages',
            ],
            'how-it-works' => [
                'title' => 'How '.$productName.' works',
                'description' => 'Learn how the Chrome extension sends public YouTube audio to the Laravel backend and returns synced subtitle tracks.',
                'route' => 'marketing.how-it-works',
            ],
            'faq' => [
                'title' => $productName.' FAQ',
                'description' => 'Answers about YouTube support, beta limits, billing, language coverage, generated tracks, and privacy.',
                'route' => 'marketing.faq',
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

    /**
     * @return array<int, array{eyebrow: string, title: string, description: string, image: string, alt: string, width: int, height: int}>
     */
    private function landingFeatures(): array
    {
        return [
            [
                'eyebrow' => '01 / Missing captions',
                'title' => 'Study any video',
                'description' => 'Turn missing or weak captions into useful study subtitles for public YouTube videos.',
                'image' => 'img/desktop/landing-study-any-video.webp',
                'alt' => 'Generated caption setup for a YouTube language study session',
                'width' => 1334,
                'height' => 1148,
            ],
            [
                'eyebrow' => '02 / Translation',
                'title' => 'Layered context',
                'description' => 'Add translation and romanization layers inside the player for immediate comprehension.',
                'image' => 'img/desktop/landing-layered-context.webp',
                'alt' => 'Caption translation and romanization controls',
                'width' => 1334,
                'height' => 1148,
            ],
            [
                'eyebrow' => '03 / Retention',
                'title' => 'Word cards',
                'description' => 'Open vocabulary cards from generated subtitles and inspect gloss, romanization, and usage notes.',
                'image' => 'img/desktop/landing-word-cards.webp',
                'alt' => 'Vocabulary word cards generated from subtitle cues',
                'width' => 1334,
                'height' => 1148,
            ],
            [
                'eyebrow' => '04 / History',
                'title' => 'Review recent jobs',
                'description' => 'Track recent generations, job status, and public-safe support IDs from the side panel and account dashboard.',
                'image' => 'img/desktop/landing-review-recent-jobs.webp',
                'alt' => 'Recent subtitle generation jobs and status history',
                'width' => 1334,
                'height' => 1148,
            ],
            [
                'eyebrow' => '05 / Timing',
                'title' => 'Sync the study flow',
                'description' => 'Adjust caption timing, density, size, contrast, and overlay position without leaving the video.',
                'image' => 'img/desktop/landing-sync-study-flow.webp',
                'alt' => 'Subtitle display controls for timing and caption layout',
                'width' => 1334,
                'height' => 1148,
            ],
            [
                'eyebrow' => '06 / Privacy',
                'title' => 'Clear boundaries',
                'description' => 'The extension sends the selected public video details only when you start generation.',
                'image' => 'img/desktop/landing-clear-boundaries.webp',
                'alt' => 'Privacy and account controls for subtitle generation',
                'width' => 1334,
                'height' => 1148,
            ],
        ];
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
