@extends('layouts.account')

@section('content')
    <div>
        <h1>Choose new password</h1>
        <p>Use the new password in the extension popup after signing in.</p>
    </div>

    @if ($errors->any())
        <ul class="error-list">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    <form method="post" action="{{ route('password.update') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <label>
            Email
            <input type="email" name="email" value="{{ old('email', $email) }}" autocomplete="email" required>
        </label>
        <label>
            Password
            <input type="password" name="password" autocomplete="new-password" required>
        </label>
        <label>
            Confirm password
            <input type="password" name="password_confirmation" autocomplete="new-password" required>
        </label>
        <div class="actions">
            <a class="button-link" href="{{ route('login') }}">Back to sign in</a>
            <button type="submit">Save password</button>
        </div>
    </form>
@endsection
