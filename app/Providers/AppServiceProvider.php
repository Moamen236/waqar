<?php

namespace App\Providers;

use App\Listeners\LogAccessChange;
use App\Models\Employee;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\Review;
use App\Models\ShippingCompanyStatement;
use App\Models\Treasury;
use App\Models\TreasuryTransaction;
use App\Observers\InventoryMovementObserver;
use App\Observers\OrderObserver;
use App\Observers\OrderReturnObserver;
use App\Observers\ReviewObserver;
use App\Observers\ShippingCompanyStatementObserver;
use App\Observers\TreasuryObserver;
use App\Observers\TreasuryTransactionObserver;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Activitylog\Facades\CauserResolver;
use Spatie\Permission\Events\PermissionAttachedEvent;
use Spatie\Permission\Events\PermissionDetachedEvent;
use Spatie\Permission\Events\RoleAttachedEvent;
use Spatie\Permission\Events\RoleDetachedEvent;

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

        // Notifications for the order lifecycle and the post-delivery
        // return flow (Section 23's event list) — see the observers for
        // why they hang off the models rather than off each Action. Both
        // carry the staff side as well as the customer side.
        Order::observe(OrderObserver::class);
        OrderReturn::observe(OrderReturnObserver::class);

        // Staff-only events. Each observer does its own filtering: most
        // rows written to these tables are routine traffic nobody needs
        // telling about, and the interesting minority is what each class
        // defines. TreasuryTransactionObserver in particular exists
        // mostly to exclude the per-order COD income.
        TreasuryTransaction::observe(TreasuryTransactionObserver::class);
        Treasury::observe(TreasuryObserver::class);
        ShippingCompanyStatement::observe(ShippingCompanyStatementObserver::class);
        InventoryMovement::observe(InventoryMovementObserver::class);
        Review::observe(ReviewObserver::class);

        $this->resolveActivityCauser();

        // Role/permission grants are pivot writes with no model event of
        // their own — see the listener for why they are audited from
        // Spatie's events rather than from the two controllers.
        Event::listen(RoleAttachedEvent::class, [LogAccessChange::class, 'onRoleAttached']);
        Event::listen(RoleDetachedEvent::class, [LogAccessChange::class, 'onRoleDetached']);
        Event::listen(PermissionAttachedEvent::class, [LogAccessChange::class, 'onPermissionAttached']);
        Event::listen(PermissionDetachedEvent::class, [LogAccessChange::class, 'onPermissionDetached']);
    }

    /**
     * Who gets credited on an activity-log entry.
     *
     * This project has two guards and no shared `users` table (Q19), and
     * `auth.defaults.guard` is `customer` because the storefront is the
     * larger surface. Spatie's stock resolver asks the *default* guard —
     * so every audited admin action would have been recorded with a null
     * causer, which is exactly the half of "a readable, attributed log
     * entry" (roadmap Phase 7) that matters.
     *
     * Employee is checked first: an employee session is the only one that
     * can reach the admin area at all, and a machine running a console
     * command or a queued job has neither, which correctly logs as a
     * system action rather than being falsely attributed to someone.
     */
    private function resolveActivityCauser(): void
    {
        CauserResolver::resolveUsing(
            fn () => Auth::guard('employee')->user() ?? Auth::guard('customer')->user()
        );
    }
}
