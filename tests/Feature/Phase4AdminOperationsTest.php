<?php

use App\Models\Area;
use App\Models\City;
use App\Models\Country;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\DeliveryRepresentative;
use App\Models\District;
use App\Models\Employee;
use App\Models\Governorate;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShippingCompany;
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

it('never lets a non-Super-Admin employee assign the Super Admin role, even with employees.create granted', function () {
    // Grant employees.create to Checking specifically to test the worst
    // case: a role that was never meant to touch this at all.
    Role::where('name', 'Checking')->where('guard_name', 'employee')->first()->givePermissionTo('employees.create');
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

it('gates Resume on main-warehouse stock and needs no warehouse pick', function () {
    $geo = p4Geo();
    $warehouse = Warehouse::create(['name' => 'Main Warehouse', 'address' => 'Cairo', 'phone' => '1']);
    ShippingRate::create(['geo_type' => 'governorate', 'geo_id' => $geo['governorate']->id, 'price' => 30]);
    $customer = p4Customer();
    [$csAgent] = p4Employee('Customer Service');
    [$checker] = p4Employee('Checking');

    // An Advertisement product — never reserved, so it can reach Backorder.
    $product = Product::create([
        'name' => ['ar' => 'إعلان', 'en' => 'Ad Widget'], 'slug' => 'ad-widget', 'sku' => 'AD-1',
        'price' => 100, 'inventory_tracking_enabled' => false,
    ]);
    $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'AD-1-V']);

    $this->actingAs($csAgent, 'employee')->post(route('admin.orders.store'), [
        'customer_id' => $customer->id,
        'warehouse_id' => $warehouse->id,
        'items' => [['product_variant_id' => $variant->id, 'quantity' => 2]],
        'governorate_id' => $geo['governorate']->id,
        'city_id' => $geo['city']->id,
        'area_id' => $geo['area']->id,
        'address_line' => '1 Test St',
        'recipient_name' => $customer->name,
        'phone' => $customer->phone,
    ])->assertRedirect();
    $order = Order::firstOrFail();

    $this->actingAs($checker, 'employee')->post(route('admin.checking.confirm', $order))->assertRedirect();
    $this->actingAs($checker, 'employee')
        ->post(route('admin.checking.backorder', $order), ['reason' => 'Advertisement item unavailable'])
        ->assertRedirect();
    expect($order->fresh()->status->value)->toBe('Backorder');

    // Converted to Real but with only 1 of the 2 units on hand — Resume
    // must refuse without a 500, and the page must say so up front.
    $product->update(['inventory_tracking_enabled' => true]);
    WarehouseInventory::create([
        'warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id,
        'quantity' => 1, 'reserved_quantity' => 0,
    ]);

    $this->actingAs($checker, 'employee')->get(route('admin.checking.show', $order))
        ->assertInertia(fn ($page) => $page->where('stock.can_resume', false)->where('stock.warehouse', 'Main Warehouse'));

    $this->actingAs($checker, 'employee')->post(route('admin.checking.resume', $order))
        ->assertRedirect()
        ->assertSessionHas('error');
    expect($order->fresh()->status->value)->toBe('Backorder');

    // Stock arrives — no warehouse_id in the request, and it reserves from main.
    WarehouseInventory::where('product_variant_id', $variant->id)->update(['quantity' => 5]);

    $this->actingAs($checker, 'employee')->post(route('admin.checking.resume', $order))
        ->assertRedirect()
        ->assertSessionHas('success');

    $inventory = WarehouseInventory::where('product_variant_id', $variant->id)->firstOrFail();
    expect($order->fresh()->status->value)->toBe('Confirmed')
        ->and($inventory->reserved_quantity)->toBe(2)
        ->and($inventory->warehouse_id)->toBe($warehouse->id);
});

