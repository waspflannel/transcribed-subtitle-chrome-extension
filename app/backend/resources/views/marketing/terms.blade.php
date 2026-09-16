@extends('layouts.site')

@section('content')
    <section class="page-hero legal-hero">
        <p class="eyebrow">The details</p>
        <h1 id="legal-title">Terms of <span class="hl">service.</span></h1>
        <p>The terms for using our service, including subscriptions, video minutes, lyrics correction, and AI-generated study content.</p>
        <div class="legal-meta">
            <span>Last updated <time datetime="2026-09-16">September 16, 2026</time></span>
            <a href="{{ route('marketing.privacy') }}">Read the privacy policy &rarr;</a>
        </div>
    </section>

    <div class="section legal-layout">
        <nav class="legal-toc" aria-label="On this page">
            <p class="eyebrow">On this page</p>
            <ol>
                <li><a href="#service">Using the service</a></li>
                <li><a href="#account">Your account</a></li>
                <li><a href="#billing">Plans &amp; video minutes</a></li>
                <li><a href="#cancellation">Cancellation &amp; refunds</a></li>
                <li><a href="#content">Videos &amp; lyrics</a></li>
                <li><a href="#acceptable-use">Acceptable use</a></li>
                <li><a href="#ai-limitations">AI &amp; timing limitations</a></li>
                <li><a href="#availability">Service availability</a></li>
                <li><a href="#contact">Updates &amp; contact</a></li>
            </ol>
        </nav>

        <article class="legal-copy" aria-labelledby="legal-title">
            <section id="service">
                <h2><span aria-hidden="true">01</span> Using the service</h2>
                <p>These terms apply to your use of {{ config('marketing.product_name') }}, including our website and Chrome extension. By creating an account or using the service, you agree to these terms. If you do not agree, do not use the service.</p>
                <p>The service generates study-oriented subtitles on supported public YouTube watch pages and Shorts in desktop Chrome. Features include translation, pronunciation guides, word cards, model choice, and lyrics correction. Check the <a href="{{ route('marketing.how-to-use') }}#how-to-install">extension installation information</a> before subscribing.</p>
                <p>Our <a href="{{ route('marketing.privacy') }}">privacy policy</a> explains how account information, videos, lyrics, and generated content are processed.</p>
            </section>

            <section id="account">
                <h2><span aria-hidden="true">02</span> Your account</h2>
                <p>Provide accurate account information, keep your sign-in details secure, and tell <a href="{{ route('marketing.support') }}">support</a> if you believe someone has accessed your account without permission. Do not share access tokens or use another person's account without authorization.</p>
                <p>You must have the legal capacity or authorization required to agree to these terms and purchase a subscription. You are responsible for the content you submit and your use of the service.</p>
            </section>

            <section id="billing">
                <h2><span aria-hidden="true">03</span> Plans &amp; video minutes</h2>
                <p>The <a href="{{ route('marketing.pricing') }}">pricing page</a> and checkout show the price, currency, included monthly video minutes, and generation limits for your plan. Subscriptions are billed through Stripe and renew monthly unless cancelled. Review the checkout total and recurring payment details before confirming payment.</p>
                <ul>
                    <li>Generation is measured in video minutes, with video duration rounded up to the next whole minute. Your account shows the allowance and remaining balance.</li>
                    <li>Starting a job can reserve minutes. A completed track uses those minutes; a failed job releases unused reservations when no completed track is produced.</li>
                    <li>You can cancel generation, but cancelling or deleting an in-progress job uses all the minutes reserved for it. Review the confirmation before starting generation.</li>
                    <li>Reopening a retained track does not spend generation minutes again. Starting a new generation, including with a different model, can use additional minutes.</li>
                    <li>Single-word fixes and full lyrics correction on an existing track do not consume additional generation minutes under the current plans.</li>
                    <li>Deleting a completed generation does not restore the minutes used to create it. Included minutes are a service allowance and cannot be redeemed for cash.</li>
                </ul>
            </section>

            <section id="cancellation">
                <h2><span aria-hidden="true">04</span> Cancellation &amp; refunds</h2>
                <p>Manage or cancel your subscription through the billing portal linked from your <a href="{{ route('dashboard') }}">account dashboard</a>. The portal shows when cancellation takes effect and whether access continues through the end of the current billing period. Uninstalling the extension or signing out does not cancel your subscription.</p>
                <p>Using <strong>Delete account</strong> cancels an active subscription immediately and deletes account-linked data. If the subscription cannot be cancelled, account deletion does not proceed. Account deletion does not itself issue a refund.</p>
                <p>Refund requests should go through <a href="{{ route('marketing.support') }}">support</a>. We consider requests involving billing, access, or generation failures that prevent reasonable use of the subscription. Include your account email and the relevant charge or job identifier, without sending payment credentials.</p>
                <p>Nothing in these terms limits cancellation, refund, or other consumer rights that apply by law.</p>
            </section>

            <section id="content">
                <h2><span aria-hidden="true">05</span> Videos &amp; lyrics</h2>
                <p>Only submit public videos, lyrics, and other text that you have the right or permission to use with this service. Public availability does not mean content is free of copyright or other restrictions. You remain responsible for following applicable law and YouTube's terms.</p>
                <p>You retain any rights you hold in content you submit. You authorize us and our service providers to process, reproduce, store, and transform it as needed to provide the requested features, as described in the privacy policy. Public-video transcriptions may be cached and reused for later requests; your pasted lyrics and personal corrections are not added to that shared cache.</p>
                <p>The service does not grant rights to third-party videos, music, or lyrics, and generated output does not remove the rights of the original creators. Check your permissions before publishing, distributing, or otherwise reusing content.</p>
            </section>

            <section id="acceptable-use">
                <h2><span aria-hidden="true">06</span> Acceptable use</h2>
                <p>Do not use the service to violate the law or the rights of others, submit malicious content, interfere with other users, or access private or restricted videos through unauthorized means.</p>
                <p>Do not bypass account, billing, minute, rate, provider, or platform restrictions; probe for other users' data; or use automated requests to overload the service. We may restrict or suspend access where necessary to address misuse, security threats, or unpaid subscriptions. Contact support if you believe access was restricted in error.</p>
            </section>

            <section id="ai-limitations">
                <h2><span aria-hidden="true">07</span> AI &amp; timing limitations</h2>
                <p>Transcriber prioritizes language understanding and quality. Transcriber-Spark prioritizes speed. These are product choices, not guarantees of accuracy or completion time. Wait times depend on video length, workload, and provider availability.</p>
                <p>Subtitles, translations, pronunciation guides, and word cards can be incomplete or incorrect. Singing, background noise, overlapping voices, and ambiguous language can affect results. Review output before relying on it. The service is a learning aid, not a source of official captions, certified translation, or guaranteed accessibility compliance.</p>
                <p>Full lyrics correction fits pasted lyrics to the existing subtitle timing; it does not create a new audio alignment. Words can be omitted or matched imperfectly. A successful replacement overwrites the current track and has no undo, so keep a separate copy of anything you need to preserve.</p>
            </section>

            <section id="availability">
                <h2><span aria-hidden="true">08</span> Service availability</h2>
                <p>Features, supported videos, language performance, and model availability may change over time. Service can be interrupted by maintenance, errors, or changes to YouTube, Chrome, payment services, or AI providers. We do not promise uninterrupted availability or that every video will generate successfully.</p>
                <p>Generated tracks are available for 30 days and may be deleted after expiry or when you delete them or your account. The service is not a permanent archive. Keep independent copies of material you need, where you have the right to do so.</p>
                <p>Any service limitations in these terms apply only to the extent permitted by law and do not exclude statutory guarantees or remedies that cannot be excluded.</p>
            </section>

            <section id="contact">
                <h2><span aria-hidden="true">09</span> Updates &amp; contact</h2>
                <p>We may update these terms as the service develops. The date at the top identifies the latest revision. We will provide notice of material changes where required, including changes affecting recurring charges, and obtain any consent required by applicable law.</p>
                <p>For questions about these terms, billing, or a service issue, <a href="{{ route('marketing.support') }}">contact support</a>.</p>
            </section>
        </article>
    </div>
@endsection
