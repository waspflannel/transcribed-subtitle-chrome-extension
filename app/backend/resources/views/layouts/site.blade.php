@php
    $productName = (string) config('marketing.product_name');
    $title = $pageTitle ?? $productName;
    $description = $metaDescription ?? __('Generated AI subtitles and language-learning word cards for public YouTube videos.');
    $canonical = $canonicalUrl ?? url()->current();
    $robotsValue = $robots ?? 'index,follow';
    $bodyClassValue = $bodyClass ?? 'marketing-body';
    $headerClass = $headerClass ?? '';
    $socialImage = $socialImageUrl ?? null;
    $siteStylesheets = ['tokens', 'base', 'shell', 'ui', 'marketing', 'app', 'motion', 'responsive'];
@endphp
<!doctype html>
<html lang="{{ \App\Support\WebsiteLocale::languageTag() }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $title }}</title>
        <meta name="description" content="{{ $description }}">
        <meta name="robots" content="{{ $robotsValue }}">
        <link rel="canonical" href="{{ $canonical }}">
        @if (request()->routeIs('marketing.*', '*.marketing.*'))
            @foreach (config('localization.locales') as $code => $name)
                <link rel="alternate" hreflang="{{ \App\Support\WebsiteLocale::languageTag($code) }}" href="{{ \App\Support\WebsiteLocale::route(request()->route()->getName(), locale: $code) }}">
            @endforeach
            <link rel="alternate" hreflang="x-default" href="{{ \App\Support\WebsiteLocale::route(request()->route()->getName(), locale: 'en') }}">
        @endif
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
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,400..800&family=Schibsted+Grotesk:ital,wght@0,400..900;1,400..900&family=Spline+Sans+Mono:wght@400;500;700&display=swap" rel="stylesheet">
        @foreach ($siteStylesheets as $stylesheet)
            <link rel="stylesheet" href="{{ asset('css/site/'.$stylesheet.'.css') }}?v={{ filemtime(public_path('css/site/'.$stylesheet.'.css')) }}">
        @endforeach
        @stack('styles')
        <script defer src="{{ asset('js/site-interactions.js') }}?v={{ filemtime(public_path('js/site-interactions.js')) }}"></script>
        @stack('scripts')
    </head>
    <body class="@yield('body_class', $bodyClassValue)">
        <x-layout.skip-link />
        @stack('body_start')
        <header class="site-header @yield('header_class', $headerClass)" id="navbar">
            <a class="brand" href="{{ \App\Support\WebsiteLocale::route('marketing.home') }}" aria-label="{{ __(':product home', ['product' => $productName]) }}">{!! strtr(e(__(':slot1:Aa:slot2: :slot3::slot4::slot5:')), [':slot1:' => '<span class="brand-mark" aria-hidden="true">', ':slot2:' => '</span>', ':slot3:' => '<span>', ':slot4:' => e($productName), ':slot5:' => '</span>']) !!}</a>
            <nav class="site-nav" aria-label="{{ __('Primary') }}">
                <a href="{{ \App\Support\WebsiteLocale::route('marketing.home') }}#lyrics">{{ __('Lyrics') }}</a>
                <a href="{{ \App\Support\WebsiteLocale::route('marketing.home') }}#models">{{ __('Models') }}</a>
                <a href="{{ \App\Support\WebsiteLocale::route('marketing.home') }}#features">{{ __('Study tools') }}</a>
                <a href="{{ \App\Support\WebsiteLocale::route('marketing.how-to-use') }}" @if (request()->routeIs('marketing.how-to-use', '*.marketing.how-to-use')) aria-current="page" @endif>{{ __('How To Use') }}</a>
                <a href="{{ \App\Support\WebsiteLocale::route('marketing.home') }}#languages">{{ __('Languages') }}</a>
                <a href="{{ \App\Support\WebsiteLocale::route('marketing.home') }}#pricing">{{ __('Pricing') }}</a>
            </nav>
            <div class="site-actions">
                <x-locale-switcher />
                @auth
                    @if (request()->routeIs('dashboard'))
                        <form method="post" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="text-link">{{ __('Log out') }}</button>
                        </form>
                    @else
                        <a class="text-link" href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a>
                    @endif
                @else
                    <a class="text-link" href="{{ \App\Support\WebsiteLocale::route('login') }}">{{ __('Sign in') }}</a>
                    <a class="button button-accent button-small" href="{{ \App\Support\WebsiteLocale::route('marketing.home') }}#pricing">{{ __('Get started') }}</a>
                @endauth
            </div>
        </header>

        <main id="main-content" tabindex="-1">
            @yield('content')
        </main>

        <footer class="site-footer">
            <div>
                <strong>{{ $productName }}</strong>
                <p>{{ __('Learn from the videos and music you love. AI subtitles, interactive word cards, and lyrics correction for YouTube.') }}</p>
            </div>
            <nav aria-label="{{ __('Footer') }}">
                <a href="{{ \App\Support\WebsiteLocale::route('marketing.how-to-use') }}">{{ __('How To Use') }}</a>
                <a href="{{ \App\Support\WebsiteLocale::route('marketing.pricing') }}">{{ __('Pricing') }}</a>
                <a href="{{ \App\Support\WebsiteLocale::route('marketing.support') }}">{{ __('Support') }}</a>
                <a href="{{ \App\Support\WebsiteLocale::route('marketing.privacy') }}">{{ __('Privacy') }}</a>
                <a href="{{ \App\Support\WebsiteLocale::route('marketing.terms') }}">{{ __('Terms') }}</a>
            </nav>
        </footer>
    </body>
</html>
