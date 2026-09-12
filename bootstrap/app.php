<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
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
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('admin*', '*/admin*')
            ? route('admin.login')
            : route('login'));

        // Where an *already signed-in* identity lands if it hits the other
        // area's guest-only routes.
        $middleware->redirectUsersTo(fn (Request $request) => $request->is('admin*', '*/admin*')
            ? route('admin.dashboard')
            : route('account.dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Anvogue's page-not-found.html, rendered through Inertia so a
        // missing product/category URL keeps the storefront shell rather
        // than dropping to Laravel's framework error page. Admin 404s are
        // left alone — that area has its own Bootstrap bundle.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('admin*', '*/admin*') || $request->expectsJson()) {
                return null;
            }

            // The exception handler renders outside the middleware
            // pipeline, so HandleInertiaRequests::rootView() never ran for
            // this request and Inertia would reach for its default `app`
            // view (which this project doesn't have — it has two).
            Inertia::setRootView('storefront');

            return Inertia::render('Errors/NotFound')->toResponse($request)->setStatusCode(404);
        });
    })->create();
