@extends('layouts.site')

@section('content')
    <section class="page-hero">
        <p class="eyebrow">{{ __('Support') }}</p>
        <h1>{{ __('Report a bug. Get in touch.') }}</h1>
        <p>{{ __('Use public-safe job details from your dashboard when asking for help. Do not send generated transcript text unless support explicitly asks for a minimal excerpt.') }}</p>
    </section>

    <section class="section split-section" id="extension-install">
        <div data-reveal>
            <h2>{{ __('Contact') }}</h2>
            <p>{!! strtr(e(__('Email :slot1::slot2::slot3: for bugs, questions, and account or billing help.')), [':slot1:' => '<a class="text-link strong-link" href="mailto:'.e($supportEmail).'">', ':slot2:' => e($supportEmail), ':slot3:' => '</a>']) !!}</p>
            <p>{{ __('Include what you were doing, what you expected, and what happened instead. For generation issues, include your account email, Support ID, and failure code from your dashboard.') }}</p>
        </div>
        <div data-reveal data-reveal-delay="100">
            <h2>{{ __('Looking for instructions?') }}</h2>
            <p>{!! strtr(e(__('Start with :slot1:How to install:slot2: for Chrome Web Store and manual installation steps.')), [':slot1:' => '<a class="strong-link" href="'.e(\App\Support\WebsiteLocale::route('marketing.how-to-use')).'#how-to-install">', ':slot2:' => '</a>']) !!}</p>
            <p>{!! strtr(e(__('The :slot1:How To Use guide:slot2: covers generation, lyrics correction, single-word fixes, and study tools.')), [':slot1:' => '<a class="strong-link" href="'.e(\App\Support\WebsiteLocale::route('marketing.how-to-use')).'">', ':slot2:' => '</a>']) !!}</p>
        </div>
    </section>

    <section class="section support-grid" data-reveal>
        <article>
            <h2>{{ __('Generation support') }}</h2>
            <p>{{ __('Share status, stage, timings, language pair, minute usage, and failure code from the job detail page.') }}</p>
        </article>
        <article>
            <h2>{{ __('Billing support') }}</h2>
            <p>{{ __('Use the dashboard billing portal for card and subscription changes. Contact support for refunds or invoice issues.') }}</p>
        </article>
        <article>
            <h2>{{ __('Language coverage') }}</h2>
            <p>{{ __('Report repeated accuracy issues with the language pair and public video context, but avoid sending full transcripts.') }}</p>
        </article>
    </section>
@endsection
