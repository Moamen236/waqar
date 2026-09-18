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
 * Every CRUD-shaped resource is split into .view/.create/.update/.delete
 * rather than one bundled .manage permission — seeing a list and being
 * able to change or remove what's on it are different grants, and a
 * single .manage meant any role holding it got every one of those
 * abilities with no way to hand out just one. A handful of screens aren't
 * CRUD-shaped at all (returns approve/receive/refund, treasury's three
 * distinct writes, reconciliation's view/create/transfer) — those are
 * split by the actual action name instead of forced into that mould.
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
        'categories.view', 'categories.create', 'categories.update', 'categories.delete',
        'collections.view', 'collections.create', 'collections.update', 'collections.delete',
        // Value-level create/update/delete rides along with the matching
        // attribute-level grant (one screen, not two) — see routes/admin.php.
        'attributes.view', 'attributes.create', 'attributes.update', 'attributes.delete',
        'coupons.manage',
        'promotions.view', 'promotions.create', 'promotions.update', 'promotions.delete',
        'reviews.moderate',

        // Inventory
        'inventory.view', 'inventory.adjust', 'inventory.transfer',
        'warehouses.manage',

        // Orders & fulfillment
        'orders.view', 'orders.create',
        'orders.status.update',
        'orders.assign',
        'orders.confirm_delivery',
        // Deliberately *not* granted alongside orders.status.update:
        // cancelling an order is Checking's daily work, removing one from
        // the book is not. See OrderController::destroy() for why the two
        // are sequenced rather than alternatives.
        'orders.delete',
        // .create is filing a return on a customer's behalf and recording
        // their shipping-fee consent (Customer Service's job, same
        // convention as orders.create). approve/receive/refund are three
        // sequential business transitions on the same return, not
        // create/update/delete of a record, so they're named for what
        // they do instead (Warehouse Manager/Accounting).
        'returns.create',
        'returns.approve', 'returns.receive', 'returns.refund',
        'delivery.representatives.view', 'delivery.representatives.create',
        'delivery.representatives.update', 'delivery.representatives.delete',
        'delivery.companies.view', 'delivery.companies.create',
        'delivery.companies.update', 'delivery.companies.delete',
        // Level-flexible shipping rates (Section 11) — without at least
        // one row the storefront checkout refuses every order, so this is
        // deliberately a Delivery Manager responsibility rather than a
        // developer-only seeder concern.
        'delivery.rates.view', 'delivery.rates.create',
        'delivery.rates.update', 'delivery.rates.delete',
        // Not CRUD-shaped: .view covers index/show, .create records a new
        // statement, .transfer settles one — there's no "update" of a
        // statement's own fields at all.
        'accounting.reconciliation.view', 'accounting.reconciliation.create',
        'accounting.reconciliation.transfer',

        // Finance. Not CRUD-shaped either: opening a new treasury account,
        // posting a manual transaction and transferring between two
        // existing accounts are three distinct writes, none of which is
        // really an "update" of an existing row.
        'treasury.view', 'treasury.create',
        'treasury.transactions.create', 'treasury.transfer',
        'expenses.manage',

        // People
        'customers.view', 'customers.create', 'customers.update', 'customers.delete',
        'employees.view', 'employees.create', 'employees.update', 'employees.delete',
        // No create/delete: roles are fixed by RoleSeeder, not authored
        // here — only which permissions each one carries changes.
        'roles.view', 'roles.update',

        // Reporting (screens are later phases — permission exists now so
        // it can be attached ahead of that UI landing)
        'reports.view',

        // Excel downloads. Deliberately separate from the matching
        // .view/.create permission each export sits alongside in its
        // route group — everyone who can see a list should not
        // automatically be able to walk out with the whole table as a
        // file, so this is its own grant a Super Admin opts a role into
        // via the permission-matrix editor rather than something that
        // rides along with view access.
        'orders.export', 'checking.export', 'returns.export',
        'inventory.export', 'products.export',

        // The audit trail (Section 23). Read-only by construction —
        // there is deliberately no activity.delete: an audit log an
        // operator can edit is not an audit log.
        'activity.view',
    ];

    /**
     * @var array<string, array<int, string>>
     */
    private const ROLE_DEFAULTS = [
        // Super Admin deliberately omitted — Gate::before bypasses it.
        'Chairman' => [
            'products.view', 'products.create', 'products.update', 'products.delete',
            'categories.view', 'categories.create', 'categories.update', 'categories.delete',
            'collections.view', 'collections.create', 'collections.update', 'collections.delete',
            'treasury.view', 'treasury.create', 'treasury.transactions.create', 'treasury.transfer',
            'orders.view', 'orders.delete', 'employees.view', 'customers.view',
            'reports.view', 'activity.view',
            'orders.export', 'products.export',
        ],
        'Vice Chairman' => [
            'products.view', 'products.create', 'products.update', 'products.delete',
            'categories.view', 'categories.create', 'categories.update', 'categories.delete',
            'collections.view', 'collections.create', 'collections.update', 'collections.delete',
            'attributes.view', 'attributes.create', 'attributes.update', 'attributes.delete',
            'coupons.manage',
            'promotions.view', 'promotions.create', 'promotions.update', 'promotions.delete',
            'reviews.moderate',
            'products.export',
        ],
        'Warehouse Manager' => [
            'inventory.view', 'inventory.adjust', 'inventory.transfer',
            'warehouses.manage',
            'returns.create', 'returns.approve', 'returns.receive', 'returns.refund',
            'inventory.export', 'returns.export',
        ],
        'Customer Service' => [
            'customers.view', 'customers.create', 'customers.update',
            'orders.view', 'orders.create',
            'returns.create',
        ],
        'Customer Service Team Leader' => [
            // Same permission names as Customer Service — the narrowing to
            // "own team only" is a query-scope concern (Order::scopeVisibleTo,
            // Employee team filtering), not a distinct permission (Q16).
            'customers.view', 'customers.create', 'customers.update',
            'orders.view', 'orders.create',
            'returns.create',
            'employees.view',
        ],
        // Read-only by design: it watches the storefront order book and
        // nothing else. Order::scopeVisibleTo() narrows what it reads to
        // website-sourced orders; the empty rest of this list is what
        // keeps it from editing customers or filing returns.
        'Store Orders' => [
            'orders.view',
        ],
        'Checking' => [
            'orders.view', 'orders.status.update',
            'checking.export',
        ],
        'Delivery Manager' => [
            'orders.view', 'orders.assign',
            'delivery.representatives.view', 'delivery.representatives.create',
            'delivery.representatives.update', 'delivery.representatives.delete',
            'delivery.companies.view', 'delivery.companies.create',
            'delivery.companies.update', 'delivery.companies.delete',
            'delivery.rates.view', 'delivery.rates.create',
            'delivery.rates.update', 'delivery.rates.delete',
        ],
        'Accounting' => [
            'orders.view', 'orders.confirm_delivery',
            'accounting.reconciliation.view', 'accounting.reconciliation.create', 'accounting.reconciliation.transfer',
            'treasury.view', 'treasury.create', 'treasury.transactions.create', 'treasury.transfer',
            'returns.create', 'returns.approve', 'returns.receive', 'returns.refund', 'expenses.manage',
            'returns.export',
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
