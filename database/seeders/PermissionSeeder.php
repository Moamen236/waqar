<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Granular, resource.action permissions (spec Section 15: "every screen or
 * action is gated by a granular permission ... never by role name
 * directly"). Super Admin isn't assigned any of these explicitly — it
 * bypasses every check entirely via the Gate::before hook in
 * AppServiceProvider, per Spatie's own recommended pattern for a
 * super-user role.
 *
 * The defaults below seed a sensible starting matrix per role's Section 15
 * responsibilities; Phase 4's permission-matrix editor
 * (/admin/roles/{role}) lets a Super Admin change any of this afterward —
 * this seeder only establishes day-one state, it isn't the source of truth
 * once the system is running.
 */
class PermissionSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * @var array<int, string>
     */
    private const PERMISSIONS = [
        // Catalog
        'products.view', 'products.create', 'products.update', 'products.delete',
        'categories.manage',
        'collections.manage',
        'attributes.manage',
        'coupons.manage',
        'promotions.manage',
        'reviews.moderate',

        // Inventory
        'inventory.view', 'inventory.adjust', 'inventory.transfer',
        'warehouses.manage',

        // Orders & fulfillment
        'orders.view', 'orders.create',
        'orders.status.update',
        'orders.assign',
        'orders.confirm_delivery',
        // Split in two: .create is filing a return on a customer's behalf
        // and recording their shipping-fee consent (Customer Service's
        // job, same convention as orders.create) — .manage is the actual
        // approve/receive/refund workflow (Warehouse Manager/Accounting).
        'returns.create',
        'returns.manage',
        'delivery.representatives.manage',
        'delivery.companies.manage',
        // Level-flexible shipping rates (Section 11) — without at least
        // one row the storefront checkout refuses every order, so this is
        // deliberately a Delivery Manager responsibility rather than a
        // developer-only seeder concern.
        'delivery.rates.manage',
        'accounting.reconciliation.manage',

        // Finance
        'treasury.view', 'treasury.manage',
        'expenses.manage',

        // People
        'customers.view', 'customers.manage',
        'employees.view', 'employees.manage',
        'roles.manage',

        // Reporting (screens are later phases — permission exists now so
        // it can be attached ahead of that UI landing)
        'reports.view',
    ];

    /**
     * @var array<string, array<int, string>>
     */
    private const ROLE_DEFAULTS = [
        // Super Admin deliberately omitted — Gate::before bypasses it.
        'Chairman' => [
            'products.view', 'products.create', 'products.update', 'products.delete',
            'categories.manage', 'collections.manage',
            'treasury.view', 'treasury.manage',
            'orders.view', 'employees.view', 'customers.view',
            'reports.view',
        ],
        'Vice Chairman' => [
            'products.view', 'products.create', 'products.update', 'products.delete',
            'categories.manage', 'collections.manage', 'attributes.manage',
            'coupons.manage', 'promotions.manage', 'reviews.moderate',
        ],
        'Warehouse Manager' => [
            'inventory.view', 'inventory.adjust', 'inventory.transfer',
            'warehouses.manage', 'returns.create', 'returns.manage',
        ],
        'Customer Service' => [
            'customers.view', 'customers.manage',
            'orders.view', 'orders.create',
            'returns.create',
        ],
        'Customer Service Team Leader' => [
            // Same permission names as Customer Service — the narrowing to
            // "own team only" is a query-scope concern (Order::scopeVisibleTo,
            // Employee team filtering), not a distinct permission (Q16).
            'customers.view', 'customers.manage',
            'orders.view', 'orders.create',
            'returns.create',
            'employees.view',
        ],
        'Checking' => [
            'orders.view', 'orders.status.update',
        ],
        'Delivery Manager' => [
            'orders.view', 'orders.assign',
            'delivery.representatives.manage', 'delivery.companies.manage',
            'delivery.rates.manage',
        ],
        'Accounting' => [
            'orders.view', 'orders.confirm_delivery',
            'accounting.reconciliation.manage',
            'treasury.view', 'treasury.manage',
            'returns.create', 'returns.manage', 'expenses.manage',
        ],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $name) {
            Permission::findOrCreate($name, 'employee');
        }

        // Spatie caches the permission list in memory the first time
        // it's read — since that can happen before this seeder runs
        // (RoleSeeder, or anything else touching roles/permissions
        // earlier in the same process), the cache has to be invalidated
        // again here too, not just once at the top, or syncPermissions()
        // below won't find the rows just created above.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::ROLE_DEFAULTS as $roleName => $permissions) {
            $role = Role::findOrCreate($roleName, 'employee');
            $role->syncPermissions($permissions);
        }
    }
}
