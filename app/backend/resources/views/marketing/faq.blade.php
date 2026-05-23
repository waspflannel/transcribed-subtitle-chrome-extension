@extends('layouts.site')

@section('content')
    <section class="page-hero">
        <p class="eyebrow">FAQ</p>
        <h1>Beta answers without hidden product promises.</h1>
        <p>
            AI Language Subtitles focuses on public YouTube videos, generated subtitle tracks, translations, romanization, and word-card study data.
        </p>
    </section>

    <section class="section faq-list">
        <details open>
            <summary>Which platforms are supported?</summary>
            <p>Only public YouTube watch pages are supported for beta. Netflix, private videos, playlists as a source, live captions, and other platforms are out of scope.</p>
        </details>
        <details>
            <summary>Do I need to start generation manually?</summary>
            <p>Yes. The extension waits for an explicit Generate action before sending the selected video URL and generation controls to the backend.</p>
        </details>
        <details>
            <summary>What happens to audio and transcripts?</summary>
            <p>Temporary raw audio is used for backend processing and deleted after success or failure. Generated tracks are retained for 30 days for reuse and support.</p>
        </details>
        <details>
            <summary>Can AI subtitles be wrong?</summary>
            <p>Yes. Accuracy depends on the language, audio, speaker, and provider output. The product is a study aid, not an official caption source.</p>
        </details>
        <details>
            <summary>How do refunds work during beta?</summary>
            <p>Contact support when billing or generation failures prevent reasonable use. Refund decisions are handled case by case for the paid beta.</p>
        </details>
    </section>
@endsection
