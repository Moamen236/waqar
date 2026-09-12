<?php

namespace App\Providers;

use App\Models\Employee;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Observers\OrderObserver;
use App\Observers\OrderReturnObserver;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Super Admin bypasses every permission check outright (spec
        // Section 15: "Full system access") rather than needing every
        // permission explicitly assigned — Spatie's own recommended
        // pattern for a super-user role. Gate::before runs for every
        // authorization check regardless of guard, including customer
        // Policy checks (OrderPolicy::view) — so the parameter can't be
        // type-hinted to Employee, or a Customer's check would TypeError.
        Gate::before(function ($authenticatable, string $ability) {
            return $authenticatable instanceof Employee && $authenticatable->hasRole('Super Admin')
                ? true
                : null;
        });

        // Customer-facing notifications for the order lifecycle and the
        // post-delivery return flow (Section 23's event list) — see the
        // observers for why they hang off the models rather than off each
        // Action.
        Order::observe(OrderObserver::class);
        OrderReturn::observe(OrderReturnObserver::class);
    }
}
