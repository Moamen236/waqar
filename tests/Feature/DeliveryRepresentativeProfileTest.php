<?php

use App\Models\Area;
use App\Models\City;
use App\Models\Country;
use App\Models\Customer;
use App\Models\DeliveryRepresentative;
use App\Models\DeliveryRepresentativeArea;
use App\Models\District;
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

// Delivery representative profile (/admin/delivery/representatives/{representative}).
// Helper names use the drp* prefix: Pest loads every Feature test file into
// the same global function namespace, so these must not collide with the
// p4* helpers in Phase4AdminOperationsTest.php.

function drpGeo(): array
{
    $country = Country::create(['name' => ['ar' => 'مصر', 'en' => 'Egypt'], 'code' => 'EG']);
    $governorate = Governorate::create(['country_id' => $country->id, 'name' => ['ar' => 'القاهرة', 'en' => 'Cairo']]);
    $city = City::create(['governorate_id' => $governorate->id, 'name' => ['ar' => 'مدينة نصر', 'en' => 'Nasr City']]);
    $district = District::create(['city_id' => $city->id, 'name' => ['ar' => 'حي أ', 'en' => 'District A']]);
    $area = Area::create(['city_id' => $city->id, 'district_id' => $district->id, 'name' => ['ar' => 'منطقة أ', 'en' => 'Zone A']]);

    return compact('country', 'governorate', 'city', 'district', 'area');
}

function drpEmployee(string $role): Employee
{
    $employee = Employee::create([
        'full_name' => $role.' User', 'email' => 'e-'.uniqid().'@waqar.test', 'phone' => '01012345678',
        'password' => 'password', 'residence_address' => 'N/A', 'national_id_number' => 'N/A',
    ]);
    $employee->assignRole(Role::findOrCreate($role, 'employee'));

    return $employee;
}

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
});

it('shows a delivery representative profile with orders, coverage areas and the still-owed balance', function () {
    $geo = drpGeo();
    $warehouse = Warehouse::create(['name' => 'Main', 'address' => 'Cairo', 'phone' => '01012345678']);
    ShippingRate::create(['geo_type' => 'governorate', 'geo_id' => $geo['governorate']->id, 'price' => 30]);

    $product = Product::create(['name' => ['ar' => 'منتج', 'en' => 'Widget'], 'slug' => 'widget-'.uniqid(), 'sku' => 'W-'.uniqid(), 'price' => 100]);
    $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'W-'.uniqid().'-V']);
    WarehouseInventory::create(['warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id, 'quantity' => 10, 'reserved_quantity' => 0]);

    $customer = Customer::create(['name' => 'Test Customer', 'email' => 'c-'.uniqid().'@waqar.test', 'phone' => '01012345678', 'password' => 'password']);

    $csAgent = drpEmployee('Customer Service');
    $checker = drpEmployee('Checking');
    $deliveryManager = drpEmployee('Delivery Manager');
    $accountant = drpEmployee('Accounting');

    $rep = DeliveryRepresentative::create(['name' => 'Ahmed', 'phone' => '01012345678', 'status' => 'active']);
    DeliveryRepresentativeArea::create([
        'delivery_representative_id' => $rep->id, 'geo_type' => 'governorate', 'geo_id' => $geo['governorate']->id,
    ]);

    // Place, confirm and assign the order to the representative.
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

    $this->actingAs($checker, 'employee')->post(route('admin.checking.confirm', $order))->assertRedirect();
    $this->actingAs($deliveryManager, 'employee')
        ->post(route('admin.delivery.assign', $order), ['assignment_type' => 'representative', 'assignee_id' => $rep->id])
        ->assertRedirect();

    // Deliver short: 100 goods money owed net of the 30 shipping the
    // courier keeps, only 60 handed over — 40 stays out with the courier.
    $treasury = Treasury::create(['name' => 'Main Cash', 'type' => 'cash', 'current_balance' => 0]);
    $this->actingAs($accountant, 'employee')
        ->post(route('admin.accounting.delivered', $order), [
            'treasury_id' => $treasury->id,
            'collected_method' => 'cash',
            'collected_amount' => 60,
        ])
        ->assertRedirect();
    expect($order->fresh()->payment_status->value)->toBe('partially_collected');

    $this->actingAs($deliveryManager, 'employee')
        ->get(route('admin.delivery.representatives.show', $rep))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Delivery/Representatives/Show')
            ->where('representative.id', $rep->id)
            ->where('areas.0.geo_id', $geo['governorate']->id)
            ->where('stats.total_orders', 1)
            ->where('stats.delivered_orders', 1)
            ->where('stats.active_orders', 0)
            ->where('stats.outstanding_orders', 1)
            ->where('stats.outstanding_balance', 40)
            ->where('stats.collected_total', 0)
            ->where('orders.data.0.id', $order->id)
            ->where('orders.data.0.net_due', 100)
            ->where('orders.data.0.collected_amount', 60)
            ->where('orders.data.0.still_owed', 40)
            ->where('orders.data.0.is_delivered', true));
});

it('keeps representatives/create reachable and forbids the profile without the view permission', function () {
    $deliveryManager = drpEmployee('Delivery Manager');
    $checker = drpEmployee('Checking'); // has orders.view, not delivery.representatives.view
    $rep = DeliveryRepresentative::create(['name' => 'Ahmed', 'phone' => '01012345678', 'status' => 'active']);

    // The two-segment show route must not swallow /create (registered first wins).
    $this->actingAs($deliveryManager, 'employee')
        ->get(route('admin.delivery.representatives.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Delivery/Representatives/Form'));

    $this->actingAs($checker, 'employee')
        ->get(route('admin.delivery.representatives.show', $rep))
        ->assertForbidden();
});