it('creates a customer inline from the order screen and keeps the shipping address as their default', function () {
    $geo = p4Geo();
    $warehouse = Warehouse::create(['name' => 'Main Warehouse', 'address' => 'Cairo', 'phone' => '1']);
    ShippingRate::create(['geo_type' => 'governorate', 'geo_id' => $geo['governorate']->id, 'price' => 30]);
    $variant = p4Variant($warehouse->id);
    [$agent] = p4Employee('Customer Service');

    $this->actingAs($agent, 'employee')->post(route('admin.orders.store'), [
        'new_customer' => ['name' => 'Walk-in Buyer', 'phone' => '01000000000'],
        'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
        'governorate_id' => $geo['governorate']->id,
        'city_id' => $geo['city']->id,
        'area_id' => $geo['area']->id,
        'address_line' => '7 Phone St',
        'recipient_name' => 'Walk-in Buyer',
        'phone' => '01000000000',
    ])->assertRedirect(route('admin.checking.index'));

    $customer = Customer::where('name', 'Walk-in Buyer')->firstOrFail();
    $address = $customer->addresses()->firstOrFail();

    expect($customer->is_guest)->toBeTrue()
        ->and($customer->email)->toBeNull()
        ->and($address->address_line)->toBe('7 Phone St')
        ->and((bool) $address->is_default)->toBeTrue()
        ->and(Order::firstOrFail()->customer_id)->toBe($customer->id);
});

it('rejects an order that names neither an existing customer nor a new one', function () {
    $geo = p4Geo();
    $warehouse = Warehouse::create(['name' => 'Main Warehouse', 'address' => 'Cairo', 'phone' => '1']);
    ShippingRate::create(['geo_type' => 'governorate', 'geo_id' => $geo['governorate']->id, 'price' => 30]);
    $variant = p4Variant($warehouse->id);
    [$agent] = p4Employee('Customer Service');

    $this->actingAs($agent, 'employee')->post(route('admin.orders.store'), [
        'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
        'governorate_id' => $geo['governorate']->id,
        'city_id' => $geo['city']->id,
        'area_id' => $geo['area']->id,
        'address_line' => '7 Phone St',
        'recipient_name' => 'Nobody',
        'phone' => '1',
    ])->assertSessionHasErrors('customer_id');

    expect(Order::count())->toBe(0);
});

it('hands the order screen each customer with their saved addresses attached', function () {
    $geo = p4Geo();
    Warehouse::create(['name' => 'Main Warehouse', 'address' => 'Cairo', 'phone' => '1']);
    $customer = p4Customer();
    $customer->addresses()->create([
        'governorate_id' => $geo['governorate']->id,
        'city_id' => $geo['city']->id,
        'area_id' => $geo['area']->id,
        'address_line' => '3 Saved St',
        'recipient_name' => $customer->name,
        'phone' => $customer->phone,
        'is_default' => true,
    ]);
    [$agent] = p4Employee('Customer Service');

    $this->actingAs($agent, 'employee')->get(route('admin.orders.create'))
        ->assertInertia(fn ($page) => $page
            ->where('customers.0.addresses.0.address_line', '3 Saved St')
            ->where('customers.0.addresses.0.is_default', true));
});

it('quotes the order screen its subtotal, shipping, discount and total from the same services the Action uses', function () {
    $geo = p4Geo();
    $warehouse = Warehouse::create(['name' => 'Main Warehouse', 'address' => 'Cairo', 'phone' => '1']);
    ShippingRate::create(['geo_type' => 'governorate', 'geo_id' => $geo['governorate']->id, 'price' => 30]);
    $variant = p4Variant($warehouse->id); // priced at 100
    [$agent] = p4Employee('Customer Service');

    $payload = [
        'items' => [['product_variant_id' => $variant->id, 'quantity' => 2]],
        'governorate_id' => $geo['governorate']->id,
        'city_id' => $geo['city']->id,
        'area_id' => $geo['area']->id,
    ];

    $this->actingAs($agent, 'employee')->postJson(route('admin.orders.quote'), $payload)
        ->assertOk()
        ->assertJson(['subtotal' => 200, 'discount' => 0, 'shipping' => 30, 'total' => 230, 'coupon_error' => null]);

    Coupon::create([
        'code' => 'TEN', 'type' => 'percentage', 'value' => 10,
        'is_active' => true, 'times_used' => 0,
    ]);

    $this->actingAs($agent, 'employee')
        ->postJson(route('admin.orders.quote'), [...$payload, 'coupon_code' => 'TEN'])
        ->assertJson(['subtotal' => 200, 'discount' => 20, 'shipping' => 30, 'total' => 210]);

    // A bad code reports itself rather than 500ing or silently pricing at full.
    $this->actingAs($agent, 'employee')
        ->postJson(route('admin.orders.quote'), [...$payload, 'coupon_code' => 'NOPE'])
        ->assertJson(['discount' => 0, 'total' => 230])
        ->assertJsonPath('coupon_error', 'Invalid or inactive coupon code.');

    // No rate anywhere up the chain → null, not a free order.
    ShippingRate::query()->delete();
    $this->actingAs($agent, 'employee')->postJson(route('admin.orders.quote'), $payload)
        ->assertJson(['shipping' => null, 'total' => 200]);
});

