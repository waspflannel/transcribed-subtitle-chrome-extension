@extends('layouts.site')

@section('content')
    <section class="page-hero">
        <p class="eyebrow">Support</p>
        <h1>Report a bug. Get in touch.</h1>
        <p>
            Use public-safe job details from your dashboard when asking for help. Do not send generated transcript text unless support explicitly asks for a minimal excerpt.
        </p>
    </section>

    <section class="section split-section" id="extension-install">
        <div data-reveal>
            <h2>Contact</h2>
            <p>Email <a class="text-link strong-link" href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a> for bugs, questions, and account or billing help.</p>
            <p>Include what you were doing, what you expected, and what happened instead. For generation issues, include your account email, Support ID, and failure code from your dashboard.</p>
        </div>
        <div data-reveal data-reveal-delay="100">
            <h2>Looking for instructions?</h2>
            <p>Start with <a class="strong-link" href="{{ route('marketing.how-to-use') }}#how-to-install">How to install</a> for Chrome Web Store and manual installation steps.</p>
            <p>The <a class="strong-link" href="{{ route('marketing.how-to-use') }}">How To Use guide</a> covers generation, lyrics correction, single-word fixes, and study tools.</p>
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
