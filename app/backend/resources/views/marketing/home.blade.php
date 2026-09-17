@extends('layouts.site')

@section('header_class', 'on-night')

@section('content')
    <section class="hero-band">
        <p class="eyebrow">{{ __('Chrome extension for YouTube immersion') }}</p>
        <h1>{!! strtr(e(__('Learn a language from the videos and :slot1:music you love.:slot2:')), [':slot1:' => '<span class="hl">', ':slot2:' => '</span>']) !!}</h1>
        <p class="hero-lede">{{ __('Generate subtitles, explore words and translations, and bring your own lyrics to study your favorite songs.') }}</p>
        <div class="hero-actions">
            <a class="button button-accent" href="#pricing">{{ __('Get started') }}</a>
            <a class="button button-secondary" href="{{ \App\Support\WebsiteLocale::route('marketing.how-to-use') }}">{!! strtr(e(__('How To Use :slot1:↗:slot2:')), [':slot1:' => '<span aria-hidden="true">', ':slot2:' => '</span>']) !!}</a>
        </div>
        <p class="hero-meta">{{ __('Public YouTube videos + Shorts · No captions required') }}</p>
    </section>

    <section class="section guide-intro" aria-labelledby="guide-intro-title">
        <div>
            <p class="eyebrow">{{ __('How To Use') }}</p>
            <h2 id="guide-intro-title">{!! strtr(e(__('A guide for :slot1:every step.:slot2:')), [':slot1:' => '<span class="hl">', ':slot2:' => '</span>']) !!}</h2>
            <p>{{ __('Install the extension, generate your first subtitles, and learn how to correct lyrics and make the most of your study tools.') }}</p>
            <a class="button" href="{{ \App\Support\WebsiteLocale::route('marketing.how-to-use') }}">{!! strtr(e(__('Open the guide :slot1:↗:slot2:')), [':slot1:' => '<span aria-hidden="true">', ':slot2:' => '</span>']) !!}</a>
        </div>
        <nav class="guide-intro-links" aria-label="{{ __('Popular guides') }}">
            <a href="{{ \App\Support\WebsiteLocale::route('marketing.how-to-use') }}#how-to-install">{!! strtr(e(__(':slot1:01:slot2: How to install :slot3:↗:slot4:')), [':slot1:' => '<span>', ':slot2:' => '</span>', ':slot3:' => '<span aria-hidden="true">', ':slot4:' => '</span>']) !!}</a>
            <a href="{{ \App\Support\WebsiteLocale::route('marketing.how-to-use') }}#generate-subtitles">{!! strtr(e(__(':slot1:02:slot2: Generate subtitles :slot3:↗:slot4:')), [':slot1:' => '<span>', ':slot2:' => '</span>', ':slot3:' => '<span aria-hidden="true">', ':slot4:' => '</span>']) !!}</a>
            <a href="{{ \App\Support\WebsiteLocale::route('marketing.how-to-use') }}#replace-lyrics">{!! strtr(e(__(':slot1:03:slot2: Replace full lyrics :slot3:↗:slot4:')), [':slot1:' => '<span>', ':slot2:' => '</span>', ':slot3:' => '<span aria-hidden="true">', ':slot4:' => '</span>']) !!}</a>
            <a href="{{ \App\Support\WebsiteLocale::route('marketing.how-to-use') }}#fix-a-word">{!! strtr(e(__(':slot1:04:slot2: Fix a single word :slot3:↗:slot4:')), [':slot1:' => '<span>', ':slot2:' => '</span>', ':slot3:' => '<span aria-hidden="true">', ':slot4:' => '</span>']) !!}</a>
        </nav>
    </section>

    <section class="section lyrics-section" id="lyrics">
        <div class="section-heading" data-reveal>
            <p class="eyebrow">{{ __('Bring your own lyrics') }}</p>
            <h2>{!! strtr(e(__('Your favorite song.:slot1:Your lyrics. :slot2:Your next lesson.:slot3:')), [':slot1:' => '<br>', ':slot2:' => '<span class="hl">', ':slot3:' => '</span>']) !!}</h2>
            <p>{{ __('Already have the words? Paste the full lyrics for a generated song track. We fit them to its existing timing and rebuild translations, pronunciation, and word data.') }}</p>
            <a class="text-link strong-link" href="{{ \App\Support\WebsiteLocale::route('marketing.how-to-use') }}#replace-lyrics">{!! strtr(e(__('Learn how to replace lyrics :slot1:↗:slot2:')), [':slot1:' => '<span aria-hidden="true">', ':slot2:' => '</span>']) !!}</a>
        </div>
        <div class="lyric-sheet" data-reveal>
            <p class="eyebrow">{{ __('A small correction. A different meaning.') }}</p>
            <div class="lyric-comparison" lang="es">
                <div><span class="sheet-label">{{ __('Generated line') }}</span><p>Bajo la <del>una</del>, vuelvo a cantar.</p></div>
                <div><span class="sheet-label">{{ __('With your lyrics') }}</span><p>Bajo la <ins>luna</ins>, vuelvo a cantar.</p></div>
            </div>
            <p class="lyric-translation">{{ __('“Under the moon, I sing again.”') }}</p>
            <p class="sheet-note">{{ __('Example text · Existing timing, refreshed learning layers.') }}</p>
        </div>
        <p class="feature-footnote">{{ __('Just one word to fix? Edit it directly in the transcript. Full lyrics correction replaces the whole track; use complete lyrics for the song.') }}</p>
    </section>

    <section class="section models-section" id="models">
        <div class="section-heading" data-reveal>
            <p class="eyebrow">{{ __('Less waiting. More watching.') }}</p>
            <h2>{!! strtr(e(__('Start with the first subtitles.:slot1::slot2:Keep learning as more arrive.:slot3:')), [':slot1:' => '<br>', ':slot2:' => '<span class="hl">', ':slot3:' => '</span>']) !!}</h2>
            <p>{{ __('Subtitles appear as they become ready, before the whole track is finished. Choose the AI model for your next generation to suit the language you are studying.') }}</p>
        </div>
        <div class="model-options" data-reveal>
            <article>
                <p class="eyebrow">{{ __('For demanding language study') }}</p>
                <h3>{!! strtr(e(__('Transcriber:slot1:The default choice:slot2:')), [':slot1:' => '<span class="model-note">', ':slot2:' => '</span>']) !!}</h3>
                <p>{{ __('Our most capable model for language processing. Takes a little longer, but delivers better quality for complex phrases, subtle meanings, and detailed study.') }}</p>
                <span class="model-example">{{ __('Nuance · Context · Detail') }}</span>
            </article>
            <article>
                <p class="eyebrow">{{ __('For a faster start') }}</p>
                <h3>Transcriber-Spark<span class="model-note">{{ __('Built for speed') }}</span></h3>
                <p>{{ __('Blazing fast for everyday watching. Less capable than Transcriber with complex language, but a great choice when speed matters most.') }}</p>
                <span class="model-example">{{ __('Speed · Flow · Everyday study') }}</span>
            </article>
        </div>
        <p class="feature-footnote">{{ __('Generation time varies with the video, language, model, and queue. Model choice applies to generation; full lyrics correction uses Transcriber with either selection.') }}</p>
    </section>

    <section class="section" id="features">
        <div class="section-heading" data-reveal>
            <p class="eyebrow">{{ __('Make the words stick') }}</p>
            <h2>{!! strtr(e(__('Watch a little. :slot1:Understand a lot.:slot2:')), [':slot1:' => '<span class="hl">', ':slot2:' => '</span>']) !!}</h2>
            <p>{{ __('Your study tools live beside the video, ready when you need them.') }}</p>
        </div>
        <div class="fc-grid">
            <article class="fc" data-reveal>
                <span class="fc-ruby" aria-hidden="true">ja · 単語 · tan·go</span>
                <h3>{{ __('A word worth stopping for') }}</h3>
                <p>{{ __('Click a subtitle word for its meaning, reading, and usage in context. Optional hover pause gives you a moment to take it in.') }}</p>
            </article>
            <article class="fc" data-reveal data-reveal-delay="80">
                <span class="fc-ruby" aria-hidden="true">ru · перевод · pe·re·vod</span>
                <h3>{{ __('Understand it. Then read it.') }}</h3>
                <p>{{ __('Follow the original subtitles with a translation and optional romanization. Choose your subtitle language and the language you want to learn through.') }}</p>
            </article>
            <article class="fc" data-reveal data-reveal-delay="140">
                <span class="fc-ruby" aria-hidden="true">fr · ré·pé·ter</span>
                <h3>{{ __('Give your ears a second chance') }}</h3>
                <p>{{ __('Blur words or translations, reveal them when you need a hint, and replay the current line. Keyboard shortcuts keep you in the flow.') }}</p>
            </article>
            <article class="fc" data-reveal data-reveal-delay="200">
                <span class="fc-ruby" aria-hidden="true">es · vol·ver</span>
                <h3>{{ __('Find that line again') }}</h3>
                <p>{{ __('Search the transcript, jump to a cue, and switch between saved generations. Reopen generated tracks from your history while they are retained.') }}</p>
            </article>
        </div>
    </section>

    <section class="section" id="how">
        <div class="section-heading" data-reveal>
            <p class="eyebrow">{{ __('From setup to subtitles') }}</p>
            <h2>{!! strtr(e(__('Make tonight’s video :slot1:your next lesson.:slot2:')), [':slot1:' => '<span class="hl">', ':slot2:' => '</span>']) !!}</h2>
        </div>
        <div class="step-list" data-reveal>
            <article class="step" id="install">
                <h3>{{ __('Get the extension') }}</h3>
                <p>{{ __('Add the extension to desktop Chrome through the Chrome Web Store or install a downloaded package.') }}</p>
                <a class="text-link strong-link" href="{{ \App\Support\WebsiteLocale::route('marketing.how-to-use') }}#how-to-install">{!! strtr(e(__('How to install :slot1:↗:slot2:')), [':slot1:' => '<span aria-hidden="true">', ':slot2:' => '</span>']) !!}</a>
            </article>
            <article class="step">
                <h3>{{ __('Choose your plan') }}</h3>
                <p>{{ __('Create an account and choose a subscription. Sign in to the extension with the same account.') }}</p>
                <a class="text-link strong-link" href="#pricing">{!! strtr(e(__('Compare plans :slot1:↘:slot2:')), [':slot1:' => '<span aria-hidden="true">', ':slot2:' => '</span>']) !!}</a>
            </article>
            <article class="step">
                <h3>{{ __('Open a video and generate') }}</h3>
                <p>{{ __('Pick your languages and model, then select Generate. Subtitles appear inside YouTube as they become ready.') }}</p>
            </article>
        </div>
    </section>

    <section class="section languages-section" id="languages">
        <div class="section-heading" data-reveal>
            <p class="eyebrow">{{ __('Language coverage') }}</p>
            <h2>{!! strtr(e(__('A world of videos.:slot1::slot2:Find your language.:slot3:')), [':slot1:' => '<br>', ':slot2:' => '<span class="hl">', ':slot3:' => '</span>']) !!}</h2>
            <p>{{ __('From Spanish and French to Japanese, Arabic, and Hindi. Auto detect is available when you are unsure of the subtitle language.') }}</p>
        </div>
        <details class="language-directory" data-reveal>
            <summary>{!! strtr(e(__('Supported subtitle and translation languages :slot1:+:slot2:')), [':slot1:' => '<span aria-hidden="true">', ':slot2:' => '</span>']) !!}</summary>
            <div class="language-directory-content">
                <p>{{ __('Choose your subtitle and translation languages independently. Results vary with the language, audio, and selected model.') }}</p>
                <ul class="lang-cloud">
                    @foreach ($supportedLanguages as $language)
                        <li>{{ __($language['label']) }}</li>
                    @endforeach
                </ul>
            </div>
        </details>
    </section>

    <section class="section" id="pricing">
        <div class="section-heading" data-reveal>
            <p class="eyebrow">{{ __('Simple monthly plans') }}</p>
            <h2>{!! strtr(e(__('Pick your pace.:slot1::slot2:Keep your curiosity.:slot3:')), [':slot1:' => '<br>', ':slot2:' => '<span class="hl">', ':slot3:' => '</span>']) !!}</h2>
            <p>{{ __('Choose how many video minutes you generate each month and how many videos you work on at once. Cancel any time from your account.') }}</p>
        </div>
        @include('marketing.partials.pricing-plans')
    </section>

    <section class="section" id="faq">
        <div class="section-heading" data-reveal>
            <p class="eyebrow">{{ __('Before you get started') }}</p>
            <h2>{{ __('A few things worth knowing.') }}</h2>
        </div>
        <div class="faq-list" data-reveal>
            <details open>
                <summary>{!! strtr(e(__(':slot1:01:slot2:What can I watch?')), [':slot1:' => '<span class="faq-q">', ':slot2:' => '</span>']) !!}</summary>
                <p>{{ __('Only public YouTube watch pages and Shorts are supported, including music videos. Use the extension in desktop Chrome. Existing captions are not required. Private videos, live captioning, and other platforms are not supported.') }}</p>
            </details>
            <details>
                <summary>{!! strtr(e(__(':slot1:02:slot2:Can I use my own lyrics?')), [':slot1:' => '<span class="faq-q">', ':slot2:' => '</span>']) !!}</summary>
                <p>{{ __('Yes. After generating a song track, open Lyric correction and paste the complete lyrics. The app fits them to the track’s existing timing and rebuilds translations, pronunciation, and word data using Transcriber. This replaces the full track; it does not fetch lyrics or create new timing from the audio. Check the result before studying.') }}</p>
            </details>
            <details>
                <summary>{!! strtr(e(__(':slot1:03:slot2:Can I fix just one word?')), [':slot1:' => '<span class="faq-q">', ':slot2:' => '</span>']) !!}</summary>
                <p>{{ __('Yes. Choose Edit on a transcript line, select a word, and enter the correction. The app refreshes that line’s learning data while keeping its timing. Word fixes and full lyrics correction do not use additional generated-video minutes.') }}</p>
            </details>
            <details>
                <summary>{!! strtr(e(__(':slot1:04:slot2:Which model should I choose?')), [':slot1:' => '<span class="faq-q">', ':slot2:' => '</span>']) !!}</summary>
                <p>{{ __('Choose Transcriber for better quality with complex language and detailed study. Choose Transcriber-Spark for blazing-fast generation when speed is your priority. Select the model before generating; an existing generation keeps its model. Full lyrics correction always uses Transcriber.') }}</p>
            </details>
            <details>
                <summary>{!! strtr(e(__(':slot1:05:slot2:How long does generation take?')), [':slot1:' => '<span class="faq-q">', ':slot2:' => '</span>']) !!}</summary>
                <p>{{ __('The wait depends on video length, language, model, selected learning layers, and queue demand. Subtitles appear as they become ready, so you can start before the entire track is finished. Reopening a retained completed track does not require a new generation.') }}</p>
            </details>
            <details>
                <summary>{!! strtr(e(__(':slot1:06:slot2:What happens to my audio and subtitles?')), [':slot1:' => '<span class="faq-q">', ':slot2:' => '</span>']) !!}</summary>
                <p>{{ __('Temporary raw audio is used for processing and deleted after success or failure. Generated tracks are retained for 30 days for reuse and support. Interactive word cards explain words in context; saved vocabulary and a spaced-repetition review system are not currently included.') }}</p>
            </details>
            <details>
                <summary>{!! strtr(e(__(':slot1:07:slot2:What if something goes wrong?')), [':slot1:' => '<span class="faq-q">', ':slot2:' => '</span>']) !!}</summary>
                <p>{!! strtr(e(__('Subtitles can contain mistakes, especially with unclear audio. You can correct words or replace a song’s lyrics. :slot1:Contact support:slot2: to report a bug or get help with billing, access, or generation failures.')), [':slot1:' => '<a class="strong-link" href="'.e(\App\Support\WebsiteLocale::route('marketing.support')).'">', ':slot2:' => '</a>']) !!}</p>
            </details>
        </div>
    </section>

    <section class="section cta-band">
        <p class="eyebrow" data-reveal>{{ __('Made for your kind of immersion') }}</p>
        <h2 data-reveal>{!! strtr(e(__('One more video.:slot1:One more thing understood.')), [':slot1:' => '<br>']) !!}</h2>
        <p data-reveal>{{ __('Your favorite creators and songs already have your attention. Give them a place in your language learning.') }}</p>
        <div class="hero-actions" data-reveal>
            <a class="button button-accent" href="#pricing">{{ __('Find your plan') }}</a>
        </div>
    </section>
@endsection
