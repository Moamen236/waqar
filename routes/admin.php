<?php

use App\Http\Controllers\Admin\Accounting\AccountingController;
use App\Http\Controllers\Admin\Accounting\ReconciliationController;
use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\AttributeController;
use App\Http\Controllers\Admin\Auth\LoginController;
use App\Http\Controllers\Admin\Catalog\CategoryController;
use App\Http\Controllers\Admin\Catalog\ProductController;
use App\Http\Controllers\Admin\Checking\CheckingController;
use App\Http\Controllers\Admin\CollectionController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\Delivery\DeliveryController;
use App\Http\Controllers\Admin\Delivery\RepresentativeController;
use App\Http\Controllers\Admin\Delivery\ShippingCompanyController;
use App\Http\Controllers\Admin\Delivery\ShippingRateController;
use App\Http\Controllers\Admin\EmployeeController;
use App\Http\Controllers\Admin\InventoryController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\PromotionController;
use App\Http\Controllers\Admin\Returns\ReturnController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\TreasuryController;
use Illuminate\Support\Facades\Route;

/*
 * Internal Operations surface (spec Section 14 / WAQAR-DELIVERY-ROADMAP.html
 * Phase 4). Loaded with an "admin" prefix + name group from routes/web.php.
 * Every route below (except the login pair) sits behind auth:employee.
 *
 * Authorization itself lives on each controller, not here: every admin
 * controller implements Illuminate\Routing\Controllers\HasMiddleware and
 * declares a static middleware() gating its own actions with the specific
 * permission Section 15 assigns them — never a role-name check directly,
 * per that section's own rule. This file only wires up paths, route names,
 * and (where the comments below explain why) registration order — see each
 * controller for what permission a given action actually requires.
 *
 * Every resource is gated per-action (.view/.create/.update/.delete, or a
 * named action where the screen isn't CRUD-shaped) rather than one bundled
 * .manage permission — seeing a list and being able to change or remove
 * what's on it are different grants. See PermissionSeeder for the default
 * matrix.
 */

Route::middleware('guest:employee')->group(function () {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->name('login.store');
});

