@extends('layouts.account')

@section('content')
    @php
        $limit = max(0, (int) $account['monthlyMinuteLimit']);
        $committed = (int) $account['monthlyMinutesUsed'] + (int) $account['monthlyMinutesPending'];
        $usagePercent = $limit > 0 ? min(100, (int) round(($committed / $limit) * 100)) : 0;
    @endphp

    <div>
        <h1>Account</h1>
        <p>{{ $user->email }}</p>
    </div>

    @if (session('billing_error'))
        <p class="error-copy">{{ session('billing_error') }}</p>
    @endif

    @if (session('billing_status'))
        <p class="status">{{ session('billing_status') }}</p>
    @endif

    @if (request('billing') === 'success')
        <p class="status">Checkout completed. Billing updates can take a moment while Stripe sends webhooks.</p>
    @elseif (request('billing') === 'cancelled')
        <p class="error-copy">Checkout was cancelled.</p>
    @endif

    <dl>
        <div>
            <dt>Email status</dt>
            <dd>{{ $user->hasVerifiedEmail() ? 'Verified' : 'Unverified' }}</dd>
        </div>
        <div>
            <dt>Billing plan</dt>
            <dd>{{ $account['planName'] }}</dd>
        </div>
        <div>
            <dt>Subscription status</dt>
            <dd>{{ $user->billing_subscription_status ?? 'none' }}</dd>
        </div>
        <div>
            <dt>Speed</dt>
            <dd>{{ $account['tierSpeedLabel'] }}</dd>
        </div>
        <div>
            <dt>Current period ends</dt>
            <dd>{{ $account['resetAt'] }}</dd>
        </div>
    </dl>

    <section class="usage-block">
        <div class="usage-label">
            <span>{{ $account['monthlyMinutesUsed'] }} of {{ $account['monthlyMinuteLimit'] }} minutes used</span>
            <strong>{{ $account['monthlyMinutesRemaining'] }} minutes left</strong>
        </div>
        <div class="usage-bar" aria-hidden="true">
            <span style="width: {{ $usagePercent }}%"></span>
        </div>
        <p>{{ $account['monthlyMinutesPending'] }} minutes are reserved by running jobs.</p>
    </section>

    <section class="plan-list" aria-label="Billing plans">
        @foreach ($plans as $plan)
            <article>
                <div>
                    <h2>{{ $plan['name'] }}</h2>
                    <p>${{ number_format(((int) $plan['price_cents']) / 100, 0) }}/month</p>
                </div>
                <ul>
                    <li>{{ $plan['monthly_minutes'] }} generated-video minutes</li>
                    <li>{{ $plan['speed_label'] }}</li>
                    <li>{{ $plan['concurrency'] }} concurrent {{ $plan['concurrency'] === 1 ? 'generation' : 'generations' }}</li>
                    <li>{{ data_get($plan, 'features.full_word_cards') ? 'Full word cards included' : 'Full word cards require Plus' }}</li>
                </ul>
                <form method="post" action="{{ route('billing.checkout', ['planCode' => $plan['code']]) }}">
                    @csrf
                    <button type="submit">Choose {{ $plan['name'] }}</button>
                </form>
            </article>
        @endforeach
    </section>

    @if ($testingPlanSwitcherEnabled)
        <section class="usage-block">
            <div>
                <h2>Test billing</h2>
                <p>Switch plans without Stripe.</p>
            </div>
            <form method="post" action="{{ route('billing.testing-plan') }}">
                @csrf
                <label for="plan_code">Plan</label>
                <select id="plan_code" name="plan_code">
                    @foreach ($plans as $plan)
                        <option value="{{ $plan['code'] }}" @selected($user->billing_plan_code === $plan['code'])>
                            {{ $plan['name'] }}
                        </option>
                    @endforeach
                </select>
                <button type="submit">Set test plan</button>
            </form>
            <form method="post" action="{{ route('billing.testing-plan') }}">
                @csrf
                <input type="hidden" name="plan_code" value="none">
                <button type="submit">Clear test plan</button>
            </form>
        </section>
    @endif

    <form method="post" action="{{ route('billing.portal') }}">
        @csrf
        <button type="submit">Manage billing</button>
    </form>

    <form method="post" action="{{ route('logout') }}">
        @csrf
        <button type="submit">Log out</button>
    </form>
@endsection
