@extends('layouts.site')

@section('content')
    <section class="hero">
        <div class="hero-copy">
            <p class="eyebrow">Paid beta for YouTube language learners</p>
            <h1>AI Language Subtitles</h1>
            <p class="hero-lede">
                Generate synced subtitle tracks, translations, romanization, and word cards for public YouTube videos when captions are missing or not useful for study.
            </p>
            <div class="hero-actions">
                <a class="button" href="{{ route('register') }}">Join beta</a>
                <a class="button button-secondary" href="{{ route('marketing.how-it-works') }}#install">Install path</a>
            </div>
        </div>
        <div class="hero-visual" aria-label="Subtitle overlay preview">
            <img
                class="hero-photo"
                src="https://images.unsplash.com/photo-1516321497487-e288fb19713f?auto=format&fit=crop&w=1400&q=80"
                alt=""
            >
            <div class="video-scene">
                <div class="video-bar"></div>
                <div class="caption-line source">Je pensais connaitre cette scene par coeur.</div>
                <div class="caption-line target">I thought I knew this scene by heart.</div>
                <div class="token-rail">
                    <span>pensais</span>
                    <span>connaitre</span>
                    <span>scene</span>
                    <strong>by heart</strong>
                </div>
            </div>
        </div>
    </section>

    <section class="section section-tight">
        <div class="section-heading">
            <p class="eyebrow">Workflow</p>
            <h2>One explicit generation step, then a synced learning overlay.</h2>
        </div>
        <div class="workflow-grid">
            <article>
                <span>1</span>
                <h3>Open a public YouTube video</h3>
                <p>The Chrome extension reads the current watch page and waits for you to start generation.</p>
            </article>
            <article>
                <span>2</span>
                <h3>Choose source and target languages</h3>
                <p>Use Auto detect or pick a subtitle language, then choose the translation language for study.</p>
            </article>
            <article>
                <span>3</span>
                <h3>Review the generated track</h3>
                <p>Synced captions, optional translation, romanization, and word cards stay in the YouTube page overlay.</p>
            </article>
        </div>
    </section>

    <section class="section split-section">
        <div>
            <p class="eyebrow">Language range</p>
            <h2>Built for polyglot watch lists.</h2>
            <p>
                Coverage follows the shared transcription catalog used by the extension and backend, with clear quality tiers instead of vague promises.
            </p>
            <a class="text-link strong-link" href="{{ route('marketing.languages') }}">View language coverage</a>
        </div>
        <ul class="language-strip" aria-label="Featured languages">
            @foreach ($featuredLanguages as $language)
                <li>{{ $language }}</li>
            @endforeach
        </ul>
    </section>

    <section class="section pricing-preview">
        <div class="section-heading">
            <p class="eyebrow">Pricing</p>
            <h2>Plans use generated video minutes.</h2>
        </div>
        <div class="plan-row">
            @foreach ($plans as $plan)
                <article class="plan-card">
                    <h3>{{ $plan['name'] }}</h3>
                    <p class="price">${{ number_format(((int) $plan['price_cents']) / 100, 0) }}<span>/month</span></p>
                    <p>{{ $plan['monthly_minutes'] }} minutes, {{ $plan['speed_label'] }}.</p>
                </article>
            @endforeach
        </div>
        <a class="button button-secondary" href="{{ route('marketing.pricing') }}">Compare plans</a>
    </section>
@endsection
