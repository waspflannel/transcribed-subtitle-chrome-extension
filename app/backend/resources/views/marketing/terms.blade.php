@extends('layouts.site')

@section('content')
    <section class="page-hero legal-hero">
        <p class="eyebrow">{{ __('The details') }}</p>
        <h1 id="legal-title">{!! strtr(e(__('Terms of :slot1:service.:slot2:')), [':slot1:' => '<span class="hl">', ':slot2:' => '</span>']) !!}</h1>
        <p>{{ __('The terms for using our service, including subscriptions, video minutes, lyrics correction, and AI-generated study content.') }}</p>
        <div class="legal-meta">
            <span>{!! strtr(e(__('Last updated :slot1:September 16, 2026:slot2:')), [':slot1:' => '<time datetime="2026-09-16">', ':slot2:' => '</time>']) !!}</span>
            <a href="{{ \App\Support\WebsiteLocale::route('marketing.privacy') }}">{{ __('Read the privacy policy →') }}</a>
        </div>
    </section>

    <div class="section legal-layout">
        <nav class="legal-toc" aria-label="{{ __('On this page') }}">
            <p class="eyebrow">{{ __('On this page') }}</p>
            <ol>
                <li>{!! strtr(e(__(':slot1:Using the service:slot2:')), [':slot1:' => '<a href="#service">', ':slot2:' => '</a>']) !!}</li>
                <li>{!! strtr(e(__(':slot1:Your account:slot2:')), [':slot1:' => '<a href="#account">', ':slot2:' => '</a>']) !!}</li>
                <li>{!! strtr(e(__(':slot1:Plans & video minutes:slot2:')), [':slot1:' => '<a href="#billing">', ':slot2:' => '</a>']) !!}</li>
                <li>{!! strtr(e(__(':slot1:Cancellation & refunds:slot2:')), [':slot1:' => '<a href="#cancellation">', ':slot2:' => '</a>']) !!}</li>
                <li>{!! strtr(e(__(':slot1:Videos & lyrics:slot2:')), [':slot1:' => '<a href="#content">', ':slot2:' => '</a>']) !!}</li>
                <li>{!! strtr(e(__(':slot1:Acceptable use:slot2:')), [':slot1:' => '<a href="#acceptable-use">', ':slot2:' => '</a>']) !!}</li>
                <li>{!! strtr(e(__(':slot1:AI & timing limitations:slot2:')), [':slot1:' => '<a href="#ai-limitations">', ':slot2:' => '</a>']) !!}</li>
                <li>{!! strtr(e(__(':slot1:Service availability:slot2:')), [':slot1:' => '<a href="#availability">', ':slot2:' => '</a>']) !!}</li>
                <li>{!! strtr(e(__(':slot1:Updates & contact:slot2:')), [':slot1:' => '<a href="#contact">', ':slot2:' => '</a>']) !!}</li>
            </ol>
        </nav>

        <article class="legal-copy" aria-labelledby="legal-title">
            <section id="service">
                <h2>{!! strtr(e(__(':slot1:01:slot2: Using the service')), [':slot1:' => '<span aria-hidden="true">', ':slot2:' => '</span>']) !!}</h2>
                <p>{!! strtr(e(__('These terms apply to your use of :slot1:, including our website and Chrome extension. By creating an account or using the service, you agree to these terms. If you do not agree, do not use the service.')), [':slot1:' => e(config('marketing.product_name'))]) !!}</p>
                <p>{!! strtr(e(__('The service generates study-oriented subtitles on supported public YouTube watch pages and Shorts in desktop Chrome. Features include translation, pronunciation guides, word cards, model choice, and lyrics correction. Check the :slot1:extension installation information:slot2: before subscribing.')), [':slot1:' => '<a href="'.e(\App\Support\WebsiteLocale::route('marketing.how-to-use')).'#how-to-install">', ':slot2:' => '</a>']) !!}</p>
                <p>{!! strtr(e(__('Our :slot1:privacy policy:slot2: explains how account information, videos, lyrics, and generated content are processed.')), [':slot1:' => '<a href="'.e(\App\Support\WebsiteLocale::route('marketing.privacy')).'">', ':slot2:' => '</a>']) !!}</p>
            </section>

            <section id="account">
                <h2>{!! strtr(e(__(':slot1:02:slot2: Your account')), [':slot1:' => '<span aria-hidden="true">', ':slot2:' => '</span>']) !!}</h2>
                <p>{!! strtr(e(__('Provide accurate account information, keep your sign-in details secure, and tell :slot1:support:slot2: if you believe someone has accessed your account without permission. Do not share access tokens or use another person\'s account without authorization.')), [':slot1:' => '<a href="'.e(\App\Support\WebsiteLocale::route('marketing.support')).'">', ':slot2:' => '</a>']) !!}</p>
                <p>{{ __('You must have the legal capacity or authorization required to agree to these terms and purchase a subscription. You are responsible for the content you submit and your use of the service.') }}</p>
            </section>

            <section id="billing">
                <h2>{!! strtr(e(__(':slot1:03:slot2: Plans & video minutes')), [':slot1:' => '<span aria-hidden="true">', ':slot2:' => '</span>']) !!}</h2>
                <p>{!! strtr(e(__('The :slot1:pricing page:slot2: and checkout show the price, currency, included monthly video minutes, and generation limits for your plan. Subscriptions are billed through Stripe and renew monthly unless cancelled. Review the checkout total and recurring payment details before confirming payment.')), [':slot1:' => '<a href="'.e(\App\Support\WebsiteLocale::route('marketing.pricing')).'">', ':slot2:' => '</a>']) !!}</p>
                <ul>
                    <li>{{ __('Generation is measured in video minutes, with video duration rounded up to the next whole minute. Your account shows the allowance and remaining balance.') }}</li>
                    <li>{{ __('Starting a job can reserve minutes. A completed track uses those minutes; a failed job releases unused reservations when no completed track is produced.') }}</li>
                    <li>{{ __('You can cancel generation, but cancelling or deleting an in-progress job uses all the minutes reserved for it. Review the confirmation before starting generation.') }}</li>
                    <li>{{ __('Reopening a retained track does not spend generation minutes again. Starting a new generation, including with a different model, can use additional minutes.') }}</li>
                    <li>{{ __('Single-word fixes and full lyrics correction on an existing track do not consume additional generation minutes under the current plans.') }}</li>
                    <li>{{ __('Deleting a completed generation does not restore the minutes used to create it. Included minutes are a service allowance and cannot be redeemed for cash.') }}</li>
                </ul>
            </section>

            <section id="cancellation">
                <h2>{!! strtr(e(__(':slot1:04:slot2: Cancellation & refunds')), [':slot1:' => '<span aria-hidden="true">', ':slot2:' => '</span>']) !!}</h2>
                <p>{!! strtr(e(__('Manage or cancel your subscription through the billing portal linked from your :slot1:account dashboard:slot2:. The portal shows when cancellation takes effect and whether access continues through the end of the current billing period. Uninstalling the extension or signing out does not cancel your subscription.')), [':slot1:' => '<a href="'.e(route('dashboard')).'">', ':slot2:' => '</a>']) !!}</p>
                <p>{!! strtr(e(__('Using :slot1:Delete account:slot2: cancels an active subscription immediately and deletes account-linked data. If the subscription cannot be cancelled, account deletion does not proceed. Account deletion does not itself issue a refund.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>']) !!}</p>
                <p>{!! strtr(e(__('Refund requests should go through :slot1:support:slot2:. We consider requests involving billing, access, or generation failures that prevent reasonable use of the subscription. Include your account email and the relevant charge or job identifier, without sending payment credentials.')), [':slot1:' => '<a href="'.e(\App\Support\WebsiteLocale::route('marketing.support')).'">', ':slot2:' => '</a>']) !!}</p>
                <p>{{ __('Nothing in these terms limits cancellation, refund, or other consumer rights that apply by law.') }}</p>
            </section>

            <section id="content">
                <h2>{!! strtr(e(__(':slot1:05:slot2: Videos & lyrics')), [':slot1:' => '<span aria-hidden="true">', ':slot2:' => '</span>']) !!}</h2>
                <p>{{ __('Only submit public videos, lyrics, and other text that you have the right or permission to use with this service. Public availability does not mean content is free of copyright or other restrictions. You remain responsible for following applicable law and YouTube\'s terms.') }}</p>
                <p>{{ __('You retain any rights you hold in content you submit. You authorize us and our service providers to process, reproduce, store, and transform it as needed to provide the requested features, as described in the privacy policy. Public-video transcriptions may be cached and reused for later requests; your pasted lyrics and personal corrections are not added to that shared cache.') }}</p>
                <p>{{ __('The service does not grant rights to third-party videos, music, or lyrics, and generated output does not remove the rights of the original creators. Check your permissions before publishing, distributing, or otherwise reusing content.') }}</p>
            </section>

            <section id="acceptable-use">
                <h2>{!! strtr(e(__(':slot1:06:slot2: Acceptable use')), [':slot1:' => '<span aria-hidden="true">', ':slot2:' => '</span>']) !!}</h2>
                <p>{{ __('Do not use the service to violate the law or the rights of others, submit malicious content, interfere with other users, or access private or restricted videos through unauthorized means.') }}</p>
                <p>{{ __('Do not bypass account, billing, minute, rate, provider, or platform restrictions; probe for other users\' data; or use automated requests to overload the service. We may restrict or suspend access where necessary to address misuse, security threats, or unpaid subscriptions. Contact support if you believe access was restricted in error.') }}</p>
            </section>

            <section id="ai-limitations">
                <h2>{!! strtr(e(__(':slot1:07:slot2: AI & timing limitations')), [':slot1:' => '<span aria-hidden="true">', ':slot2:' => '</span>']) !!}</h2>
                <p>{{ __('Transcriber prioritizes language understanding and quality. Transcriber-Spark prioritizes speed. These are product choices, not guarantees of accuracy or completion time. Wait times depend on video length, workload, and provider availability.') }}</p>
                <p>{{ __('Subtitles, translations, pronunciation guides, and word cards can be incomplete or incorrect. Singing, background noise, overlapping voices, and ambiguous language can affect results. Review output before relying on it. The service is a learning aid, not a source of official captions, certified translation, or guaranteed accessibility compliance.') }}</p>
                <p>{{ __('Full lyrics correction fits pasted lyrics to the existing subtitle timing; it does not create a new audio alignment. Words can be omitted or matched imperfectly. A successful replacement overwrites the current track and has no undo, so keep a separate copy of anything you need to preserve.') }}</p>
            </section>

            <section id="availability">
                <h2>{!! strtr(e(__(':slot1:08:slot2: Service availability')), [':slot1:' => '<span aria-hidden="true">', ':slot2:' => '</span>']) !!}</h2>
                <p>{{ __('Features, supported videos, language performance, and model availability may change over time. Service can be interrupted by maintenance, errors, or changes to YouTube, Chrome, payment services, or AI providers. We do not promise uninterrupted availability or that every video will generate successfully.') }}</p>
                <p>{{ __('Generated tracks are available for 30 days and may be deleted after expiry or when you delete them or your account. The service is not a permanent archive. Keep independent copies of material you need, where you have the right to do so.') }}</p>
                <p>{{ __('Any service limitations in these terms apply only to the extent permitted by law and do not exclude statutory guarantees or remedies that cannot be excluded.') }}</p>
            </section>

            <section id="contact">
                <h2>{!! strtr(e(__(':slot1:09:slot2: Updates & contact')), [':slot1:' => '<span aria-hidden="true">', ':slot2:' => '</span>']) !!}</h2>
                <p>{{ __('We may update these terms as the service develops. The date at the top identifies the latest revision. We will provide notice of material changes where required, including changes affecting recurring charges, and obtain any consent required by applicable law.') }}</p>
                <p>{!! strtr(e(__('For questions about these terms, billing, or a service issue, :slot1:contact support:slot2:.')), [':slot1:' => '<a href="'.e(\App\Support\WebsiteLocale::route('marketing.support')).'">', ':slot2:' => '</a>']) !!}</p>
            </section>
        </article>
    </div>
@endsection
