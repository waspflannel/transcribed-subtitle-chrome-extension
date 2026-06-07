@extends('layouts.site')

@section('content')
    <section class="page-hero">
        <p class="eyebrow">Pricing</p>
        <h1>Generated-video-minute plans for the paid beta.</h1>
        <p>
            Pick the queue speed, monthly minute cap, and learning depth that match your YouTube study volume.
            Checkout is hosted by Stripe, and billing changes are managed from the account dashboard.
        </p>
    </section>

    <section class="section">
        <div class="plan-row" data-reveal>
            @foreach ($plans as $plan)
                <article @class(['plan-card', 'plan-card-detailed', 'plan-card-featured' => $loop->index === intdiv($loop->count - 1, 2)])>
                    <div>
                        <h2>{{ $plan['name'] }}</h2>
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
                        <form method="post" action="{{ route('billing.checkout', ['planCode' => $plan['code']]) }}">
                            @csrf
                            <button type="submit" class="button full-width">Choose {{ $plan['name'] }}</button>
                        </form>
                    @else
                        <a class="button full-width" href="{{ route('register') }}">Create account</a>
                    @endauth
                </article>
            @endforeach
        </div>
    </section>

    <section class="section split-section">
        <div data-reveal>
            <h2>What counts as usage?</h2>
            <p>
                Usage is based on the public YouTube video's generated duration, rounded up to whole minutes. Running jobs reserve minutes, completed tracks debit them, and failed jobs release unused reservations.
            </p>
        </div>
        <div data-reveal data-reveal-delay="100">
            <h2>Refund posture</h2>
            <p>
                Beta refunds are handled through support when billing, access, or generation failures prevent reasonable use of the subscription.
            </p>
        </div>
    </section>
@endsection
