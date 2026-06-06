@extends('layouts.hermes-desktop')

@section('content')
<nav class="hermes-navbar" id="navbar">
    <div class="hermes-nav-inner">
        <div class="hermes-nav-left">
            <a href="/" class="hermes-brand" aria-label="Transcribed Subtitle Extension">
                <img src="{{ asset('img/desktop/nous.webp') }}" alt="" aria-hidden="true" style="height: 18px; width: auto; filter: brightness(0) invert(1);">
            </a>
            <a href="#home" class="is-active">Home</a>
            <a href="{{ route('marketing.how-it-works') }}">How it works</a>
            <a href="{{ route('marketing.pricing') }}">Pricing</a>
        </div>
        <div class="hermes-nav-right">
            <a href="{{ route('login') }}" class="hermes-nav-link">Login</a>
            <a href="{{ route('register') }}" class="hermes-button hermes-button-primary hermes-button-small">
                Get Started
            </a>
        </div>
    </div>
</nav>

<main class="da-main" id="home">
    <!-- HERO -->
    <section class="da-hero">
        <img class="hermes-hero-texture" src="{{ asset('img/desktop/filler-bg0.webp') }}" alt="" aria-hidden="true">
        <div class="hermes-container da-hero-content">
            <div class="da-hero-text">
                <div class="da-eyebrow">learn languages using youtube</div>
                <h1 class="da-h1">
                    <span>TRANSCRIBED</span>
                    <span>SUBTITLE</span>
                    <span>EXTENSION</span>
                </h1>
                <p class="da-hero-lede">Turn any public YouTube video into a language learning masterclass. AI-powered transcription and translation layers built for serious learners.</p>
                <br>
                <a href="#download" class="da-button">
                    <span class="da-icon">↓</span> Download Extension [Beta]
                </a>
            </div>
            <div class="da-hero-visual">
                <img src="{{ asset('img/desktop/hero-art.webp') }}" alt="Transcribed Subtitle Extension">
            </div>
        </div>
    </section>

    <!-- VIDEO/PREVIEW -->
    <section class="da-preview">
        <div class="da-preview-frame">
             <img src="{{ asset('img/desktop/filler-bg0.webp') }}" alt="Product Preview">
             <div class="da-preview-bar">PRODUCT DEMO — BETA PREVIEW</div>
        </div>
    </section>

    <!-- FEATURES / WHY USE IT -->
    <section class="da-features-section">
        <div class="da-features-grid">
            <!-- Perk 1 -->
            <div class="da-feature">
                <div class="da-feature-header">
                    <span class="da-feature-num">#1 MISSING CAPTIONS</span>
                </div>
                <h2 class="da-h2">STUDY ANY<br>VIDEO</h2>
                <div class="da-feature-image">
                    <img src="{{ asset('img/desktop/feature-connect.webp') }}" alt="Missing Captions">
                </div>
                <p class="da-feature-desc">TURNS MISSING OR POOR CAPTIONS INTO USEFUL STUDY SUBTITLES. ONE CLICK, EVERY VIDEO.</p>
            </div>
            <!-- Perk 2 -->
            <div class="da-feature">
                <div class="da-feature-header">
                    <span class="da-feature-num">#2 TRANSLATION</span>
                </div>
                <h2 class="da-h2">LAYERED<br>CONTEXT</h2>
                <div class="da-feature-image">
                    <img src="{{ asset('img/desktop/feature-memory.webp') }}" alt="Translation Layer">
                </div>
                <p class="da-feature-desc">INJECTS TRANSLATION AND ROMANIZATION LAYERS DIRECTLY INTO THE PLAYER. IMMEDIATE COMPREHENSION.</p>
            </div>
            <!-- Perk 3 -->
            <div class="da-feature">
                <div class="da-feature-header">
                    <span class="da-feature-num">#3 RETENTION</span>
                </div>
                <h2 class="da-h2">WORD<br>CARDS</h2>
                <div class="da-feature-image">
                    <img src="{{ asset('img/desktop/feature-tasks.webp') }}" alt="Word Cards">
                </div>
                <p class="da-feature-desc">VOCABULARY WORD CARDS GENERATED FROM VIDEO SUBTITLES. AUTOMATIC FLASHCARDS FOR SPACED REPETITION.</p>
            </div>
            <!-- Perk 4 -->
             <div class="da-feature">
                <div class="da-feature-header">
                    <span class="da-feature-num">#4 EXPORT</span>
                </div>
                <h2 class="da-h2">TAKE IT<br>WITH YOU</h2>
                <div class="da-feature-image">
                    <img src="{{ asset('img/desktop/feature-automation.webp') }}" alt="Export">
                </div>
                <p class="da-feature-desc">EXPORT YOUR LEARNED VOCABULARY TO ANKI, CSV, OR NOTION. OWN YOUR PROGRESS DATA.</p>
            </div>
            <!-- Perk 5 -->
             <div class="da-feature">
                <div class="da-feature-header">
                    <span class="da-feature-num">#5 OFFLINE</span>
                </div>
                <h2 class="da-h2">READ<br>ANYWHERE</h2>
                <div class="da-feature-image">
                    <img src="{{ asset('img/desktop/feature-browse.webp') }}" alt="Read Anywhere">
                </div>
                <p class="da-feature-desc">DOWNLOAD FULL TRANSCRIPTS AS PDF OR TEXT. STUDY ALONG WITHOUT AN INTERNET CONNECTION.</p>
            </div>
            <!-- Perk 6 -->
             <div class="da-feature">
                <div class="da-feature-header">
                    <span class="da-feature-num">#6 PRIVACY</span>
                </div>
                <h2 class="da-h2">LOCAL<br>PROCESSING</h2>
                <div class="da-feature-image">
                    <img src="{{ asset('img/desktop/feature-sandbox.webp') }}" alt="Privacy">
                </div>
                <p class="da-feature-desc">YOUR WATCH HISTORY REMAINS PRIVATE. TRANSCRIPTIONS RUN SECURELY ON MANAGED INFRASTRUCTURE.</p>
            </div>
        </div>
    </section>

    <!-- FINAL CTA -->
    <section class="da-final-cta" id="download">
        <div class="da-giant-text">TRANSCRIBE</div>
        <div class="da-final-content">
            <div class="da-final-copy">
                <div class="da-eyebrow" style="color: rgba(255,255,255,0.7);">Download Extension</div>
                <h2 class="da-h2">BRING THE READING ROOM<br>TO EVERY VIDEO</h2>
                <p class="da-feature-desc">Join the paid beta for YouTube language learners. Professional subtitles, instant word cards, and persistent memory.</p>
                <br><br>
                <a href="#download" class="da-button">
                    <span class="da-icon">↓</span> Get Started Now
                </a>
            </div>
            <div class="da-final-image">
                <img src="{{ asset('img/desktop/portal-figure.webp') }}" alt="Transcribed Subtitle Extension study figure">
            </div>
        </div>
    </section>

    <footer class="hermes-footer">
        <div class="hermes-container hermes-footer-inner">
            <div class="hermes-footer-brand">
                <img src="{{ asset('img/desktop/nous.webp') }}" alt="" aria-hidden="true" style="filter: brightness(0) invert(1);">
                <strong>Transcribed Subtitle Extension</strong>
                <p>AI Language Study v1.0.0-beta</p>
                <p>&copy; 2026</p>
            </div>
            <nav aria-label="Footer">
                <a href="{{ route('marketing.faq') }}">FAQ</a>
                <a href="{{ route('marketing.privacy') }}">Privacy</a>
                <a href="{{ route('marketing.support') }}">Support</a>
            </nav>
        </div>
    </footer>
</main>
@endsection