it('splits delivery into a queue, a per-order assign form, and an out-for-delivery list', function () {
    $geo = p4Geo();
    $warehouse = Warehouse::create(['name' => 'Main Warehouse', 'address' => 'Cairo', 'phone' => '1']);
    ShippingRate::create(['geo_type' => 'governorate', 'geo_id' => $geo['governorate']->id, 'price' => 30]);
    $variant = p4Variant($warehouse->id);
    $customer = p4Customer();

    [$csAgent] = p4Employee('Customer Service');
    [$checker] = p4Employee('Checking');
    [$deliveryManager] = p4Employee('Delivery Manager');

    $this->actingAs($csAgent, 'employee')->post(route('admin.orders.store'), [
        'customer_id' => $customer->id,
        'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
        'governorate_id' => $geo['governorate']->id,
        'city_id' => $geo['city']->id,
        'area_id' => $geo['area']->id,
        'address_line' => '1 Test St',
        'recipient_name' => $customer->name,
        'phone' => $customer->phone,
    ])->assertRedirect();
    $order = Order::firstOrFail();

    // Still Confirmed-only on the queue, and the assign form is its own page.
    $this->actingAs($deliveryManager, 'employee')->get(route('admin.delivery.assign.form', $order))
        ->assertNotFound();

    $this->actingAs($checker, 'employee')->post(route('admin.checking.confirm', $order))->assertRedirect();

    $this->actingAs($deliveryManager, 'employee')->get(route('admin.delivery.index'))
        ->assertInertia(fn ($page) => $page->component('Delivery/Index')->where('ready.data.0.id', $order->id));

    $this->actingAs($deliveryManager, 'employee')->get(route('admin.delivery.assign.form', $order))
        ->assertInertia(fn ($page) => $page->component('Delivery/Assign')->where('order.id', $order->id)->etc());

    $rep = DeliveryRepresentative::create(['name' => 'Ahmed', 'phone' => '1']);
    $this->actingAs($deliveryManager, 'employee')
        ->post(route('admin.delivery.assign', $order), ['assignment_type' => 'representative', 'assignee_id' => $rep->id])
        ->assertRedirect(route('admin.delivery.index'));

    // Assigned — off the queue, onto the out-for-delivery list.
    $this->actingAs($deliveryManager, 'employee')->get(route('admin.delivery.index'))
        ->assertInertia(fn ($page) => $page->where('ready.data', []));

    $this->actingAs($deliveryManager, 'employee')->get(route('admin.delivery.orders'))
        ->assertInertia(fn ($page) => $page->component('Delivery/Orders')
            ->where('orders.data.0.id', $order->id)
            ->where('orders.data.0.delivery_representative.name', 'Ahmed')
            ->etc());
});

it('assigns a batch of orders to one representative and skips any that left the queue', function () {
    $geo = p4Geo();
    $warehouse = Warehouse::create(['name' => 'Main Warehouse', 'address' => 'Cairo', 'phone' => '1']);
    ShippingRate::create(['geo_type' => 'governorate', 'geo_id' => $geo['governorate']->id, 'price' => 30]);
    $customer = p4Customer();

    [$csAgent] = p4Employee('Customer Service');
    [$checker] = p4Employee('Checking');
    [$deliveryManager] = p4Employee('Delivery Manager');

    $place = function () use ($csAgent, $customer, $geo, $warehouse) {
        $variant = p4Variant($warehouse->id);
        $this->actingAs($csAgent, 'employee')->post(route('admin.orders.store'), [
            'customer_id' => $customer->id,
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
            'governorate_id' => $geo['governorate']->id,
            'city_id' => $geo['city']->id,
            'area_id' => $geo['area']->id,
            'address_line' => '1 Test St',
            'recipient_name' => $customer->name,
            'phone' => $customer->phone,
        ])->assertRedirect();

        return Order::latest('id')->firstOrFail();
    };

    $a = $place();
    $b = $place();
    $stillNew = $place(); // never confirmed — can't be assigned

    foreach ([$a, $b] as $order) {
        $this->actingAs($checker, 'employee')->post(route('admin.checking.confirm', $order))->assertRedirect();
    }

    $rep = DeliveryRepresentative::create(['name' => 'Ahmed', 'phone' => '1']);

    $this->actingAs($deliveryManager, 'employee')->post(route('admin.delivery.assign.bulk'), [
        'order_ids' => [$a->id, $b->id, $stillNew->id],
        'assignment_type' => 'representative',
        'assignee_id' => $rep->id,
    ])
        ->assertRedirect(route('admin.delivery.index'))
        // One skipped, so the batch reports rather than claiming success.
        ->assertSessionHas('error');

    expect($a->fresh()->status->value)->toBe('Assigned')
        ->and($b->fresh()->status->value)->toBe('Assigned')
        ->and($a->fresh()->delivery_representative_id)->toBe($rep->id)
        ->and($b->fresh()->delivery_representative_id)->toBe($rep->id)
        // The unconfirmed one is untouched, not half-assigned.
        ->and($stillNew->fresh()->status->value)->toBe('New')
        ->and($stillNew->fresh()->delivery_representative_id)->toBeNull();

    // A clean batch says so.
    $c = $place();
    $this->actingAs($checker, 'employee')->post(route('admin.checking.confirm', $c))->assertRedirect();
    $this->actingAs($deliveryManager, 'employee')->post(route('admin.delivery.assign.bulk'), [
        'order_ids' => [$c->id],
        'assignment_type' => 'representative',
        'assignee_id' => $rep->id,
    ])->assertSessionHas('success');
});

