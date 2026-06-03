@extends('layouts.site')

@section('content')
    @php
        $limit = max(0, (int) $account['monthlyMinuteLimit']);
        $committed = (int) $account['monthlyMinutesUsed'] + (int) $account['monthlyMinutesPending'];
        $usagePercent = $limit > 0 ? min(100, (int) round(($committed / $limit) * 100)) : 0;
    @endphp

    <section class="workspace">
        <div class="workspace-heading">
            <div>
                <p class="eyebrow">Account dashboard</p>
                <h1>{{ $user->email }}</h1>
                <p>Manage billing, usage, extension connection, and recent subtitle jobs.</p>
            </div>
            <div class="action-stack horizontal-actions">
                <a class="button" href="{{ route('marketing.how-it-works') }}#install">Install extension</a>
                <a class="button button-secondary" href="https://www.youtube.com" rel="noopener noreferrer">Open YouTube</a>
            </div>
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

        <div class="workspace-grid">
            <section class="workspace-main">
                <section class="panel">
                    <div class="panel-heading">
                        <h2>Usage</h2>
                        <p>{{ $account['monthlyMinutesRemaining'] }} minutes remaining this period.</p>
                    </div>
                    <div class="usage-meter">
                        <div class="usage-meter-label">
                            <span>{{ $account['monthlyMinutesUsed'] }} used, {{ $account['monthlyMinutesPending'] }} reserved</span>
                            <strong>{{ $account['monthlyMinuteLimit'] }} total</strong>
                        </div>
                        <div class="usage-bar" aria-hidden="true">
                            <span style="width: {{ $usagePercent }}%"></span>
                        </div>
                    </div>
                    <dl class="metric-grid">
                        <div>
                            <dt>Plan</dt>
                            <dd>{{ $account['planName'] }}</dd>
                        </div>
                        <div>
                            <dt>Speed</dt>
                            <dd>{{ $account['tierSpeedLabel'] }}</dd>
                        </div>
                        <div>
                            <dt>Period ends</dt>
                            <dd>{{ $account['resetAt'] }}</dd>
                        </div>
                    </dl>
                </section>

                <section class="panel">
                    <div class="panel-heading">
                        <h2>Recent jobs</h2>
                        <p>Public-safe support details for your latest subtitle generations.</p>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Status</th>
                                    <th>Language</th>
                                    <th>Minutes</th>
                                    <th>Updated</th>
                                    <th>Support ID</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($recentJobs as $job)
                                    <tr>
                                        <td>
                                            <span class="status-pill status-{{ $job['status'] }}">{{ $job['status'] }}</span>
                                            <small>{{ $job['stage'] }}</small>
                                        </td>
                                        <td>{{ $job['languagePair'] }}</td>
                                        <td>{{ $job['minutes'] }}</td>
                                        <td>{{ $job['updatedAt'] }}</td>
                                        <td><a class="text-link" href="{{ $job['href'] }}">{{ $job['jobId'] }}</a></td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5">No subtitle jobs yet. Install the extension and start generation from a YouTube watch page or Short.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>
            </section>

            <aside class="workspace-side">
                <section class="panel">
                    <div class="panel-heading">
                        <h2>Billing</h2>
                        <p>{{ $user->billing_subscription_status ?? 'No active subscription' }}</p>
                    </div>
                    <form method="post" action="{{ route('billing.portal') }}">
                        @csrf
                        <button type="submit" class="button full-width">Manage billing</button>
                    </form>
                </section>

                <section class="panel">
                    <div class="panel-heading">
                        <h2>Plans</h2>
                        <p>Checkout opens in Stripe.</p>
                    </div>
                    <div class="mini-plan-list">
                        @foreach ($plans as $plan)
                            <form method="post" action="{{ route('billing.checkout', ['planCode' => $plan['code']]) }}">
                                @csrf
                                <button type="submit" class="plan-button">
                                    <span>{{ $plan['name'] }}</span>
                                    <strong>${{ number_format(((int) $plan['price_cents']) / 100, 0) }}/mo</strong>
                                </button>
                            </form>
                        @endforeach
                    </div>
                </section>

                <section class="panel">
                    <div class="panel-heading">
                        <h2>Extension</h2>
                        <p>{{ $extensionTokens->isEmpty() ? 'No connected extension installs.' : $extensionTokens->count().' recent connection(s).' }}</p>
                    </div>
                    <dl class="stacked-list">
                        @forelse ($extensionTokens as $token)
                            <div>
                                <dt>{{ $token['label'] }}</dt>
                                <dd>Last used: {{ $token['lastUsedAt'] }}. Expires: {{ $token['expiresAt'] }}.</dd>
                            </div>
                        @empty
                            <div>
                                <dt>Next step</dt>
                                <dd>Install the extension, open its Account tab, and sign in with this verified email.</dd>
                            </div>
                        @endforelse
                    </dl>
                </section>

                @if ($testingPlanSwitcherEnabled)
                    <section class="panel">
                        <div class="panel-heading">
                            <h2>Test billing</h2>
                            <p>Switch plans without Stripe.</p>
                        </div>
                        <form method="post" action="{{ route('billing.testing-plan') }}" class="stack-form">
                            @csrf
                            <label>
                                Plan
                                <select name="plan_code">
                                    @foreach ($plans as $plan)
                                        <option value="{{ $plan['code'] }}" @selected($user->billing_plan_code === $plan['code'])>
                                            {{ $plan['name'] }}
                                        </option>
                                    @endforeach
                                </select>
                            </label>
                            <button type="submit" class="button full-width">Set test plan</button>
                        </form>
                        <form method="post" action="{{ route('billing.testing-plan') }}">
                            @csrf
                            <input type="hidden" name="plan_code" value="none">
                            <button type="submit" class="button button-secondary full-width">Clear test plan</button>
                        </form>
                    </section>
                @endif

                <section class="panel">
                    <div class="panel-heading">
                        <h2>Support</h2>
                        <p>Use job details for public-safe troubleshooting.</p>
                    </div>
                    <a class="button button-secondary full-width" href="{{ route('marketing.support') }}">Open support</a>
                    <form method="post" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="text-button full-width">Log out</button>
                    </form>
                </section>
            </aside>
        </div>
    </section>
@endsection
