<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * The {locale} route segment is the single source of truth for which
 * language renders (spec Section 16, Question 20) — not a session value.
 * Two people sharing an /en/product/{slug} link both see English
 * regardless of each other's session, which is what makes every page
 * independently crawlable, shareable and bookmarkable per language.
 *
 * This middleware reads that segment, rejects anything that isn't a
 * supported locale, sets the application locale, and — crucially —
 * registers it as a default route parameter so every route() call
 * (server-side and, through Ziggy's `defaults`, client-side too) keeps
 * the visitor inside their own language without threading `locale`
 * through hundreds of call sites.
 */
class SetLocale
{
    /** Arabic and English are the base languages for the whole project (Section 16). */
    public const SUPPORTED = ['ar', 'en'];

    /** Arabic-first: a bare URL with no locale segment lands on /ar (Q20). */
    public const DEFAULT = 'ar';

    /** Remembers the visitor's last explicit choice, only to decide where a bare "/" redirects. */
    public const COOKIE = 'locale';

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->route('locale');

        // Unsupported segment = not a locale at all. 404 rather than
        // silently falling back, so /fr/shop doesn't quietly render
        // Arabic under a URL that claims otherwise.
        abort_unless(is_string($locale) && in_array($locale, self::SUPPORTED, true), 404);

        App::setLocale($locale);

        // Every route() / Ziggy route() call from here on fills {locale}
        // with the current one unless explicitly overridden — which is
        // what the locale switcher does to build the sibling URL.
        URL::defaults(['locale' => $locale]);

        // Drop the segment from the route's parameter bag now that it has
        // been consumed.
        //
        // This is load-bearing, not tidiness: Laravel passes route
        // parameters to controller actions *positionally*, not by name, so
        // leaving {locale} in place shifts every scalar argument by one —
        // ProductController::show(Request, string $slug) would receive
        // "en" as the slug and 404 on every product. Forgetting it here
        // keeps every existing controller signature correct, and URL
        // generation is unaffected because the default registered above is
        // what fills {locale} back in.
        $request->route()?->forgetParameter('locale');

        $response = $next($request);

        // Queued rather than set directly so it rides along with whatever
        // response the request produced (redirect, Inertia, JSON alike).
        Cookie::queue(self::COOKIE, $locale, 60 * 24 * 365);

        return $response;
    }

    /**
     * The locale in force for this request: the URL segment when there is
     * one, otherwise the visitor's preference. Safe to call from outside
     * the middleware pipeline — exception handlers and the auth redirect
     * callbacks both need it, and neither can assume SetLocale ran.
     */
    public static function current(Request $request): string
    {
        $locale = $request->route('locale');

        if (is_string($locale) && in_array($locale, self::SUPPORTED, true)) {
            return $locale;
        }

        // handle() forgets the route parameter once it has been applied
        // (see there for why), so the app locale is the next best source —
        // and the cookie only after that, for callers reached before any
        // of this ran.
        $applied = App::getLocale();

        return in_array($applied, self::SUPPORTED, true) ? $applied : self::preferred($request);
    }

    /**
     * Where a locale-less URL should go: the visitor's last explicit
     * choice if they have one, otherwise Arabic.
     */
    public static function preferred(Request $request): string
    {
        $cookie = $request->cookie(self::COOKIE);

        return is_string($cookie) && in_array($cookie, self::SUPPORTED, true)
            ? $cookie
            : self::DEFAULT;
    }
}
