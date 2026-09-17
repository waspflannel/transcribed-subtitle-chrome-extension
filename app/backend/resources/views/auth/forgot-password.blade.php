@extends('layouts.account')

@section('content')
    <div>
        <h1>{{ __('Reset password') }}</h1>
        <p>{{ __('Enter your account email and we will send a reset link.') }}</p>
    </div>

    @if (session('status'))
        <p class="status">{{ __(session('status')) }}</p>
    @endif

    <x-form.error-list :errors="$errors" />

    <form method="post" action="{{ route('password.email') }}">
        @csrf
        <x-form.field label="{{ __('Email') }}">
            <input type="email" name="email" value="{{ old('email') }}" autocomplete="email" required>
        </x-form.field>
        <div class="actions">
            <a class="button-link" href="{{ \App\Support\WebsiteLocale::route('login') }}">{{ __('Back to sign in') }}</a>
            <button type="submit">{{ __('Send reset link') }}</button>
        </div>
    </form>
@endsection
