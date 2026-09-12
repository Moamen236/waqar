<?php

use App\Models\Area;
use App\Models\City;
use App\Models\Country;
use App\Models\Customer;
use App\Models\DeliveryRepresentative;
use App\Models\Employee;
use App\Models\Governorate;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShippingRate;
use App\Models\Treasury;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Spatie\Permission\Models\Role;

// Phase 4 — Admin / Internal Operations Surface (WAQAR-DELIVERY-ROADMAP.
// html). The roadmap's own "Done when" bar: Checking, Delivery Manager,
// Accounting and Customer Service can each complete their slice of one
// order's lifecycle from the admin UI alone — the full-lifecycle test
// below walks exactly that path. Helper names are deliberately distinct
// from Phase3CriticalBusinessRulesTest.php's (p4* prefix) since Pest
// loads every Feature test file into the same global function
// namespace — redeclaring a same-named function would fatal-error.

function p4Geo(): array
{
    $country = Country::create(['name' => ['ar' => 'مصر', 'en' => 'Egypt'], 'code' => 'EG']);
    $governorate = Governorate::create(['country_id' => $country->id, 'name' => ['ar' => 'القاهرة', 'en' => 'Cairo']]);
    $city = City::create(['governorate_id' => $governorate->id, 'name' => ['ar' => 'مدينة نصر', 'en' => 'Nasr City']]);
    $area = Area::create(['city_id' => $city->id, 'name' => ['ar' => 'منطقة أ', 'en' => 'Zone A']]);

    return compact('country', 'governorate', 'city', 'area');
}

function p4Variant(int $warehouseId, int $stock = 10): ProductVariant
{
    $product = Product::create(['name' => ['ar' => 'منتج', 'en' => 'Widget'], 'slug' => 'widget-'.uniqid(), 'sku' => 'W-'.uniqid(), 'price' => 100]);
    $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'W-'.uniqid().'-V']);
    WarehouseInventory::create(['warehouse_id' => $warehouseId, 'product_variant_id' => $variant->id, 'quantity' => $stock, 'reserved_quantity' => 0]);

    return $variant;
}

function p4Customer(): Customer
{
    return Customer::create(['name' => 'Test Customer', 'email' => 'c-'.uniqid().'@waqar.test', 'phone' => '1', 'password' => 'password']);
}

/**
 * @return array{0: Employee, 1: Role}
 */
function p4Employee(string $role, ?string $teamLeaderId = null): array
{
    $employee = Employee::create([
        'full_name' => $role.' User', 'email' => 'e-'.uniqid().'@waqar.test', 'phone' => '1',
        'password' => 'password', 'residence_address' => 'N/A', 'national_id_number' => 'N/A',
        'team_leader_id' => $teamLeaderId,
    ]);
    $roleModel = Role::findOrCreate($role, 'employee');
    $employee->assignRole($roleModel);

    return [$employee, $roleModel];
}

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
});

it('redirects an unauthenticated request to the admin routes to the employee login page', function () {
    $this->get(route('admin.dashboard'))->assertRedirect(route('admin.login'));
});

it('logs an employee in and out on the employee guard', function () {
    [$employee] = p4Employee('Checking');

    $this->post(route('admin.login.store'), ['email' => $employee->email, 'password' => 'password'])
        ->assertRedirect(route('admin.dashboard'));
    $this->assertAuthenticatedAs($employee, 'employee');

    $this->post(route('admin.logout'))->assertRedirect(route('admin.login'));
    $this->assertGuest('employee');
});

it('blocks a route behind a permission the employee does not hold', function () {
    [$employee] = p4Employee('Checking'); // has orders.view, not treasury.view

    $this->actingAs($employee, 'employee')
        ->get(route('admin.treasury.index'))
        ->assertForbidden();
});

it('lets a Super Admin reach every permission-gated route without explicit grants', function () {
    [$superAdmin] = p4Employee('Super Admin');

    $this->actingAs($superAdmin, 'employee')
        ->get(route('admin.treasury.index'))
        ->assertOk();
});

it('scopes a Customer Service Team Leader to only their own team members', function () {
    [$leader] = p4Employee('Customer Service Team Leader');
    [$ownAgent] = p4Employee('Customer Service', (string) $leader->id);
    [$otherAgent] = p4Employee('Customer Service'); // no team_leader_id — not this leader's team

    $visible = Employee::query()->visibleTo($leader)->pluck('id');

    expect($visible)->toContain($leader->id)
        ->toContain($ownAgent->id)
        ->not->toContain($otherAgent->id);
});

