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
                <li>{{ $plan['monthly_minutes'] }} generated-video minutes</li>
                <li>{{ $plan['speed_label'] }}</li>
                <li>{{ $plan['concurrency'] }} concurrent {{ $plan['concurrency'] === 1 ? 'generation' : 'generations' }}</li>
                <li>{{ $plan['batch_concurrency'] }} AI batch {{ $plan['batch_concurrency'] === 1 ? 'slot' : 'slots' }}</li>
                <li>{{ data_get($plan, 'features.full_word_cards') ? 'Full word cards included' : 'On-demand word cards included' }}</li>
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
    Usage is the public video's generated duration, rounded up to whole minutes. Running jobs reserve minutes, completed tracks debit them, and failed jobs release unused reservations. Checkout is hosted by Stripe; beta refunds are handled through support when billing, access, or generation failures prevent reasonable use.
</p>
