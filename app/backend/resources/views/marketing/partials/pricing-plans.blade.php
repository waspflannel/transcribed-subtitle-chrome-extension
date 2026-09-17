<div class="plan-access" data-reveal>
    <p>{!! strtr(e(__(':slot1:Included with every plan::slot2: Transcriber and Transcriber-Spark model choice, translations, optional romanization, interactive word cards, and lyrics correction.')), [':slot1:' => '<strong>', ':slot2:' => '</strong>']) !!}</p>
    <p>{!! strtr(e(__('For desktop Chrome. Follow the :slot1:installation guide:slot2:, then sign in to the extension with your account.')), [':slot1:' => '<a class="strong-link" href="'.e(\App\Support\WebsiteLocale::route('marketing.how-to-use')).'#how-to-install">', ':slot2:' => '</a>']) !!}</p>
</div>
<div class="plan-row" data-reveal>
    @foreach ($plans as $plan)
        @php($featured = $loop->index === intdiv($loop->count - 1, 2))
        <article @class(['plan-card', 'plan-card-featured' => $featured])>
            @if ($featured)
                <span class="plan-sticker">{{ __('Best fit') }}</span>
            @endif
            <div>
                <h3>{{ $plan['name'] }}</h3>
                <p class="price">{!! strtr(e(__('$:slot1::slot2:/month:slot3:')), [':slot1:' => e(number_format(((int) $plan['price_cents']) / 100, 0)), ':slot2:' => '<span>', ':slot3:' => '</span>']) !!}</p>
            </div>
            <ul class="check-list">
                <li>{!! strtr(e(__(':slot1: video minutes per month')), [':slot1:' => e($plan['monthly_minutes'])]) !!}</li>
                <li>{{ __($plan['speed_label']) }}</li>
                <li>{{ __('Simultaneous videos: :count', ['count' => $plan['concurrency']]) }}</li>
                <li>{{ __('All learning tools included') }}</li>
            </ul>
            @auth
                @if ($checkoutBlocked ?? false)
                    <a @class(['button', 'full-width', 'button-accent' => $featured]) href="{{ route('dashboard') }}">{{ __('Manage billing') }}</a>
                @else
                    <form method="post" action="{{ route('billing.checkout', ['planCode' => $plan['code']]) }}">
                        @csrf
                        <button type="submit" @class(['button', 'full-width', 'button-accent' => $featured])>{!! strtr(e(__('Choose :slot1:')), [':slot1:' => e($plan['name'])]) !!}</button>
                    </form>
                @endif
            @else
                <a @class(['button', 'full-width', 'button-accent' => $featured]) href="{{ \App\Support\WebsiteLocale::route('register', ['plan' => $plan['code']]) }}">{!! strtr(e(__('Choose :slot1:')), [':slot1:' => e($plan['name'])]) !!}</a>
            @endauth
        </article>
    @endforeach
</div>
<p class="plan-note">{{ __('Minutes count the video you generate, rounded up to whole minutes. A 10-minute video uses 10 minutes; replaying a retained track uses none. Cancelling generation does not refund its reserved minutes. Failed generations release unused reserved minutes. Checkout is hosted by Stripe. Cancel your subscription from your account; contact support for refunds.') }}</p>
