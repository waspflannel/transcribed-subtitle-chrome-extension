@extends('layouts.site')

@section('content')
    <section class="page-hero">
        <p class="eyebrow">How it works</p>
        <h1>From public YouTube audio to a synced study overlay.</h1>
        <p>
            The extension never calls AI providers directly. It sends one explicit request to the Laravel backend, which manages providers, billing, queues, and generated track retention.
        </p>
    </section>

    <section class="section timeline">
        <article>
            <span>01</span>
            <h2>Choose a public YouTube video</h2>
            <p>Only YouTube watch pages for public videos are in scope for beta. Live streams, private videos, other platforms, and subtitle editing are not supported.</p>
        </article>
        <article>
            <span>02</span>
            <h2>Start generation in the extension</h2>
            <p>You choose the subtitle/source language, translation/target language, and optional romanization or word-card depth before any video-derived audio or text leaves the browser.</p>
        </article>
        <article>
            <span>03</span>
            <h2>Backend processing runs through providers</h2>
            <p>The backend acquires temporary audio, sends it to configured AI services, validates generated cues and token data, deletes temporary raw audio, and keeps generated tracks for 30 days.</p>
        </article>
        <article>
            <span>04</span>
            <h2>Review captions in the YouTube page</h2>
            <p>The extension binds generated WebVTT to the page video and renders the language-learning overlay with public-safe job status for troubleshooting.</p>
        </article>
    </section>

    <section class="section split-section" id="install">
        <div>
            <p class="eyebrow">Install path</p>
            <h2>Install the Chrome extension, then connect a verified account.</h2>
            <p>
                During beta, use the install link supplied with your account invitation or support response. After installing, sign in from the extension Account tab with the same verified email.
            </p>
        </div>
        <div class="action-stack">
            @if (config('marketing.chrome_extension_url'))
                <a class="button" href="{{ config('marketing.chrome_extension_url') }}">Open Chrome listing</a>
            @else
                <a class="button" href="{{ route('marketing.support') }}#extension-install">Request install help</a>
            @endif
            <a class="button button-secondary" href="https://www.youtube.com" rel="noopener noreferrer">Open YouTube</a>
        </div>
    </section>
@endsection
