@extends('layouts.site')

@section('content')
    <section class="hero">
        <div class="hero-copy">
            <p class="eyebrow">Paid beta for YouTube language learners</p>
            <h1>Turn any public YouTube video into a study session.</h1>
            <p class="hero-lede">
                Generate synced subtitles, translations, romanization, and word cards when captions are missing or not useful for learning.
            </p>
            <div class="hero-actions">
                <a class="button" href="{{ route('register') }}">Join paid beta</a>
                <a class="button button-secondary" href="{{ route('marketing.pricing') }}">See pricing</a>
            </div>
        </div>
    </section>

    <section class="section product-preview-section">
        <div class="section-heading">
            <p class="eyebrow">Product preview</p>
            <h2>Bring the video. Add the study layer.</h2>
            <p>Preview the generation flow and study overlay before choosing a plan.</p>
        </div>
        <div class="product-preview-grid" aria-label="Product preview frames">
            <div class="product-frame product-frame-wide" aria-hidden="true">
                <span></span>
                <span></span>
                <span></span>
            </div>
            <div class="product-frame product-frame-narrow" aria-hidden="true">
                <span></span>
                <span></span>
                <span></span>
            </div>
        </div>
    </section>

    <section class="section workflow-story" aria-labelledby="workflow-title">
        <div class="section-heading workflow-story-heading">
            <p class="eyebrow">Workflow</p>
            <h2 id="workflow-title">Open, generate, learn.</h2>
            <p>Move from video to study mode in three focused steps.</p>
        </div>

        <article class="workflow-feature">
            <div class="workflow-copy">
                <p class="workflow-step">Step 1</p>
                <h3>Open a video</h3>
                <p>Pick any public YouTube video you want to understand.</p>
            </div>
            <div class="workflow-visual workflow-visual-video" role="img" aria-label="Placeholder screenshot for opening a YouTube video">
                <span></span>
                <span></span>
                <span></span>
            </div>
        </article>

        <article class="workflow-feature workflow-feature-reverse">
            <div class="workflow-copy">
                <p class="workflow-step">Step 2</p>
                <h3>Generate subtitles</h3>
                <p>Choose your languages and start one explicit generation step.</p>
            </div>
            <div class="workflow-visual workflow-visual-generate" role="img" aria-label="Placeholder screenshot for generating subtitles">
                <span></span>
                <span></span>
                <span></span>
                <span></span>
            </div>
        </article>

        <article class="workflow-feature">
            <div class="workflow-copy">
                <p class="workflow-step">Step 3</p>
                <h3>Start learning instantly</h3>
                <p>Break down the foreign-language track while you watch.</p>
            </div>
            <div class="workflow-visual workflow-visual-learn" role="img" aria-label="Placeholder screenshot for the learning overlay">
                <span></span>
                <span></span>
                <span></span>
                <span></span>
                <span></span>
            </div>
        </article>
    </section>

    <section class="section split-section language-range-section">
        <div>
            <p class="eyebrow">Language range</p>
            <h2>Built for more than one watch list.</h2>
            <p>
                Start with popular study languages and keep going. The full catalog includes clear coverage tiers instead of vague promises.
            </p>
            <a class="coverage-link" href="{{ route('marketing.languages') }}">
                <span>View all language coverage</span>
                <span>Browse the full catalog and quality tiers</span>
            </a>
        </div>
        <ul class="language-strip" aria-label="Featured languages">
            @foreach ($featuredLanguages as $language)
                <li>{{ $language }}</li>
            @endforeach
            <li>and more</li>
        </ul>
    </section>

    <section class="section final-cta">
        <p class="eyebrow">Try it</p>
        <h2>Try AI Language Subtitles on your next video.</h2>
        <p>Join the paid beta, choose a plan, and start turning public YouTube videos into study sessions.</p>
        <a class="button" href="{{ route('marketing.pricing') }}">Try AI Language Subtitles</a>
    </section>
@endsection
