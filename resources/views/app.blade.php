<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- What a shared link shows (WhatsApp, search), in the page's locale (docs/frontend.md rule 10) --}}
        <meta name="description" content="{{ __('welcome.meta.description') }}">
        <meta property="og:type" content="website">
        <meta property="og:site_name" content="{{ config('app.name', 'Elifba') }}">
        <meta property="og:title" content="{{ __('welcome.meta.title') }}">
        <meta property="og:description" content="{{ __('welcome.meta.description') }}">
        <meta property="og:locale" content="{{ app()->getLocale() === 'en' ? 'en_US' : 'az_AZ' }}">
        <meta property="og:image" content="{{ url('images/og.png') }}">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
        <meta property="og:image:alt" content="{{ __('welcome.meta.image_alt') }}">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="{{ __('welcome.meta.title') }}">
        <meta name="twitter:description" content="{{ __('welcome.meta.description') }}">
        <meta name="twitter:image" content="{{ url('images/og.png') }}">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: oklch(1 0 0);
            }

            html.dark {
                background-color: oklch(0.145 0 0);
            }
        </style>

        <link rel="icon" href="/favicon.svg" type="image/svg+xml">

        {{-- Elifba as an installable app (docs/architecture.md §10 "Installing Elifba") --}}
        <link rel="manifest" href="{{ route('manifest', absolute: false) }}">
        <link rel="apple-touch-icon" href="{{ \App\Support\WebApp::APPLE_TOUCH_ICON }}">
        <meta name="theme-color" content="{{ \App\Support\WebApp::THEME_COLOR }}">
        <meta name="apple-mobile-web-app-title" content="{{ config('app.name', 'Elifba') }}">

        {{--
            Chrome's offer to install Elifba, caught before React mounts so it is
            never missed. Chrome's own install bar is held back on every page (the
            board, the remote); the install banner offers it only where
            docs/frontend.md rule 11 allows (resources/js/hooks/use-install-offer.ts).
        --}}
        <script>
            window.elifbaInstall = { prompt: null, installed: false };

            window.addEventListener('beforeinstallprompt', function (event) {
                event.preventDefault();
                window.elifbaInstall = { prompt: event, installed: false };
                window.dispatchEvent(new Event('elifba:install'));
            });

            window.addEventListener('appinstalled', function () {
                window.elifbaInstall = { prompt: null, installed: true };
                window.dispatchEvent(new Event('elifba:install'));
            });
        </script>

        @fonts

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Elifba') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
