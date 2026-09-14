@extends('layouts.site')

@section('content')
    <section class="page-hero">
        <p class="eyebrow">Support</p>
        <h1>Help for beta access, billing, and generation failures.</h1>
        <p>
            Use public-safe job details from your dashboard when asking for help. Do not send generated transcript text unless support explicitly asks for a minimal excerpt.
        </p>
    </section>

    <section class="section split-section" id="extension-install">
        <div data-reveal>
            <h2>Contact</h2>
            <p>Email paid beta support at <a class="text-link strong-link" href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a>.</p>
            <p>Include your account email, plan, public-safe job ID, failure code, and what you expected to happen.</p>
        </div>
        <div data-reveal data-reveal-delay="100">
            <h2>Install help</h2>
            @if (config('marketing.chrome_extension_url'))
                <p><a class="strong-link" href="{{ config('marketing.chrome_extension_url') }}">Add the extension to desktop Chrome</a>, then create an account, verify your email, and choose a plan. Sign in to the extension with that same account.</p>
            @else
                <p>Before subscribing, email support to request the current extension package or Chrome Web Store beta link. Install it in desktop Chrome, create an account, verify your email, and choose a plan. Sign in to the extension with that same account.</p>
            @endif
            <p>An active subscription is required to generate subtitles. The website preview works without an account.</p>
        </div>
    </section>

    <section class="section support-grid" data-reveal>
        <article>
            <h2>Generation support</h2>
            <p>Share status, stage, timings, language pair, minute usage, and failure code from the job detail page.</p>
        </article>
        <article>
            <h2>Billing support</h2>
            <p>Use the dashboard billing portal for card and subscription changes. Contact support for refunds or invoice issues.</p>
        </article>
        <article>
            <h2>Language coverage</h2>
            <p>Report repeated accuracy issues with the language pair and public video context, but avoid sending full transcripts.</p>
        </article>
    </section>
@endsection
