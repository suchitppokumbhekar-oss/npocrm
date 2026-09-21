<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=yes, viewport-fit=cover, interactive-widget=resizes-content">
    <title>@yield('title', 'Login — NPO CRM')</title>

    {{-- PWA --}}
    <link rel="manifest" href="{{ asset('manifest.json') }}">
    <meta name="theme-color" content="#27ae60">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="NPO CRM">
    <link rel="apple-touch-icon" href="{{ asset('icons/icon-192.png') }}">
    <link rel="icon" type="image/svg+xml" href="{{ asset('icons/icon.svg') }}">
    <link rel="icon" type="image/png" sizes="192x192" href="{{ asset('icons/icon-192.png') }}">

    <link rel="stylesheet" href="{{ asset('assets/css/app.css') }}?v={{ @filemtime(public_path('assets/css/app.css')) ?: time() }}">
</head>
<body>

    <div class="auth-wrap">
        @yield('content')
    </div>

    <script>
        // Register service worker even from login page
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function () {
                navigator.serviceWorker.register('/service-worker.js').catch(function () {});
            });
        }
    </script>
    @stack('scripts')
</body>
</html>