it('refuses to bulk-assign an order the employee cannot see', function () {
    $geo = p4Geo();
    $warehouse = Warehouse::create(['name' => 'Main Warehouse', 'address' => 'Cairo', 'phone' => '1']);
    ShippingRate::create(['geo_type' => 'governorate', 'geo_id' => $geo['governorate']->id, 'price' => 30]);
    $variant = p4Variant($warehouse->id);
    $customer = p4Customer();

    [$owner] = p4Employee('Customer Service');
    [$otherAgent] = p4Employee('Customer Service'); // scoped to its own orders only
    [$checker] = p4Employee('Checking');

    $this->actingAs($owner, 'employee')->post(route('admin.orders.store'), [
        'customer_id' => $customer->id,
        'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
        'governorate_id' => $geo['governorate']->id,
        'city_id' => $geo['city']->id,
        'area_id' => $geo['area']->id,
        'address_line' => '1 Test St',
        'recipient_name' => $customer->name,
        'phone' => $customer->phone,
    ])->assertRedirect();
    $order = Order::firstOrFail();
    $this->actingAs($checker, 'employee')->post(route('admin.checking.confirm', $order))->assertRedirect();

    $otherAgent->givePermissionTo('orders.assign');
    $rep = DeliveryRepresentative::create(['name' => 'Ahmed', 'phone' => '1']);

    $this->actingAs($otherAgent, 'employee')->post(route('admin.delivery.assign.bulk'), [
        'order_ids' => [$order->id],
        'assignment_type' => 'representative',
        'assignee_id' => $rep->id,
    ])->assertRedirect();

    expect($order->fresh()->status->value)->toBe('Confirmed');
});

it('spells the destination out on every delivery screen', function () {
    $geo = p4Geo();
    $district = District::create(['city_id' => $geo['city']->id, 'name' => ['ar' => 'حي أ', 'en' => 'District A']]);
    $geo['area']->update(['district_id' => $district->id]);
    $warehouse = Warehouse::create(['name' => 'Main Warehouse', 'address' => 'Cairo', 'phone' => '1']);
    ShippingRate::create(['geo_type' => 'governorate', 'geo_id' => $geo['governorate']->id, 'price' => 30]);
    $variant = p4Variant($warehouse->id);
    $customer = p4Customer();

    [$csAgent] = p4Employee('Customer Service');
    [$checker] = p4Employee('Checking');
    [$deliveryManager] = p4Employee('Delivery Manager');

    $this->actingAs($csAgent, 'employee')->post(route('admin.orders.store'), [
        'customer_id' => $customer->id,
        'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
        'governorate_id' => $geo['governorate']->id,
        'city_id' => $geo['city']->id,
        'district_id' => $district->id,
        'area_id' => $geo['area']->id,
        'address_line' => '1 Test St',
        'recipient_name' => $customer->name,
        'phone' => $customer->phone,
    ])->assertRedirect();
    $order = Order::firstOrFail();
    $this->actingAs($checker, 'employee')->post(route('admin.checking.confirm', $order))->assertRedirect();

    $destination = fn ($page, string $prefix) => $page
        ->where("{$prefix}shipping_governorate.name", 'Cairo')
        ->where("{$prefix}shipping_city.name", 'Nasr City')
        ->where("{$prefix}shipping_district.name", 'District A')
        ->where("{$prefix}shipping_area.name", 'Zone A');

    $this->actingAs($deliveryManager, 'employee')->withLocale('en')->get(route('admin.delivery.index'))
        ->assertInertia(fn ($page) => $destination($page, 'ready.data.0.')->etc());

    $this->actingAs($deliveryManager, 'employee')->withLocale('en')->get(route('admin.delivery.assign.form', $order))
        ->assertInertia(fn ($page) => $destination($page, 'order.')->etc());

    $rep = DeliveryRepresentative::create(['name' => 'Ahmed', 'phone' => '1']);
    $this->actingAs($deliveryManager, 'employee')
        ->post(route('admin.delivery.assign', $order), ['assignment_type' => 'representative', 'assignee_id' => $rep->id])
        ->assertRedirect();

    $this->actingAs($deliveryManager, 'employee')->withLocale('en')->get(route('admin.delivery.orders'))
        ->assertInertia(fn ($page) => $destination($page, 'orders.data.0.')->etc());
});

