@php
    $productName = (string) config('marketing.product_name');
    $siteCssPaths = [
        public_path('css/site.css'),
        ...glob(public_path('css/site/*.css')),
    ];
    $siteCssVersion = max(array_map(static fn (string $path): int => is_file($path) ? filemtime($path) : 0, $siteCssPaths));
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $productName }}</title>
        <meta name="robots" content="noindex,nofollow">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,400..800&family=Schibsted+Grotesk:ital,wght@0,400..900;1,400..900&family=Spline+Sans+Mono:wght@400;500;700&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="{{ asset('css/site.css') }}?v={{ $siteCssVersion }}">
    </head>
    <body class="auth-body">
        <x-layout.skip-link />
        <header class="auth-header">
            <x-locale-switcher />
            <a class="brand" href="{{ \App\Support\WebsiteLocale::route('marketing.home') }}">{!! strtr(e(__(':slot1:Aa:slot2: :slot3::slot4::slot5:')), [':slot1:' => '<span class="brand-mark" aria-hidden="true">', ':slot2:' => '</span>', ':slot3:' => '<span>', ':slot4:' => e($productName), ':slot5:' => '</span>']) !!}</a>
        </header>
        <main class="auth-shell" id="main-content" tabindex="-1">
            <section class="auth-panel">
                @yield('content')
            </section>
        </main>
    </body>
</html>
