@extends('layouts.account')

@section('content')
    <div>
        <h1>Reset password</h1>
        <p>Enter your account email and we will send a reset link.</p>
    </div>

    @if (session('status'))
        <p class="status">{{ session('status') }}</p>
    @endif

    <x-form.error-list :errors="$errors" />

    <form method="post" action="{{ route('password.email') }}">
        @csrf
        <x-form.field label="Email">
            <input type="email" name="email" value="{{ old('email') }}" autocomplete="email" required>
        </x-form.field>
        <div class="actions">
            <a class="button-link" href="{{ route('login') }}">Back to sign in</a>
            <button type="submit">Send reset link</button>
        </div>
    </form>
@endsection
