@extends('layouts.site')

@section('body_class', 'hermes-landing-body marketing-body')

@section('content')
<div class="da-main" id="home">
    <!-- HERO -->
    <section class="da-hero">
        <img class="hermes-hero-texture" src="{{ asset('img/desktop/filler-bg0.webp') }}" alt="" aria-hidden="true">
        <div class="hermes-container da-hero-layered-content">
            <div class="da-hero-visual-bg">
                <img src="{{ asset('img/desktop/hero-art.webp') }}" alt="">
            </div>
            <div class="da-hero-text-overlay">
                <div class="da-eyebrow">learn languages using youtube</div>
                <h1 class="da-h1-overlay">
                    <span>TRANSCRIBED</span>
                    <span>SUBTITLE</span>
                    <span>EXTENSION</span>
                </h1>
                <p class="da-hero-lede-center">Turn any public YouTube video into a language learning masterclass. AI-powered transcription and translation layers built for serious learners.</p>
                <a href="#download" class="da-button">
                    <span class="da-icon">↓</span> Download Extension [Beta]
                </a>
            </div>
        </div>
    </section>

    <!-- VIDEO/PREVIEW -->
    <section class="da-preview">
        <div class="da-preview-frame" data-reveal>
             <img src="{{ asset('img/desktop/filler-bg0.webp') }}" alt="Product Preview">
             <div class="da-preview-bar">PRODUCT DEMO — BETA PREVIEW</div>
        </div>
    </section>

    <!-- FEATURES -->
    <section class="da-features">
        <div class="feature-row" data-reveal>
            <div class="feature-row-inner">
                <div class="feature-text">
                    <span class="feature-num">01 / Missing captions</span>
                    <h2>Study any video</h2>
                    <p>Turns missing or poor captions into useful study subtitles. One click, every video.</p>
                </div>
                <div class="feature-media">
                    <img src="{{ asset('img/desktop/feature-connect.webp') }}" alt="Missing captions">
                </div>
            </div>
        </div>
        <div class="feature-row" data-reveal>
            <div class="feature-row-inner">
                <div class="feature-text">
                    <span class="feature-num">02 / Translation</span>
                    <h2>Layered context</h2>
                    <p>Injects translation and romanization layers directly into the player for immediate comprehension.</p>
                </div>
                <div class="feature-media">
                    <img src="{{ asset('img/desktop/feature-memory.webp') }}" alt="Translation layer">
                </div>
            </div>
        </div>
        <div class="feature-row" data-reveal>
            <div class="feature-row-inner">
                <div class="feature-text">
                    <span class="feature-num">03 / Retention</span>
                    <h2>Word cards</h2>
                    <p>Vocabulary word cards generated from video subtitles — automatic flashcards for spaced repetition.</p>
                </div>
                <div class="feature-media">
                    <img src="{{ asset('img/desktop/feature-tasks.webp') }}" alt="Word cards">
                </div>
            </div>
        </div>
        <div class="feature-row" data-reveal>
            <div class="feature-row-inner">
                <div class="feature-text">
                    <span class="feature-num">04 / Export</span>
                    <h2>Take it with you</h2>
                    <p>Export your learned vocabulary to Anki, CSV, or Notion. Own your progress data.</p>
                </div>
                <div class="feature-media">
                    <img src="{{ asset('img/desktop/feature-automation.webp') }}" alt="Export">
                </div>
            </div>
        </div>
        <div class="feature-row" data-reveal>
            <div class="feature-row-inner">
                <div class="feature-text">
                    <span class="feature-num">05 / Offline</span>
                    <h2>Read anywhere</h2>
                    <p>Download full transcripts as PDF or text and study along without an internet connection.</p>
                </div>
                <div class="feature-media">
                    <img src="{{ asset('img/desktop/feature-browse.webp') }}" alt="Read anywhere">
                </div>
            </div>
        </div>
        <div class="feature-row" data-reveal>
            <div class="feature-row-inner">
                <div class="feature-text">
                    <span class="feature-num">06 / Privacy</span>
                    <h2>Local processing</h2>
                    <p>Your watch history stays private; transcriptions run securely on managed infrastructure.</p>
                </div>
                <div class="feature-media">
                    <img src="{{ asset('img/desktop/feature-sandbox.webp') }}" alt="Privacy">
                </div>
            </div>
        </div>
    </section>

    <!-- FINAL CTA -->
    <section class="da-final-cta" id="download">
        <div class="da-final-content">
            <div class="da-final-copy" data-reveal>
                <div class="da-eyebrow">Download Extension</div>
                <h2 class="da-h2">BRING THE READING ROOM<br>TO EVERY VIDEO</h2>
                <p class="da-feature-desc">Join the paid beta for YouTube language learners. Professional subtitles, instant word cards, and persistent memory.</p>
                <a href="#download" class="da-button">
                    <span class="da-icon">↓</span> Get Started Now
                </a>
            </div>
            <div class="da-final-image" data-reveal data-reveal-delay="120">
                <img src="{{ asset('img/desktop/portal-figure.webp') }}" alt="Transcribed Subtitle Extension study figure">
            </div>
        </div>
    </section>
</div>
@endsection
