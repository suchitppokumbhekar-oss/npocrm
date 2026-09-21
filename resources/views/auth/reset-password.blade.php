@extends('layouts.auth')

@section('title', 'Set New Password — NPO CRM')

@section('content')
<div class="auth-card">
    <h2>🔑 New Password</h2>

    @if ($errors->any())
        <div class="alert alert-error" style="margin-bottom:12px;">
            {{ $errors->first() }}
        </div>
    @endif

    <p class="muted" style="font-size:13px;margin-bottom:12px;">
        Choose a new password for <strong>{{ $email }}</strong>.
    </p>

    <form method="POST" action="{{ url('/reset-password') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <input type="hidden" name="email" value="{{ $email }}">

        <div class="field">
            <label for="password">New Password</label>
            <input type="password" id="password" name="password"
                   placeholder="Minimum 6 characters" required minlength="6"
                   autocomplete="new-password" autofocus>
        </div>

        <div class="field">
            <label for="password_confirmation">Confirm Password</label>
            <input type="password" id="password_confirmation" name="password_confirmation"
                   placeholder="Type it again" required minlength="6"
                   autocomplete="new-password">
        </div>

        <button type="submit" class="btn btn-block">Reset Password</button>
    </form>

    <div class="auth-note">
        <a href="{{ url('/login') }}">← Back to sign in</a>
    </div>
</div>
@endsection