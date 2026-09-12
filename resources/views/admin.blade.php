<!DOCTYPE html>
{{-- Admin root view — the real Larkon theme (Section 23), not a plain-
     Bootstrap placeholder: vendor.min.css/icons.min.css/app.min.css are
     Larkon's own compiled assets, served statically from public/admin-theme
     (copied from Admin Template/assets/, pruned of unused template demo
     images) rather than run through Vite — a precompiled vendor theme's
     internal relative asset paths are fragile to rebundle, so this mirrors
     how the template ships it. Arabic-only for v1 staff UI (Q2); same
     {locale} mechanism as storefront.blade.php will apply once Phase 6
     lands (Larkon ships an app-rtl.min.css for that, not wired yet). --}}
{{-- data-menu-color/data-topbar-color activate Larkon's own themed
     variable blocks (app.min.css only defines --bs-main-nav-bg etc.
     inside [data-menu-color=...] attribute selectors — without this
     attribute the sidebar had no background color at all, not any
     intentional default). "dark" is Larkon's own documented default
     (config.js's defaultConfig.menu.color). --}}
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}" data-menu-color="dark" data-topbar-color="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Read by the storefront's fetch() calls (mini-cart, search suggest, shipping quote) --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title inertia>{{ config('app.name', 'WAQAR') }} Admin</title>
    <link rel="shortcut icon" href="{{ asset('admin-theme/assets/images/favicon.ico') }}">
    <link href="{{ asset('admin-theme/assets/css/vendor.min.css') }}" rel="stylesheet" type="text/css">
    <link href="{{ asset('admin-theme/assets/css/icons.min.css') }}" rel="stylesheet" type="text/css">
    <link href="{{ asset('admin-theme/assets/css/app.min.css') }}" rel="stylesheet" type="text/css">
    @routes
    @vite(['resources/css/admin.css', 'resources/js/admin/app.tsx'])
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>
