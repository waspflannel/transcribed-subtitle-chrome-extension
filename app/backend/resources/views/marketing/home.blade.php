@extends('layouts.site')

@section('header_class', 'on-night')

@section('content')
    <!-- NIGHT HERO -->
    <section class="hero-band">
        <p class="eyebrow">Chrome extension for YouTube immersion</p>
        <h1>Learn a language from the videos you <span class="hl">actually watch</span>.</h1>
        <p class="hero-lede">
            Generate word-by-word subtitles for any public YouTube video — with translation, romanization, and tap-to-learn flashcards, synced right inside the player.
        </p>
        <div class="hero-actions">
            <a class="button button-accent" href="#pricing">Start learning</a>
            <a class="button button-secondary" href="#how">See how it works</a>
        </div>
        <p class="hero-meta">Public YouTube videos + Shorts · No captions required</p>
    </section>

    <!-- PRODUCT MOCK, overlapping the hero -->
    <section class="section mk-wrap" aria-label="Product preview">
        <div class="mk" role="img" aria-label="YouTube player with generated subtitles: each Japanese word carries a small romanized reading, an English translation sits below, and a flashcard is open for the word eiga, meaning movie.">
            <div class="mk-bar" aria-hidden="true">
                <div class="mk-dots"><span></span><span></span><span></span></div>
                <div class="mk-url">youtube.com/watch?v=tonights-lesson</div>
                <div class="mk-ext">Aa</div>
            </div>
            <div class="mk-player" aria-hidden="true">
                <div class="mk-card">
                    <span class="mk-card-tag">flashcard</span>
                    <div class="mk-card-word">
                        <strong>映画</strong>
                        <span>えいが · eiga</span>
                    </div>
                    <p>noun — a movie, a film. Pairs with 見る (to watch): 映画を見る, “to watch a movie.”</p>
                </div>
                <div class="mk-subs">
                    <div class="mk-line" lang="ja">
                        <span class="mk-tok mk-tok-hot">映画<small>eiga</small></span>
                        <span class="mk-tok">は<small>wa</small></span>
                        <span class="mk-tok">明日<small>ashita</small></span>
                        <span class="mk-tok">見ます<small>mimasu</small></span>
                    </div>
                    <div class="mk-trans">I’ll watch the movie tomorrow.</div>
                </div>
                <div class="mk-controls">
                    <span class="mk-play"></span>
                    <div class="mk-track"><span></span></div>
                    <span class="mk-time">04:32 / 12:08</span>
                </div>
            </div>
        </div>
        <p class="mk-caption">
            Click any word for an instant flashcard — meaning, reading, and usage — without pausing the video.
        </p>
    </section>

    <!-- FEATURES -->
    <section class="section" id="features">
        <div class="section-heading" data-reveal>
            <p class="eyebrow">What you get</p>
            <h2>Built for studying, not just watching.</h2>
        </div>
        <div class="fc-grid">
            <article class="fc" data-reveal>
                <span class="fc-ruby" aria-hidden="true">es · sub·tí·tu·los</span>
                <h3>Subtitles for videos that have none</h3>
                <p>Accurate, synced subtitles for any public YouTube video or Short — even when the uploader never added captions.</p>
            </article>
            <article class="fc" data-reveal data-reveal-delay="80">
                <span class="fc-ruby" aria-hidden="true">ru · перевод · pe·re·vod</span>
                <h3>Translation and romanization, layered</h3>
                <p>A translation line and a romanized reading stack under the original subtitles, so you understand first and decode second.</p>
            </article>
            <article class="fc" data-reveal data-reveal-delay="140">
                <span class="fc-ruby" aria-hidden="true">ja · 単語 · tan·go</span>
                <h3>A flashcard behind every word</h3>
                <p>Click any word to open a card with meaning, reading, and usage notes — saved to your account for review.</p>
            </article>
            <article class="fc" data-reveal data-reveal-delay="200">
                <span class="fc-ruby" aria-hidden="true">fr · ré·vi·sion</span>
                <h3>Controls and history for review</h3>
                <p>Adjust caption timing, size, and layers without leaving the video. Recent generations stay in the side panel and your dashboard.</p>
            </article>
        </div>
    </section>

    <!-- HOW IT WORKS -->
    <section class="section" id="how">
        <div class="section-heading" data-reveal>
            <p class="eyebrow">How it works</p>
            <h2>Three steps from install to immersion.</h2>
        </div>
        <div class="step-list" data-reveal>
            <article class="step">
                <h3>Install the Chrome extension</h3>
                <p>Add the extension to Chrome, then sign in from the side panel Account tab with your verified account email.</p>
            </article>
            <article class="step">
                <h3>Pick a video and generate</h3>
                <p>Open any public YouTube video, choose your subtitle and translation languages, and start generation with one click.</p>
            </article>
            <article class="step">
                <h3>Study inside the player</h3>
                <p>Synced subtitles appear as they are ready — with translation, romanization, and clickable words for instant flashcards.</p>
            </article>
        </div>
        <div class="action-stack" id="install" style="margin-top: 40px;" data-reveal>
            @if (config('marketing.chrome_extension_url'))
                <a class="button button-accent" href="{{ config('marketing.chrome_extension_url') }}">Add to Chrome</a>
            @else
                <a class="button button-accent" href="{{ route('marketing.support') }}#extension-install">Request install link</a>
            @endif
            <a class="button button-secondary" href="#pricing">See pricing</a>
        </div>
    </section>

    <!-- LANGUAGES -->
    <section class="section" id="languages">
        <div class="section-heading" data-reveal>
            <p class="eyebrow">Language coverage</p>
            <h2>Supported subtitle and translation languages.</h2>
            <p>Auto detect is available for subtitle generation, grouped below by current transcription quality.</p>
        </div>
        <div>
            @foreach ($languageGroups as $group)
                <section class="lang-group" data-reveal data-reveal-delay="{{ $loop->index * 60 }}">
                    <div>
                        <div class="lang-tier">
                            <h2>{{ $group['label'] }}</h2>
                            <span class="lang-badge">tier {{ $loop->iteration }}</span>
                        </div>
                        <p>{{ $group['description'] }}</p>
                    </div>
                    <ul class="lang-cloud">
                        @foreach ($group['languages'] as $language)
                            <li>{{ $language['label'] }}</li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
        </div>
    </section>

    <!-- PRICING -->
    <section class="section" id="pricing">
        <div class="section-heading" data-reveal>
            <p class="eyebrow">Pricing</p>
            <h2>One subscription. Every video becomes a lesson.</h2>
            <p>Plans meter generated-video minutes — pick the queue speed, monthly cap, and learning depth that match your study volume. Cancel any time from the billing portal.</p>
        </div>
        @include('marketing.partials.pricing-plans')
    </section>

    <!-- FAQ -->
    <section class="section" id="faq">
        <div class="section-heading" data-reveal>
            <p class="eyebrow">FAQ</p>
            <h2>Common questions.</h2>
        </div>
        <div class="faq-list" data-reveal>
            <details open>
                <summary><span class="faq-q">Q1</span>Which platforms are supported?</summary>
                <p>Only public YouTube watch pages and Shorts are supported for beta. Netflix, private videos, playlists as a source, live captions, and other platforms are out of scope.</p>
            </details>
            <details>
                <summary><span class="faq-q">Q2</span>Do I need to start generation manually?</summary>
                <p>Yes. The extension waits for an explicit Generate action before sending the selected video URL and generation controls to the backend.</p>
            </details>
            <details>
                <summary><span class="faq-q">Q3</span>What happens to audio and transcripts?</summary>
                <p>Temporary raw audio is used for backend processing and deleted after success or failure. Generated tracks are retained for 30 days for reuse and support.</p>
            </details>
            <details>
                <summary><span class="faq-q">Q4</span>Can AI subtitles be wrong?</summary>
                <p>Yes. Accuracy depends on the language, audio, speaker, and provider output. The product is a study aid, not an official caption source.</p>
            </details>
            <details>
                <summary><span class="faq-q">Q5</span>How do refunds work during beta?</summary>
                <p>Contact support when billing or generation failures prevent reasonable use. Refund decisions are handled case by case for the paid beta.</p>
            </details>
        </div>
    </section>

    <!-- FINAL NIGHT BAND -->
    <section class="section cta-band">
        <p class="eyebrow" data-reveal>Paid beta</p>
        <h2 data-reveal>Your next lesson is already in your subscriptions.</h2>
        <p data-reveal>Create an account, pick a plan, and turn tonight’s video into study material.</p>
        <div class="hero-actions" data-reveal>
            <a class="button button-accent" href="{{ route('register') }}">Create your account</a>
        </div>
    </section>
@endsection
