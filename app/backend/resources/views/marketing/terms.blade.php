@extends('layouts.site')

@section('content')
    <section class="page-hero legal-hero">
        <p class="eyebrow">Terms</p>
        <h1>Paid beta terms for Transcribed Subtitle Extension.</h1>
        <p>
            These terms describe the beta limits that matter before public launch. They are product copy for review, not a substitute for legal counsel.
        </p>
    </section>

    <section class="section legal-copy" data-reveal>
        <h2>Beta service</h2>
        <p>Transcribed Subtitle Extension is a beta Chrome extension and Laravel web app for generating study-oriented subtitle tracks for public YouTube videos. Availability, speed, quality, language accuracy, and provider behavior can change during beta.</p>

        <h2>Subscriptions and usage</h2>
        <p>Plans include monthly generated-video-minute credits, queue behavior, and feature gates shown on the pricing page. Running jobs can reserve minutes before provider work completes. Failed jobs release unused reservations when no completed track is produced.</p>

        <h2>Refunds</h2>
        <p>Refund requests should go through support. Beta refunds are considered when billing, access, or generation failures prevent reasonable use of the subscription.</p>

        <h2>Acceptable use</h2>
        <p>Use the product only with public YouTube videos you are allowed to access. Do not attempt to bypass account, billing, rate-limit, provider, or platform restrictions.</p>

        <h2>AI limitations</h2>
        <p>Generated subtitles, translations, romanization, and word cards can be incomplete or incorrect. The product is a learning aid and does not provide official captions, professional translation, or accessibility compliance.</p>
    </section>
@endsection
