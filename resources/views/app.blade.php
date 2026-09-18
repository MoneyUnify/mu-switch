<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        {{-- Primary SEO meta --}}
        <meta name="description" content="MoneyUnify Switch is a source-available, self-hosted payment switch API that unifies mobile money and cards across Africa. Collect through one endpoint — POST /api/v1/payment/request — with automatic, sequential provider failover across 32 countries and 24 currencies.">
        <meta name="keywords" content="payment switch, mobile money API, payment orchestration, provider failover, collections API, MTN, Airtel Money, M-Pesa, TNM Mpamba, Africa payments, self-hosted payment gateway, MoneyUnify">
        <meta name="author" content="Blessed Jason Mwanza">
        <meta name="robots" content="index, follow">
        <meta name="theme-color" content="#2563eb">
        <link rel="canonical" href="{{ url()->current() }}">

        {{-- Open Graph (Facebook, LinkedIn, WhatsApp, Slack, …) --}}
        <meta property="og:type" content="website">
        <meta property="og:site_name" content="MoneyUnify Switch">
        <meta property="og:title" content="MoneyUnify Payment Switch — one API for African mobile money & cards">
        <meta property="og:description" content="Orchestrate, route, and optimize payments across providers. Source-available and self-hosted, with automatic failover across 32 countries and 24 currencies.">
        <meta property="og:url" content="{{ url()->current() }}">
        <meta property="og:image" content="{{ url('/og-image.png') }}">
        <meta property="og:image:type" content="image/png">
        <meta property="og:image:width" content="2400">
        <meta property="og:image:height" content="1260">
        <meta property="og:image:alt" content="MoneyUnify Payment Switch landing page showing supported African countries, providers and currencies">
        <meta property="og:locale" content="en_US">

        {{-- Twitter / X card --}}
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="MoneyUnify Payment Switch — one API for African mobile money & cards">
        <meta name="twitter:description" content="Source-available, self-hosted payment switch. One endpoint, automatic provider failover across 32 countries and 24 currencies.">
        <meta name="twitter:image" content="{{ url('/og-image.png') }}">
        <meta name="twitter:image:alt" content="MoneyUnify Payment Switch landing page showing supported African countries, providers and currencies">

        {{-- Point AI assistants & crawlers at the machine-readable overview --}}
        <link rel="alternate" type="text/markdown" title="llms.txt" href="{{ url('/llms.txt') }}">

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

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        <x-inertia::head>
            <title>{{ config('app.name') === 'Laravel' ? 'MoneyUnify Switch' : config('app.name', 'MoneyUnify Switch') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
