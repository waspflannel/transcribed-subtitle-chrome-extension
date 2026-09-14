@extends('layouts.site')

@section('header_class', 'on-night')

@section('content')
    <section class="hero-band">
        <p class="eyebrow">Chrome extension for YouTube immersion</p>
        <h1>Learn a language from the videos and <span class="hl">music you love.</span></h1>
        <p class="hero-lede">Generate AI subtitles, explore words and translations, and bring your own lyrics to study your favorite songs.</p>
        <div class="hero-actions">
            <a class="button button-accent" href="#pricing">Get started</a>
            <a class="button button-secondary" href="#demo">Try the preview <span aria-hidden="true">↘</span></a>
        </div>
        <p class="hero-meta">Public YouTube videos + Shorts · No captions required</p>
    </section>

    @include('marketing.partials.product-demo')

    <section class="section lyrics-section" id="lyrics">
        <div class="section-heading" data-reveal>
            <p class="eyebrow">Bring your own lyrics</p>
            <h2>Your favorite song.<br>Your lyrics. <span class="hl">Your next lesson.</span></h2>
            <p>Already have the words? Paste the full lyrics for a generated song track. We fit them to its existing timing and rebuild translations, pronunciation, and word data.</p>
            <a class="text-link strong-link" href="#demo" data-preview-link="lyrics">Try lyrics correction <span aria-hidden="true">↗</span></a>
        </div>
        <div class="lyric-sheet" data-reveal>
            <p class="eyebrow">A small correction. A different meaning.</p>
            <div class="lyric-comparison" lang="es">
                <div><span class="sheet-label">Generated line</span><p>Bajo la <del>una</del>, vuelvo a cantar.</p></div>
                <div><span class="sheet-label">With your lyrics</span><p>Bajo la <ins>luna</ins>, vuelvo a cantar.</p></div>
            </div>
            <p class="lyric-translation">“Under the moon, I sing again.”</p>
            <p class="sheet-note">Example text · Existing timing, refreshed learning layers.</p>
        </div>
        <p class="feature-footnote">Just one word to fix? Edit it directly in the transcript. Full lyrics correction replaces the whole track; use complete lyrics for the song.</p>
    </section>

    <section class="section models-section" id="models">
        <div class="section-heading" data-reveal>
            <p class="eyebrow">Less waiting. More watching.</p>
            <h2>Start with the first subtitles.<br><span class="hl">Keep learning as more arrive.</span></h2>
            <p>Subtitles appear as they become ready, before the whole track is finished. Choose the AI model for your next generation to suit the language you are studying.</p>
        </div>
        <div class="model-options" data-reveal>
            <article>
                <p class="eyebrow">For demanding language study</p>
                <h3>Transcriber<span class="model-note">The default choice</span></h3>
                <p>Our most capable model for language processing. Takes a little longer, but delivers better quality for complex phrases, subtle meanings, and detailed study.</p>
                <span class="model-example">Nuance <span aria-hidden="true">·</span> Context <span aria-hidden="true">·</span> Detail</span>
            </article>
            <article>
                <p class="eyebrow">For a faster start</p>
                <h3>Transcriber-Spark<span class="model-note">Built for speed</span></h3>
                <p>Blazing fast for everyday watching. Less capable than Transcriber with complex language, but a great choice when speed matters most.</p>
                <span class="model-example">Speed <span aria-hidden="true">·</span> Flow <span aria-hidden="true">·</span> Everyday study</span>
            </article>
        </div>
        <p class="feature-footnote">Generation time varies with the video, language, model, and queue. Model choice applies to generation; full lyrics correction uses Transcriber with either selection.</p>
    </section>

    <section class="section" id="features">
        <div class="section-heading" data-reveal>
            <p class="eyebrow">Make the words stick</p>
            <h2>Watch a little. <span class="hl">Understand a lot.</span></h2>
            <p>Your study tools live beside the video, ready when you need them.</p>
        </div>
        <div class="fc-grid">
            <article class="fc" data-reveal>
                <span class="fc-ruby" aria-hidden="true">ja · 単語 · tan·go</span>
                <h3>A word worth stopping for</h3>
                <p>Click a subtitle word for its meaning, reading, and usage in context. Optional hover pause gives you a moment to take it in.</p>
            </article>
            <article class="fc" data-reveal data-reveal-delay="80">
                <span class="fc-ruby" aria-hidden="true">ru · перевод · pe·re·vod</span>
                <h3>Understand it. Then read it.</h3>
                <p>Follow the original subtitles with a translation and optional romanization. Choose your subtitle language and the language you want to learn through.</p>
            </article>
            <article class="fc" data-reveal data-reveal-delay="140">
                <span class="fc-ruby" aria-hidden="true">fr · ré·pé·ter</span>
                <h3>Give your ears a second chance</h3>
                <p>Blur words or translations, reveal them when you need a hint, and replay the current line. Keyboard shortcuts keep you in the flow.</p>
            </article>
            <article class="fc" data-reveal data-reveal-delay="200">
                <span class="fc-ruby" aria-hidden="true">es · vol·ver</span>
                <h3>Find that line again</h3>
                <p>Search the transcript, jump to a cue, and switch between saved generations. Reopen generated tracks from your history while they are retained.</p>
            </article>
        </div>
    </section>

    <section class="section" id="how">
        <div class="section-heading" data-reveal>
            <p class="eyebrow">From setup to subtitles</p>
            <h2>Make tonight’s video <span class="hl">your next lesson.</span></h2>
        </div>
        <div class="step-list" data-reveal>
            <article class="step" id="install">
                <h3>Get the extension</h3>
                @if (config('marketing.chrome_extension_url'))
                    <p>Add the extension to desktop Chrome. You will need an account and an active plan to generate subtitles.</p>
                    <a class="text-link strong-link" href="{{ config('marketing.chrome_extension_url') }}">Add to Chrome <span aria-hidden="true">↗</span></a>
                @else
                    <p>Request your beta install link before subscribing, then add the extension to desktop Chrome.</p>
                    <a class="text-link strong-link" href="{{ route('marketing.support') }}#extension-install">Request install link <span aria-hidden="true">↗</span></a>
                @endif
            </article>
            <article class="step">
                <h3>Choose your plan</h3>
                <p>Create an account, verify your email, and choose a subscription. Sign in to the extension with the same account.</p>
                <a class="text-link strong-link" href="#pricing">Compare plans <span aria-hidden="true">↘</span></a>
            </article>
            <article class="step">
                <h3>Open a video and generate</h3>
                <p>Pick your languages and model, then select Generate. Subtitles appear inside YouTube as they become ready.</p>
            </article>
        </div>
    </section>

    <section class="section languages-section" id="languages">
        <div class="section-heading" data-reveal>
            <p class="eyebrow">Language coverage</p>
            <h2>A world of videos.<br><span class="hl">Find your language.</span></h2>
            <p>From Spanish and French to Japanese, Arabic, and Hindi. Auto detect is available when you are unsure of the subtitle language.</p>
        </div>
        <details class="language-directory" data-reveal>
            <summary>Supported subtitle and translation languages <span aria-hidden="true">+</span></summary>
            <div class="language-directory-content">
                <p>Choose your subtitle and translation languages independently. Results vary with the language, audio, and selected model.</p>
                <ul class="lang-cloud">
                    @foreach ($supportedLanguages as $language)
                        <li>{{ $language['label'] }}</li>
                    @endforeach
                </ul>
            </div>
        </details>
    </section>

    <section class="section" id="pricing">
        <div class="section-heading" data-reveal>
            <p class="eyebrow">Simple monthly plans</p>
            <h2>Pick your pace.<br><span class="hl">Keep your curiosity.</span></h2>
            <p>Choose how many video minutes you generate each month and how many videos you work on at once. Cancel any time from your account.</p>
        </div>
        @include('marketing.partials.pricing-plans')
    </section>

    <section class="section" id="faq">
        <div class="section-heading" data-reveal>
            <p class="eyebrow">Before you get started</p>
            <h2>A few things worth knowing.</h2>
        </div>
        <div class="faq-list" data-reveal>
            <details open>
                <summary><span class="faq-q">01</span>What can I watch?</summary>
                <p>Only public YouTube watch pages and Shorts are supported for beta, including music videos. Use the extension in desktop Chrome. Existing captions are not required. Private videos, live captioning, and other platforms are not supported.</p>
            </details>
            <details>
                <summary><span class="faq-q">02</span>Can I use my own lyrics?</summary>
                <p>Yes. After generating a song track, open Lyric correction and paste the complete lyrics. The app fits them to the track’s existing timing and rebuilds translations, pronunciation, and word data using Transcriber. This replaces the full track; it does not fetch lyrics or create new timing from the audio. Check the result before studying.</p>
            </details>
            <details>
                <summary><span class="faq-q">03</span>Can I fix just one word?</summary>
                <p>Yes. Choose Edit on a transcript line, select a word, and enter the correction. The app refreshes that line’s learning data while keeping its timing. Word fixes and full lyrics correction do not use additional generated-video minutes.</p>
            </details>
            <details>
                <summary><span class="faq-q">04</span>Which model should I choose?</summary>
                <p>Choose Transcriber for better quality with complex language and detailed study. Choose Transcriber-Spark for blazing-fast generation when speed is your priority. Select the model before generating; an existing generation keeps its model. Full lyrics correction always uses Transcriber.</p>
            </details>
            <details>
                <summary><span class="faq-q">05</span>How long does generation take?</summary>
                <p>The wait depends on video length, language, model, selected learning layers, and queue demand. Subtitles appear as they become ready, so you can start before the entire track is finished. Reopening a retained completed track does not require a new generation.</p>
            </details>
            <details>
                <summary><span class="faq-q">06</span>What do I need for the paid beta?</summary>
                <p>You need desktop Chrome, the extension, a verified account, and an active subscription. <a class="strong-link" href="#install">Check installation access</a> before choosing a plan. Generation starts only when you select Generate.</p>
            </details>
            <details>
                <summary><span class="faq-q">07</span>What happens to my audio and subtitles?</summary>
                <p>Temporary raw audio is used for processing and deleted after success or failure. Generated tracks are retained for 30 days for reuse and support. Interactive word cards explain words in context; saved vocabulary and a spaced-repetition review system are not included in this beta.</p>
            </details>
            <details>
                <summary><span class="faq-q">08</span>What if something goes wrong?</summary>
                <p>AI subtitles can contain mistakes, especially with unclear audio. You can correct words or replace a song’s lyrics. Contact support for billing, access, or generation failures; beta refund requests are handled case by case.</p>
            </details>
        </div>
    </section>

    <section class="section cta-band">
        <p class="eyebrow" data-reveal>Made for your kind of immersion</p>
        <h2 data-reveal>One more video.<br>One more thing understood.</h2>
        <p data-reveal>Your favorite creators and songs already have your attention. Give them a place in your language learning.</p>
        <div class="hero-actions" data-reveal>
            <a class="button button-accent" href="#pricing">Find your plan</a>
        </div>
    </section>
@endsection
