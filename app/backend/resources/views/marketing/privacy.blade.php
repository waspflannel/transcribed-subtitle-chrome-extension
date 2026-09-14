@extends('layouts.site')

@section('content')
    <section class="page-hero legal-hero">
        <p class="eyebrow">The details</p>
        <h1 id="legal-title">Privacy <span class="hl">policy.</span></h1>
        <p>What we process, why we need it, and the choices you have when you use our website and Chrome extension.</p>
        <div class="legal-meta">
            <span>Last updated <time datetime="2026-09-14">September 14, 2026</time></span>
            <a href="{{ route('marketing.terms') }}">Read the terms of service &rarr;</a>
        </div>
    </section>

    <div class="section legal-layout">
        <nav class="legal-toc" aria-label="On this page">
            <p class="eyebrow">On this page</p>
            <ol>
                <li><a href="#scope">Where this applies</a></li>
                <li><a href="#data">Information we process</a></li>
                <li><a href="#processing">How features use data</a></li>
                <li><a href="#providers">Service providers</a></li>
                <li><a href="#storage">Cookies &amp; analytics</a></li>
                <li><a href="#retention">How long we keep it</a></li>
                <li><a href="#choices">Your choices</a></li>
                <li><a href="#contact">Contact &amp; updates</a></li>
            </ol>
        </nav>

        <article class="legal-copy" aria-labelledby="legal-title">
            <section id="scope">
                <h2><span aria-hidden="true">01</span> Where this applies</h2>
                <p>This policy explains how {{ config('marketing.product_name') }} handles information through our website, account services, and Chrome extension. It covers subtitle generation, translations, pronunciation guides, word cards, and lyrics correction for supported public YouTube videos.</p>
                <p>YouTube, Chrome, and the third-party services linked below also have their own privacy notices. This policy describes our service and the information we send to those providers.</p>
            </section>

            <section id="data">
                <h2><span aria-hidden="true">02</span> Information we process</h2>
                <ul>
                    <li><strong>Account details:</strong> your name, email address, password hash, verification status, and sign-in sessions or extension tokens.</li>
                    <li><strong>Billing and usage:</strong> subscription and customer identifiers, plan status, and records of minutes reserved or used. Payment details are collected through Stripe Checkout; our application stores billing references rather than your full card number.</li>
                    <li><strong>Video and study content:</strong> the selected public YouTube URL and video identifier, audio needed for transcription, language and model choices, generated subtitles, translations, readings, and word-card content.</li>
                    <li><strong>Your corrections:</strong> lyrics you paste, individual word changes, and the subtitle context needed to apply those edits.</li>
                    <li><strong>Operational and support information:</strong> job status, timing, error codes, request metadata, and messages you send to support. Our servers and service providers receive connection information such as your IP address and browser headers.</li>
                </ul>
            </section>

            <section id="processing">
                <h2><span aria-hidden="true">03</span> How features use data</h2>
                <p>We use this information to authenticate your account, run billing, check your available minutes, deliver study features, restore recent work, troubleshoot failures, and protect the service from abuse.</p>
                <p>When you use the extension on a supported video, it can check for existing tracks and retrieve video metadata. Starting generation sends the selected video and your settings to our backend, which acquires audio or passes the public video URL to the transcription provider. Text-processing providers then create the requested study layers.</p>
                <p>Word cards and single-word corrections can send the selected text and surrounding subtitle context for processing. Full lyrics correction sends your pasted lyrics and the existing track context to fit replacement words to the current timing and refresh the study layers.</p>
                <p>Only submit videos and lyrics you are allowed to use. Avoid putting sensitive personal information into lyrics, corrections, or support messages.</p>
            </section>

            <section id="providers">
                <h2><span aria-hidden="true">04</span> Service providers</h2>
                <p>Our model names describe product choices. The companies below provide the underlying services and receive the information needed for their part of the workflow:</p>
                <ul>
                    <li><strong>ElevenLabs:</strong> audio or a public video URL for speech transcription. <a href="https://elevenlabs.io/privacy-policy">ElevenLabs privacy policy</a>.</li>
                    <li><strong>OpenAI:</strong> text processing for <strong>Transcriber</strong>, including translations and study content. Full lyrics correction also uses OpenAI, including when the original track used Transcriber-Spark. <a href="https://openai.com/enterprise-privacy/">OpenAI business data privacy</a>.</li>
                    <li><strong>Cerebras:</strong> text processing for <strong>Transcriber-Spark</strong>, including its translations and study content. <a href="https://www.cerebras.ai/privacy-policy">Cerebras privacy policy</a>.</li>
                    <li><strong>Stripe:</strong> payments, recurring subscriptions, and the billing portal. <a href="https://stripe.com/privacy">Stripe privacy policy</a>.</li>
                    <li><strong>Google Fonts:</strong> fonts loaded by the website. Your browser contacts Google to request them, sharing connection information such as your IP address and browser headers. <a href="https://fonts.googleblog.com/2022/11/your-privacy-and-google-fonts.html">Google Fonts privacy information</a>.</li>
                </ul>
                <p>We also use infrastructure and delivery services to host the application, store data, and send account emails. Providers may process information outside your country. Their own retention rules and the agreements or settings for the services we use can differ from our track-retention period below.</p>
                <p>Information may also be disclosed where required by law or where necessary to investigate abuse and protect the service or the rights of others.</p>
            </section>

            <section id="storage">
                <h2><span aria-hidden="true">05</span> Cookies &amp; analytics</h2>
                <p>The website uses session and security cookies for functions such as sign-in and protecting forms. The extension uses local browser storage for your sign-in token, installation identifier, preferences, recent tracks, and in-progress work. Clearing that storage can sign you out or remove locally remembered work.</p>
                <p>We use first-party operational analytics to understand page visits, signup, checkout, extension connection, and generation activity. Events can include plan, language, model, duration, and job status, with hashed account or installation identifiers. Those identifiers are pseudonymous, not a guarantee of anonymity.</p>
                <p>These analytics events exclude transcripts, pasted lyrics, prompts, generated subtitles, full YouTube URLs, provider payloads, passwords, and access tokens. Content still needs to be processed and stored for the study features described above.</p>
            </section>

            <section id="retention">
                <h2><span aria-hidden="true">06</span> How long we keep it</h2>
                <ul>
                    <li><strong>Audio:</strong> temporary raw audio is deleted after processing succeeds or fails.</li>
                    <li><strong>Generated tracks:</strong> saved tracks are available for 30 days. Expired tracks and related jobs are removed by scheduled cleanup.</li>
                    <li><strong>Lyrics corrections:</strong> pasted lyrics and temporary correction state are encrypted while stored for processing and cleared when the correction completes, fails, or is cancelled. The resulting corrected subtitles remain part of the saved track.</li>
                    <li><strong>Public-video transcript cache:</strong> we can reuse a transcription of the same public video for later requests, including requests from other users. This shared cache is separate from your account and does not contain your pasted lyrics or personal corrections. It normally expires after 30 days; a refreshed cache entry can start a new retention period.</li>
                    <li><strong>Account and service records:</strong> account, billing, support, and security records can be kept longer than subtitle tracks where needed to run your account, resolve issues, prevent abuse, or meet legal obligations.</li>
                </ul>
                <p>Deleting an account or track does not automatically delete copies held by external providers or the separate public-video transcript cache. Backup copies and records subject to legal retention may remain until their applicable retention periods end.</p>
            </section>

            <section id="choices">
                <h2><span aria-hidden="true">07</span> Your choices</h2>
                <p>You choose which supported videos to process, which model and study layers to use, and whether to submit lyrics or word corrections. You can remove saved generations through your account and clear local extension data through your browser.</p>
                <p>To delete your account, open the <a href="{{ route('dashboard') }}">account dashboard</a> and use <strong>Delete account</strong>. This requires your current password, cancels an active subscription, revokes access, and deletes account-linked records, including saved jobs and tracks. If subscription cancellation fails, the account is kept so you can retry. Stripe may retain its own payment records.</p>
                <p>Depending on where you live, you may have rights to access, correct, delete, or obtain a copy of personal information, restrict or object to processing, withdraw consent where processing relies on it, or complain to a privacy authority. <a href="{{ route('marketing.support') }}">Contact support</a> to make a request. We may need to verify your identity before acting on account information.</p>
            </section>

            <section id="contact">
                <h2><span aria-hidden="true">08</span> Contact &amp; updates</h2>
                <p>For privacy questions or requests, use our <a href="{{ route('marketing.support') }}">support page</a>. Include the email address associated with your account and a brief description of your request. Never send your password or access token.</p>
                <p>We will update this page when our practices change and revise the date at the top. Where required, we will provide additional notice or ask for consent before a new use of your information.</p>
            </section>
        </article>
    </div>
@endsection
