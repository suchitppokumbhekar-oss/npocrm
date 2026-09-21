@extends('layouts.auth')

@section('title', 'Login — NPO CRM')

@section('content')
<div class="auth-card">
    <h2>🔐 NPO CRM</h2>

    @if ($errors->any())
        <div class="alert alert-error">
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ url('/login') }}" autocomplete="on">
        @csrf

        <div class="field">
            <label for="email">Email</label>
            <input
                type="email"
                id="email"
                name="email"
                value="{{ old('email') }}"
                placeholder="your@email.com"
                required
                autofocus
                inputmode="email"
                autocomplete="email"
                autocapitalize="off"
                autocorrect="off"
                spellcheck="false">
        </div>

        <div class="field">
            <label for="password">Password</label>
            <input
                type="password"
                id="password"
                name="password"
                placeholder="••••••••"
                required
                autocomplete="current-password">
        </div>

        <button type="submit" class="btn btn-block">Sign In</button>
    </form>

    <div class="auth-note" style="text-align:center;">
        <a href="{{ url('/forgot-password') }}">Forgot password?</a>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    // ----------------------------------------------------------
    // Mobile keyboard handling
    //
    // When the on-screen keyboard opens, the viewport shrinks.
    // Without this script:
    //   - iOS Safari doesn't update 100dvh
    //   - The form can end up half-hidden under the keyboard
    //
    // With this script:
    //   - We pin .auth-wrap to the *actual visible* viewport height
    //   - The focused input always stays visible
    //   - Restores natural height when the keyboard closes
    // ----------------------------------------------------------
    if (!window.visualViewport) return;

    var wrap = document.querySelector('.auth-wrap');
    var card = document.querySelector('.auth-card');
    if (!wrap) return;

    var lastHeight = 0;

    function fitToViewport() {
        var h = window.visualViewport.height;
        // Only shrink the wrap when the keyboard is actually up
        // (viewport much smaller than the window)
        if (h < window.innerHeight - 100) {
            wrap.style.height = h + 'px';
            wrap.style.overflowY = 'auto';
            lastHeight = h;
        } else if (lastHeight) {
            wrap.style.height = '';
            wrap.style.overflowY = '';
            lastHeight = 0;
        }
    }

    function keepFocusVisible() {
        var el = document.activeElement;
        if (!el || !el.matches || !el.matches('input, select, textarea')) return;
        if (!card || !card.contains(el)) return;

        // iOS quirk: needs a tick before scrollIntoView works
        setTimeout(function () {
            try {
                el.scrollIntoView({ block: 'center', behavior: 'smooth' });
            } catch (e) {
                el.scrollIntoView(false);
            }
        }, 250);
    }

    window.visualViewport.addEventListener('resize', fitToViewport);
    window.visualViewport.addEventListener('scroll', fitToViewport);

    document.addEventListener('focusin', function (e) {
        if (e.target.matches && e.target.matches('input, select, textarea')) {
            keepFocusVisible();
        }
    });

    document.addEventListener('focusout', function () {
        // Restore full height after keyboard closes
        setTimeout(fitToViewport, 150);
    });

    // Initial fit (in case page loads with keyboard already open — rare)
    fitToViewport();
})();
</script>
@endpush