Route::middleware('auth:employee')->group(function () {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // Customer Service — /admin/orders/create (Section 08), reuses
    // CreateOrderAction directly rather than duplicating checkout logic.
    Route::get('orders/create', [OrderController::class, 'create'])->name('orders.create');
    Route::post('orders', [OrderController::class, 'store'])->name('orders.store');

    // The whole order book. Every other order screen below is a role's work
    // queue filtered to the statuses that role acts on — Checking sees New
    // through Backorder, Delivery sees Confirmed, Accounting sees what is
    // out for delivery — so until now no screen could answer "where is
    // order #2003" without knowing which department held it. These two are
    // read-only: the status-changing actions stay on the department screens
    // that own them.
    //
    // orders/{order} is registered after orders/create for the usual
    // reason: both are two-segment GETs and Laravel matches in
    // registration order, so the wildcard first would swallow
    // /orders/create as order="create".
    Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
    // Registered ahead of orders/{order} for the same wildcard-collision
    // reason as orders/create above.
    Route::get('orders/export', [OrderController::class, 'export'])->name('orders.export');
    Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');

    // Removing an order from the book. Soft delete, and only reachable once
    // the order is already Cancelled — see OrderController::destroy().
    Route::delete('orders/{order}', [OrderController::class, 'destroy'])->name('orders.destroy');

    // Checking — work queue + single-order confirm/postpone/cancel/backorder.
    Route::get('checking', [CheckingController::class, 'index'])->name('checking.index');
    Route::get('checking/export', [CheckingController::class, 'export'])->name('checking.export');
    Route::get('checking/{order}', [CheckingController::class, 'show'])->name('checking.show');
    Route::post('checking/{order}/confirm', [CheckingController::class, 'confirm'])->name('checking.confirm');
    Route::post('checking/{order}/postpone', [CheckingController::class, 'postpone'])->name('checking.postpone');
    Route::post('checking/{order}/cancel', [CheckingController::class, 'cancel'])->name('checking.cancel');
    Route::post('checking/{order}/backorder', [CheckingController::class, 'backorder'])->name('checking.backorder');
    Route::post('checking/{order}/resume', [CheckingController::class, 'resume'])->name('checking.resume');

    // Delivery Manager — assignment board + representatives/companies.
    Route::get('delivery', [DeliveryController::class, 'index'])->name('delivery.index');
    Route::post('delivery/{order}/assign', [DeliveryController::class, 'assign'])->name('delivery.assign');

    Route::get('delivery/representatives', [RepresentativeController::class, 'index'])->name('delivery.representatives.index');
    Route::get('delivery/representatives/{representative}/areas', [RepresentativeController::class, 'areas'])->name('delivery.representatives.areas');
    Route::get('delivery/representatives/create', [RepresentativeController::class, 'create'])->name('delivery.representatives.create');
    Route::post('delivery/representatives', [RepresentativeController::class, 'store'])->name('delivery.representatives.store');
    // Coverage-area add/remove folds into .update rather than getting its
    // own permission — it isn't a separate screen, just part of editing
    // what a representative covers.
    Route::get('delivery/representatives/{representative}/edit', [RepresentativeController::class, 'edit'])->name('delivery.representatives.edit');
    Route::put('delivery/representatives/{representative}', [RepresentativeController::class, 'update'])->name('delivery.representatives.update');
    Route::post('delivery/representatives/{representative}/areas', [RepresentativeController::class, 'storeArea'])->name('delivery.representatives.areas.store');
    Route::delete('delivery/representatives/{representative}/areas/{area}', [RepresentativeController::class, 'destroyArea'])->name('delivery.representatives.areas.destroy');
    Route::delete('delivery/representatives/{representative}', [RepresentativeController::class, 'destroy'])->name('delivery.representatives.destroy');

    Route::get('delivery/shipping-rates', [ShippingRateController::class, 'index'])->name('delivery.shipping-rates.index');
    Route::get('delivery/shipping-rates/create', [ShippingRateController::class, 'create'])->name('delivery.shipping-rates.create');
    Route::post('delivery/shipping-rates', [ShippingRateController::class, 'store'])->name('delivery.shipping-rates.store');
    Route::get('delivery/shipping-rates/{shippingRate}/edit', [ShippingRateController::class, 'edit'])->name('delivery.shipping-rates.edit');
    Route::put('delivery/shipping-rates/{shippingRate}', [ShippingRateController::class, 'update'])->name('delivery.shipping-rates.update');
    Route::delete('delivery/shipping-rates/{shippingRate}', [ShippingRateController::class, 'destroy'])->name('delivery.shipping-rates.destroy');

    Route::get('delivery/shipping-companies', [ShippingCompanyController::class, 'index'])->name('delivery.shipping-companies.index');
    Route::get('delivery/shipping-companies/create', [ShippingCompanyController::class, 'create'])->name('delivery.shipping-companies.create');
    Route::post('delivery/shipping-companies', [ShippingCompanyController::class, 'store'])->name('delivery.shipping-companies.store');
    Route::get('delivery/shipping-companies/{shippingCompany}/edit', [ShippingCompanyController::class, 'edit'])->name('delivery.shipping-companies.edit');
    Route::put('delivery/shipping-companies/{shippingCompany}', [ShippingCompanyController::class, 'update'])->name('delivery.shipping-companies.update');
    Route::delete('delivery/shipping-companies/{shippingCompany}', [ShippingCompanyController::class, 'destroy'])->name('delivery.shipping-companies.destroy');

    // Accounting — delivery confirmation queue + shipping-company
    // reconciliation. The reconciliation routes must be registered
    // before accounting/{order} below — Laravel matches routes in
    // registration order, so a wildcard {order} segment registered
    // first would swallow GET /admin/accounting/reconciliation as
    // order="reconciliation" and 404 on the (non-existent) model lookup.
    Route::get('accounting/reconciliation', [ReconciliationController::class, 'index'])->name('accounting.reconciliation.index');
    Route::get('accounting/reconciliation/{shippingCompany}', [ReconciliationController::class, 'show'])->name('accounting.reconciliation.show');
    Route::post('accounting/reconciliation/{shippingCompany}', [ReconciliationController::class, 'store'])->name('accounting.reconciliation.store');
    Route::post('accounting/reconciliation/statements/{statement}/transfer', [ReconciliationController::class, 'recordTransfer'])->name('accounting.reconciliation.transfer');
    Route::get('accounting', [AccountingController::class, 'index'])->name('accounting.index');
    Route::get('accounting/{order}', [AccountingController::class, 'show'])->name('accounting.show');
    Route::post('accounting/{order}/delivered', [AccountingController::class, 'delivered'])->name('accounting.delivered');
    Route::post('accounting/{order}/returned', [AccountingController::class, 'returned'])->name('accounting.returned');
    Route::post('accounting/{order}/partially-returned', [AccountingController::class, 'partiallyReturned'])->name('accounting.partially-returned');

    // Customers — real create/edit form (Section 17: the template's
    // customer-add/edit.html is a mislabeled Seller-list copy, not usable).
    Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
    Route::get('customers/create', [CustomerController::class, 'create'])->name('customers.create');
    Route::post('customers', [CustomerController::class, 'store'])->name('customers.store');
    Route::get('customers/{customer}/edit', [CustomerController::class, 'edit'])->name('customers.edit');
    Route::put('customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');
    Route::delete('customers/{customer}', [CustomerController::class, 'destroy'])->name('customers.destroy');

    // Products & Categories (Vice Chairman + Chairman) — not in the
    // roadmap's original Phase 4 task table, added after the fact since
    // no phase otherwise covers it and the storefront (Phase 5) will
    // need real catalog data to browse.
    Route::get('products', [ProductController::class, 'index'])->name('products.index');
    // Registered ahead of products/{product} below for the same wildcard
    // reason as orders/export above.
    Route::get('products/export', [ProductController::class, 'export'])->name('products.export');
    Route::get('products/create', [ProductController::class, 'create'])->name('products.create');
    Route::post('products', [ProductController::class, 'store'])->name('products.store');
    // Registered *after* products/create, not with products.index above it:
    // both are two-segment GET routes, and Laravel matches in registration
    // order, so a wildcard placed first swallows /products/create as
    // product="create" and 404s on the model lookup. Same shape as the
    // accounting/{order} vs accounting/reconciliation collision fixed in
    // Phase 4 — see PHASE-4-HANDOVER.md.
    Route::get('products/{product}', [ProductController::class, 'show'])->name('products.show');
    Route::get('products/{product}/edit', [ProductController::class, 'edit'])->name('products.edit');
    Route::put('products/{product}', [ProductController::class, 'update'])->name('products.update');
    Route::delete('products/{product}/images/{media}', [ProductController::class, 'destroyImage'])->name('products.images.destroy');
    Route::delete('products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');

    Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
    Route::get('categories/create', [CategoryController::class, 'create'])->name('categories.create');
    Route::post('categories', [CategoryController::class, 'store'])->name('categories.store');
    Route::get('categories/{category}/edit', [CategoryController::class, 'edit'])->name('categories.edit');
    Route::put('categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
    Route::delete('categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');

    // Attributes/values: value-level create/update/delete folds into the
    // matching attribute-level permission — there is one screen, not two,
    // and a separate permission per sub-action here would just be a way to
    // grant "can add a Color value but not add a Color attribute", which
    // nothing asks for.
    Route::get('attributes', [AttributeController::class, 'index'])->name('attributes.index');
    Route::post('attributes', [AttributeController::class, 'store'])->name('attributes.store');
    Route::post('attributes/{attribute}/values', [AttributeController::class, 'storeValue'])->name('attributes.values.store');
    Route::put('attributes/{attribute}', [AttributeController::class, 'update'])->name('attributes.update');
    Route::put('attributes/{attribute}/values/{value}', [AttributeController::class, 'updateValue'])->name('attributes.values.update');
    Route::delete('attributes/{attribute}', [AttributeController::class, 'destroy'])->name('attributes.destroy');
    Route::delete('attributes/{attribute}/values/{value}', [AttributeController::class, 'destroyValue'])->name('attributes.values.destroy');

    // Returns/Refunds (Warehouse Manager approves/receives-to-stock,
    // Accounting refunds). Not in the roadmap's route list or task table
    // even though Phase 3 built the full Actions chain; added after the
    // fact for the same reason as Products/Categories above. Covers only
    // the post-delivery customer-initiated flow (Section 12) — an
    // at-delivery refusal is Accounting's confirmReturnedAtDelivery(),
    // already on the Accounting screen. No storefront exists yet for a
    // customer to request one themselves, so "create" here is staff
    // filing a return on a customer's behalf (e.g. a phone call), the
    // same convention /admin/orders/create already established for order
    // placement.
    Route::get('returns', [ReturnController::class, 'index'])->name('returns.index');
    Route::get('returns/create', [ReturnController::class, 'create'])->name('returns.create');
    Route::post('returns', [ReturnController::class, 'store'])->name('returns.store');
    // Registered ahead of returns/{return} for the usual wildcard reason.
    Route::get('returns/export', [ReturnController::class, 'export'])->name('returns.export');
    Route::get('returns/{return}', [ReturnController::class, 'show'])->name('returns.show');
    Route::post('returns/{return}/accept-shipping-fee', [ReturnController::class, 'acceptShippingFee'])->name('returns.accept-shipping-fee');
    Route::post('returns/{return}/approve', [ReturnController::class, 'approve'])->name('returns.approve');
    Route::post('returns/{return}/receive', [ReturnController::class, 'receive'])->name('returns.receive');
    Route::post('returns/{return}/refund', [ReturnController::class, 'refund'])->name('returns.refund');

    // Collections (Vice Chairman) — no template counterpart, built from scratch.
    Route::get('collections', [CollectionController::class, 'index'])->name('collections.index');
    Route::get('collections/create', [CollectionController::class, 'create'])->name('collections.create');
    Route::post('collections', [CollectionController::class, 'store'])->name('collections.store');
    Route::get('collections/{collection}/edit', [CollectionController::class, 'edit'])->name('collections.edit');
    Route::put('collections/{collection}', [CollectionController::class, 'update'])->name('collections.update');
    Route::delete('collections/{collection}', [CollectionController::class, 'destroy'])->name('collections.destroy');

    // Promotions (Vice Chairman) — bundle / buy-X-get-Y (Question 17).
    Route::get('promotions', [PromotionController::class, 'index'])->name('promotions.index');
    Route::get('promotions/create', [PromotionController::class, 'create'])->name('promotions.create');
    Route::post('promotions', [PromotionController::class, 'store'])->name('promotions.store');
    Route::get('promotions/{promotion}/edit', [PromotionController::class, 'edit'])->name('promotions.edit');
    Route::put('promotions/{promotion}', [PromotionController::class, 'update'])->name('promotions.update');
    Route::delete('promotions/{promotion}', [PromotionController::class, 'destroy'])->name('promotions.destroy');

    // Employees (Super Admin + department leads).
    Route::get('employees', [EmployeeController::class, 'index'])->name('employees.index');
    Route::get('employees/create', [EmployeeController::class, 'create'])->name('employees.create');
    Route::post('employees', [EmployeeController::class, 'store'])->name('employees.store');
    Route::get('employees/{employee}/edit', [EmployeeController::class, 'edit'])->name('employees.edit');
    Route::put('employees/{employee}', [EmployeeController::class, 'update'])->name('employees.update');
    Route::delete('employees/{employee}', [EmployeeController::class, 'destroy'])->name('employees.destroy');

    // Treasury ledger (Chairman + Accounting). Not CRUD-shaped — the three
    // writes are opening a new account, posting a manual transaction, and
    // transferring between two existing accounts, none of which is really
    // an "update" of an existing row — so split by what each one does.
    Route::get('treasury', [TreasuryController::class, 'index'])->name('treasury.index');
    Route::post('treasury', [TreasuryController::class, 'store'])->name('treasury.store');
    Route::post('treasury/transactions', [TreasuryController::class, 'storeTransaction'])->name('treasury.transactions.store');
    Route::post('treasury/transfer', [TreasuryController::class, 'transfer'])->name('treasury.transfer');

    // Stock on hand, and manual corrections to it (Warehouse Manager).
    // Viewing and adjusting are split: plenty of roles have reason to see
    // stock levels, far fewer to change them without an order behind it.
    Route::get('inventory', [InventoryController::class, 'index'])->name('inventory.index');
    Route::get('inventory/export', [InventoryController::class, 'export'])->name('inventory.export');
    Route::post('inventory/adjust', [InventoryController::class, 'adjust'])->name('inventory.adjust');

    // The audit trail (Section 23). Read-only by construction — there is
    // no write/delete route here at all, not merely no permission for one.
    Route::get('activity-log', [ActivityLogController::class, 'index'])->name('activity-log.index');

    // RBAC — permission-matrix editor. No create/delete: roles are fixed
    // by RoleSeeder, not authored here — only which permissions each one
    // carries changes on this screen.
    Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
    Route::get('roles/{role}/edit', [RoleController::class, 'edit'])->name('roles.edit');
    Route::put('roles/{role}', [RoleController::class, 'update'])->name('roles.update');
});