it('gives Accounting the order totals and the destination, not just the line items', function () {
    $geo = p4Geo();
    $district = District::create(['city_id' => $geo['city']->id, 'name' => ['ar' => 'حي أ', 'en' => 'District A']]);
    $warehouse = Warehouse::create(['name' => 'Main Warehouse', 'address' => 'Cairo', 'phone' => '1']);
    ShippingRate::create(['geo_type' => 'governorate', 'geo_id' => $geo['governorate']->id, 'price' => 30]);
    $variant = p4Variant($warehouse->id); // 100 each
    $customer = p4Customer();

    [$csAgent] = p4Employee('Customer Service');
    [$checker] = p4Employee('Checking');
    [$deliveryManager] = p4Employee('Delivery Manager');
    [$accountant] = p4Employee('Accounting');

    $this->actingAs($csAgent, 'employee')->post(route('admin.orders.store'), [
        'customer_id' => $customer->id,
        'items' => [['product_variant_id' => $variant->id, 'quantity' => 2]],
        'governorate_id' => $geo['governorate']->id,
        'city_id' => $geo['city']->id,
        'district_id' => $district->id,
        'area_id' => $geo['area']->id,
        'address_line' => '9 Ledger St',
        'recipient_name' => $customer->name,
        'phone' => $customer->phone,
    ])->assertRedirect();
    $order = Order::firstOrFail();

    $this->actingAs($checker, 'employee')->post(route('admin.checking.confirm', $order))->assertRedirect();
    $rep = DeliveryRepresentative::create(['name' => 'Ahmed', 'phone' => '1']);
    $this->actingAs($deliveryManager, 'employee')
        ->post(route('admin.delivery.assign', $order), ['assignment_type' => 'representative', 'assignee_id' => $rep->id])
        ->assertRedirect();

    $this->actingAs($accountant, 'employee')->withLocale('en')->get(route('admin.accounting.show', $order))
        ->assertInertia(fn ($page) => $page
            ->component('Accounting/Show')
            ->where('order.subtotal', '200.00')
            ->where('order.shipping_amount', '30.00')
            ->where('order.discount_amount', '0.00')
            ->where('order.total', '230.00')
            ->where('order.shipping_address_line', '9 Ledger St')
            ->where('order.shipping_governorate.name', 'Cairo')
            ->where('order.shipping_city.name', 'Nasr City')
            ->where('order.shipping_district.name', 'District A')
            ->where('order.shipping_area.name', 'Zone A')
            ->etc());
});

