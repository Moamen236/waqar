<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Pages
    |--------------------------------------------------------------------------
    |
    | This project has two Inertia apps, not one (Section 23) — the Tailwind
    | storefront and the Bootstrap admin, each with its own Pages/ directory
    | resolved relative to its own entry point (resources/js/storefront/app.tsx
    | and resources/js/admin/app.tsx). The package default
    | (resources/js/pages) matches neither, so assertInertia()'s
    | "does this page component exist?" check has to be pointed at both —
    | otherwise every Inertia assertion fails on a component that does exist.
    |
    | Both directories are listed so an admin-side assertion resolves the
    | same way a storefront one does; the two never share a component name.
    |
    */

    'pages' => [

        'ensure_pages_exist' => false,

        'paths' => [
            resource_path('js/storefront/Pages'),
            resource_path('js/admin/Pages'),
        ],

        'extensions' => ['tsx'],

    ],

];
