@extends('layouts.account')

@section('content')
    <div>
        <h1>Sign in</h1>
        <p>Use your account email to connect the extension.</p>
    </div>

    @if (session('status'))
        <p class="status">{{ session('status') }}</p>
    @endif

    <x-form.error-list :errors="$errors" />

    <form method="post" action="{{ route('login.store') }}">
        @csrf
        <x-form.field label="Email">
            <input type="email" name="email" value="{{ old('email') }}" autocomplete="email" required>
        </x-form.field>
        <x-form.field label="Password">
            <input type="password" name="password" autocomplete="current-password" required>
        </x-form.field>
        <label>
            <span>
                <input type="checkbox" name="remember" value="1">
                Remember this browser
            </span>
        </label>
        <div class="actions">
            <a class="button-link" href="{{ route('register') }}">Create account</a>
            <a class="button-link" href="{{ route('password.request') }}">Reset password</a>
            <button type="submit">Sign in</button>
        </div>
    </form>
@endsection
