<!DOCTYPE html>
{{-- Storefront root view — Tailwind bundle only (Section 23). Locale/dir
     come from the {locale} route segment via SetLocale (Q20). --}}
@php($meta = \App\Support\StorefrontMeta::for($page))
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Read by the storefront's fetch() calls (mini-cart, search suggest, shipping quote) --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- Server-rendered on purpose: link-preview and search crawlers read this
         HTML without running the app. See App\Support\StorefrontMeta. The
         <title> is taken over by Inertia's <Head> once the app boots. --}}
    <title inertia>{{ $meta['title'] }}</title>
    <meta name="description" content="{{ $meta['description'] }}">
    <meta name="robots" content="{{ $meta['robots'] }}">
    <link rel="canonical" href="{{ $meta['canonical'] }}">
    @foreach ($meta['alternates'] as $hreflang => $href)
        <link rel="alternate" hreflang="{{ $hreflang }}" href="{{ $href }}">
    @endforeach

    <meta property="og:site_name" content="{{ $meta['site_name'] }}">
    <meta property="og:type" content="{{ $meta['type'] }}">
    <meta property="og:title" content="{{ $meta['title'] }}">
    <meta property="og:description" content="{{ $meta['description'] }}">
    <meta property="og:url" content="{{ $meta['canonical'] }}">
    <meta property="og:image" content="{{ $meta['image'] }}">
    <meta property="og:image:alt" content="{{ $meta['image_alt'] }}">
    @if ($meta['image_size'])
        <meta property="og:image:width" content="{{ $meta['image_size'][0] }}">
        <meta property="og:image:height" content="{{ $meta['image_size'][1] }}">
    @endif
    <meta property="og:locale" content="{{ $meta['locale'] }}">
    @foreach ($meta['alternate_locales'] as $alternateLocale)
        <meta property="og:locale:alternate" content="{{ $alternateLocale }}">
    @endforeach
    @if ($meta['price'] !== null)
        <meta property="product:price:amount" content="{{ number_format($meta['price'], 2, '.', '') }}">
        <meta property="product:price:currency" content="EGP">
    @endif
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $meta['title'] }}">
    <meta name="twitter:description" content="{{ $meta['description'] }}">
    <meta name="twitter:image" content="{{ $meta['image'] }}">
    <meta name="twitter:image:alt" content="{{ $meta['image_alt'] }}">

    {{-- Brand icons: the وقار mark (favicon.ico supplied with the brand kit,
         PNGs cut from the same mark), navy tiles where transparency isn't
         allowed (iOS/Android home screens). --}}
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="32x32">
    <link rel="icon" href="{{ asset('storefront/images/icons/favicon-32.png') }}" type="image/png" sizes="32x32">
    <link rel="apple-touch-icon" href="{{ asset('storefront/images/icons/apple-touch-icon.png') }}">
    <link rel="manifest" href="{{ asset('site.webmanifest') }}">
    <meta name="theme-color" content="#012c4e">
    <meta name="application-name" content="{{ $meta['site_name'] }}">
    <meta name="apple-mobile-web-app-title" content="{{ app()->getLocale() === 'ar' ? 'وقار' : 'WAQAR' }}">

    @routes(nonce: request()->attributes->get('csp_nonce'))
    @vite(['resources/css/storefront.css', 'resources/js/storefront/app.tsx'])
    @inertiaHead
</head>
<body class="antialiased">
    @inertia
</body>
</html>
