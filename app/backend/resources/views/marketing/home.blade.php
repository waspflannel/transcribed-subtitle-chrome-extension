@extends('layouts.site')

@section('body_class', 'hermes-landing-body marketing-body')

@push('body_start')
    <img
        class="site-texture"
        src="{{ asset('img/desktop/filler-bg0.webp') }}"
        alt=""
        aria-hidden="true"
        width="1444"
        height="1444"
        loading="lazy"
        decoding="async"
    >
@endpush

@section('content')
<div class="da-main" id="home">
    <!-- HERO -->
    <section class="da-hero">
        <div class="hermes-container da-hero-layered-content">
            <div class="da-hero-visual-bg">
                <img
                    src="{{ asset('img/desktop/hero-art.webp') }}"
                    alt=""
                    width="1129"
                    height="1418"
                    fetchpriority="high"
                    decoding="async"
                >
            </div>
            <div class="da-hero-text-overlay">
                <div class="da-eyebrow">learn languages using youtube</div>
                <h1 class="da-h1-overlay">
                    <span>TRANSCRIBED</span>
                    <span>SUBTITLE</span>
                    <span>EXTENSION</span>
                </h1>
                <p class="da-hero-lede-center">Turn any public YouTube video into a language learning masterclass. AI-powered transcription and translation layers built for serious learners.</p>
                <a href="#download" class="da-button">Download Extension [Beta]</a>
            </div>
        </div>
    </section>

    <!-- VIDEO/PREVIEW -->
    <section class="da-preview">
        <div class="da-preview-frame" data-reveal>
             <img
                src="{{ asset('img/desktop/filler-bg0.webp') }}"
                alt="Preview frame for generated subtitles and study controls"
                width="1444"
                height="1444"
                loading="lazy"
                decoding="async"
            >
             <div class="da-preview-bar">PRODUCT DEMO - BETA PREVIEW</div>
        </div>
    </section>

    <!-- FEATURES -->
    <section class="da-features">
        @foreach ($landingFeatures as $feature)
            <x-marketing.feature-row :feature="$feature" />
        @endforeach
    </section>

    <!-- FINAL CTA -->
    <section class="da-final-cta" id="download">
        <div class="da-final-content">
            <div class="da-final-copy" data-reveal>
                <div class="da-eyebrow">Download Extension</div>
                <h2 class="da-h2">BRING THE READING ROOM<br>TO EVERY VIDEO</h2>
                <p class="da-feature-desc">Join the paid beta for YouTube language learners. Professional subtitles, instant word cards, and persistent memory.</p>
                <a href="#download" class="da-button">Get Started Now</a>
            </div>
            <div class="da-final-image" data-reveal data-reveal-delay="120">
                <img
                    src="{{ asset('img/desktop/portal-figure.webp') }}"
                    alt="Transcribed Subtitle Extension study figure"
                    width="1284"
                    height="1590"
                    loading="lazy"
                    decoding="async"
                >
            </div>
        </div>
    </section>
</div>
@endsection
