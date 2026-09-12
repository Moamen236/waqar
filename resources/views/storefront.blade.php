<!DOCTYPE html>
{{-- Storefront root view — Tailwind bundle only (Section 23). Locale/dir
     are read from the app locale for now; Phase 6 wires the {locale} route
     segment + SetLocale middleware (Q20) to drive this per-request. --}}
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Read by the storefront's fetch() calls (mini-cart, search suggest, shipping quote) --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" href="/storefront/images/generated/favicon.svg" type="image/svg+xml">
    <title inertia>{{ config('app.name', 'WAQAR') }}</title>
    @routes(nonce: request()->attributes->get('csp_nonce'))
    @vite(['resources/css/storefront.css', 'resources/js/storefront/app.tsx'])
    @inertiaHead
</head>
<body class="antialiased">
    @inertia
</body>
</html>
