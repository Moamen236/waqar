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
 * CRUD-shaped at all (returns check/receive/refund, treasury's three
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
        // What the company pays for goods — visible only to those granted
        // it, not to everyone who can edit a product.
        'products.cost_price.view',
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
        'warehouses.view', 'warehouses.create', 'warehouses.update', 'warehouses.delete',

        // Orders & fulfillment. Each department screen has its own .view —
        // orders.view opens the Order Book and nothing else, so seeing the
        // book never implies Checking, Delivery or Accounting (or back).
        'orders.view', 'orders.create',
        // Deliberately *not* granted alongside checking.cancel: cancelling
        // an order is Checking's daily work, removing one from the book is
        // not. See OrderController::destroy() for why the two are
        // sequenced rather than alternatives.
        'orders.delete',
        // Printing is its own grant, not part of orders.view, so Delivery
        // and Accounting can print without opening the Order Book.
        'orders.print_invoice', 'orders.print_label',

        // Checking — the queue, and one grant per transition so "may
        // confirm" never implies "may cancel". backorder covers resume:
        // resuming is only ever undoing a backorder.
        'checking.view',
        'checking.confirm', 'checking.postpone', 'checking.cancel', 'checking.backorder',

        // Delivery board. Assigning a Confirmed order and moving one that
        // is already on the road are separate decisions.
        'delivery.view', 'delivery.assign', 'delivery.move',

        // Accounting — the delivery-outcome queue. Split by consequence:
        // Delivered deducts stock, Returned releases it, collect moves cash.
        'accounting.view',
        'accounting.confirm_handover', 'accounting.confirm_delivered',
        'accounting.confirm_returned', 'accounting.collect',
        // .create is filing a return on a customer's behalf and recording
        // their shipping-fee consent (Customer Service's job, same
        // convention as orders.create). The rest are the return's steps in
        // order, named for what they do rather than forced into CRUD:
        // check (the call — its Confirm is the only way a return is
        // approved), assign_pickup, receive, then refund OR replace.
        // .view opens the returns screens; filing one is a separate grant.
        'returns.view',
        'returns.create',
        'returns.check',
        'returns.assign_pickup', 'returns.receive',
        'returns.refund', 'returns.replace',
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
        // The geography every address is built from, CRUD-split like every
        // other resource.
        'geo.view', 'geo.create', 'geo.update', 'geo.delete',
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
            'products.cost_price.view',
            'categories.view', 'categories.create', 'categories.update', 'categories.delete',
            'collections.view', 'collections.create', 'collections.update', 'collections.delete',
            'treasury.view', 'treasury.create', 'treasury.transactions.create', 'treasury.transfer',
            'orders.view', 'orders.delete', 'employees.view', 'customers.view',
            'orders.print_invoice', 'orders.print_label',
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
            'products.cost_price.view',
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
            'warehouses.view', 'warehouses.create', 'warehouses.update', 'warehouses.delete',
            'returns.view', 'returns.create', 'returns.receive', 'returns.refund',
            'returns.assign_pickup', 'returns.replace',
            'inventory.export', 'returns.export',
            // Stock and returns reporting, plus its own team's activity —
            // no cost columns: it manages stock levels, not purchase prices.
            'reports.view', 'reports.inventory.view', 'reports.returns.view',
            'reports.employees.view', 'reports.export',
        ],
        'Customer Service' => [
            'customers.view', 'customers.create', 'customers.update',
            'orders.view', 'orders.create',
            'orders.print_invoice', 'orders.print_label',
            'returns.view', 'returns.create',
        ],
        'Customer Service Team Leader' => [
            // Same permission names as Customer Service — the narrowing to
            // "own team only" is a query-scope concern (Order::scopeVisibleTo,
            // Employee team filtering), not a distinct permission (Q16).
            'customers.view', 'customers.create', 'customers.update',
            'orders.view', 'orders.create',
            'orders.print_invoice', 'orders.print_label',
            'returns.view', 'returns.create',
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
            'orders.print_invoice', 'orders.print_label',
            // Order reporting only, and scopeVisibleTo() narrows every row
            // to website-sourced orders — the same rule as its queue.
            'reports.view', 'reports.orders.view',
        ],
        'Checking' => [
            'orders.view',
            'orders.print_invoice', 'orders.print_label',
            'checking.view',
            'checking.confirm', 'checking.postpone', 'checking.cancel', 'checking.backorder',
            'checking.export',
            'returns.view', 'returns.check',
            // Orders reporting, plus low-stock: a Backorder decision needs
            // to know what is actually out of stock (spec E.2 note 2).
            'reports.view', 'reports.orders.view', 'reports.inventory.view',
        ],
        'Delivery Manager' => [
            'orders.view',
            'orders.print_invoice', 'orders.print_label',
            'delivery.view', 'delivery.assign', 'delivery.move',
            'delivery.representatives.view', 'delivery.representatives.create',
            'delivery.representatives.update', 'delivery.representatives.delete',
            'delivery.companies.view', 'delivery.companies.create',
            'delivery.companies.update', 'delivery.companies.delete',
            'delivery.rates.view', 'delivery.rates.create',
            'delivery.rates.update', 'delivery.rates.delete',
            'geo.view', 'geo.create', 'geo.update', 'geo.delete',
            // Carrier performance and geographic demand, plus its own
            // assignment activity — no sales value, no cost.
            'reports.view', 'reports.orders.view', 'reports.sales.view',
            'reports.employees.view', 'reports.export',
        ],
        // Mirrors the grants the live system settled on through the
        // permission-matrix editor: Accounting also works the Checking
        // queue and the returns check call, and treasury/reports were
        // taken off it.
        'Accounting' => [
            'orders.view',
            'orders.print_invoice', 'orders.print_label',
            'accounting.view',
            'accounting.confirm_handover', 'accounting.confirm_delivered',
            'accounting.confirm_returned', 'accounting.collect',
            'accounting.reconciliation.view', 'accounting.reconciliation.create', 'accounting.reconciliation.transfer',
            'checking.view',
            'checking.confirm', 'checking.postpone', 'checking.cancel', 'checking.backorder',
            'returns.view', 'returns.create', 'returns.check', 'returns.receive', 'returns.refund',
            'returns.assign_pickup', 'returns.replace',
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

        // Super Admin holds every permission that exists on the system —
        // sourced from the same PERMISSIONS list just created above, so a
        // newly added permission is picked up on the next seed.
        $superAdmin = Role::findOrCreate('Super Admin', 'employee');
        $superAdmin->syncPermissions(self::PERMISSIONS);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
