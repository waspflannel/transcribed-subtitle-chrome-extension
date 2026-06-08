@extends('layouts.account')

@section('content')
    <div>
        <h1>Create account</h1>
        <p>Use the same email in the extension popup after verification.</p>
    </div>

    <x-form.error-list :errors="$errors" />

    <form method="post" action="{{ route('register.store') }}">
        @csrf
        <x-form.field label="Name">
            <input name="name" value="{{ old('name') }}" autocomplete="name" required>
        </x-form.field>
        <x-form.field label="Email">
            <input type="email" name="email" value="{{ old('email') }}" autocomplete="email" required>
        </x-form.field>
        <x-form.field label="Password">
            <input type="password" name="password" autocomplete="new-password" required>
        </x-form.field>
        <x-form.field label="Confirm password">
            <input type="password" name="password_confirmation" autocomplete="new-password" required>
        </x-form.field>
        <div class="actions">
            <a class="button-link" href="{{ route('login') }}">Sign in</a>
            <button type="submit">Create account</button>
        </div>
    </form>
@endsection