it('keeps a short-collected order on Accounting books until the balance is paid', function () {
    $geo = p4Geo();
    $warehouse = Warehouse::create(['name' => 'Main Warehouse', 'address' => 'Cairo', 'phone' => '1']);
    ShippingRate::create(['geo_type' => 'governorate', 'geo_id' => $geo['governorate']->id, 'price' => 30]);
    $variant = p4Variant($warehouse->id); // 100 each
    $customer = p4Customer();

    [$csAgent] = p4Employee('Customer Service');
    [$checker] = p4Employee('Checking');
    [$deliveryManager] = p4Employee('Delivery Manager');
    [$accountant] = p4Employee('Accounting');

    $this->actingAs($csAgent, 'employee')->post(route('admin.orders.store'), [
        'customer_id' => $customer->id,
        'items' => [['product_variant_id' => $variant->id, 'quantity' => 2]],
        'governorate_id' => $geo['governorate']->id,
        'city_id' => $geo['city']->id,
        'area_id' => $geo['area']->id,
        'address_line' => '1 Test St',
        'recipient_name' => $customer->name,
        'phone' => $customer->phone,
    ])->assertRedirect();
    $order = Order::firstOrFail(); // 200 goods + 30 shipping = 230

    $this->actingAs($checker, 'employee')->post(route('admin.checking.confirm', $order))->assertRedirect();
    $rep = DeliveryRepresentative::create(['name' => 'Ahmed', 'phone' => '1']);
    $this->actingAs($deliveryManager, 'employee')
        ->post(route('admin.delivery.assign', $order), ['assignment_type' => 'representative', 'assignee_id' => $rep->id])
        ->assertRedirect();

    $treasury = Treasury::create(['name' => 'Main Cash', 'type' => 'cash', 'current_balance' => 0]);

    // The courier comes back with 150 of the 230.
    $this->actingAs($accountant, 'employee')->post(route('admin.accounting.delivered', $order), [
        'treasury_id' => $treasury->id,
        'collected_method' => 'cash',
        'collected_amount' => 150,
    ])->assertRedirect();

    $order->refresh();
    $payment = $order->payments()->latest('id')->firstOrFail();
    expect($order->status->value)->toBe('Delivered')
        ->and($order->payment_status->value)->toBe('partially_collected')
        ->and($payment->collected_amount)->toEqual('150.00')
        // The goods still moved — only the money is open.
        ->and(WarehouseInventory::where('product_variant_id', $variant->id)->firstOrFail()->quantity)->toBe(8)
        ->and($treasury->fresh()->current_balance)->toEqual('150.00');

    // It shows up on the outstanding list, not the delivery-result queue.
    $this->actingAs($accountant, 'employee')->get(route('admin.accounting.index'))
        ->assertInertia(fn ($page) => $page
            ->where('orders.data', [])
            ->where('outstanding.data.0.id', $order->id)
            ->etc());

    // Over-collecting is refused rather than banked.
    $this->actingAs($accountant, 'employee')->post(route('admin.accounting.collect', $order), [
        'treasury_id' => $treasury->id,
        'collected_method' => 'cash',
        'amount' => 100,
    ])->assertSessionHas('error');
    expect($order->fresh()->payment_status->value)->toBe('partially_collected');

    // A further instalment still leaves a balance.
    $this->actingAs($accountant, 'employee')->post(route('admin.accounting.collect', $order), [
        'treasury_id' => $treasury->id,
        'collected_method' => 'cash',
        'amount' => 50,
    ])->assertSessionHas('success');
    expect($order->fresh()->payment_status->value)->toBe('partially_collected')
        ->and($payment->fresh()->collected_amount)->toEqual('200.00');

    // The last 30 settles it.
    $this->actingAs($accountant, 'employee')->post(route('admin.accounting.collect', $order), [
        'treasury_id' => $treasury->id,
        'collected_method' => 'cash',
        'amount' => 30,
    ])->assertSessionHas('success');

    expect($order->fresh()->payment_status->value)->toBe('collected')
        ->and($payment->fresh()->status->value)->toBe('collected')
        ->and($treasury->fresh()->current_balance)->toEqual('230.00');

    // Nothing left to collect.
    $this->actingAs($accountant, 'employee')->post(route('admin.accounting.collect', $order), [
        'treasury_id' => $treasury->id,
        'collected_method' => 'cash',
        'amount' => 10,
    ])->assertSessionHas('error');

    $this->actingAs($accountant, 'employee')->get(route('admin.accounting.index'))
        ->assertInertia(fn ($page) => $page->where('outstanding.data', [])->etc());
});

