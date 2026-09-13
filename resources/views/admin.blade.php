<!DOCTYPE html>
{{-- Admin root view — the real Larkon theme (Section 23), not a plain-
     Bootstrap placeholder: vendor.min.css/icons.min.css/app.min.css are
     Larkon's own compiled assets, served statically from public/admin-theme
     (copied from Admin Template/assets/, pruned of unused template demo
     images) rather than run through Vite — a precompiled vendor theme's
     internal relative asset paths are fragile to rebundle, so this mirrors
     how the template ships it. Arabic-only for v1 staff UI (Q2), under the
     same {locale} mechanism as the storefront (Q20).

     app.min.css is Larkon's LTR build and app-rtl.min.css its mirrored
     one; they are alternatives, never both — loading the LTR sheet under
     dir="rtl" is what broke the layout before Phase 6. --}}
{{-- data-menu-color/data-topbar-color activate Larkon's own themed
     variable blocks (app.min.css only defines --bs-main-nav-bg etc.
     inside [data-menu-color=...] attribute selectors — without this
     attribute the sidebar had no background color at all, not any
     intentional default). "dark" is Larkon's own documented default
     (config.js's defaultConfig.menu.color).

     data-bs-theme is the light/dark switch — Bootstrap 5.3's own colour
     mode attribute, which app.min.css compiles against twice
     (`:root,[data-bs-theme=light]` and `[data-bs-theme=dark]`). "light"
     is Larkon's defaultConfig.theme. Writing it explicitly rather than
     relying on the bare `:root` fallback keeps the attribute a single
     source of truth for the toggle to read back. Flipping it to "dark"
     also re-points the topbar, because Larkon groups
     `html[data-bs-theme=dark][data-topbar-color=light]` with its own
     dark topbar block — so the topbar follows the mode without this
     view having to touch data-topbar-color. --}}
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}" data-bs-theme="light" data-menu-color="dark" data-topbar-color="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Read by the storefront's fetch() calls (mini-cart, search suggest, shipping quote) --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title inertia>{{ config('app.name', 'WAQAR') }} Admin</title>
    <link rel="shortcut icon" href="{{ asset('admin-theme/assets/images/favicon.ico') }}">
    <link href="{{ asset('admin-theme/assets/css/vendor.min.css') }}" rel="stylesheet" type="text/css">
    <link href="{{ asset('admin-theme/assets/css/icons.min.css') }}" rel="stylesheet" type="text/css">
    @php($rtl = in_array(app()->getLocale(), ['ar'], true))
    <link href="{{ asset($rtl ? 'admin-theme/assets/css/app-rtl.min.css' : 'admin-theme/assets/css/app.min.css') }}" rel="stylesheet" type="text/css">
    {{-- Larkon picks its sidebar size in JavaScript, not CSS: app.min.css
         has no media query for .main-nav at all, only [data-menu-size]
         attribute selectors, and the template's own config.js sets the
         attribute from window.innerWidth in a blocking <head> script —
         before first paint. AdminLayout keeps it in sync from there (and
         owns the resize listener), but the *initial* value has to be set
         here: an effect runs after mount, so a phone-width visit painted
         one frame with the 280px sidebar over the content first.
         1140 is the template's own breakpoint, hard-coded the same way in
         its config.js and app.js. --}}
    <script nonce="{{ request()->attributes->get('csp_nonce') }}">
        (function () {
            // 'sm-hover-active' is Larkon's own defaultConfig.menu.size, and
            // the only desktop mode in which the sidebar's collapse chevron
            // is visible at all.
            var size = 'sm-hover-active';
            if (window.innerWidth <= 1140) {
                size = 'hidden';
            } else {
                // Throws outright in a browser set to block site data.
                try {
                    var stored = localStorage.getItem('waqar.admin.menuSize');
                    if (stored === 'sm-hover' || stored === 'condensed') size = stored;
                } catch (e) {}
            }
            document.documentElement.setAttribute('data-menu-size', size);

            // The colour mode has to be restored here for the same reason as
            // the sidebar size, only more visibly: React mounts after first
            // paint, so a dark-mode user would get one full frame of the
            // light theme — a white flash on every page load.
            try {
                var theme = localStorage.getItem('waqar.admin.theme');
                if (theme === 'dark' || theme === 'light') {
                    document.documentElement.setAttribute('data-bs-theme', theme);
                }
            } catch (e) {}
        })();
    </script>
    @routes(nonce: request()->attributes->get('csp_nonce'))
    @vite(['resources/css/admin.css', 'resources/js/admin/app.tsx'])
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>
