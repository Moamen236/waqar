<?php

use App\Models\DeliveryRepresentative;
use App\Models\Employee;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * Every dashboard page, tab and action has its own grant, and holding one
 * never opens another just because both are about orders.
 */

function pstEmployee(array $permissions = [], ?string $role = null): Employee
{
    $employee = Employee::create([
        'full_name' => 'Separation User',
        'email' => 'pst-'.uniqid().'@waqar.test',
        'phone' => '01012345678',
        'password' => 'password',
        'residence_address' => 'N/A',
        'national_id_number' => '29001010100000',
    ]);

    if ($role !== null) {
        $employee->assignRole(Role::findByName($role, 'employee'));
    }

    $employee->givePermissionTo($permissions);

    return $employee;
}

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
});

it('opens only the page whose own view permission is held', function (string $permission) {
    $pages = [
        'orders.view' => 'admin.orders.index',
        'checking.view' => 'admin.checking.index',
        'delivery.view' => 'admin.delivery.index',
        'accounting.view' => 'admin.accounting.index',
        'returns.view' => 'admin.returns.index',
    ];

    $employee = pstEmployee([$permission]);

    foreach ($pages as $held => $routeName) {
        $response = $this->actingAs($employee, 'employee')->withLocale('en')->get(route($routeName));

        $held === $permission ? $response->assertOk() : $response->assertForbidden();
    }
})->with(['orders.view', 'checking.view', 'delivery.view', 'accounting.view', 'returns.view']);

it('gates every workflow action behind its own permission', function () {
    // Declarative on purpose: one row per route, so a route that drifts back
    // onto a shared grant fails here by name.
    $expected = [
        'admin.orders.invoice' => 'orders.print_invoice',
        'admin.orders.invoices' => 'orders.print_invoice',
        'admin.orders.label' => 'orders.print_label',
        'admin.orders.labels' => 'orders.print_label',
        'admin.checking.show' => 'checking.view',
        'admin.checking.confirm' => 'checking.confirm',
        'admin.checking.postpone' => 'checking.postpone',
        'admin.checking.cancel' => 'checking.cancel',
        'admin.checking.backorder' => 'checking.backorder',
        'admin.checking.resume' => 'checking.backorder',
        'admin.delivery.assign.form' => 'delivery.assign',
        'admin.delivery.assign' => 'delivery.assign',
        'admin.delivery.assign.bulk' => 'delivery.assign',
        'admin.delivery.reassign' => 'delivery.move',
        'admin.accounting.show' => 'accounting.view',
        'admin.accounting.handover' => 'accounting.confirm_handover',
        'admin.accounting.delivered' => 'accounting.confirm_delivered',
        'admin.accounting.returned' => 'accounting.confirm_returned',
        'admin.accounting.partially-returned' => 'accounting.confirm_returned',
        'admin.accounting.collect' => 'accounting.collect',
        'admin.accounting.settle.bulk' => 'accounting.collect',
        'admin.accounting.collect-from-courier' => 'accounting.collect',
        'admin.returns.show' => 'returns.view',
        // The fee is agreed on the call, so the caller may record it too.
        'admin.returns.accept-shipping-fee' => 'returns.create|returns.check',
        'admin.returns.assign-pickup' => 'returns.assign_pickup',
        'admin.returns.replace' => 'returns.replace',
        'admin.returns.product-search' => 'returns.replace',
        'admin.geo.store' => 'geo.create',
        'admin.geo.update' => 'geo.update',
        'admin.geo.destroy' => 'geo.delete',
    ];

    foreach ($expected as $routeName => $permission) {
        $gates = collect(Route::getRoutes()->getByName($routeName)?->gatherMiddleware() ?? [])
            ->filter(fn ($middleware) => is_string($middleware) && str_starts_with($middleware, 'permission:'))
            ->values()
            ->all();

        expect($gates)->toBe(["permission:{$permission}"], $routeName);
    }
});

it('refuses a sibling action to an employee holding only the other one', function () {
    $mover = pstEmployee(['delivery.view', 'delivery.move']);
    $this->actingAs($mover, 'employee')->withLocale('en')
        ->post(route('admin.delivery.assign.bulk'), [])
        ->assertForbidden();

    $deliverer = pstEmployee(['accounting.view', 'accounting.confirm_delivered']);
    $this->actingAs($deliverer, 'employee')->withLocale('en')
        ->post(route('admin.accounting.settle.bulk'), [])
        ->assertForbidden();

    $editor = pstEmployee(['geo.view', 'geo.update']);
    $this->actingAs($editor, 'employee')->withLocale('en')
        ->post(route('admin.geo.store', 'governorates'), [])
        ->assertForbidden();
});

it('keeps the Order Book roles out of the department screens', function (string $role) {
    $employee = pstEmployee(role: $role);

    $this->actingAs($employee, 'employee')->withLocale('en')->get(route('admin.orders.index'))->assertOk();

    foreach (['admin.checking.index', 'admin.delivery.index', 'admin.accounting.index'] as $routeName) {
        $this->actingAs($employee, 'employee')->withLocale('en')->get(route($routeName))->assertForbidden();
    }
})->with(['Chairman', 'Customer Service', 'Store Orders']);

it('shows a courier profile without its order history to someone who cannot open the board', function () {
    $representative = DeliveryRepresentative::create(['name' => 'Ahmed', 'phone' => '01012345678']);

    $this->actingAs(pstEmployee(['delivery.representatives.view']), 'employee')->withLocale('en')
        ->get(route('admin.delivery.representatives.show', $representative))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('orders', null)->where('stats', null)->etc());

    $this->actingAs(pstEmployee(['delivery.representatives.view', 'delivery.view']), 'employee')->withLocale('en')
        ->get(route('admin.delivery.representatives.show', $representative))
        ->assertInertia(fn ($page) => $page->where('stats.total_orders', 0)->etc());
});

it('carries existing grants onto the split permissions when migrating', function () {
    $migration = require database_path('migrations/2026_09_25_010000_split_dashboard_permissions.php');

    // Put a role back on the pre-split names, as a live system would be.
    $migration->down();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $role = Role::create(['name' => 'Legacy Desk', 'guard_name' => 'employee']);
    foreach (['orders.view', 'orders.status.update', 'orders.assign', 'orders.confirm_delivery', 'geo.manage', 'returns.receive', 'products.update'] as $name) {
        Permission::findOrCreate($name, 'employee');
    }
    $role->givePermissionTo(['orders.view', 'orders.status.update', 'orders.assign', 'orders.confirm_delivery', 'geo.manage', 'returns.receive', 'products.update']);

    $migration->up();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $granted = $role->fresh()->permissions->pluck('name')->all();

    expect($granted)->toContain(
        'checking.view', 'checking.confirm', 'checking.postpone', 'checking.cancel', 'checking.backorder',
        'delivery.view', 'delivery.assign', 'delivery.move',
        'accounting.view', 'accounting.confirm_handover', 'accounting.confirm_delivered', 'accounting.confirm_returned', 'accounting.collect',
        'orders.print_invoice', 'orders.print_label',
        'geo.create', 'geo.update', 'geo.delete',
        'returns.assign_pickup', 'products.cost_price.view',
    )->and(DB::table('permissions')->whereIn('name', ['orders.status.update', 'orders.assign', 'orders.confirm_delivery', 'geo.manage'])->count())
        ->toBe(0);
});