it('prices a partial return server-side and flags a short collection against it', function () {
    $geo = p4Geo();
    $warehouse = Warehouse::create(['name' => 'Main Warehouse', 'address' => 'Cairo', 'phone' => '1']);
    ShippingRate::create(['geo_type' => 'governorate', 'geo_id' => $geo['governorate']->id, 'price' => 30]);
    $variant = p4Variant($warehouse->id); // 100 each
    $customer = p4Customer();

    [$csAgent] = p4Employee('Customer Service');
    [$checker] = p4Employee('Checking');
    [$deliveryManager] = p4Employee('Delivery Manager');
    [$accountant] = p4Employee('Accounting');

    $this->actingAs($csAgent, 'employee')->post(route('admin.orders.store'), [
        'customer_id' => $customer->id,
        'items' => [['product_variant_id' => $variant->id, 'quantity' => 4]],
        'governorate_id' => $geo['governorate']->id,
        'city_id' => $geo['city']->id,
        'area_id' => $geo['area']->id,
        'address_line' => '1 Test St',
        'recipient_name' => $customer->name,
        'phone' => $customer->phone,
    ])->assertRedirect();
    $order = Order::firstOrFail();
    $item = $order->items()->firstOrFail();

    $this->actingAs($checker, 'employee')->post(route('admin.checking.confirm', $order))->assertRedirect();
    $rep = DeliveryRepresentative::create(['name' => 'Ahmed', 'phone' => '1']);
    $this->actingAs($deliveryManager, 'employee')
        ->post(route('admin.delivery.assign', $order), ['assignment_type' => 'representative', 'assignee_id' => $rep->id])
        ->assertRedirect();

    $treasury = Treasury::create(['name' => 'Main Cash', 'type' => 'cash', 'current_balance' => 0]);

    // Keeps 3 of 4 → 300 goods + 30 shipping = 330 due, but pays 300.
    $this->actingAs($accountant, 'employee')->post(route('admin.accounting.partially-returned', $order), [
        'treasury_id' => $treasury->id,
        'collected_method' => 'cash',
        'collected_amount' => 300,
        'kept_quantities' => [$item->id => 3],
    ])->assertRedirect();

    $order->refresh();
    $payment = $order->payments()->latest('id')->firstOrFail();
    expect($order->status->value)->toBe('Partially Returned')
        ->and($order->payment_status->value)->toBe('partially_collected')
        // The due figure is the Action own, not the caller supplied one.
        ->and($payment->amount)->toEqual('330.00')
        ->and($payment->collected_amount)->toEqual('300.00');

    $this->actingAs($accountant, 'employee')->post(route('admin.accounting.collect', $order), [
        'treasury_id' => $treasury->id,
        'collected_method' => 'cash',
        'amount' => 30,
    ])->assertSessionHas('success');

    expect($order->fresh()->payment_status->value)->toBe('collected')
        ->and($treasury->fresh()->current_balance)->toEqual('330.00');
});

it('lists every collection made against an order payment on the order page', function () {
    $geo = p4Geo();
    $warehouse = Warehouse::create(['name' => 'Main Warehouse', 'address' => 'Cairo', 'phone' => '1']);
    ShippingRate::create(['geo_type' => 'governorate', 'geo_id' => $geo['governorate']->id, 'price' => 30]);
    $variant = p4Variant($warehouse->id); // 100 each
    $customer = p4Customer();

    [$csAgent] = p4Employee('Customer Service');
    [$checker] = p4Employee('Checking');
    [$deliveryManager] = p4Employee('Delivery Manager');
    [$accountant] = p4Employee('Accounting');
    [$chairman] = p4Employee('Chairman');

    $this->actingAs($csAgent, 'employee')->post(route('admin.orders.store'), [
        'customer_id' => $customer->id,
        'items' => [['product_variant_id' => $variant->id, 'quantity' => 2]],
        'governorate_id' => $geo['governorate']->id,
        'city_id' => $geo['city']->id,
        'area_id' => $geo['area']->id,
        'address_line' => '1 Test St',
        'recipient_name' => $customer->name,
        'phone' => $customer->phone,
    ])->assertRedirect();
    $order = Order::firstOrFail(); // 230 due

    $this->actingAs($checker, 'employee')->post(route('admin.checking.confirm', $order))->assertRedirect();
    $rep = DeliveryRepresentative::create(['name' => 'Ahmed', 'phone' => '1']);
    $this->actingAs($deliveryManager, 'employee')
        ->post(route('admin.delivery.assign', $order), ['assignment_type' => 'representative', 'assignee_id' => $rep->id])
        ->assertRedirect();

    $treasury = Treasury::create(['name' => 'Main Cash', 'type' => 'cash', 'current_balance' => 0]);

    $this->actingAs($accountant, 'employee')->post(route('admin.accounting.delivered', $order), [
        'treasury_id' => $treasury->id,
        'collected_method' => 'cash',
        'collected_amount' => 200,
    ])->assertRedirect();

    $this->actingAs($accountant, 'employee')->post(route('admin.accounting.collect', $order), [
        'treasury_id' => $treasury->id,
        'collected_method' => 'cash',
        'amount' => 30,
    ])->assertSessionHas('success');

    // Both instalments reach the order book page, oldest first, each
    // naming the treasury it landed in and who banked it.
    $this->actingAs($chairman, 'employee')->withLocale('en')->get(route('admin.orders.show', $order))
        ->assertInertia(fn ($page) => $page
            ->where('order.payments.0.transactions.0.amount', '200.00')
            ->where('order.payments.0.transactions.0.treasury.name', 'Main Cash')
            ->where('order.payments.0.transactions.0.created_by.full_name', $accountant->full_name)
            ->where('order.payments.0.transactions.1.amount', '30.00')
            ->etc());

    // And to the accounting screen for the same order.
    $this->actingAs($accountant, 'employee')->withLocale('en')->get(route('admin.accounting.show', $order))
        ->assertInertia(fn ($page) => $page
            ->where('order.payments.0.transactions.1.amount', '30.00')
            ->etc());
});

