@php
    $productName = (string) config('marketing.product_name');
    $siteCssPaths = [
        public_path('css/site.css'),
        ...glob(public_path('css/site/*.css')),
    ];
    $siteCssVersion = max(array_map(static fn (string $path): int => is_file($path) ? filemtime($path) : 0, $siteCssPaths));
@endphp
<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $productName }}</title>
        <meta name="robots" content="noindex,nofollow">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,600;1,400&family=IBM+Plex+Mono:wght@400;500&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="{{ asset('css/site.css') }}?v={{ $siteCssVersion }}">
        </head>
    <body class="auth-body">
        <x-layout.skip-link />
        <header class="auth-header">
            <a class="brand" href="{{ route('marketing.home') }}">
                <span class="brand-mark" aria-hidden="true">TS</span>
                <span>{{ $productName }}</span>
            </a>
        </header>
        <main class="auth-shell" id="main-content" tabindex="-1">
            <section class="auth-panel">
                @yield('content')
            </section>
        </main>
    </body>
</html>
