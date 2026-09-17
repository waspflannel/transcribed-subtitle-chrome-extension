@extends('layouts.site')

@section('content')
    <section class="page-hero legal-hero">
        <p class="eyebrow">{{ __('The details') }}</p>
        <h1 id="legal-title">{!! strtr(e(__('Privacy :slot1:policy.:slot2:')), [':slot1:' => '<span class="hl">', ':slot2:' => '</span>']) !!}</h1>
        <p>{{ __('What we process, why we need it, and the choices you have when you use our website and Chrome extension.') }}</p>
        <div class="legal-meta">
            <span>{!! strtr(e(__('Last updated :slot1:September 14, 2026:slot2:')), [':slot1:' => '<time datetime="2026-09-14">', ':slot2:' => '</time>']) !!}</span>
            <a href="{{ \App\Support\WebsiteLocale::route('marketing.terms') }}">{{ __('Read the terms of service →') }}</a>
        </div>
    </section>

    <div class="section legal-layout">
        <nav class="legal-toc" aria-label="{{ __('On this page') }}">
            <p class="eyebrow">{{ __('On this page') }}</p>
            <ol>
                <li>{!! strtr(e(__(':slot1:Where this applies:slot2:')), [':slot1:' => '<a href="#scope">', ':slot2:' => '</a>']) !!}</li>
                <li>{!! strtr(e(__(':slot1:Information we process:slot2:')), [':slot1:' => '<a href="#data">', ':slot2:' => '</a>']) !!}</li>
                <li>{!! strtr(e(__(':slot1:How features use data:slot2:')), [':slot1:' => '<a href="#processing">', ':slot2:' => '</a>']) !!}</li>
                <li>{!! strtr(e(__(':slot1:Service providers:slot2:')), [':slot1:' => '<a href="#providers">', ':slot2:' => '</a>']) !!}</li>
                <li>{!! strtr(e(__(':slot1:Cookies & analytics:slot2:')), [':slot1:' => '<a href="#storage">', ':slot2:' => '</a>']) !!}</li>
                <li>{!! strtr(e(__(':slot1:How long we keep it:slot2:')), [':slot1:' => '<a href="#retention">', ':slot2:' => '</a>']) !!}</li>
                <li>{!! strtr(e(__(':slot1:Your choices:slot2:')), [':slot1:' => '<a href="#choices">', ':slot2:' => '</a>']) !!}</li>
                <li>{!! strtr(e(__(':slot1:Contact & updates:slot2:')), [':slot1:' => '<a href="#contact">', ':slot2:' => '</a>']) !!}</li>
            </ol>
        </nav>

        <article class="legal-copy" aria-labelledby="legal-title">
            <section id="scope">
                <h2>{!! strtr(e(__(':slot1:01:slot2: Where this applies')), [':slot1:' => '<span aria-hidden="true">', ':slot2:' => '</span>']) !!}</h2>
                <p>{!! strtr(e(__('This policy explains how :slot1: handles information through our website, account services, and Chrome extension. It covers subtitle generation, translations, pronunciation guides, word cards, and lyrics correction for supported public YouTube videos.')), [':slot1:' => e(config('marketing.product_name'))]) !!}</p>
                <p>{{ __('YouTube, Chrome, and the third-party services linked below also have their own privacy notices. This policy describes our service and the information we send to those providers.') }}</p>
            </section>

            <section id="data">
                <h2>{!! strtr(e(__(':slot1:02:slot2: Information we process')), [':slot1:' => '<span aria-hidden="true">', ':slot2:' => '</span>']) !!}</h2>
                <ul>
                    <li>{!! strtr(e(__(':slot1:Account details::slot2: your name, email address, password hash, verification status, and sign-in sessions or extension tokens.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>']) !!}</li>
                    <li>{!! strtr(e(__(':slot1:Billing and usage::slot2: subscription and customer identifiers, plan status, and records of minutes reserved or used. Payment details are collected through Stripe Checkout; our application stores billing references rather than your full card number.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>']) !!}</li>
                    <li>{!! strtr(e(__(':slot1:Video and study content::slot2: the selected public YouTube URL and video identifier, audio needed for transcription, language and model choices, generated subtitles, translations, readings, and word-card content.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>']) !!}</li>
                    <li>{!! strtr(e(__(':slot1:Your corrections::slot2: lyrics you paste, individual word changes, and the subtitle context needed to apply those edits.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>']) !!}</li>
                    <li>{!! strtr(e(__(':slot1:Operational and support information::slot2: job status, timing, error codes, request metadata, and messages you send to support. Our servers and service providers receive connection information such as your IP address and browser headers.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>']) !!}</li>
                </ul>
            </section>

            <section id="processing">
                <h2>{!! strtr(e(__(':slot1:03:slot2: How features use data')), [':slot1:' => '<span aria-hidden="true">', ':slot2:' => '</span>']) !!}</h2>
                <p>{{ __('We use this information to authenticate your account, run billing, check your available minutes, deliver study features, restore recent work, troubleshoot failures, and protect the service from abuse.') }}</p>
                <p>{{ __('When you use the extension on a supported video, it can check for existing tracks and retrieve video metadata. Starting generation sends the selected video and your settings to our backend, which acquires audio or passes the public video URL to the transcription provider. Text-processing providers then create the requested study layers.') }}</p>
                <p>{{ __('Word cards and single-word corrections can send the selected text and surrounding subtitle context for processing. Full lyrics correction sends your pasted lyrics and the existing track context to fit replacement words to the current timing and refresh the study layers.') }}</p>
                <p>{{ __('Only submit videos and lyrics you are allowed to use. Avoid putting sensitive personal information into lyrics, corrections, or support messages.') }}</p>
            </section>

            <section id="providers">
                <h2>{!! strtr(e(__(':slot1:04:slot2: Service providers')), [':slot1:' => '<span aria-hidden="true">', ':slot2:' => '</span>']) !!}</h2>
                <p>{{ __('Our model names describe product choices. The companies below provide the underlying services and receive the information needed for their part of the workflow:') }}</p>
                <ul>
                    <li>{!! strtr(e(__(':slot1:ElevenLabs::slot2: audio or a public video URL for speech transcription. :slot3:ElevenLabs privacy policy:slot4:.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>', ':slot3:' => '<a href="https://elevenlabs.io/privacy-policy">', ':slot4:' => '</a>']) !!}</li>
                    <li>{!! strtr(e(__(':slot1:OpenAI::slot2: text processing for :slot3:Transcriber:slot4:, including translations and study content. Full lyrics correction also uses OpenAI, including when the original track used Transcriber-Spark. :slot5:OpenAI business data privacy:slot6:.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>', ':slot3:' => '<strong>', ':slot4:' => '</strong>', ':slot5:' => '<a href="https://openai.com/enterprise-privacy/">', ':slot6:' => '</a>']) !!}</li>
                    <li>{!! strtr(e(__(':slot1:Cerebras::slot2: text processing for :slot3:Transcriber-Spark:slot4:, including its translations and study content. :slot5:Cerebras privacy policy:slot6:.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>', ':slot3:' => '<strong>', ':slot4:' => '</strong>', ':slot5:' => '<a href="https://www.cerebras.ai/privacy-policy">', ':slot6:' => '</a>']) !!}</li>
                    <li>{!! strtr(e(__(':slot1:Stripe::slot2: payments, recurring subscriptions, and the billing portal. :slot3:Stripe privacy policy:slot4:.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>', ':slot3:' => '<a href="https://stripe.com/privacy">', ':slot4:' => '</a>']) !!}</li>
                    <li>{!! strtr(e(__(':slot1:Google Fonts::slot2: fonts loaded by the website. Your browser contacts Google to request them, sharing connection information such as your IP address and browser headers. :slot3:Google Fonts privacy information:slot4:.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>', ':slot3:' => '<a href="https://fonts.googleblog.com/2022/11/your-privacy-and-google-fonts.html">', ':slot4:' => '</a>']) !!}</li>
                </ul>
                <p>{{ __('We also use infrastructure and delivery services to host the application, store data, and send account emails. Providers may process information outside your country. Their own retention rules and the agreements or settings for the services we use can differ from our track-retention period below.') }}</p>
                <p>{{ __('Information may also be disclosed where required by law or where necessary to investigate abuse and protect the service or the rights of others.') }}</p>
            </section>

            <section id="storage">
                <h2>{!! strtr(e(__(':slot1:05:slot2: Cookies & analytics')), [':slot1:' => '<span aria-hidden="true">', ':slot2:' => '</span>']) !!}</h2>
                <p>{{ __('The website uses session, security, and language-preference cookies for functions such as sign-in, protecting forms, and remembering your interface language. The extension uses local browser storage for your sign-in token, installation identifier, preferences, recent tracks, and in-progress work. Clearing that storage can sign you out or remove locally remembered work.') }}</p>
                <p>{{ __('We use first-party operational analytics to understand page visits, signup, checkout, extension connection, and generation activity. Events can include plan, language, model, duration, and job status, with hashed account or installation identifiers. Those identifiers are pseudonymous, not a guarantee of anonymity.') }}</p>
                <p>{{ __('These analytics events exclude transcripts, pasted lyrics, prompts, generated subtitles, full YouTube URLs, provider payloads, passwords, and access tokens. Content still needs to be processed and stored for the study features described above.') }}</p>
            </section>

            <section id="retention">
                <h2>{!! strtr(e(__(':slot1:06:slot2: How long we keep it')), [':slot1:' => '<span aria-hidden="true">', ':slot2:' => '</span>']) !!}</h2>
                <ul>
                    <li>{!! strtr(e(__(':slot1:Audio::slot2: temporary raw audio is deleted after processing succeeds or fails.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>']) !!}</li>
                    <li>{!! strtr(e(__(':slot1:Generated tracks::slot2: saved tracks are available for 30 days. Expired tracks and related jobs are removed by scheduled cleanup.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>']) !!}</li>
                    <li>{!! strtr(e(__(':slot1:Lyrics corrections::slot2: pasted lyrics and temporary correction state are encrypted while stored for processing and cleared when the correction completes, fails, or is cancelled. The resulting corrected subtitles remain part of the saved track.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>']) !!}</li>
                    <li>{!! strtr(e(__(':slot1:Public-video transcript cache::slot2: we can reuse a transcription of the same public video for later requests, including requests from other users. This shared cache is separate from your account and does not contain your pasted lyrics or personal corrections. It normally expires after 30 days; a refreshed cache entry can start a new retention period.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>']) !!}</li>
                    <li>{!! strtr(e(__(':slot1:Account and service records::slot2: account, billing, support, and security records can be kept longer than subtitle tracks where needed to run your account, resolve issues, prevent abuse, or meet legal obligations.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>']) !!}</li>
                </ul>
                <p>{{ __('Deleting an account or track does not automatically delete copies held by external providers or the separate public-video transcript cache. Backup copies and records subject to legal retention may remain until their applicable retention periods end.') }}</p>
            </section>

            <section id="choices">
                <h2>{!! strtr(e(__(':slot1:07:slot2: Your choices')), [':slot1:' => '<span aria-hidden="true">', ':slot2:' => '</span>']) !!}</h2>
                <p>{{ __('You choose which supported videos to process, which model and study layers to use, and whether to submit lyrics or word corrections. You can remove saved generations through your account and clear local extension data through your browser.') }}</p>
                <p>{!! strtr(e(__('To delete your account, open the :slot1:account dashboard:slot2: and use :slot3:Delete account:slot4:. This requires your current password, cancels an active subscription, revokes access, and deletes account-linked records, including saved jobs and tracks. If subscription cancellation fails, the account is kept so you can retry. Stripe may retain its own payment records.')), [':slot1:' => '<a href="'.e(route('dashboard')).'">', ':slot2:' => '</a>', ':slot3:' => '<strong>', ':slot4:' => '</strong>']) !!}</p>
                <p>{!! strtr(e(__('Depending on where you live, you may have rights to access, correct, delete, or obtain a copy of personal information, restrict or object to processing, withdraw consent where processing relies on it, or complain to a privacy authority. :slot1:Contact support:slot2: to make a request. We may need to verify your identity before acting on account information.')), [':slot1:' => '<a href="'.e(\App\Support\WebsiteLocale::route('marketing.support')).'">', ':slot2:' => '</a>']) !!}</p>
            </section>

            <section id="contact">
                <h2>{!! strtr(e(__(':slot1:08:slot2: Contact & updates')), [':slot1:' => '<span aria-hidden="true">', ':slot2:' => '</span>']) !!}</h2>
                <p>{!! strtr(e(__('For privacy questions or requests, use our :slot1:support page:slot2:. Include the email address associated with your account and a brief description of your request. Never send your password or access token.')), [':slot1:' => '<a href="'.e(\App\Support\WebsiteLocale::route('marketing.support')).'">', ':slot2:' => '</a>']) !!}</p>
                <p>{{ __('We will update this page when our practices change and revise the date at the top. Where required, we will provide additional notice or ask for consent before a new use of your information.') }}</p>
            </section>
        </article>
    </div>
@endsection
