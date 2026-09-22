<?php

namespace App\Services\Notifications;

use App\Enums\OrderSource;
use App\Models\Employee;
use App\Models\Order;
use App\Notifications\Staff\StaffNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;

/**
 * Who gets told, for every staff-facing event (Section 23's event list).
 *
 * Two entry points because there are two kinds of recipient rule in this
 * system, and conflating them is how an agent ends up reading another
 * team's order book:
 *
 * - toRoles() — plain fan-out. Everyone holding one of these roles.
 * - forOrder() — the same, plus the *scoped* roles resolved through the
 *   order itself, mirroring Order::scopeVisibleTo(). Customer Service
 *   means "the agent who created this order, and their team leader" —
 *   never the whole role. Store Orders means "every Store Orders
 *   employee, but only for website orders". Keep this in step with that
 *   scope: a recipient who could not open the order must never be
 *   notified about it.
 *
 * Roles are resolved live, never from PermissionSeeder's constants — the
 * matrix is editable at runtime via /admin/roles/{role}.
 *
 * Super Admin is deliberately absent. It bypasses every gate through
 * Gate::before, so wiring that bypass in here would deliver every
 * notification in the system to one inbox. A Super Admin receives only
 * what the roles they are actually assigned would earn them.
 */
class StaffNotifier
{
    /**
     * Roles whose recipients depend on the order, not just the role.
     *
     * @var list<string>
     */
    private const ORDER_SCOPED_ROLES = [
        'Customer Service',
        'Customer Service Team Leader',
        'Store Orders',
    ];

    /**
     * @param  list<string>  $roles
     * @param  array<string, string|int|float|null>  $params
     * @param  array<string, string|int>  $routeParams
     */
    public function toRoles(
        array $roles,
        string $type,
        array $params = [],
        ?string $route = null,
        array $routeParams = [],
        ?int $actorId = null,
    ): void {
        $this->dispatch($this->withRoles($roles), $type, $params, $route, $routeParams, $actorId);
    }

    /**
     * @param  list<string>  $roles
     * @param  array<string, string|int|float|null>  $params
     * @param  array<string, string|int>  $routeParams
     */
    public function forOrder(
        Order $order,
        array $roles,
        string $type,
        array $params = [],
        ?string $route = null,
        array $routeParams = [],
        ?int $actorId = null,
    ): void {
        $recipients = $this->withRoles(array_values(array_diff($roles, self::ORDER_SCOPED_ROLES)));

        // Either Customer Service role in the list means the same thing:
        // the agent who owns this order plus whoever leads them. Listing
        // the two separately at a call site would be noise — the leader
        // is reached through the agent, not through their own role.
        if (array_intersect($roles, ['Customer Service', 'Customer Service Team Leader']) !== []) {
            $owner = $order->created_by_employee_id !== null
                ? Employee::query()->with('teamLeader')->find($order->created_by_employee_id)
                : null;

            $recipients = $recipients->push($owner, $owner?->teamLeader);
        }

        if (in_array('Store Orders', $roles, true) && $order->order_source === OrderSource::Website) {
            $recipients = $recipients->merge($this->withRoles(['Store Orders']));
        }

        $this->dispatch(
            $recipients,
            $type,
            [...$params, 'number' => $order->order_number],
            $route ?? 'admin.orders.show',
            $route === null ? ['order' => $order->id] : $routeParams,
            $actorId,
        );
    }

    /**
     * The employee behind the current request, or null for anything the
     * storefront or a console command triggered — in which case there is
     * nobody to suppress, which is correct.
     */
    public static function actor(): ?int
    {
        return Auth::guard('employee')->id();
    }

    /**
     * @param  list<string>  $roles
     * @return Collection<int, Employee|null>
     */
    private function withRoles(array $roles): Collection
    {
        if ($roles === []) {
            return new Collection;
        }

        // Spatie's role() scope throws RoleDoesNotExist for a name that
        // isn't in the table, and every caller reaches this from inside
        // the business transaction that triggered the notification — so
        // one role renamed through /admin/roles would roll back a cash
        // collection or a stock deduction. Notifying nobody is the only
        // acceptable failure mode for a notification lookup; narrowing to
        // the roles that actually exist is what makes that possible.
        $known = Role::query()
            ->where('guard_name', 'employee')
            ->whereIn('name', $roles)
            ->pluck('name')
            ->all();

        if ($known === []) {
            return new Collection;
        }

        return new Collection(
            Employee::query()->role($known, 'employee')->where('is_active', true)->get()->all()
        );
    }

    /**
     * @param  Collection<int, Employee|null>  $recipients
     * @param  array<string, string|int|float|null>  $params
     * @param  array<string, string|int>  $routeParams
     */
    private function dispatch(
        Collection $recipients,
        string $type,
        array $params,
        ?string $route,
        array $routeParams,
        ?int $actorId,
    ): void {
        $recipients = $recipients
            ->filter(fn (?Employee $employee) => $employee !== null && $employee->is_active)
            // A Team Leader holds both Customer Service roles and is
            // reached twice by forOrder(); without this they get two of
            // every notification about their own team's orders.
            ->unique(fn (Employee $employee) => $employee->id)
            // Nobody needs telling what they just did themselves.
            ->reject(fn (Employee $employee) => $employee->id === $actorId)
            ->values();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new StaffNotification($type, $params, $route, $routeParams));
    }
}
