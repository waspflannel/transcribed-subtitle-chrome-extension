@extends('layouts.site')

@section('content')
    @php
        $limit = max(0, (int) $account['monthlyMinuteLimit']);
        $committed = (int) $account['monthlyMinutesUsed'] + (int) $account['monthlyMinutesPending'];
        $usagePercent = $limit > 0 ? min(100, (int) round(($committed / $limit) * 100)) : 0;
        $deletionConsequences = __('Deleting removes jobs and stored tracks, so those tracks cannot be reused. Used minutes are not refunded. Deleting an in-progress generation uses its full reserved minutes. Provider requests already in progress may finish.');
    @endphp

    <section class="workspace">
        <div class="workspace-heading">
            <div>
                <p class="eyebrow">{{ __('Account dashboard') }}</p>
                <h1>{{ $user->email }}</h1>
                <p>{{ __('Manage billing, usage, extension connection, and recent subtitle jobs.') }}</p>
                <p>{!! strtr(e(__('Snapshot at :slot1:. This page does not update automatically.')), [':slot1:' => e(now()->toIso8601String())]) !!}</p>
            </div>
            <div class="action-stack horizontal-actions">
                <a class="button button-secondary" href="{{ route('dashboard') }}">{{ __('Refresh status') }}</a>
                <a class="button" href="{{ \App\Support\WebsiteLocale::route('marketing.how-to-use') }}#how-to-install">{{ __('Install extension') }}</a>
                <a class="button button-secondary" href="https://www.youtube.com" rel="noopener noreferrer">{{ __('Open YouTube') }}</a>
                <a class="button button-secondary" href="{{ \App\Support\WebsiteLocale::route('marketing.support') }}">{{ __('Open support') }}</a>
            </div>
        </div>

        @if ($checkoutPlan !== null)
            <div class="checkout-banner">
                <p>{!! strtr(e(__('Finish setting up your :slot1: plan — $:slot2:/month. :slot3:Checkout opens in Stripe. You can pick a different plan from the list on the right.:slot4:')), [':slot1:' => e($checkoutPlan['name']), ':slot2:' => e(number_format(((int) $checkoutPlan['price_cents']) / 100, 0)), ':slot3:' => '<small>', ':slot4:' => '</small>']) !!}</p>
                <form method="post" action="{{ route('billing.checkout', ['planCode' => $checkoutPlan['code']]) }}">
                    @csrf
                    <button type="submit" class="button button-primary">{{ __('Continue to checkout') }}</button>
                </form>
            </div>
        @endif

        @if (session('billing_error'))
            <p class="error-copy">{{ __(session('billing_error')) }}</p>
        @endif

        @if (session('billing_status'))
            <p class="status">{{ __(session('billing_status')) }}</p>
        @endif

        @if (request('billing') === 'success')
            <p class="status">{{ __('Checkout completed. Billing updates can take a moment while Stripe sends webhooks. Use Refresh status to check for updates.') }}</p>
        @elseif (request('billing') === 'cancelled')
            <p class="error-copy">{{ __('Checkout was cancelled.') }}</p>
        @endif

        @if (session('jobs_status'))
            <p class="status">{{ __(session('jobs_status')) }}</p>
        @endif

        <div class="workspace-grid">
            <section class="workspace-main">
                <x-ui.panel title="{{ __('Usage') }}" description="{{ __(':count minutes remaining this period.', ['count' => $account['monthlyMinutesRemaining']]) }}">
                    <div class="usage-meter">
                        <div class="usage-meter-label">
                            <span>{!! strtr(e(__(':slot1: used, :slot2: reserved')), [':slot1:' => e($account['monthlyMinutesUsed']), ':slot2:' => e($account['monthlyMinutesPending'])]) !!}</span>
                            <strong>{!! strtr(e(__(':slot1: total')), [':slot1:' => e($account['monthlyMinuteLimit'])]) !!}</strong>
                        </div>
                        <div class="usage-bar" aria-hidden="true">
                            <span style="width: {{ $usagePercent }}%"></span>
                        </div>
                    </div>
                    <dl class="metric-grid">
                        <div>
                            <dt>{{ __('Plan') }}</dt>
                            <dd>{{ $account['planName'] }}</dd>
                        </div>
                        <div>
                            <dt>{{ __('Speed') }}</dt>
                            <dd>{{ __($account['tierSpeedLabel']) }}</dd>
                        </div>
                        <div>
                            <dt>{{ __('Period ends') }}</dt>
                            <dd>{{ $account['resetAt'] }}</dd>
                        </div>
                    </dl>
                </x-ui.panel>

                <x-ui.panel title="{{ __('Recent jobs') }}" description="{{ __('Public-safe support details for your latest subtitle generations.') }}">
                    @if ($totalJobs > 0)
                        <p>{!! strtr(e(__('Showing :slot1: recent jobs. :slot2: total jobs in your account at this check.')), [':slot1:' => e($recentJobs->count()), ':slot2:' => e($totalJobs)]) !!}</p>
                        <p>{!! strtr(e(__('Clear all includes jobs not shown in this list, including older processing versions. :slot1:')), [':slot1:' => e($deletionConsequences)]) !!}</p>
                        <form method="post" action="{{ route('dashboard.jobs.clear') }}" data-confirm="{{ __('Clear all jobs in your account (:count at this check), including jobs not shown here and older processing versions? :consequences', ['count' => $totalJobs, 'consequences' => $deletionConsequences]) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="button button-secondary button-small">{!! strtr(e(__('Clear all jobs (:slot1:)')), [':slot1:' => e($totalJobs)]) !!}</button>
                        </form>
                    @endif
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>{{ __('Video') }}</th>
                                    <th>{{ __('Status') }}</th>
                                    <th>{{ __('Language') }}</th>
                                    <th>{{ __('Minutes') }}</th>
                                    <th>{{ __('Updated') }}</th>
                                    <th>{{ __('Support ID') }}</th>
                                    <th aria-label="{{ __('Actions') }}"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($recentJobs as $job)
                                    <tr>
                                        <td><a class="text-link" href="https://www.youtube.com/watch?v={{ rawurlencode($job['videoId']) }}" rel="noopener noreferrer">{{ $job['videoId'] }}</a></td>
                                        <td>
                                            <x-ui.status-pill :status="$job['status']" />
                                            <small>{{ __($job['stage']) }}</small>
                                        </td>
                                        <td>{{ $job['languagePair'] }}</td>
                                        <td>{{ $job['minutes'] }}</td>
                                        <td>{{ $job['updatedAt'] }}</td>
                                        <td><a class="text-link" href="{{ $job['href'] }}">{{ $job['jobId'] }}</a></td>
                                        <td>
                                            <form method="post" action="{{ route('dashboard.jobs.destroy', ['jobId' => $job['jobId']]) }}" data-confirm="{{ __('Delete subtitle job :job for video :video? :consequences', ['job' => $job['jobId'], 'video' => $job['videoId'], 'consequences' => $deletionConsequences]) }}">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="text-button button-small" aria-label="{{ __('Delete job :job for video :video', ['job' => $job['jobId'], 'video' => $job['videoId']]) }}">{{ __('Delete') }}</button>
                                            </form>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7">{{ $totalJobs > 0 ? __('No recent jobs to display.') : __('No subtitle jobs yet. Install the extension and start generation from a YouTube watch page or Short.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-ui.panel>
            </section>

            <aside class="workspace-side">
                <x-ui.panel title="{{ __('Billing') }}" description="{{ $checkoutBlocked ? __('Opens Stripe — update payment, switch plans, or cancel anytime.') : __('No active subscription.') }}">
                    <form method="post" action="{{ route('billing.portal') }}">
                        @csrf
                        <button type="submit" class="button full-width">{{ $checkoutBlocked ? __('Manage or cancel subscription') : __('Manage billing') }}</button>
                    </form>
                </x-ui.panel>

                <x-ui.panel title="{{ __('Plans') }}" description="{{ $checkoutBlocked ? __('Change an existing subscription in Stripe.') : __('Checkout opens in Stripe.') }}">
                    @if ($checkoutBlocked)
                        <p>{{ __('Use Manage or cancel subscription above to change plans or cancel.') }}</p>
                    @else
                        <div class="mini-plan-list">
                            @foreach ($plans as $plan)
                                <form method="post" action="{{ route('billing.checkout', ['planCode' => $plan['code']]) }}">
                                    @csrf
                                    <button type="submit" class="plan-button">{!! strtr(e(__(':slot1::slot2::slot3: :slot4:$:slot5:/mo:slot6:')), [':slot1:' => '<span>', ':slot2:' => e($plan['name']), ':slot3:' => '</span>', ':slot4:' => '<strong>', ':slot5:' => e(number_format(((int) $plan['price_cents']) / 100, 0)), ':slot6:' => '</strong>']) !!}</button>
                                </form>
                            @endforeach
                        </div>
                    @endif
                </x-ui.panel>

                <x-ui.panel title="{{ __('Extension') }}" description="{{ $extensionTokens->isEmpty() ? __('No connected extension installs.') : __(':count recent connection(s).', ['count' => $extensionTokens->count()]) }}">
                    <dl class="stacked-list">
                        @forelse ($extensionTokens as $token)
                            <div>
                                <dt>{{ $token['label'] }}</dt>
                                <dd>{!! strtr(e(__('Last used: :slot1:. Expires: :slot2:.')), [':slot1:' => e($token['lastUsedAt']), ':slot2:' => e($token['expiresAt'])]) !!}</dd>
                            </div>
                        @empty
                            <div>
                                <dt>{{ __('Next step') }}</dt>
                                <dd>{{ __('Install the extension, open its Account tab, and sign in with this email.') }}</dd>
                            </div>
                        @endforelse
                    </dl>
                </x-ui.panel>

                <x-ui.panel class="danger-panel" title="{{ __('Delete account') }}" description="{{ __('Permanent, immediate, and irreversible.') }}">
                    <p class="danger-note">{{ __('Deleting your account cancels any active subscription right away and erases your subtitle jobs, usage history, and extension connections.') }}</p>
                    <form method="post" action="{{ route('account.destroy') }}" class="stack-form" data-confirm="{{ __('Permanently delete your account and all of its data? This cannot be undone.') }}">
                        @csrf
                        @method('DELETE')
                        <label>
                            {{ __('Confirm your password') }}
                            <input type="password" name="password" autocomplete="current-password" required>
                        </label>
                        @error('password', 'deleteAccount')
                            <p class="error-copy">{{ $message }}</p>
                        @enderror
                        <button type="submit" class="button button-danger full-width">{{ __('Delete account') }}</button>
                    </form>
                </x-ui.panel>
            </aside>
        </div>
    </section>
@endsection
