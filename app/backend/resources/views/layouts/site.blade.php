@php
    $productName = (string) config('marketing.product_name');
    $title = $pageTitle ?? $productName;
    $description = $metaDescription ?? __('Generated AI subtitles and language-learning word cards for public YouTube videos.');
    $canonical = $canonicalUrl ?? url()->current();
    $robotsValue = $robots ?? 'noindex,nofollow';
    $bodyClassValue = $bodyClass ?? 'marketing-body';
    $headerClass = $headerClass ?? '';
    $socialImage = $socialImageUrl ?? null;
    $siteStylesheets = ['tokens', 'base', 'shell', 'ui', 'motion', 'responsive'];
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
            <div class="site-actions">
                <x-locale-switcher />
            </div>
        </header>

        <main id="main-content" tabindex="-1">
            @yield('content')
        </main>

    </body>
</html>
