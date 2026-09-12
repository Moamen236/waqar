<?php

use App\Http\Middleware\SetLocale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Locale-prefixed routes (Section 16, Question 20)
|--------------------------------------------------------------------------
| Every storefront and admin route lives under /{locale}/… — /ar/shop,
| /en/shop, /ar/admin/checking, /en/admin/checking. The URL is the single
| source of truth for which language renders; SetLocale validates the
| segment, sets the app locale, and registers it as a default route
| parameter so nothing inside these files has to know about it.
|
| Section 16's "never a separate architecture" principle is why admin gets
| the same treatment as the storefront, even though only Arabic is turned
| on for staff in v1 (Q2).
*/

Route::prefix('{locale}')
    // Constrained here as well as validated in the middleware, and both
    // matter: without it "/shop" matches this group with locale="shop"
    // and 404s in the middleware, instead of falling through to the
    // redirect below that sends it to "/ar/shop".
    ->whereIn('locale', SetLocale::SUPPORTED)
    ->middleware(SetLocale::class)
    ->group(function () {
        Route::group([], base_path('routes/store.php'));

        Route::prefix('admin')->name('admin.')->group(base_path('routes/admin.php'));
    });

/*
|--------------------------------------------------------------------------
| Locale-less entry points
|--------------------------------------------------------------------------
| A bare URL redirects into the visitor's language rather than rendering
| one — Arabic by default, or their last explicit choice if the preference
| cookie is set (Q20). 302, not 301: the target depends on the visitor, so
| it must not be cached as permanent.
|
| The catch-all is registered last so it only ever sees paths no
| locale-prefixed route matched, and it preserves the rest of the path so
| an old /shop link lands on /ar/shop rather than the homepage.
*/

Route::get('/', fn (Request $request) => redirect('/'.SetLocale::preferred($request)))
    ->name('locale.root');

Route::get('/{path}', function (Request $request, string $path) {
    // A path that *already* starts with a valid locale reached here only
    // because nothing inside that locale group matched it — i.e. it is a
    // genuine 404. Redirecting it would prepend a second locale segment
    // and loop (/ar/nope -> /ar/ar/nope -> …).
    abort_if(in_array(explode('/', $path)[0], SetLocale::SUPPORTED, true), 404);

    return redirect('/'.SetLocale::preferred($request).'/'.$path);
})
    ->where('path', '.*')
    ->name('locale.redirect');
