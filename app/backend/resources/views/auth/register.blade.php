@extends('layouts.account')

@section('content')
    <div>
        <h1>Create account</h1>
        <p>Use the same email in the extension side panel after verification.</p>
    </div>

    <x-form.error-list :errors="$errors" />

    @if ($selectedPlan ?? null)
        <p class="plan-pick-note">
            {{ $selectedPlan['name'] }} plan selected — ${{ number_format(((int) $selectedPlan['price_cents']) / 100, 0) }}/month.
            After you verify your email, checkout continues from your dashboard.
        </p>
    @endif

    <form method="post" action="{{ route('register.store') }}">
        @csrf
        @if ($selectedPlan ?? null)
            <input type="hidden" name="plan" value="{{ $selectedPlan['code'] }}">
        @endif
        <x-form.field label="Name">
            <input name="name" value="{{ old('name') }}" autocomplete="name" required>
        </x-form.field>
        <x-form.field label="Email">
            <input type="email" name="email" value="{{ old('email') }}" autocomplete="email" required>
        </x-form.field>
        <x-form.field label="Password">
            <input type="password" name="password" autocomplete="new-password" required>
        </x-form.field>
        <x-form.field label="Confirm password">
            <input type="password" name="password_confirmation" autocomplete="new-password" required>
        </x-form.field>
        <div class="actions">
            <a class="button-link" href="{{ route('login') }}">Sign in</a>
            <button type="submit">Create account</button>
        </div>
    </form>
@endsection
