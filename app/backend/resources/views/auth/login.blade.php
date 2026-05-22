@extends('layouts.account')

@section('content')
    <div>
        <h1>Sign in</h1>
        <p>Use your verified account to connect the extension.</p>
    </div>

    @if (session('status'))
        <p class="status">{{ session('status') }}</p>
    @endif

    @if ($errors->any())
        <ul class="error-list">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    <form method="post" action="{{ route('login.store') }}">
        @csrf
        <label>
            Email
            <input type="email" name="email" value="{{ old('email') }}" autocomplete="email" required>
        </label>
        <label>
            Password
            <input type="password" name="password" autocomplete="current-password" required>
        </label>
        <label>
            <span>
                <input type="checkbox" name="remember" value="1">
                Remember this browser
            </span>
        </label>
        <div class="actions">
            <a class="button-link" href="{{ route('password.request') }}">Reset password</a>
            <button type="submit">Sign in</button>
        </div>
    </form>
@endsection
