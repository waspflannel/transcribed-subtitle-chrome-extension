@extends('layouts.account')

@section('content')
    <div>
        <h1>{{ __('Sign in') }}</h1>
        <p>{{ __('Use your account email to connect the extension.') }}</p>
    </div>

    @if (session('status'))
        <p class="status">{{ __(session('status')) }}</p>
    @endif

    <x-form.error-list :errors="$errors" />

    <form method="post" action="{{ route('login.store') }}">
        @csrf
        <x-form.field label="{{ __('Email') }}">
            <input type="email" name="email" value="{{ old('email') }}" autocomplete="email" required>
        </x-form.field>
        <x-form.field label="{{ __('Password') }}">
            <input type="password" name="password" autocomplete="current-password" required>
        </x-form.field>
        <label>
            <span>
                <input type="checkbox" name="remember" value="1">
                {{ __('Remember this browser') }}
            </span>
        </label>
        <div class="actions">
            <a class="button-link" href="{{ \App\Support\WebsiteLocale::route('register') }}">{{ __('Create account') }}</a>
            <a class="button-link" href="{{ \App\Support\WebsiteLocale::route('password.request') }}">{{ __('Reset password') }}</a>
            <button type="submit">{{ __('Sign in') }}</button>
        </div>
    </form>
@endsection
