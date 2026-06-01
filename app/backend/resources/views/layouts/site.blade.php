@php
    $productName = (string) config('marketing.product_name');
    $title = $pageTitle ?? $productName;
    $description = $metaDescription ?? 'Generated AI subtitles and language-learning word cards for public YouTube videos.';
    $canonical = $canonicalUrl ?? url()->current();
    $robotsValue = $robots ?? 'index,follow';
    $bodyClassValue = $bodyClass ?? 'marketing-body';
    $socialImage = $socialImageUrl ?? null;
@endphp
<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $title }}</title>
        <meta name="description" content="{{ $description }}">
        <meta name="robots" content="{{ $robotsValue }}">
        <link rel="canonical" href="{{ $canonical }}">
        <meta property="og:site_name" content="{{ $productName }}">
        <meta property="og:type" content="website">
        <meta property="og:title" content="{{ $title }}">
        <meta property="og:description" content="{{ $description }}">
        <meta property="og:url" content="{{ $canonical }}">
        @if ($socialImage)
            <meta property="og:image" content="{{ $socialImage }}">
        @endif
        <meta name="twitter:card" content="{{ $socialImage ? 'summary_large_image' : 'summary' }}">
        <meta name="twitter:title" content="{{ $title }}">
        <meta name="twitter:description" content="{{ $description }}">
        @if ($socialImage)
            <meta name="twitter:image" content="{{ $socialImage }}">
        @endif
        <link rel="stylesheet" href="{{ asset('css/site.css') }}">
    </head>
    <body class="{{ $bodyClassValue }}">
        <header class="site-header">
            <a class="brand" href="{{ route('marketing.home') }}" aria-label="{{ $productName }} home">
                <span class="brand-mark" aria-hidden="true">CC</span>
                <span>{{ $productName }}</span>
            </a>
            <nav class="site-nav" aria-label="Primary">
                <a href="{{ route('marketing.how-it-works') }}">How it works</a>
                <a href="{{ route('marketing.languages') }}">Languages</a>
                <a href="{{ route('marketing.pricing') }}">Pricing</a>
                <a href="{{ route('marketing.faq') }}">FAQ</a>
                <a href="{{ route('marketing.support') }}">Support</a>
            </nav>
            <div class="site-actions">
                @auth
                    <a class="text-link" href="{{ route('dashboard') }}">Dashboard</a>
                @else
                    <a class="text-link" href="{{ route('login') }}">Sign in</a>
                    <a class="button button-small" href="{{ route('register') }}">Join paid beta</a>
                @endauth
            </div>
        </header>

        <main>
            @yield('content')
        </main>

        <footer class="site-footer">
            <div>
                <strong>{{ $productName }}</strong>
                <p>AI subtitles, translations, romanization, and word cards for public YouTube videos.</p>
            </div>
            <nav aria-label="Footer">
                <a href="{{ route('marketing.privacy') }}">Privacy</a>
                <a href="{{ route('marketing.terms') }}">Terms</a>
                <a href="{{ route('marketing.support') }}">Support</a>
                <a href="{{ route('robots') }}">Robots</a>
                <a href="{{ route('sitemap') }}">Sitemap</a>
            </nav>
        </footer>
    </body>
</html>
