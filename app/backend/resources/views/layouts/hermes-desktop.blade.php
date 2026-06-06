@php
    $title = $pageTitle ?? 'Hermes Desktop | Nous Research';
    $description = $metaDescription ?? 'The Agent That Grows With You.';
    $canonical = $canonicalUrl ?? url()->current();
    $robotsValue = $robots ?? 'index,follow';
    $socialImage = $socialImageUrl ?? asset('img/desktop/hero-art.webp');
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
        <meta property="og:site_name" content="Hermes Desktop">
        <meta property="og:type" content="website">
        <meta property="og:title" content="{{ $title }}">
        <meta property="og:description" content="{{ $description }}">
        <meta property="og:url" content="{{ $canonical }}">
        <meta property="og:image" content="{{ $socialImage }}">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="{{ $title }}">
        <meta name="twitter:description" content="{{ $description }}">
        <meta name="twitter:image" content="{{ $socialImage }}">
        <link rel="preconnect" href="https://cdn.jsdelivr.net">
        <link rel="preconnect" href="https://hermes-assets.nousresearch.com">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/geist@1/dist/fonts/geist-sans/style.css">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/geist@1/dist/fonts/geist-mono/style.css">
        <link rel="stylesheet" href="{{ asset('css/site.css') }}">
        <script defer src="{{ asset('js/hermes-desktop.js') }}"></script>
    </head>
    <body class="hermes-landing-body">
        @yield('content')
    </body>
</html>
