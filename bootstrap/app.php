<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);

        // Two guards, two login screens (Section 15) — an unauthenticated
        // hit on an /admin/* route goes to the employee login, everything
        // else to the customer one.
        //
        // The locale is passed explicitly rather than leaned on from
        // URL::defaults(): these callbacks can fire from places SetLocale
        // never ran (a guest hitting a locale-less URL), and a missing
        // {locale} parameter there is a 500 rather than a redirect. The
        // patterns match "*/admin" — not "admin*" — because every path is
        // now prefixed with the locale segment (Q20).
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('*/admin', '*/admin/*')
            ? route('admin.login', ['locale' => SetLocale::current($request)])
            : route('login', ['locale' => SetLocale::current($request)]));

        // Where an *already signed-in* identity lands if it hits the other
        // area's guest-only routes.
        $middleware->redirectUsersTo(fn (Request $request) => $request->is('*/admin', '*/admin/*')
            ? route('admin.dashboard', ['locale' => SetLocale::current($request)])
            : route('account.dashboard', ['locale' => SetLocale::current($request)]));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Anvogue's page-not-found.html, rendered through Inertia so a
        // missing product/category URL keeps the storefront shell rather
        // than dropping to Laravel's framework error page. Admin 404s are
        // left alone — that area has its own Bootstrap bundle.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('*/admin', '*/admin/*') || $request->expectsJson()) {
                return null;
            }

            // The exception handler renders outside the middleware
            // pipeline, so HandleInertiaRequests::rootView() never ran for
            // this request and Inertia would reach for its default `app`
            // view (which this project doesn't have — it has two).
            Inertia::setRootView('storefront');

            // Same reason as the redirect callbacks above — the handler
            // runs outside the middleware pipeline, so the locale has to
            // be re-derived rather than assumed.
            App::setLocale(SetLocale::current($request));
            URL::defaults(['locale' => App::getLocale()]);

            return Inertia::render('Errors/NotFound')->toResponse($request)->setStatusCode(404);
        });
    })->create();
