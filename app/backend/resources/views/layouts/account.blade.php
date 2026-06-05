@php
    $productName = (string) config('marketing.product_name');
@endphp
<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $productName }}</title>
        <meta name="robots" content="noindex,nofollow">
        <link rel="stylesheet" href="{{ asset('css/site.css') }}">
    </head>
    <body class="auth-body">
        <header class="auth-header">
            <a class="brand" href="{{ route('marketing.home') }}">
                <span class="brand-mark" aria-hidden="true">TS</span>
                <span>{{ $productName }}</span>
            </a>
        </header>
        <main class="auth-shell">
            <section class="auth-panel">
                @yield('content')
            </section>
        </main>
    </body>
</html>
