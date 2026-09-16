<div class="plan-access" data-reveal>
    <p><strong>Included with every plan:</strong> Transcriber and Transcriber-Spark model choice, translations, optional romanization, interactive word cards, and lyrics correction.</p>
    @if (config('marketing.chrome_extension_url'))
        <p>Paid beta for desktop Chrome. <a class="strong-link" href="{{ config('marketing.chrome_extension_url') }}">Get the extension</a>, then choose a subscription to generate subtitles.</p>
    @else
        <p>Paid beta for desktop Chrome. <a class="strong-link" href="{{ route('marketing.support') }}#extension-install">Request your beta install link</a> before subscribing.</p>
    @endif
</div>
<div class="plan-row" data-reveal>
    @foreach ($plans as $plan)
        @php($featured = $loop->index === intdiv($loop->count - 1, 2))
        <article @class(['plan-card', 'plan-card-featured' => $featured])>
            @if ($featured)
                <span class="plan-sticker">Best fit</span>
            @endif
            <div>
                <h3>{{ $plan['name'] }}</h3>
                <p class="price">${{ number_format(((int) $plan['price_cents']) / 100, 0) }}<span>/month</span></p>
            </div>
            <ul class="check-list">
                <li>{{ $plan['monthly_minutes'] }} video minutes per month</li>
                <li>{{ $plan['speed_label'] }}</li>
                <li>Generate {{ $plan['concurrency'] }} {{ $plan['concurrency'] === 1 ? 'video' : 'videos' }} at a time</li>
                <li>All learning tools included</li>
            </ul>
            @auth
                @if ($checkoutBlocked ?? false)
                    <a @class(['button', 'full-width', 'button-accent' => $featured]) href="{{ route('dashboard') }}">Manage billing</a>
                @else
                    <form method="post" action="{{ route('billing.checkout', ['planCode' => $plan['code']]) }}">
                        @csrf
                        <button type="submit" @class(['button', 'full-width', 'button-accent' => $featured])>Choose {{ $plan['name'] }}</button>
                    </form>
                @endif
            @else
                <a @class(['button', 'full-width', 'button-accent' => $featured]) href="{{ route('register', ['plan' => $plan['code']]) }}">Choose {{ $plan['name'] }}</a>
            @endauth
        </article>
    @endforeach
</div>
<p class="plan-note">
    Minutes count the video you generate, rounded up to whole minutes. A 10-minute video uses 10 minutes; replaying a retained track uses none. Cancelling generation does not refund its reserved minutes. Failed generations release unused reserved minutes. Checkout is hosted by Stripe. Cancel your subscription from your account; contact support for beta refunds.
</p>
