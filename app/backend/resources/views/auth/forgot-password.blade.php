@extends('layouts.account')

@section('content')
    <div>
        <h1>Reset password</h1>
        <p>Enter your account email and we will send a reset link.</p>
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

    <form method="post" action="{{ route('password.email') }}">
        @csrf
        <label>
            Email
            <input type="email" name="email" value="{{ old('email') }}" autocomplete="email" required>
        </label>
        <div class="actions">
            <a class="button-link" href="{{ route('login') }}">Back to sign in</a>
            <button type="submit">Send reset link</button>
        </div>
    </form>
@endsection
