@extends('layouts.account')

@section('content')
    <div>
        <h1>Verify email</h1>
        <p>Open the verification link sent to your inbox before connecting the extension.</p>
    </div>

    @if (session('status') === 'verification-link-sent')
        <p class="status">Verification link sent.</p>
    @endif

    <form method="post" action="{{ route('verification.send') }}">
        @csrf
        <div class="actions">
            <a class="button-link" href="{{ route('login') }}">Back to sign in</a>
            <button type="submit">Send verification link</button>
        </div>
    </form>
@endsection