it('tells Accounting who is carrying the order, representative or company', function () {
    $geo = p4Geo();
    $warehouse = Warehouse::create(['name' => 'Main Warehouse', 'address' => 'Cairo', 'phone' => '1']);
    ShippingRate::create(['geo_type' => 'governorate', 'geo_id' => $geo['governorate']->id, 'price' => 30]);
    $customer = p4Customer();

    [$csAgent] = p4Employee('Customer Service');
    [$checker] = p4Employee('Checking');
    [$deliveryManager] = p4Employee('Delivery Manager');
    [$accountant] = p4Employee('Accounting');

    $place = function () use ($csAgent, $customer, $geo, $warehouse, $checker) {
        $variant = p4Variant($warehouse->id);
        $this->actingAs($csAgent, 'employee')->post(route('admin.orders.store'), [
            'customer_id' => $customer->id,
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
            'governorate_id' => $geo['governorate']->id,
            'city_id' => $geo['city']->id,
            'area_id' => $geo['area']->id,
            'address_line' => '1 Test St',
            'recipient_name' => $customer->name,
            'phone' => $customer->phone,
        ])->assertRedirect();

        $order = Order::latest('id')->firstOrFail();
        $this->actingAs($checker, 'employee')->post(route('admin.checking.confirm', $order))->assertRedirect();

        return $order;
    };

    // Assigned to a representative.
    $byRep = $place();
    $rep = DeliveryRepresentative::create(['name' => 'Ahmed', 'phone' => '01111111111']);
    $this->actingAs($deliveryManager, 'employee')
        ->post(route('admin.delivery.assign', $byRep), ['assignment_type' => 'representative', 'assignee_id' => $rep->id])
        ->assertRedirect();

    $this->actingAs($accountant, 'employee')->withLocale('en')->get(route('admin.accounting.show', $byRep))
        ->assertInertia(fn ($page) => $page
            ->where('order.delivery_assignment_type', 'representative')
            ->where('order.delivery_representative.name', 'Ahmed')
            ->where('order.delivery_representative.phone', '01111111111')
            ->where('order.shipping_company', null)
            ->where('order.delivery_assignments.0.assigned_by.full_name', $deliveryManager->full_name)
            ->etc());

    // Assigned to a shipping company.
    $byCompany = $place();
    $company = ShippingCompany::create([
        'name' => 'Fast Ship', 'phone' => '0222', 'address' => 'Cairo',
        'delivery_fee' => 20, 'return_fee' => 10, 'status' => 'active',
        'contact_person' => 'Mona',
    ]);
    $this->actingAs($deliveryManager, 'employee')
        ->post(route('admin.delivery.assign', $byCompany), ['assignment_type' => 'shipping_company', 'assignee_id' => $company->id])
        ->assertRedirect();

    $this->actingAs($accountant, 'employee')->withLocale('en')->get(route('admin.accounting.show', $byCompany))
        ->assertInertia(fn ($page) => $page
            ->where('order.delivery_assignment_type', 'shipping_company')
            ->where('order.shipping_company.name', 'Fast Ship')
            ->where('order.shipping_company.contact_person', 'Mona')
            ->where('order.delivery_representative', null)
            ->etc());
});
