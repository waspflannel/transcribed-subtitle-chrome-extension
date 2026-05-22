@extends('layouts.account')

@section('content')
    <div>
        <h1>Account</h1>
        <p>{{ $user->email }}</p>
    </div>

    <dl>
        <div>
            <dt>Email status</dt>
            <dd>{{ $user->hasVerifiedEmail() ? 'Verified' : 'Unverified' }}</dd>
        </div>
    </dl>

    <form method="post" action="{{ route('logout') }}">
        @csrf
        <button type="submit">Log out</button>
    </form>
@endsection
