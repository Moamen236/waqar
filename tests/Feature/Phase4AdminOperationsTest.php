<?php

use App\Enums\PaymentStatus;
use App\Exports\OrdersExport;
use App\Models\Area;
use App\Models\Attribute;
use App\Models\AttributeValue;
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
use Illuminate\Testing\TestResponse;
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
    return Customer::create(['name' => 'Test Customer', 'email' => 'c-'.uniqid().'@waqar.test', 'phone' => '01012345678', 'password' => 'password']);
}

/**
 * @return array{0: Employee, 1: Role}
 */
function p4Employee(string $role, ?string $teamLeaderId = null): array
{
    $employee = Employee::create([
        'full_name' => $role.' User', 'email' => 'e-'.uniqid().'@waqar.test', 'phone' => '01012345678',
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
    $warehouse = Warehouse::create(['name' => 'Main', 'address' => 'Cairo', 'phone' => '01012345678']);
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
    $warehouse = Warehouse::create(['name' => 'Main', 'address' => 'Cairo', 'phone' => '01012345678']);
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
    $rep = DeliveryRepresentative::create(['name' => 'Ahmed', 'phone' => '01012345678']);
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
        // 100 unit price + 30 shipping = 130 at the door, but the courier
        // keeps the 30 as their fee, so only the goods money is banked.
        ->and($treasury->fresh()->current_balance)->toEqual('100.00');
});

it('lets a Super Admin edit a role permission matrix via /admin/roles', function () {
    [$superAdmin] = p4Employee('Super Admin');
    $checkingRole = Role::where('name', 'Checking')->where('guard_name', 'employee')->firstOrFail();

    $this->actingAs($superAdmin, 'employee')
        ->put(route('admin.roles.update', $checkingRole), ['permissions' => ['orders.view', 'checking.view', 'checking.confirm']])
        ->assertRedirect(route('admin.roles.index'));

    expect($checkingRole->fresh()->permissions->pluck('name')->all())
        ->toEqualCanonicalizing(['orders.view', 'checking.view', 'checking.confirm']);
});

it('never lets a non-Super-Admin employee assign the Super Admin role, even with employees.create granted', function () {
    // Grant employees.create to Checking specifically to test the worst
    // case: a role that was never meant to touch this at all.
    Role::where('name', 'Checking')->where('guard_name', 'employee')->first()->givePermissionTo('employees.create');
    [$checker] = p4Employee('Checking');

    $this->actingAs($checker, 'employee')
        ->post(route('admin.employees.store'), [
            'full_name' => 'Attempted Escalation', 'email' => 'escalate@waqar.test', 'phone' => '01012345678',
            'password' => 'password12345', 'residence_address' => 'N/A', 'national_id_number' => 'N/A',
            'is_active' => true, 'role' => 'Super Admin',
        ])
        ->assertForbidden();

    expect(Employee::where('email', 'escalate@waqar.test')->exists())->toBeFalse();
});

it('gates Resume on main-warehouse stock and needs no warehouse pick', function () {
    $geo = p4Geo();
    $warehouse = Warehouse::create(['name' => 'Main Warehouse', 'address' => 'Cairo', 'phone' => '01012345678']);
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

    // Confirm is refused on an Advertisement line, so Checking sends it
    // straight to Backorder from the queue.
    $this->actingAs($checker, 'employee')->post(route('admin.checking.confirm', $order))->assertSessionHas('error');
    expect($order->fresh()->status->value)->toBe('New');
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
    $warehouse = Warehouse::create(['name' => 'Main Warehouse', 'address' => 'Cairo', 'phone' => '01012345678']);
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

it('takes an order with only the governorate and street address, pricing shipping at the governorate', function () {
    $geo = p4Geo();
    $warehouse = Warehouse::create(['name' => 'Main Warehouse', 'address' => 'Cairo', 'phone' => '01012345678']);
    ShippingRate::create(['geo_type' => 'governorate', 'geo_id' => $geo['governorate']->id, 'price' => 30]);
    ShippingRate::create(['geo_type' => 'area', 'geo_id' => $geo['area']->id, 'price' => 60]);
    $variant = p4Variant($warehouse->id);
    [$agent] = p4Employee('Customer Service');

    // City, district and area left on "None".
    $this->actingAs($agent, 'employee')->post(route('admin.orders.store'), [
        'new_customer' => ['name' => 'No Area Buyer', 'phone' => '01000000001'],
        'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
        'governorate_id' => $geo['governorate']->id,
        'city_id' => null,
        'district_id' => null,
        'area_id' => null,
        'address_line' => '9 Side St',
        'recipient_name' => 'No Area Buyer',
        'phone' => '01000000001',
    ])->assertRedirect(route('admin.checking.index'));

    $order = Order::firstOrFail();
    $address = Customer::where('name', 'No Area Buyer')->firstOrFail()->addresses()->firstOrFail();

    expect((float) $order->shipping_amount)->toBe(30.0)
        ->and($order->shipping_city_id)->toBeNull()
        ->and($order->shipping_area_id)->toBeNull()
        ->and($address->city_id)->toBeNull()
        ->and($address->area_id)->toBeNull();

    // The governorate itself is still required.
    $this->actingAs($agent, 'employee')->post(route('admin.orders.store'), [
        'new_customer' => ['name' => 'No Geo Buyer', 'phone' => '01000000002'],
        'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
        'address_line' => '9 Side St',
        'recipient_name' => 'No Geo Buyer',
        'phone' => '01000000002',
    ])->assertSessionHasErrors('governorate_id');
});

it('turns a refused order into a message on the form, and files no customer for it', function () {
    $geo = p4Geo();
    $warehouse = Warehouse::create(['name' => 'Main Warehouse', 'address' => 'Cairo', 'phone' => '01012345678']);
    ShippingRate::create(['geo_type' => 'governorate', 'geo_id' => $geo['governorate']->id, 'price' => 30]);
    $variant = p4Variant($warehouse->id, 2);
    [$agent] = p4Employee('Customer Service');

    // More than the warehouse holds: CreateOrderAction refuses it. That used
    // to surface as a 500 — and the guest customer filed first was left
    // behind, to be filed again on the retry.
    $this->actingAs($agent, 'employee')->post(route('admin.orders.store'), [
        'new_customer' => ['name' => 'Too Keen', 'phone' => '01000000009'],
        'items' => [['product_variant_id' => $variant->id, 'quantity' => 5]],
        'governorate_id' => $geo['governorate']->id,
        'address_line' => '5 Queue St',
        'recipient_name' => 'Too Keen',
        'phone' => '01000000009',
    ])->assertRedirect()->assertSessionHas('error');

    expect(Order::count())->toBe(0)
        ->and(Customer::where('name', 'Too Keen')->exists())->toBeFalse();
});

it('rejects an order that names neither an existing customer nor a new one', function () {
    $geo = p4Geo();
    $warehouse = Warehouse::create(['name' => 'Main Warehouse', 'address' => 'Cairo', 'phone' => '01012345678']);
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
        'phone' => '01012345678',
    ])->assertSessionHasErrors('customer_id');

    expect(Order::count())->toBe(0);
});

it('finds customers for the order screen by search, with their saved addresses attached', function () {
    $geo = p4Geo();
    Warehouse::create(['name' => 'Main Warehouse', 'address' => 'Cairo', 'phone' => '01012345678']);
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

    // The page itself no longer carries a customer list at all.
    $this->actingAs($agent, 'employee')->get(route('admin.orders.create'))
        ->assertInertia(fn ($page) => $page->missing('customers')->etc());

    $this->actingAs($agent, 'employee')
        ->getJson(route('admin.orders.customer-search', ['q' => $customer->phone]))
        ->assertOk()
        ->assertJsonPath('customers.0.id', $customer->id)
        ->assertJsonPath('customers.0.addresses.0.address_line', '3 Saved St')
        ->assertJsonPath('customers.0.addresses.0.is_default', true);

    // Bounded: a broad term returns a page of matches, not the table.
    foreach (range(1, 20) as $i) {
        Customer::create(['name' => "Match {$i}", 'phone' => '0100000'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'password' => 'x']);
    }
    $this->actingAs($agent, 'employee')
        ->getJson(route('admin.orders.customer-search', ['q' => 'Match']))
        ->assertJsonCount(15, 'customers');

    // Same gate as the rest of order creation.
    [$checker] = p4Employee('Checking');
    $this->actingAs($checker, 'employee')
        ->getJson(route('admin.orders.customer-search', ['q' => 'Match']))
        ->assertForbidden();
});

it('quotes the order screen its subtotal, shipping, discount and total from the same services the Action uses', function () {
    $geo = p4Geo();
    $warehouse = Warehouse::create(['name' => 'Main Warehouse', 'address' => 'Cairo', 'phone' => '01012345678']);
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

it('quotes shipping as each address level is picked for a new customer, not only once the area is', function () {
    $geo = p4Geo();
    $warehouse = Warehouse::create(['name' => 'Main Warehouse', 'address' => 'Cairo', 'phone' => '01012345678']);
    ShippingRate::create(['geo_type' => 'governorate', 'geo_id' => $geo['governorate']->id, 'price' => 30]);
    ShippingRate::create(['geo_type' => 'city', 'geo_id' => $geo['city']->id, 'price' => 45]);
    ShippingRate::create(['geo_type' => 'area', 'geo_id' => $geo['area']->id, 'price' => 60]);
    $variant = p4Variant($warehouse->id);
    [$agent] = p4Employee('Customer Service');

    // No customer_id: the customer is being created on this same screen.
    $quote = fn (array $geoPicked) => $this->actingAs($agent, 'employee')
        ->postJson(route('admin.orders.quote'), [
            'customer_id' => null,
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
            ...$geoPicked,
        ])
        ->assertOk()
        ->json('shipping');

    // Each level picked narrows to the most specific rate so far — the
    // same answer CreateOrderAction will give on submit.
    expect($quote(['governorate_id' => $geo['governorate']->id]))->toEqual(30)
        ->and($quote(['governorate_id' => $geo['governorate']->id, 'city_id' => $geo['city']->id]))->toEqual(45)
        ->and($quote([
            'governorate_id' => $geo['governorate']->id,
            'city_id' => $geo['city']->id,
            'area_id' => $geo['area']->id,
        ]))->toEqual(60)
        ->and($quote([]))->toBeNull();
});

it('splits delivery into a queue, a per-order assign form, and an out-for-delivery list', function () {
    $geo = p4Geo();
    $warehouse = Warehouse::create(['name' => 'Main Warehouse', 'address' => 'Cairo', 'phone' => '01012345678']);
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
        ->assertInertia(fn ($page) => $page->component('Delivery/Index')
            ->where('orders.data.0.id', $order->id)
            ->where('orders.data.0.status', 'Confirmed')
            ->where('counts.Confirmed', 1)
            ->etc());

    $this->actingAs($deliveryManager, 'employee')->get(route('admin.delivery.assign.form', $order))
        ->assertInertia(fn ($page) => $page->component('Delivery/Assign')->where('order.id', $order->id)->etc());

    $rep = DeliveryRepresentative::create(['name' => 'Ahmed', 'phone' => '01012345678']);
    $this->actingAs($deliveryManager, 'employee')
        ->post(route('admin.delivery.assign', $order), ['assignment_type' => 'representative', 'assignee_id' => $rep->id])
        ->assertRedirect(route('admin.delivery.index'));

    // Assigned — gone from the Confirmed tab, still on the same board with
    // its courier.
    $this->actingAs($deliveryManager, 'employee')->get(route('admin.delivery.index', ['status' => 'Confirmed']))
        ->assertInertia(fn ($page) => $page->where('orders.data', [])->etc());

    $this->actingAs($deliveryManager, 'employee')->get(route('admin.delivery.index'))
        ->assertInertia(fn ($page) => $page->component('Delivery/Index')
            ->where('orders.data.0.id', $order->id)
            ->where('orders.data.0.status', 'Assigned')
            ->where('counts.Assigned', 1)
            ->where('orders.data.0.delivery_representative.name', 'Ahmed')
            ->etc());

    // Advanced filters narrow the board — by courier here.
    $other = DeliveryRepresentative::create(['name' => 'Other', 'phone' => '01012345679']);
    $this->actingAs($deliveryManager, 'employee')->get(route('admin.delivery.index', ['representative_id' => $rep->id]))
        ->assertInertia(fn ($page) => $page->where('orders.data.0.id', $order->id)->etc());
    $this->actingAs($deliveryManager, 'employee')->get(route('admin.delivery.index', ['representative_id' => $other->id]))
        ->assertInertia(fn ($page) => $page->where('orders.data', [])->where('counts.Assigned', 0)->etc());
});

it('assigns a batch of orders to one representative and skips any that left the queue', function () {
    $geo = p4Geo();
    $warehouse = Warehouse::create(['name' => 'Main Warehouse', 'address' => 'Cairo', 'phone' => '01012345678']);
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

    $rep = DeliveryRepresentative::create(['name' => 'Ahmed', 'phone' => '01012345678']);

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
    $warehouse = Warehouse::create(['name' => 'Main Warehouse', 'address' => 'Cairo', 'phone' => '01012345678']);
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

    $otherAgent->givePermissionTo('delivery.assign');
    $rep = DeliveryRepresentative::create(['name' => 'Ahmed', 'phone' => '01012345678']);

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
    $warehouse = Warehouse::create(['name' => 'Main Warehouse', 'address' => 'Cairo', 'phone' => '01012345678']);
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
        ->assertInertia(fn ($page) => $destination($page, 'orders.data.0.')->etc());

    $this->actingAs($deliveryManager, 'employee')->withLocale('en')->get(route('admin.delivery.assign.form', $order))
        ->assertInertia(fn ($page) => $destination($page, 'order.')->etc());

    $rep = DeliveryRepresentative::create(['name' => 'Ahmed', 'phone' => '01012345678']);
    $this->actingAs($deliveryManager, 'employee')
        ->post(route('admin.delivery.assign', $order), ['assignment_type' => 'representative', 'assignee_id' => $rep->id])
        ->assertRedirect();

    $this->actingAs($deliveryManager, 'employee')->withLocale('en')->get(route('admin.delivery.index'))
        ->assertInertia(fn ($page) => $destination($page, 'orders.data.0.')->etc());
});

it('gives Accounting the order totals and the destination, not just the line items', function () {
    $geo = p4Geo();
    $district = District::create(['city_id' => $geo['city']->id, 'name' => ['ar' => 'حي أ', 'en' => 'District A']]);
    $warehouse = Warehouse::create(['name' => 'Main Warehouse', 'address' => 'Cairo', 'phone' => '01012345678']);
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
    $rep = DeliveryRepresentative::create(['name' => 'Ahmed', 'phone' => '01012345678']);
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
    $warehouse = Warehouse::create(['name' => 'Main Warehouse', 'address' => 'Cairo', 'phone' => '01012345678']);
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
    $rep = DeliveryRepresentative::create(['name' => 'Ahmed', 'phone' => '01012345678']);
    $this->actingAs($deliveryManager, 'employee')
        ->post(route('admin.delivery.assign', $order), ['assignment_type' => 'representative', 'assignee_id' => $rep->id])
        ->assertRedirect();

    $treasury = Treasury::create(['name' => 'Main Cash', 'type' => 'cash', 'current_balance' => 0]);

    // The courier keeps the 30 shipping, so 200 is what they owe us.
    // They come back with 150 of it.
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
        'amount' => 30,
    ])->assertSessionHas('success');
    expect($order->fresh()->payment_status->value)->toBe('partially_collected')
        ->and($payment->fresh()->collected_amount)->toEqual('180.00');

    // The last 20 settles it — against the 200 of goods, never the 230
    // the customer handed over.
    $this->actingAs($accountant, 'employee')->post(route('admin.accounting.collect', $order), [
        'treasury_id' => $treasury->id,
        'collected_method' => 'cash',
        'amount' => 20,
    ])->assertSessionHas('success');

    expect($order->fresh()->payment_status->value)->toBe('collected')
        ->and($payment->fresh()->status->value)->toBe('collected')
        ->and($treasury->fresh()->current_balance)->toEqual('200.00');

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
    $warehouse = Warehouse::create(['name' => 'Main Warehouse', 'address' => 'Cairo', 'phone' => '01012345678']);
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
    $rep = DeliveryRepresentative::create(['name' => 'Ahmed', 'phone' => '01012345678']);
    $this->actingAs($deliveryManager, 'employee')
        ->post(route('admin.delivery.assign', $order), ['assignment_type' => 'representative', 'assignee_id' => $rep->id])
        ->assertRedirect();

    $treasury = Treasury::create(['name' => 'Main Cash', 'type' => 'cash', 'current_balance' => 0]);

    // Keeps 3 of 4 → 300 goods + 30 shipping = 330 owed at the door. The
    // courier keeps the 30 either way (the van drove), so 300 is what
    // reaches us — and they come back 30 short of even that.
    $this->actingAs($accountant, 'employee')->post(route('admin.accounting.partially-returned', $order), [
        'treasury_id' => $treasury->id,
        'collected_method' => 'cash',
        'collected_amount' => 270,
        'kept_quantities' => [$item->id => 3],
    ])->assertRedirect();

    $order->refresh();
    $payment = $order->payments()->latest('id')->firstOrFail();
    expect($order->status->value)->toBe('Partially Returned')
        ->and($order->payment_status->value)->toBe('partially_collected')
        // The due figure is the Action own, not the caller supplied one,
        // and it stays gross — it is what the customer actually paid.
        ->and($payment->amount)->toEqual('330.00')
        ->and($payment->collected_amount)->toEqual('270.00');

    $this->actingAs($accountant, 'employee')->post(route('admin.accounting.collect', $order), [
        'treasury_id' => $treasury->id,
        'collected_method' => 'cash',
        'amount' => 30,
    ])->assertSessionHas('success');

    expect($order->fresh()->payment_status->value)->toBe('collected')
        ->and($treasury->fresh()->current_balance)->toEqual('300.00');
});

it('lists every collection made against an order payment on the order page', function () {
    $geo = p4Geo();
    $warehouse = Warehouse::create(['name' => 'Main Warehouse', 'address' => 'Cairo', 'phone' => '01012345678']);
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
    $order = Order::firstOrFail(); // 200 goods + 30 shipping; 200 reaches us

    $this->actingAs($checker, 'employee')->post(route('admin.checking.confirm', $order))->assertRedirect();
    $rep = DeliveryRepresentative::create(['name' => 'Ahmed', 'phone' => '01012345678']);
    $this->actingAs($deliveryManager, 'employee')
        ->post(route('admin.delivery.assign', $order), ['assignment_type' => 'representative', 'assignee_id' => $rep->id])
        ->assertRedirect();

    $treasury = Treasury::create(['name' => 'Main Cash', 'type' => 'cash', 'current_balance' => 0]);

    $this->actingAs($accountant, 'employee')->post(route('admin.accounting.delivered', $order), [
        'treasury_id' => $treasury->id,
        'collected_method' => 'cash',
        'collected_amount' => 170,
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
            ->where('order.payments.0.transactions.0.amount', '170.00')
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
    $warehouse = Warehouse::create(['name' => 'Main Warehouse', 'address' => 'Cairo', 'phone' => '01012345678']);
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

// Accounting confirms from the order's own page, so that page — not the
// queue it came from — is where the result has to show up.
it('sends Accounting back to the order it just confirmed, carrying the new status', function () {
    $geo = p4Geo();
    $warehouse = Warehouse::create(['name' => 'Main Warehouse', 'address' => 'Cairo', 'phone' => '01012345678']);
    ShippingRate::create(['geo_type' => 'governorate', 'geo_id' => $geo['governorate']->id, 'price' => 30]);
    $variant = p4Variant($warehouse->id);
    $customer = p4Customer();

    [$csAgent] = p4Employee('Customer Service');
    [$checker] = p4Employee('Checking');
    [$deliveryManager] = p4Employee('Delivery Manager');
    [$accountant] = p4Employee('Accounting');

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
    $order = Order::firstOrFail(); // 100 goods + 30 shipping = 130

    $this->actingAs($checker, 'employee')->post(route('admin.checking.confirm', $order))->assertRedirect();
    $rep = DeliveryRepresentative::create(['name' => 'Ahmed', 'phone' => '01012345678']);
    $this->actingAs($deliveryManager, 'employee')
        ->post(route('admin.delivery.assign', $order), ['assignment_type' => 'representative', 'assignee_id' => $rep->id])
        ->assertRedirect();

    $treasury = Treasury::create(['name' => 'Main Cash', 'type' => 'cash', 'current_balance' => 0]);

    $this->actingAs($accountant, 'employee')->post(route('admin.accounting.delivered', $order), [
        'treasury_id' => $treasury->id,
        'collected_method' => 'cash',
        // 100 of goods is what reaches us; the courier keeps the 30. So
        // 70 is a genuine shortfall, and 30 settles it below.
        'collected_amount' => 70,
    ])->assertRedirect(route('admin.accounting.show', $order));

    // The card reads the status and the balance off these props.
    $this->actingAs($accountant, 'employee')->get(route('admin.accounting.show', $order))
        ->assertInertia(fn ($page) => $page
            ->where('order.status', 'Delivered')
            ->where('order.payment_status', 'partially_collected')
            ->where('order.payments.0.collected_amount', '70.00')
            ->etc());

    $this->actingAs($accountant, 'employee')->post(route('admin.accounting.collect', $order), [
        'treasury_id' => $treasury->id,
        'collected_method' => 'cash',
        'amount' => 30,
    ])->assertRedirect(route('admin.accounting.show', $order));

    $this->actingAs($accountant, 'employee')->get(route('admin.accounting.show', $order))
        ->assertInertia(fn ($page) => $page->where('order.payment_status', 'collected')->etc());
});

// The Actions card offers only the transitions the current status allows;
// a stale tab that posts one anyway gets a flash, not a 500.
it('refuses a Checking transition the order status no longer allows', function () {
    $geo = p4Geo();
    $warehouse = Warehouse::create(['name' => 'Main Warehouse', 'address' => 'Cairo', 'phone' => '01012345678']);
    ShippingRate::create(['geo_type' => 'governorate', 'geo_id' => $geo['governorate']->id, 'price' => 30]);
    $variant = p4Variant($warehouse->id);
    $customer = p4Customer();

    [$csAgent] = p4Employee('Customer Service');
    [$checker] = p4Employee('Checking');

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

    $this->actingAs($checker, 'employee')->post(route('admin.checking.confirm', $order))
        ->assertSessionHas('success');

    // Confirming again isn't legal from Confirmed — the button is gone
    // from the card, and the endpoint says so instead of throwing.
    $this->actingAs($checker, 'employee')->post(route('admin.checking.confirm', $order))
        ->assertSessionHas('error');

    expect($order->fresh()->status->value)->toBe('Confirmed');

    // Backorder is legal from Confirmed, and Confirm is not legal from it.
    $this->actingAs($checker, 'employee')
        ->post(route('admin.checking.backorder', $order), ['reason' => 'Supplier delay'])
        ->assertSessionHas('success');
    $this->actingAs($checker, 'employee')
        ->post(route('admin.checking.postpone', $order), ['reason' => 'Customer asked'])
        ->assertSessionHas('error');

    expect($order->fresh()->status->value)->toBe('Backorder');
});

// A4 — the courier shipping label (feature-backlog-plan.md).

it('renders a shipping label carrying the cash the courier must collect', function () {
    $geo = p4Geo();
    $warehouse = Warehouse::create(['name' => 'Main Warehouse', 'address' => 'Cairo', 'phone' => '01012345678']);
    ShippingRate::create(['geo_type' => 'governorate', 'geo_id' => $geo['governorate']->id, 'price' => 30]);
    $variant = p4Variant($warehouse->id);
    $customer = p4Customer();
    [$csAgent] = p4Employee('Customer Service');
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
    $order = Order::firstOrFail(); // 200 goods + 30 shipping

    $this->actingAs($chairman, 'employee')->withLocale('en')->get(route('admin.orders.label', $order))
        ->assertInertia(fn ($page) => $page
            ->component('Orders/Label')
            // Gross, not net: this is what the courier takes at the door.
            // The 30 of shipping is theirs, but the customer still hands
            // over all 230.
            ->where('codAmount', 230)
            ->where('sender.name', 'Main Warehouse')
            ->where('order.shipping_recipient_name', $customer->name)
            ->etc());
});

it('tells the courier not to collect on a label for an already-paid order', function () {
    $geo = p4Geo();
    $warehouse = Warehouse::create(['name' => 'Main Warehouse', 'address' => 'Cairo', 'phone' => '01012345678']);
    ShippingRate::create(['geo_type' => 'governorate', 'geo_id' => $geo['governorate']->id, 'price' => 30]);
    $variant = p4Variant($warehouse->id);
    $customer = p4Customer();
    [$csAgent] = p4Employee('Customer Service');
    [$chairman] = p4Employee('Chairman');

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
    $order->update(['payment_status' => PaymentStatus::Collected]);

    // null is what drives the "PAID — DO NOT COLLECT" banner, so a
    // courier can never be handed a number to collect twice.
    $this->actingAs($chairman, 'employee')->get(route('admin.orders.label', $order))
        ->assertInertia(fn ($page) => $page->where('codAmount', null)->etc());
});

// D1 — the handover step (feature-backlog-plan.md). OrderStatus::
// OutForDelivery existed in the enum since Phase 1 and nothing ever
// wrote it; eight filters read it. This is what finally assigns it.

/**
 * An order confirmed and assigned to a courier, ready for Accounting.
 *
 * @return array{0: Order, 1: Employee, 2: ProductVariant}
 */
function p4Assigned(int $stock = 10): array
{
    $geo = p4Geo();
    $warehouse = Warehouse::create(['name' => 'Main', 'address' => 'Cairo', 'phone' => '01012345678']);
    ShippingRate::create(['geo_type' => 'governorate', 'geo_id' => $geo['governorate']->id, 'price' => 30]);
    $variant = p4Variant($warehouse->id, $stock);
    $customer = p4Customer();
    [$csAgent] = p4Employee('Customer Service');
    [$checker] = p4Employee('Checking');
    [$deliveryManager] = p4Employee('Delivery Manager');
    [$accountant] = p4Employee('Accounting');

    test()->actingAs($csAgent, 'employee')->post(route('admin.orders.store'), [
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
    test()->actingAs($checker, 'employee')->post(route('admin.checking.confirm', $order))->assertRedirect();

    $rep = DeliveryRepresentative::create(['name' => 'Ahmed', 'phone' => '01012345678']);
    test()->actingAs($deliveryManager, 'employee')
        ->post(route('admin.delivery.assign', $order), ['assignment_type' => 'representative', 'assignee_id' => $rep->id])
        ->assertRedirect();

    return [$order->fresh(), $accountant, $variant];
}

it('signs an order out to the courier without touching stock or money', function () {
    [$order, $accountant, $variant] = p4Assigned();

    $this->actingAs($accountant, 'employee')
        ->post(route('admin.accounting.handover', $order))
        ->assertRedirect(route('admin.accounting.show', $order));

    $order->refresh();
    expect($order->status->value)->toBe('Out for Delivery')
        // Writing this is the whole point: the "have your cash ready"
        // customer notification and the storefront timeline both hang off
        // customer_status and were previously unreachable.
        ->and($order->customer_status->value)->toBe('Out for Delivery')
        // Nothing moved. Stock is still merely reserved and no payment
        // was collected — both stay at Delivered.
        ->and(WarehouseInventory::where('product_variant_id', $variant->id)->firstOrFail()->quantity)->toBe(10)
        ->and($order->payment_status->value)->toBe('pending');

    // Who signed it out, and when.
    $history = $order->statusHistory()->latest('id')->firstOrFail();
    expect($history->from_status)->toBe('Assigned')
        ->and($history->to_status)->toBe('Out for Delivery')
        ->and($history->changed_by)->toBe($accountant->id);
});

it('splits the accounting queue so handover and settlement are separate jobs', function () {
    // Both orders share one setup: CreateOrderAction resolves the
    // warehouse through Warehouse::main(), so a second p4Assigned() would
    // stock a new warehouse the order never reserves against.
    [$stillHere, $accountant, $variant] = p4Assigned(stock: 20);

    [$csAgent] = p4Employee('Customer Service');
    [$checker] = p4Employee('Checking');
    [$deliveryManager] = p4Employee('Delivery Manager');

    $this->actingAs($csAgent, 'employee')->post(route('admin.orders.store'), [
        'customer_id' => $stillHere->customer_id,
        'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
        'governorate_id' => $stillHere->shipping_governorate_id,
        'city_id' => $stillHere->shipping_city_id,
        'area_id' => $stillHere->shipping_area_id,
        'address_line' => '1 Test St',
        'recipient_name' => $stillHere->shipping_recipient_name,
        'phone' => $stillHere->shipping_phone,
    ])->assertRedirect();

    $goneOut = Order::latest('id')->firstOrFail();
    $this->actingAs($checker, 'employee')->post(route('admin.checking.confirm', $goneOut))->assertRedirect();
    $this->actingAs($deliveryManager, 'employee')
        ->post(route('admin.delivery.assign', $goneOut), [
            'assignment_type' => 'representative',
            'assignee_id' => DeliveryRepresentative::firstOrFail()->id,
        ])->assertRedirect();

    $this->actingAs($accountant, 'employee')->post(route('admin.accounting.handover', $goneOut))->assertRedirect();

    $this->actingAs($accountant, 'employee')->get(route('admin.accounting.index'))
        ->assertInertia(fn ($page) => $page
            ->where('awaitingHandover.data.0.id', $stillHere->id)
            ->where('orders.data.0.id', $goneOut->id)
            ->etc());
});

it('keeps handover optional — an order can still go straight from Assigned to Delivered', function () {
    [$order, $accountant] = p4Assigned();
    $treasury = Treasury::create(['name' => 'Main Cash', 'type' => 'cash', 'current_balance' => 0]);

    // No handover posted. A courier who skips the desk must not leave the
    // order stranded, so the delivery outcome still accepts Assigned.
    $this->actingAs($accountant, 'employee')->post(route('admin.accounting.delivered', $order), [
        'treasury_id' => $treasury->id,
        'collected_method' => 'cash',
    ])->assertRedirect();

    expect($order->fresh()->status->value)->toBe('Delivered');
});

it('refuses a second handover on an order that already went out', function () {
    [$order, $accountant] = p4Assigned();

    $this->actingAs($accountant, 'employee')->post(route('admin.accounting.handover', $order))->assertRedirect();

    // A stale tab posting again gets a flash, not a 500 and not a second
    // history row.
    $this->actingAs($accountant, 'employee')
        ->post(route('admin.accounting.handover', $order))
        ->assertSessionHas('error');

    expect($order->fresh()->statusHistory()->where('to_status', 'Out for Delivery')->count())->toBe(1);
});

// D2 — settle a whole courier's round at once (feature-backlog-plan.md).

/**
 * Two orders out for delivery with the same courier, plus one with a
 * different courier so the filter has something to exclude.
 *
 * @return array{0: Order, 1: Order, 2: Order, 3: Employee, 4: DeliveryRepresentative}
 */
function p4Round(): array
{
    $geo = p4Geo();
    $warehouse = Warehouse::create(['name' => 'Main', 'address' => 'Cairo', 'phone' => '01012345678']);
    ShippingRate::create(['geo_type' => 'governorate', 'geo_id' => $geo['governorate']->id, 'price' => 30]);
    $variant = p4Variant($warehouse->id, 30);
    $customer = p4Customer();
    [$csAgent] = p4Employee('Customer Service');
    [$checker] = p4Employee('Checking');
    [$deliveryManager] = p4Employee('Delivery Manager');
    [$accountant] = p4Employee('Accounting');

    $ahmed = DeliveryRepresentative::create(['name' => 'Ahmed', 'phone' => '01012345678']);
    $sara = DeliveryRepresentative::create(['name' => 'Sara', 'phone' => '01112345678']);

    $place = function (DeliveryRepresentative $rep) use (
        $csAgent, $checker, $deliveryManager, $accountant, $customer, $variant, $geo
    ) {
        test()->actingAs($csAgent, 'employee')->post(route('admin.orders.store'), [
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
        test()->actingAs($checker, 'employee')->post(route('admin.checking.confirm', $order))->assertRedirect();
        test()->actingAs($deliveryManager, 'employee')
            ->post(route('admin.delivery.assign', $order), [
                'assignment_type' => 'representative', 'assignee_id' => $rep->id,
            ])->assertRedirect();
        test()->actingAs($accountant, 'employee')
            ->post(route('admin.accounting.handover', $order))->assertRedirect();

        return $order->fresh();
    };

    return [$place($ahmed), $place($ahmed), $place($sara), $accountant, $ahmed];
}

it('settles a whole round in one action and banks the net of every order', function () {
    [$first, $second, $other, $accountant] = p4Round();
    $treasury = Treasury::create(['name' => 'Main Cash', 'type' => 'cash', 'current_balance' => 0]);

    $this->actingAs($accountant, 'employee')->post(route('admin.accounting.settle.bulk'), [
        'order_ids' => [$first->id, $second->id],
        'treasury_id' => $treasury->id,
        'collected_method' => 'cash',
    ])->assertRedirect()->assertSessionHas('success');

    expect($first->fresh()->status->value)->toBe('Delivered')
        ->and($second->fresh()->status->value)->toBe('Delivered')
        // Untouched: it was not in the selection.
        ->and($other->fresh()->status->value)->toBe('Out for Delivery')
        // 100 goods + 30 shipping each; the courier keeps the shipping,
        // so 200 reaches the drawer, not 260.
        ->and((float) $treasury->fresh()->current_balance)->toBe(200.0);
});

it('skips and names an order that moved on since the page loaded, instead of failing the batch', function () {
    [$first, $second, , $accountant] = p4Round();
    $treasury = Treasury::create(['name' => 'Main Cash', 'type' => 'cash', 'current_balance' => 0]);

    // Someone settled this one on another screen a moment ago.
    $this->actingAs($accountant, 'employee')->post(route('admin.accounting.delivered', $first), [
        'treasury_id' => $treasury->id,
        'collected_method' => 'cash',
    ])->assertRedirect();

    $this->actingAs($accountant, 'employee')->post(route('admin.accounting.settle.bulk'), [
        'order_ids' => [$first->id, $second->id],
        'treasury_id' => $treasury->id,
        'collected_method' => 'cash',
    ])->assertSessionHas('error');

    // The healthy one still settled; only the stale one was skipped.
    expect($second->fresh()->status->value)->toBe('Delivered')
        ->and((float) $treasury->fresh()->current_balance)->toBe(200.0);
});

it('narrows the settlement queue to one courier', function () {
    [$first, $second, $other, $accountant, $ahmed] = p4Round();

    $this->actingAs($accountant, 'employee')
        ->get(route('admin.accounting.index', ['representative_id' => $ahmed->id]))
        ->assertInertia(fn ($page) => $page
            ->where('filters.representative_id', $ahmed->id)
            ->where('orders.data', fn ($rows) => count($rows) === 2)
            ->etc());

    // Unfiltered, all three are in the queue.
    $this->actingAs($accountant, 'employee')->get(route('admin.accounting.index'))
        ->assertInertia(fn ($page) => $page
            ->where('orders.data', fn ($rows) => count($rows) === 3)
            ->etc());
});

// Collect one sum from a courier and split it across their orders,
// oldest first (accounting: "collect from courier").

/**
 * Orders placed through the real flow and left with the courier, one per
 * price. Shipping is 30 on every order and the courier keeps it, so each
 * order owes exactly its goods price — the figures below are the ones the
 * business described: 1600, 1800 and 600, 4000 in all.
 *
 * @param  list<int>  $prices
 * @return array{orders: list<Order>, accountant: Employee, ahmed: DeliveryRepresentative, sara: DeliveryRepresentative}
 */
function p4CourierOrders(array $prices, bool $handover = false): array
{
    $geo = p4Geo();
    $warehouse = Warehouse::create(['name' => 'Main', 'address' => 'Cairo', 'phone' => '01012345678']);
    ShippingRate::create(['geo_type' => 'governorate', 'geo_id' => $geo['governorate']->id, 'price' => 30]);
    $customer = p4Customer();
    [$csAgent] = p4Employee('Customer Service');
    [$checker] = p4Employee('Checking');
    [$deliveryManager] = p4Employee('Delivery Manager');
    [$accountant] = p4Employee('Accounting');

    $ahmed = DeliveryRepresentative::create(['name' => 'Ahmed', 'phone' => '01012345678']);
    $sara = DeliveryRepresentative::create(['name' => 'Sara', 'phone' => '01112345678']);

    $orders = [];
    foreach ($prices as $price) {
        $variant = p4Variant($warehouse->id, 5);
        $variant->update(['price' => $price]);

        test()->actingAs($csAgent, 'employee')->post(route('admin.orders.store'), [
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
        test()->actingAs($checker, 'employee')->post(route('admin.checking.confirm', $order))->assertRedirect();
        test()->actingAs($deliveryManager, 'employee')
            ->post(route('admin.delivery.assign', $order), [
                'assignment_type' => 'representative', 'assignee_id' => $ahmed->id,
            ])->assertRedirect();

        if ($handover) {
            test()->actingAs($accountant, 'employee')->post(route('admin.accounting.handover', $order))->assertRedirect();
        }

        $orders[] = $order->fresh();
    }

    return ['orders' => $orders, 'accountant' => $accountant, 'ahmed' => $ahmed, 'sara' => $sara];
}

/**
 * @param  list<Order>  $orders
 */
function p4CollectFromCourier(Employee $accountant, Treasury $treasury, array $orders, float $amount): TestResponse
{
    return test()->actingAs($accountant, 'employee')->post(route('admin.accounting.collect-from-courier'), [
        'order_ids' => array_map(fn (Order $order) => $order->id, $orders),
        'amount' => $amount,
        'treasury_id' => $treasury->id,
        'collected_method' => 'cash',
    ]);
}

it('splits what a courier hands over oldest order first, and sends the orders out rather than delivering them', function () {
    // Straight from "Awaiting handover": the courier pays as they take
    // the goods.
    ['orders' => [$first, $second, $third], 'accountant' => $accountant] = p4CourierOrders([1600, 1800, 600]);
    $treasury = Treasury::create(['name' => 'Main Cash', 'type' => 'cash', 'current_balance' => 0]);

    expect($first->status->value)->toBe('Assigned')
        ->and($first->stillOwed() + $second->stillOwed() + $third->stillOwed())->toBe(4000.0);

    // Posted out of order on purpose: the split follows the orders' age,
    // not the order they were ticked in.
    p4CollectFromCourier($accountant, $treasury, [$third, $first, $second], 1800)->assertSessionHas('success');

    [$first, $second, $third] = [$first->fresh(), $second->fresh(), $third->fresh()];

    // Out for Delivery, not Delivered — collecting is not delivering.
    expect([$first->status->value, $second->status->value, $third->status->value])
        ->toBe(['Out for Delivery', 'Out for Delivery', 'Out for Delivery'])
        // First paid in full, second gets the 200 left over, third nothing.
        ->and($first->payment_status)->toBe(PaymentStatus::Collected)
        ->and($second->payment_status)->toBe(PaymentStatus::PartiallyCollected)
        ->and([$first->stillOwed(), $second->stillOwed(), $third->stillOwed()])->toBe([0.0, 1600.0, 600.0])
        ->and((float) $treasury->fresh()->current_balance)->toBe(1800.0);

    // No stock moved: it deducts on Delivered and nowhere else.
    $variantId = $first->items()->value('product_variant_id');
    expect((int) WarehouseInventory::where('product_variant_id', $variantId)->value('quantity'))->toBe(5);

    // The courier comes back with 2000 of the 2200: the older order
    // clears, the newer one is left 200 short.
    p4CollectFromCourier($accountant, $treasury, [$second, $third], 2000)->assertSessionHas('success');

    expect($second->fresh()->payment_status)->toBe(PaymentStatus::Collected)
        ->and($third->fresh()->stillOwed())->toBe(200.0)
        ->and((float) $treasury->fresh()->current_balance)->toBe(3800.0);
});

it('banks only the rest when a prepaid order is later confirmed delivered', function () {
    ['orders' => [$first, $second], 'accountant' => $accountant] = p4CourierOrders([500, 700]);
    $treasury = Treasury::create(['name' => 'Main Cash', 'type' => 'cash', 'current_balance' => 0]);

    // 900 up front: 500 on the first, 400 on the second.
    p4CollectFromCourier($accountant, $treasury, [$first, $second], 900)->assertSessionHas('success');

    // Delivered with the default amount — which must be the 300 left,
    // not the whole 700 again.
    $this->actingAs($accountant, 'employee')->post(route('admin.accounting.settle.bulk'), [
        'order_ids' => [$first->id, $second->id],
        'treasury_id' => $treasury->id,
        'collected_method' => 'cash',
    ])->assertSessionHas('success');

    expect($first->fresh()->status->value)->toBe('Delivered')
        ->and($second->fresh()->status->value)->toBe('Delivered')
        ->and($second->fresh()->payment_status)->toBe(PaymentStatus::Collected)
        ->and((float) $treasury->fresh()->current_balance)->toBe(1200.0);

    // Stock deducts now, at Delivered.
    $variantId = $first->items()->value('product_variant_id');
    expect((int) WarehouseInventory::where('product_variant_id', $variantId)->value('quantity'))->toBe(4);
});

it('refuses a delivery amount that would collect a prepaid order twice', function () {
    ['orders' => [$order], 'accountant' => $accountant] = p4CourierOrders([500]);
    $treasury = Treasury::create(['name' => 'Main Cash', 'type' => 'cash', 'current_balance' => 0]);

    p4CollectFromCourier($accountant, $treasury, [$order], 200)->assertSessionHas('success');

    // 300 is what's left; typing the full 500 again is refused.
    $this->actingAs($accountant, 'employee')->post(route('admin.accounting.delivered', $order), [
        'treasury_id' => $treasury->id,
        'collected_method' => 'cash',
        'collected_amount' => 500,
    ])->assertSessionHas('error');

    expect($order->fresh()->status->value)->toBe('Out for Delivery')
        ->and((float) $treasury->fresh()->current_balance)->toBe(200.0);
});

it('hands the prepayment back to the courier when the customer refuses the order', function () {
    ['orders' => [$order], 'accountant' => $accountant] = p4CourierOrders([500]);
    $treasury = Treasury::create(['name' => 'Main Cash', 'type' => 'cash', 'current_balance' => 0]);

    p4CollectFromCourier($accountant, $treasury, [$order], 500)->assertSessionHas('success');
    expect((float) $treasury->fresh()->current_balance)->toBe(500.0);

    $this->actingAs($accountant, 'employee')->post(route('admin.accounting.returned', $order))->assertRedirect();

    $payment = $order->fresh()->payments()->latest('id')->first();
    expect($order->fresh()->status->value)->toBe('Returned')
        ->and((float) $payment->collected_amount)->toBe(0.0)
        ->and((float) $treasury->fresh()->current_balance)->toBe(0.0)
        // Both movements stay on the payment's history.
        ->and($payment->transactions()->pluck('amount')->map(fn ($a) => (float) $a)->all())->toBe([500.0, -500.0]);
});

it('hands back only the returned goods\' share of a prepayment on a partial return', function () {
    ['orders' => [$order], 'accountant' => $accountant] = p4CourierOrders([500]);
    $treasury = Treasury::create(['name' => 'Main Cash', 'type' => 'cash', 'current_balance' => 0]);

    p4CollectFromCourier($accountant, $treasury, [$order], 500)->assertSessionHas('success');

    // The customer keeps nothing of value but the delivery: the kept
    // goods owe 0 net, so the whole 500 goes back to the courier.
    $itemId = $order->items()->value('id');
    $this->actingAs($accountant, 'employee')->post(route('admin.accounting.partially-returned', $order), [
        'treasury_id' => $treasury->id,
        'collected_method' => 'cash',
        'collected_amount' => 0,
        'kept_quantities' => [$itemId => 0],
    ])->assertRedirect();

    expect((float) $treasury->fresh()->current_balance)->toBe(0.0)
        ->and($order->fresh()->payment_status)->toBe(PaymentStatus::Collected);
});

it('prepays the same way on orders that were already signed out', function () {
    ['orders' => [$first, $second], 'accountant' => $accountant] = p4CourierOrders([500, 700], handover: true);
    $treasury = Treasury::create(['name' => 'Main Cash', 'type' => 'cash', 'current_balance' => 0]);

    expect($first->status->value)->toBe('Out for Delivery');

    p4CollectFromCourier($accountant, $treasury, [$first, $second], 900)->assertSessionHas('success');

    expect($first->fresh()->status->value)->toBe('Out for Delivery')
        ->and($first->fresh()->stillOwed())->toBe(0.0)
        ->and($second->fresh()->stillOwed())->toBe(300.0);
});

it('refuses to split one sum across two couriers, and changes nothing', function () {
    ['orders' => [$first, $second], 'accountant' => $accountant, 'sara' => $sara] = p4CourierOrders([500, 700]);
    $second->update(['delivery_representative_id' => $sara->id]);
    $treasury = Treasury::create(['name' => 'Main Cash', 'type' => 'cash', 'current_balance' => 0]);

    p4CollectFromCourier($accountant, $treasury, [$first, $second], 500)->assertSessionHas('error');

    expect($first->fresh()->status->value)->toBe('Assigned')
        ->and((float) $treasury->fresh()->current_balance)->toBe(0.0);
});

it('refuses more than the courier owes, and rolls back every order in the batch', function () {
    ['orders' => [$first, $second], 'accountant' => $accountant] = p4CourierOrders([500, 700]);
    $treasury = Treasury::create(['name' => 'Main Cash', 'type' => 'cash', 'current_balance' => 0]);

    p4CollectFromCourier($accountant, $treasury, [$first, $second], 1300)->assertSessionHas('error');

    expect($first->fresh()->status->value)->toBe('Assigned')
        ->and($second->fresh()->status->value)->toBe('Assigned')
        ->and((float) $treasury->fresh()->current_balance)->toBe(0.0);
});

it('totals what each courier still owes, split between goods on the road and delivered', function () {
    ['orders' => [$first, $second, $third], 'accountant' => $accountant, 'ahmed' => $ahmed, 'sara' => $sara] = p4CourierOrders([1600, 1800, 600]);
    $third->update(['delivery_representative_id' => $sara->id]);
    $treasury = Treasury::create(['name' => 'Main Cash', 'type' => 'cash', 'current_balance' => 0]);

    p4CollectFromCourier($accountant, $treasury, [$first, $second], 2000)->assertSessionHas('success'); // Ahmed: 3400 taken, 1400 left
    p4CollectFromCourier($accountant, $treasury, [$third], 100)->assertSessionHas('success');           // Sara: 600 taken, 500 left

    // Sara's order is delivered and she still owes the 500 on it.
    $this->actingAs($accountant, 'employee')->post(route('admin.accounting.delivered', $third), [
        'treasury_id' => $treasury->id,
        'collected_method' => 'cash',
        'collected_amount' => 0,
    ])->assertRedirect();

    $this->actingAs($accountant, 'employee')
        ->get(route('admin.accounting.index'))
        ->assertInertia(fn ($page) => $page
            ->where('courierBalances', fn ($rows) => collect($rows)
                ->map(fn ($row) => [$row['key'], $row['orders'], (float) $row['with_courier'], (float) $row['delivered'], (float) $row['owed']])
                ->all() === [
                    ['representative:'.$ahmed->id, 1, 1400.0, 0.0, 1400.0],
                    ['representative:'.$sara->id, 1, 0.0, 500.0, 500.0],
                ])
            ->etc());

    // And the balance queue narrows to one courier like the others do.
    $this->actingAs($accountant, 'employee')
        ->get(route('admin.accounting.index', ['representative_id' => $sara->id]))
        ->assertInertia(fn ($page) => $page
            ->where('outstanding.data', fn ($rows) => count($rows) === 1
                && $rows[0]['id'] === $third->id
                && (float) $rows[0]['still_owed'] === 500.0)
            ->etc());
});

it('shows today\'s orders by default, and every day\'s on "all dates"', function () {
    ['orders' => [$old, $new], 'accountant' => $accountant] = p4CourierOrders([500, 700]);
    $old->forceFill(['created_at' => now()->subDays(3)])->save();

    $this->actingAs($accountant, 'employee')
        ->get(route('admin.accounting.index'))
        ->assertInertia(fn ($page) => $page
            ->where('dates.date_from', now()->toDateString())
            ->where('awaitingHandover.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$new->id])
            ->etc());

    $this->actingAs($accountant, 'employee')
        ->get(route('admin.accounting.index', ['date_from' => '']))
        ->assertInertia(fn ($page) => $page
            ->where('awaitingHandover.data', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all() === [$old->id, $new->id])
            ->etc());
});

it('refuses to settle an order the employee cannot see', function () {
    [$first, , , $accountant] = p4Round();
    $treasury = Treasury::create(['name' => 'Main Cash', 'type' => 'cash', 'current_balance' => 0]);

    // A Customer Service agent sees only orders they created. Posting
    // another agent's order id must not settle it.
    [$otherAgent] = p4Employee('Customer Service');
    $otherAgent->givePermissionTo('accounting.collect');

    $this->actingAs($otherAgent, 'employee')->post(route('admin.accounting.settle.bulk'), [
        'order_ids' => [$first->id],
        'treasury_id' => $treasury->id,
        'collected_method' => 'cash',
    ])->assertRedirect();

    expect($first->fresh()->status->value)->toBe('Out for Delivery')
        ->and((float) $treasury->fresh()->current_balance)->toBe(0.0);
});

// D3 — change who is carrying an order, or collecting a return
// (feature-backlog-plan.md). Neither was possible before: the assign
// action hard-required Confirmed, and returns named nobody at all.

it('moves an order already on the road to a different courier, keeping the trail', function () {
    [$order, $accountant] = p4Assigned();
    [$deliveryManager] = p4Employee('Delivery Manager');

    $first = DeliveryRepresentative::firstOrFail();
    $second = DeliveryRepresentative::create(['name' => 'Sara', 'phone' => '01112345678']);

    expect($order->delivery_representative_id)->toBe($first->id);

    $this->actingAs($deliveryManager, 'employee')->post(route('admin.delivery.reassign', $order), [
        'assignment_type' => 'representative',
        'assignee_id' => $second->id,
        'notes' => 'Ahmed called in sick',
    ])->assertRedirect()->assertSessionHas('success');

    $order->refresh();
    expect($order->delivery_representative_id)->toBe($second->id)
        // The status does not move — it was out with a courier before and
        // still is.
        ->and($order->status->value)->toBe('Assigned')
        // Both assignments survive: who had it yesterday is still
        // answerable, which is the point when a parcel goes missing.
        ->and($order->deliveryAssignments()->count())->toBe(2)
        ->and($order->deliveryAssignments()->latest('id')->firstOrFail()->notes)->toBe('Ahmed called in sick');
});

it('reassigns an order that is already out for delivery, not just assigned', function () {
    [$order, $accountant] = p4Assigned();
    [$deliveryManager] = p4Employee('Delivery Manager');
    $second = DeliveryRepresentative::create(['name' => 'Sara', 'phone' => '01112345678']);

    $this->actingAs($accountant, 'employee')->post(route('admin.accounting.handover', $order))->assertRedirect();
    expect($order->fresh()->status->value)->toBe('Out for Delivery');

    $this->actingAs($deliveryManager, 'employee')->post(route('admin.delivery.reassign', $order), [
        'assignment_type' => 'representative',
        'assignee_id' => $second->id,
    ])->assertSessionHas('success');

    expect($order->fresh()->delivery_representative_id)->toBe($second->id)
        ->and($order->fresh()->status->value)->toBe('Out for Delivery');
});

it('refuses to reassign an order to the courier already carrying it', function () {
    [$order] = p4Assigned();
    [$deliveryManager] = p4Employee('Delivery Manager');
    $current = DeliveryRepresentative::firstOrFail();

    // A no-op that would otherwise add a meaningless row to the trail.
    $this->actingAs($deliveryManager, 'employee')->post(route('admin.delivery.reassign', $order), [
        'assignment_type' => 'representative',
        'assignee_id' => $current->id,
    ])->assertSessionHas('error');

    expect($order->fresh()->deliveryAssignments()->count())->toBe(1);
});

it('refuses to reassign an order that is not out with anyone', function () {
    $geo = p4Geo();
    $warehouse = Warehouse::create(['name' => 'Main', 'address' => 'Cairo', 'phone' => '01012345678']);
    ShippingRate::create(['geo_type' => 'governorate', 'geo_id' => $geo['governorate']->id, 'price' => 30]);
    $variant = p4Variant($warehouse->id);
    $customer = p4Customer();
    [$csAgent] = p4Employee('Customer Service');
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

    // Still New — nobody has ever carried it.
    $order = Order::latest('id')->firstOrFail();
    $rep = DeliveryRepresentative::create(['name' => 'Sara', 'phone' => '01112345678']);

    $this->actingAs($deliveryManager, 'employee')->post(route('admin.delivery.reassign', $order), [
        'assignment_type' => 'representative',
        'assignee_id' => $rep->id,
    ])->assertSessionHas('error');

    expect($order->fresh()->delivery_representative_id)->toBeNull();
});

// F1 — pick a product by colour and size (feature-backlog-plan.md).
// The screen used to serialise every variant in the catalogue into a flat
// dropdown labelled "<product> — <sku>"; agents know the product and the
// colour, not the SKU.

/**
 * A product with two colours in one size, so the picker has something to
 * resolve against.
 */
function p4ColouredProduct(int $warehouseId): Product
{
    $product = Product::create([
        'name' => ['ar' => 'قميص', 'en' => 'Oxford Shirt'],
        'slug' => 'oxford-'.uniqid(), 'sku' => 'OX-'.strtoupper(uniqid()),
        'price' => 250, 'status' => true,
    ]);

    $colour = Attribute::create(['name' => ['ar' => 'اللون', 'en' => 'Color'], 'sort_order' => 1]);
    $red = AttributeValue::create([
        'attribute_id' => $colour->id, 'value' => ['ar' => 'أحمر', 'en' => 'Red'], 'color_hex' => '#ff0000',
    ]);
    $blue = AttributeValue::create([
        'attribute_id' => $colour->id, 'value' => ['ar' => 'أزرق', 'en' => 'Blue'], 'color_hex' => '#0000ff',
    ]);

    foreach ([$red, $blue] as $value) {
        $variant = ProductVariant::create([
            'product_id' => $product->id, 'sku' => 'OX-'.strtoupper(uniqid()).'-V', 'status' => true,
        ]);
        $variant->attributeValues()->attach($value->id);
        WarehouseInventory::create([
            'warehouse_id' => $warehouseId, 'product_variant_id' => $variant->id,
            'quantity' => 7, 'reserved_quantity' => 2,
        ]);
    }

    return $product;
}

it('searches products and returns their colours, sizes and per-variant stock', function () {
    $warehouse = Warehouse::create(['name' => 'Main', 'address' => 'Cairo', 'phone' => '01012345678']);
    p4ColouredProduct($warehouse->id);
    [$agent] = p4Employee('Customer Service');

    $this->actingAs($agent, 'employee')->withLocale('en')
        ->getJson(route('admin.orders.product-search', ['q' => 'Oxford']))
        ->assertOk()
        ->assertJsonPath('products.0.name', 'Oxford Shirt')
        ->assertJsonPath('products.0.colors.0.name', 'Red')
        ->assertJsonPath('products.0.colors.1.name', 'Blue')
        // Hex rides along so the picker can render a real swatch.
        ->assertJsonPath('products.0.colors.0.hex', '#ff0000')
        // available = quantity - reserved, which is what an agent needs to
        // know before promising it on the phone.
        ->assertJsonPath('products.0.variants.0.available', 5)
        ->assertJsonPath('products.0.variants.0.options.0.attribute', 'color');
});

it('lets Customer Service search products without any catalogue permission', function () {
    $warehouse = Warehouse::create(['name' => 'Main', 'address' => 'Cairo', 'phone' => '01012345678']);
    p4ColouredProduct($warehouse->id);
    [$agent] = p4Employee('Customer Service');

    // The whole point of gating this on orders.create: an agent takes
    // orders all day and holds no products.* permission at all.
    expect($agent->can('products.view'))->toBeFalse();

    $this->actingAs($agent, 'employee')
        ->getJson(route('admin.orders.product-search', ['q' => 'Oxford']))
        ->assertOk();
});

it('keeps the product search behind orders.create', function () {
    [$checker] = p4Employee('Checking'); // orders.view, not orders.create

    $this->actingAs($checker, 'employee')
        ->getJson(route('admin.orders.product-search', ['q' => 'Oxford']))
        ->assertForbidden();
});

it('no longer ships the whole catalogue into the order-create page', function () {
    $warehouse = Warehouse::create(['name' => 'Main', 'address' => 'Cairo', 'phone' => '01012345678']);
    p4ColouredProduct($warehouse->id);
    [$agent] = p4Employee('Customer Service');

    $this->actingAs($agent, 'employee')->get(route('admin.orders.create'))
        ->assertInertia(fn ($page) => $page
            ->component('Orders/Create')
            ->missing('variants')
            ->etc());
});

it('omits a soft-deleted product from the picker', function () {
    $warehouse = Warehouse::create(['name' => 'Main', 'address' => 'Cairo', 'phone' => '01012345678']);
    $product = p4ColouredProduct($warehouse->id);
    [$agent] = p4Employee('Customer Service');

    $product->delete();

    // A deleted product cannot be sold, so it must not be offerable — the
    // old flat list guarded this with whereHas('product').
    $this->actingAs($agent, 'employee')
        ->getJson(route('admin.orders.product-search', ['q' => 'Oxford']))
        ->assertOk()
        ->assertJsonCount(0, 'products');
});

// F2 — the stock check gates Confirm (feature-backlog-plan.md).
// Checking stays read-only: this is only the gate, no route edits an
// order's items.

/**
 * A New order sitting in the Checking queue, ordering 2 units.
 *
 * @return array{0: Order, 1: Employee, 2: ProductVariant, 3: Warehouse}
 */
function p4NewOrder(int $stock = 2, bool $tracked = true): array
{
    $geo = p4Geo();
    $warehouse = Warehouse::create(['name' => 'Main', 'address' => 'Cairo', 'phone' => '01012345678']);
    ShippingRate::create(['geo_type' => 'governorate', 'geo_id' => $geo['governorate']->id, 'price' => 30]);

    if ($tracked) {
        $variant = p4Variant($warehouse->id, $stock);
    } else {
        // An Advertisement product carries no inventory row at all, so
        // CreateOrderAction's bypass leaves it unreserved (Q14).
        $product = Product::create([
            'name' => ['ar' => 'إعلان', 'en' => 'Advertisement Widget'],
            'slug' => 'ad-'.uniqid(), 'sku' => 'AD-'.uniqid(),
            'price' => 100, 'inventory_tracking_enabled' => false,
        ]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'AD-'.uniqid().'-V']);
    }

    $customer = p4Customer();
    [$csAgent] = p4Employee('Customer Service');
    [$checker] = p4Employee('Checking');

    test()->actingAs($csAgent, 'employee')->post(route('admin.orders.store'), [
        'customer_id' => $customer->id,
        'items' => [['product_variant_id' => $variant->id, 'quantity' => 2]],
        'governorate_id' => $geo['governorate']->id,
        'city_id' => $geo['city']->id,
        'area_id' => $geo['area']->id,
        'address_line' => '1 Test St',
        'recipient_name' => $customer->name,
        'phone' => $customer->phone,
    ])->assertRedirect();

    return [Order::latest('id')->firstOrFail(), $checker, $variant, $warehouse];
}

it('keeps Confirm available when the order is the only thing holding the stock', function () {
    // The trap this guards: stock 2, order 2, so quantity minus
    // reserved_quantity is 0. Reading that bare number as "available"
    // would flag every healthy order in the queue as short and lock
    // Confirm across the whole department.
    [$order, $checker] = p4NewOrder(stock: 2);

    $this->actingAs($checker, 'employee')->get(route('admin.checking.show', $order))
        ->assertInertia(fn ($page) => $page
            ->where('stock.can_confirm', true)
            ->where('stock.items.0.required', 2)
            ->where('stock.items.0.available', 0)
            ->where('stock.items.0.reserved', 2)
            ->etc());

    $this->actingAs($checker, 'employee')->post(route('admin.checking.confirm', $order))
        ->assertRedirect()
        ->assertSessionHas('success');
    expect($order->fresh()->status->value)->toBe('Confirmed');
});

it('blocks Confirm when a tracked line has no reservation and no stock behind it', function () {
    [$order, $checker, $variant] = p4NewOrder(tracked: false);

    // Converted Advertisement → Real while the order sat in the queue.
    // The line now reads tracked, but nothing was ever reserved for it
    // and there is no inventory row to reserve from.
    $variant->product->update(['inventory_tracking_enabled' => true]);

    $this->actingAs($checker, 'employee')->get(route('admin.checking.show', $order))
        ->assertInertia(fn ($page) => $page
            ->where('stock.can_confirm', false)
            ->where('stock.items.0.reserved', 0)
            ->etc());

    $this->actingAs($checker, 'employee')->post(route('admin.checking.confirm', $order))
        ->assertRedirect()
        ->assertSessionHas('error');
    expect($order->fresh()->status->value)->toBe('New');
});

it('blocks Confirm on an Advertisement line until the product is real stock', function () {
    // An Advertisement product has no stock behind it by design, so
    // Checking stops the order here rather than confirming it and leaving
    // Backorder to catch it later.
    [$order, $checker] = p4NewOrder(tracked: false);

    $this->actingAs($checker, 'employee')->get(route('admin.checking.show', $order))
        ->assertInertia(fn ($page) => $page
            ->where('stock.can_confirm', false)
            ->where('stock.items.0.tracked', false)
            ->etc());

    $this->actingAs($checker, 'employee')->post(route('admin.checking.confirm', $order))
        ->assertRedirect()
        ->assertSessionHas('error');
    expect($order->fresh()->status->value)->not->toBe('Confirmed');
});

it('refuses a Confirm posted after the shelf was emptied', function () {
    [$order, $checker, $variant] = p4NewOrder(stock: 2);

    // The page said yes and someone cleared the shelf before the click.
    // InventoryService::adjust() refuses to cut into a reservation, so
    // this is the blunt path — a correction straight at the row.
    WarehouseInventory::where('product_variant_id', $variant->id)
        ->update(['quantity' => 0, 'reserved_quantity' => 0]);

    $this->actingAs($checker, 'employee')->post(route('admin.checking.confirm', $order))
        ->assertRedirect()
        ->assertSessionHas('error');
    expect($order->fresh()->status->value)->toBe('New');
});

it('still measures Resume against free stock alone, not the order hold', function () {
    // Resume reserves every line from scratch, so a hold it already has
    // is not stock it can take again — can_resume must keep reading the
    // bare available figure that can_confirm deliberately does not.
    [$order, $checker, $variant] = p4NewOrder(tracked: false);

    $this->actingAs($checker, 'employee')->post(route('admin.checking.confirm', $order))->assertRedirect();
    $this->actingAs($checker, 'employee')
        ->post(route('admin.checking.backorder', $order), ['reason' => 'Advertisement item unavailable'])
        ->assertRedirect();

    $variant->product->update(['inventory_tracking_enabled' => true]);
    WarehouseInventory::create([
        'warehouse_id' => Warehouse::main()->id, 'product_variant_id' => $variant->id,
        'quantity' => 1, 'reserved_quantity' => 0,
    ]);

    $this->actingAs($checker, 'employee')->get(route('admin.checking.show', $order))
        ->assertInertia(fn ($page) => $page->where('stock.can_resume', false)->etc());
});

// H2 — tick rows, then export or print just those
// (feature-backlog-plan.md, request #19).
//
// `ids` is one more filter on the same scoped query everything else
// reads, so a selection can only ever narrow what the employee could
// already see — there is no per-row guard to forget.

/**
 * An order placed by a specific agent, so Customer Service scoping has
 * something to separate.
 */
function p4OrderBy(Employee $agent, array $geo, int $warehouseId, ProductVariant $variant): Order
{
    test()->actingAs($agent, 'employee')->post(route('admin.orders.store'), [
        'customer_id' => p4Customer()->id,
        'warehouse_id' => $warehouseId,
        'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
        'governorate_id' => $geo['governorate']->id,
        'city_id' => $geo['city']->id,
        'area_id' => $geo['area']->id,
        'address_line' => '1 Test St',
        'recipient_name' => 'Recipient',
        'phone' => '01012345678',
    ])->assertRedirect();

    return Order::latest('id')->firstOrFail();
}

/**
 * @return array{0: Employee, 1: Order, 2: Order}
 */
function p4TwoOrders(): array
{
    $geo = p4Geo();
    $warehouse = Warehouse::create(['name' => 'Main', 'address' => 'Cairo', 'phone' => '01012345678']);
    ShippingRate::create(['geo_type' => 'governorate', 'geo_id' => $geo['governorate']->id, 'price' => 30]);
    $variant = p4Variant($warehouse->id, 50);
    [$agent] = p4Employee('Customer Service');

    return [
        $agent,
        p4OrderBy($agent, $geo, $warehouse->id, $variant),
        p4OrderBy($agent, $geo, $warehouse->id, $variant),
    ];
}

it('prints only the orders that were ticked', function () {
    [$agent, $first, $second] = p4TwoOrders();

    $this->actingAs($agent, 'employee')
        ->get(route('admin.orders.invoices', ['ids' => [$first->id]]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Orders/Invoices')
            ->count('orders', 1)
            ->where('orders.0.id', $first->id)
            ->etc());

    expect($second->id)->not->toBe($first->id);
});

it('refuses a batch print with nothing selected', function () {
    [$agent] = p4TwoOrders();

    // Not an empty print job: with no ids the filters alone would match
    // the whole order book, which is a very different page than the one
    // that was asked for.
    $this->actingAs($agent, 'employee')
        ->get(route('admin.orders.invoices'))
        ->assertNotFound();
});

it('cannot print an order the employee could not open anyway', function () {
    [, $mine] = p4TwoOrders();
    [$other] = p4Employee('Customer Service');

    // A hand-typed id from outside the agent's own book. visibleTo() runs
    // first, so the id narrows nothing into existence.
    $this->actingAs($other, 'employee')
        ->get(route('admin.orders.invoices', ['ids' => [$mine->id]]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->count('orders', 0)->etc());
});

it('exports only the ticked rows, still inside the employee scope', function () {
    [$agent, $first] = p4TwoOrders();

    // Asserted against the export's own query rather than the .xlsx
    // bytes: the download is generated from exactly this.
    $rows = (new OrdersExport($agent, ['ids' => [$first->id]]))->query()->get();

    expect($rows->pluck('id')->all())->toBe([$first->id]);

    [$other] = p4Employee('Customer Service');
    expect((new OrdersExport($other, ['ids' => [$first->id]]))->query()->count())->toBe(0);
});

it('keeps a selection out of the order book itself', function () {
    [$agent, $first] = p4TwoOrders();

    // The listing echoes its filters back to the page, so an `ids` that
    // survived into them would pin the table to that selection — and
    // keep it pinned through the next filter change.
    $this->actingAs($agent, 'employee')
        ->get(route('admin.orders.index', ['ids' => [$first->id]]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Orders/Index')
            ->count('orders.data', 2)
            ->missing('filters.ids')
            ->etc());
});

it('prints courier labels for the ticked orders, each with its own cash figure', function () {
    [$agent, $first, $second] = p4TwoOrders();

    // One already settled, one still owing — the label must not show a
    // number that could get collected twice.
    $first->update(['payment_status' => PaymentStatus::Collected]);

    $this->actingAs($agent, 'employee')
        ->get(route('admin.orders.labels', ['ids' => [$first->id, $second->id]]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Orders/Labels')
            ->count('labels', 2)
            ->where('labels.0.cod_amount', null)
            // Compared numerically: JSON drops the trailing .0, so a
            // strict match against a float fails on an exact amount.
            ->where('labels.1.cod_amount', fn ($cod) => (float) $cod === (float) $second->total)
            ->etc());
});

it('refuses a batch label print with nothing selected', function () {
    [$agent] = p4TwoOrders();

    $this->actingAs($agent, 'employee')
        ->get(route('admin.orders.labels'))
        ->assertNotFound();
});

it('cannot label an order the employee could not open anyway', function () {
    [, $mine] = p4TwoOrders();
    [$other] = p4Employee('Customer Service');

    $this->actingAs($other, 'employee')
        ->get(route('admin.orders.labels', ['ids' => [$mine->id]]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->count('labels', 0)->etc());
});
