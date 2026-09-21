@extends('layouts.auth')

@section('title', 'Forgot Password — NPO CRM')

@section('content')
<div class="auth-card">
    <h2>🔐 Reset Password</h2>

    @if (session('success'))
        <div class="alert" style="margin-bottom:12px;">{{ session('success') }}</div>
    @endif

    @if ($errors->any())
        <div class="alert alert-error" style="margin-bottom:12px;">
            {{ $errors->first() }}
        </div>
    @endif

    <p class="muted" style="font-size:13px;margin-bottom:12px;">
        Enter the email you use to sign in. We'll send you a link to reset your password.
    </p>

    <form method="POST" action="{{ url('/forgot-password') }}">
        @csrf

        <div class="field">
            <label for="email">Email</label>
            <input type="email" id="email" name="email"
                   value="{{ old('email') }}"
                   placeholder="your@email.com" required autofocus autocomplete="email">
        </div>

        <button type="submit" class="btn btn-block">Send Reset Link</button>
    </form>

    <div class="auth-note">
        <a href="{{ url('/login') }}">← Back to sign in</a>
    </div>
</div>
@endsection