@extends('layouts.site')

@section('content')
    <section class="page-hero legal-hero">
        <p class="eyebrow">Privacy</p>
        <h1>Video-derived data is handled only for the generation workflow.</h1>
        <p>
            This beta uses first-party account, billing, usage, support, and generation data to operate the service.
        </p>
    </section>

    <section class="section legal-copy" data-reveal>
        <h2>Processing scope</h2>
        <p>When you start generation, the extension sends the selected public YouTube URL, language choices, and feature controls to the Laravel backend. The backend may acquire temporary audio and send audio or generated text to configured AI providers for transcription, translation, romanization, and word-card metadata.</p>

        <h2>Retention</h2>
        <p>Temporary raw audio is deleted after processing succeeds or fails. Generated subtitle tracks are retained for 30 days so the extension can reuse recent work and support can inspect public-safe job status.</p>

        <h2>Analytics</h2>
        <p>Beta analytics are first-party Laravel structured logs. They capture page views and funnel events such as signup, checkout, extension connection, first generation, and repeat generation without transcripts, prompts, generated subtitles, YouTube URLs, provider payloads, tokens, raw install IDs, or raw audio paths.</p>

        <h2>Billing and account data</h2>
        <p>Account email, password hash, email verification state, Stripe customer and subscription identifiers, plan state, and minute-ledger rows are stored to run authentication, billing, and entitlement checks.</p>

        <h2>Support</h2>
        <p>Support may ask for a public-safe job ID, status, language pair, timing, and failure code. Do not send provider secrets, passwords, or private videos.</p>
    </section>
@endsection
