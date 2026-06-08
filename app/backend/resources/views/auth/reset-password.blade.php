@extends('layouts.account')

@section('content')
    <div>
        <h1>Choose new password</h1>
        <p>Use the new password in the extension popup after signing in.</p>
    </div>

    <x-form.error-list :errors="$errors" />

    <form method="post" action="{{ route('password.update') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <x-form.field label="Email">
            <input type="email" name="email" value="{{ old('email', $email) }}" autocomplete="email" required>
        </x-form.field>
        <x-form.field label="Password">
            <input type="password" name="password" autocomplete="new-password" required>
        </x-form.field>
        <x-form.field label="Confirm password">
            <input type="password" name="password_confirmation" autocomplete="new-password" required>
        </x-form.field>
        <div class="actions">
            <a class="button-link" href="{{ route('login') }}">Back to sign in</a>
            <button type="submit">Save password</button>
        </div>
    </form>
@endsection
