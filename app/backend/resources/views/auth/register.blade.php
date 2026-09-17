@extends('layouts.account')

@section('content')
    <div>
        <h1>{{ __('Create account') }}</h1>
        <p>{{ __('Use the same email in the extension side panel.') }}</p>
    </div>

    <x-form.error-list :errors="$errors" />

    @if ($selectedPlan ?? null)
        <p class="plan-pick-note">{!! strtr(e(__(':slot1: plan selected — $:slot2:/month. After you sign up, checkout continues from your dashboard.')), [':slot1:' => e($selectedPlan['name']), ':slot2:' => e(number_format(((int) $selectedPlan['price_cents']) / 100, 0))]) !!}</p>
    @endif

    <form method="post" action="{{ route('register.store') }}">
        @csrf
        @if ($selectedPlan ?? null)
            <input type="hidden" name="plan" value="{{ $selectedPlan['code'] }}">
        @endif
        <x-form.field label="{{ __('Name') }}">
            <input name="name" value="{{ old('name') }}" autocomplete="name" required>
        </x-form.field>
        <x-form.field label="{{ __('Email') }}">
            <input type="email" name="email" value="{{ old('email') }}" autocomplete="email" required>
        </x-form.field>
        <x-form.field label="{{ __('Password') }}">
            <input type="password" name="password" autocomplete="new-password" required>
        </x-form.field>
        <x-form.field label="{{ __('Confirm password') }}">
            <input type="password" name="password_confirmation" autocomplete="new-password" required>
        </x-form.field>
        <div class="actions">
            <a class="button-link" href="{{ \App\Support\WebsiteLocale::route('login') }}">{{ __('Sign in') }}</a>
            <button type="submit">{{ __('Create account') }}</button>
        </div>
    </form>
@endsection
