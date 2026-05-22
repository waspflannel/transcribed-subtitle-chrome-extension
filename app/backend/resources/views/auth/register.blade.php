@extends('layouts.account')

@section('content')
    <div>
        <h1>Create account</h1>
        <p>Use the same email in the extension popup after verification.</p>
    </div>

    @if ($errors->any())
        <ul class="error-list">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    <form method="post" action="{{ route('register.store') }}">
        @csrf
        <label>
            Name
            <input name="name" value="{{ old('name') }}" autocomplete="name" required>
        </label>
        <label>
            Email
            <input type="email" name="email" value="{{ old('email') }}" autocomplete="email" required>
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
            <a class="button-link" href="{{ route('login') }}">Sign in</a>
            <button type="submit">Create account</button>
        </div>
    </form>
@endsection
