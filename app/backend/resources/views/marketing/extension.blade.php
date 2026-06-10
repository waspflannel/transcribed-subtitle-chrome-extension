@extends('layouts.site')

@section('content')
    <section class="page-hero">
        <p class="eyebrow">Chrome extension</p>
        <h1>Install Transcribed Subtitle Extension for YouTube study.</h1>
        <p>
            The beta extension adds generated subtitles, translations, romanization, and word-card review to public YouTube videos after you connect a verified account.
        </p>
    </section>

    <section class="section split-section" id="install">
        <div data-reveal>
            <p class="eyebrow">Install path</p>
            <h2>Connect the browser extension to your paid beta account.</h2>
            <p>
                Install the Chrome extension, sign in from the side panel Account tab, then open a public YouTube video and start generation from the extension controls.
            </p>
        </div>
        <div class="action-stack" data-reveal data-reveal-delay="100">
            @if (config('marketing.chrome_extension_url'))
                <a class="button" href="{{ config('marketing.chrome_extension_url') }}">Open Chrome listing</a>
            @else
                <a class="button" href="{{ route('marketing.support') }}#extension-install">Request install help</a>
            @endif
            <a class="button button-secondary" href="{{ route('marketing.how-it-works') }}#install">Review setup flow</a>
        </div>
    </section>

    <section class="section timeline" data-reveal>
        <article>
            <span>01</span>
            <h2>Open the side panel</h2>
            <p>Use the extension toolbar button or keyboard shortcut to focus the side panel while you stay on the YouTube watch page.</p>
        </article>
        <article>
            <span>02</span>
            <h2>Choose generation controls</h2>
            <p>Select source language, target language, romanization, and word-card depth before any video-derived data is sent to the backend.</p>
        </article>
        <article>
            <span>03</span>
            <h2>Review generated tracks</h2>
            <p>Generated tracks are synced back into the player and recent jobs stay available from the side panel and dashboard while retained.</p>
        </article>
    </section>
@endsection
