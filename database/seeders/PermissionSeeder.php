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
 * directly"). Super Admin is explicitly granted every permission below —
 * plus the Gate::before bypass in AppServiceProvider as a safety net for
 * any permission added in code but not yet re-seeded — per Spatie's own
 * recommended pattern for a super-user role.
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
        // Checking's phone call before a return goes to the warehouse:
        // confirm the reason, reschedule, or reject it. Its own grant
        // rather than folding into returns.approve, because Checking
        // must not be able to approve one without making the call.
        'returns.check',
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
        // The geography every address is built from. Two grants rather
        // than four: these are low-churn reference tables, and the only
        // distinction that matters is who may read the list versus who
        // may reshape the map the whole storefront cascades through.
        'geo.view', 'geo.manage',
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

        // Reporting. `reports.view` is the module gate — it opens
        // /admin/reports itself; each group below decides which reports
        // appear in the catalogue once inside.
        'reports.view',
        'reports.orders.view',
        'reports.sales.view',
        'reports.inventory.view',
        'reports.returns.view',
        'reports.finance.view',
        'reports.employees.view',
        'reports.audit.view',
        'reports.executive.view',
        // Column-level, not screen-level: unlocks cost price, COGS and
        // margin columns wherever they appear. `products.cost_price` is
        // documented internal-only in the schema, and the same logic
        // applies to an operations employee who can legitimately see stock
        // levels but not what the company pays for goods.
        'reports.cost.view',
        // Downloads, deliberately separate from the view grants above —
        // the same principle as orders.export: everyone who can read a
        // report should not automatically be able to walk out with it.
        'reports.export',

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
        // Super Admin gets every permission explicitly (synced in run()
        // below) — Gate::before remains as a fallback for permissions
        // added in code but not yet re-seeded.
        'Chairman' => [
            'products.view', 'products.create', 'products.update', 'products.delete',
            'categories.view', 'categories.create', 'categories.update', 'categories.delete',
            'collections.view', 'collections.create', 'collections.update', 'collections.delete',
            'treasury.view', 'treasury.create', 'treasury.transactions.create', 'treasury.transfer',
            'orders.view', 'orders.delete', 'employees.view', 'customers.view',
            'reports.view', 'activity.view',
            'orders.export', 'products.export',
            // Chairman is the one role that sees the whole reporting
            // module, audit and cost included (spec E.2).
            'reports.orders.view', 'reports.sales.view', 'reports.inventory.view',
            'reports.returns.view', 'reports.finance.view', 'reports.employees.view',
            'reports.audit.view', 'reports.executive.view',
            'reports.cost.view', 'reports.export',
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
            // Catalogue authority, so sales and inventory reporting — but
            // deliberately not finance or audit (spec E.2).
            'reports.view', 'reports.orders.view', 'reports.sales.view',
            'reports.inventory.view', 'reports.returns.view', 'reports.executive.view',
            'reports.cost.view', 'reports.export',
        ],
        'Warehouse Manager' => [
            'inventory.view', 'inventory.adjust', 'inventory.transfer',
            'warehouses.manage',
            'returns.create', 'returns.approve', 'returns.receive', 'returns.refund',
            'inventory.export', 'returns.export',
            // Stock and returns reporting, plus its own team's activity —
            // no cost columns: it manages stock levels, not purchase prices.
            'reports.view', 'reports.inventory.view', 'reports.returns.view',
            'reports.employees.view', 'reports.export',
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
            // Every report a leader opens is narrowed to their own team by
            // Order::scopeVisibleTo() — the same scope their order queue
            // uses — so these need no separate "team only" permission (Q16).
            'reports.view', 'reports.orders.view', 'reports.sales.view',
            'reports.returns.view', 'reports.employees.view',
        ],
        // Read-only by design: it watches the storefront order book and
        // nothing else. Order::scopeVisibleTo() narrows what it reads to
        // website-sourced orders; the empty rest of this list is what
        // keeps it from editing customers or filing returns.
        'Store Orders' => [
            'orders.view',
            // Order reporting only, and scopeVisibleTo() narrows every row
            // to website-sourced orders — the same rule as its queue.
            'reports.view', 'reports.orders.view',
        ],
        'Checking' => [
            'orders.view', 'orders.status.update',
            'checking.export',
            'returns.check',
            // Orders reporting, plus low-stock: a Backorder decision needs
            // to know what is actually out of stock (spec E.2 note 2).
            'reports.view', 'reports.orders.view', 'reports.inventory.view',
        ],
        'Delivery Manager' => [
            'orders.view', 'orders.assign',
            'delivery.representatives.view', 'delivery.representatives.create',
            'delivery.representatives.update', 'delivery.representatives.delete',
            'delivery.companies.view', 'delivery.companies.create',
            'delivery.companies.update', 'delivery.companies.delete',
            'delivery.rates.view', 'delivery.rates.create',
            'delivery.rates.update', 'delivery.rates.delete',
            'geo.view', 'geo.manage',
            // Carrier performance and geographic demand, plus its own
            // assignment activity — no sales value, no cost.
            'reports.view', 'reports.orders.view', 'reports.sales.view',
            'reports.employees.view', 'reports.export',
        ],
        'Accounting' => [
            'orders.view', 'orders.confirm_delivery',
            'accounting.reconciliation.view', 'accounting.reconciliation.create', 'accounting.reconciliation.transfer',
            'treasury.view', 'treasury.create', 'treasury.transactions.create', 'treasury.transfer',
            'returns.create', 'returns.approve', 'returns.receive', 'returns.refund', 'expenses.manage',
            'returns.export',
            // The finance reader: everything with money in it, including
            // cost and margin. Inventory is granted for valuation and
            // shrinkage, not the operational stock screens.
            'reports.view', 'reports.orders.view', 'reports.sales.view',
            'reports.inventory.view', 'reports.returns.view', 'reports.finance.view',
            'reports.cost.view', 'reports.export',
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

        // Super Admin holds every permission that exists on the system —
        // sourced from the same PERMISSIONS list just created above, so a
        // newly added permission is picked up on the next seed.
        $superAdmin = Role::findOrCreate('Super Admin', 'employee');
        $superAdmin->syncPermissions(self::PERMISSIONS);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