it('lets Customer Service create an order through /admin/orders/create using CreateOrderAction', function () {
    $geo = p4Geo();
    $warehouse = Warehouse::create(['name' => 'Main', 'address' => 'Cairo', 'phone' => '1']);
    ShippingRate::create(['geo_type' => 'governorate', 'geo_id' => $geo['governorate']->id, 'price' => 30]);
    $variant = p4Variant($warehouse->id);
    $customer = p4Customer();
    [$agent] = p4Employee('Customer Service');

    $response = $this->actingAs($agent, 'employee')->post(route('admin.orders.store'), [
        'customer_id' => $customer->id,
        'warehouse_id' => $warehouse->id,
        'items' => [['product_variant_id' => $variant->id, 'quantity' => 2]],
        'governorate_id' => $geo['governorate']->id,
        'city_id' => $geo['city']->id,
        'area_id' => $geo['area']->id,
        'address_line' => '1 Test St',
        'recipient_name' => $customer->name,
        'phone' => $customer->phone,
    ]);

    $response->assertRedirect(route('admin.checking.index'));
    $order = Order::first();
    expect($order)->not->toBeNull()
        ->and($order->order_source->value)->toBe('customer_service')
        ->and($order->created_by_employee_id)->toBe($agent->id)
        ->and((int) $order->items()->sum('quantity'))->toBe(2);
});

it('walks one order through its full lifecycle from the admin UI alone — Checking, Delivery, Accounting', function () {
    $geo = p4Geo();
    $warehouse = Warehouse::create(['name' => 'Main', 'address' => 'Cairo', 'phone' => '1']);
    ShippingRate::create(['geo_type' => 'governorate', 'geo_id' => $geo['governorate']->id, 'price' => 30]);
    $variant = p4Variant($warehouse->id);
    $customer = p4Customer();

    [$csAgent] = p4Employee('Customer Service');
    [$checker] = p4Employee('Checking');
    [$deliveryManager] = p4Employee('Delivery Manager');
    [$accountant] = p4Employee('Accounting');

    // 1. Customer Service places the order.
    $this->actingAs($csAgent, 'employee')->post(route('admin.orders.store'), [
        'customer_id' => $customer->id,
        'warehouse_id' => $warehouse->id,
        'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
        'governorate_id' => $geo['governorate']->id,
        'city_id' => $geo['city']->id,
        'area_id' => $geo['area']->id,
        'address_line' => '1 Test St',
        'recipient_name' => $customer->name,
        'phone' => $customer->phone,
    ])->assertRedirect();
    $order = Order::firstOrFail();

    // 2. Checking confirms it.
    $this->actingAs($checker, 'employee')
        ->post(route('admin.checking.confirm', $order))
        ->assertRedirect();
    expect($order->fresh()->status->value)->toBe('Confirmed');

    // 3. Delivery Manager assigns it to a representative.
    $rep = DeliveryRepresentative::create(['name' => 'Ahmed', 'phone' => '1']);
    $this->actingAs($deliveryManager, 'employee')
        ->post(route('admin.delivery.assign', $order), ['assignment_type' => 'representative', 'assignee_id' => $rep->id])
        ->assertRedirect();
    expect($order->fresh()->status->value)->toBe('Assigned');

    // 4. Accounting confirms Delivered — deducts stock, records the treasury transaction.
    $treasury = Treasury::create(['name' => 'Main Cash', 'type' => 'cash', 'current_balance' => 0]);
    $this->actingAs($accountant, 'employee')
        ->post(route('admin.accounting.delivered', $order), [
            'treasury_id' => $treasury->id,
            'collected_method' => 'cash',
        ])
        ->assertRedirect();

    $order->refresh();
    $inventory = WarehouseInventory::where('product_variant_id', $variant->id)->first();
    expect($order->status->value)->toBe('Delivered')
        ->and($order->payment_status->value)->toBe('collected')
        ->and($inventory->quantity)->toBe(9) // 10 seeded - 1 delivered
        ->and($treasury->fresh()->current_balance)->toEqual('130.00'); // 100 unit price + 30 shipping
});

it('lets a Super Admin edit a role permission matrix via /admin/roles', function () {
    [$superAdmin] = p4Employee('Super Admin');
    $checkingRole = Role::where('name', 'Checking')->where('guard_name', 'employee')->firstOrFail();

    $this->actingAs($superAdmin, 'employee')
        ->put(route('admin.roles.update', $checkingRole), ['permissions' => ['orders.view', 'orders.status.update', 'orders.assign']])
        ->assertRedirect(route('admin.roles.index'));

    expect($checkingRole->fresh()->permissions->pluck('name')->all())
        ->toEqualCanonicalizing(['orders.view', 'orders.status.update', 'orders.assign']);
});

it('never lets a non-Super-Admin employee assign the Super Admin role, even with employees.manage granted', function () {
    // Grant employees.manage to Checking specifically to test the worst
    // case: a role that was never meant to touch this at all.
    Role::where('name', 'Checking')->where('guard_name', 'employee')->first()->givePermissionTo('employees.manage');
    [$checker] = p4Employee('Checking');

    $this->actingAs($checker, 'employee')
        ->post(route('admin.employees.store'), [
            'full_name' => 'Attempted Escalation', 'email' => 'escalate@waqar.test', 'phone' => '1',
            'password' => 'password12345', 'residence_address' => 'N/A', 'national_id_number' => 'N/A',
            'is_active' => true, 'role' => 'Super Admin',
        ])
        ->assertForbidden();

    expect(Employee::where('email', 'escalate@waqar.test')->exists())->toBeFalse();
});
