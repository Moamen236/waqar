<?php

use App\Actions\Checkout\CreateOrderAction;
use App\Actions\Orders\AssignDeliveryAction;
use App\Actions\Orders\ConfirmDeliveryResultAction;
use App\Actions\Orders\ConfirmOrderAction;
use App\Enums\CollectedMethod;
use App\Enums\DeliveryAssignmentType;
use App\Models\Area;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\City;
use App\Models\Country;
use App\Models\Customer;
use App\Models\DeliveryRepresentative;
use App\Models\Employee;
use App\Models\Governorate;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ReturnReason;
use App\Models\ShippingRate;
use App\Models\Treasury;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ReturnReasonSeeder;
use Database\Seeders\RoleSeeder;
use Spatie\Permission\Models\Role;

// Follow-up to Phase 4: Products/Categories admin CRUD and a dedicated
// Returns/Refunds admin screen — both flagged as gaps in
// PHASE-4-HANDOVER.md, requested directly rather than deferred further.
// Helpers below are deliberately self-contained (p4c* prefix) rather
// than reused from Phase4AdminOperationsTest.php's p4* helpers — those
// are only available when the whole suite runs together (every Feature
// file's top-level `function` declarations execute on require), not
// when this file is targeted on its own via --filter or a direct path,
// which breaks fast single-file iteration.

function p4cGeo(): array
{
    $country = Country::create(['name' => ['ar' => 'مصر', 'en' => 'Egypt'], 'code' => 'EG']);
    $governorate = Governorate::create(['country_id' => $country->id, 'name' => ['ar' => 'القاهرة', 'en' => 'Cairo']]);
    $city = City::create(['governorate_id' => $governorate->id, 'name' => ['ar' => 'مدينة نصر', 'en' => 'Nasr City']]);
    $area = Area::create(['city_id' => $city->id, 'name' => ['ar' => 'منطقة أ', 'en' => 'Zone A']]);

    return compact('country', 'governorate', 'city', 'area');
}

function p4cVariant(int $warehouseId, int $stock = 10): ProductVariant
{
    $product = Product::create(['name' => ['ar' => 'منتج', 'en' => 'Widget'], 'slug' => 'widget-'.uniqid(), 'sku' => 'W-'.uniqid(), 'price' => 100]);
    $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'W-'.uniqid().'-V']);
    WarehouseInventory::create(['warehouse_id' => $warehouseId, 'product_variant_id' => $variant->id, 'quantity' => $stock, 'reserved_quantity' => 0]);

    return $variant;
}

function p4cCustomer(): Customer
{
    return Customer::create(['name' => 'Test Customer', 'email' => 'c-'.uniqid().'@waqar.test', 'phone' => '1', 'password' => 'password']);
}

/**
 * @return array{0: Employee, 1: Role}
 */
function p4cEmployee(string $role): array
{
    $employee = Employee::create([
        'full_name' => $role.' User', 'email' => 'e-'.uniqid().'@waqar.test', 'phone' => '1',
        'password' => 'password', 'residence_address' => 'N/A', 'national_id_number' => 'N/A',
    ]);
    $roleModel = Role::findOrCreate($role, 'employee');
    $employee->assignRole($roleModel);

    return [$employee, $roleModel];
}

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
});

it('lets Vice Chairman create a product with variants, categories, and the type-derived inventory flag', function () {
    [$viceChairman] = p4cEmployee('Vice Chairman');
    $category = Category::create(['name' => ['en' => 'Shirts'], 'slug' => 'shirts']);

    $response = $this->actingAs($viceChairman, 'employee')->post(route('admin.products.store'), [
        'name' => ['en' => 'Test Shirt'],
        'sku' => 'TSHIRT-001',
        'price' => 199.99,
        'status' => true,
        'is_featured' => false,
        'is_new' => true,
        'is_on_sale' => false,
        'sort_order' => 0,
        'product_type' => 'real',
        'category_ids' => [$category->id],
        'variants' => [
            ['id' => null, 'sku' => 'TSHIRT-001-M', 'barcode' => null, 'price' => null, 'sale_price' => null, 'cost_price' => null, 'status' => true, 'attribute_value_ids' => []],
        ],
    ]);

    $response->assertRedirect();
    $product = Product::where('sku', 'TSHIRT-001')->firstOrFail();
    expect($product->product_type->value)->toBe('real')
        ->and($product->inventory_tracking_enabled)->toBeTrue() // derived, not client-supplied
        ->and($product->categories()->pluck('categories.id'))->toContain($category->id)
        ->and($product->variants()->count())->toBe(1);
});

it('deactivates rather than hard-deletes a variant that already has order history when removed from the form', function () {
    $geo = p4cGeo();
    $warehouse = Warehouse::create(['name' => 'Main', 'address' => 'Cairo', 'phone' => '1']);
    ShippingRate::create(['geo_type' => 'governorate', 'geo_id' => $geo['governorate']->id, 'price' => 0]);
    $orderedVariant = p4cVariant($warehouse->id);
    $product = $orderedVariant->product;
    // A second, untouched variant — kept in the form update so the
    // "at least one variant" rule is satisfied without that being what
    // this test is actually about.
    $keptVariant = ProductVariant::create(['product_id' => $product->id, 'sku' => $orderedVariant->sku.'-B']);
    $customer = p4cCustomer();
    [$viceChairman] = p4cEmployee('Vice Chairman');

    // Give the first variant order history — order_items.product_variant_id
    // has no cascade/null-on-delete, so a naive hard-delete would throw a
    // QueryException here instead of failing gracefully.
    app(CreateOrderAction::class)->execute(
        $customer, [['product_variant_id' => $orderedVariant->id, 'quantity' => 1]], $warehouse,
        $geo['governorate']->id, $geo['city']->id, null, $geo['area']->id,
        '1 Test St', $customer->name, $customer->phone,
    );

    $this->actingAs($viceChairman, 'employee')->put(route('admin.products.update', $product), [
        'name' => ['en' => $product->getTranslation('name', 'en')],
        'sku' => $product->sku,
        'price' => $product->price,
        'status' => true, 'is_featured' => false, 'is_new' => false, 'is_on_sale' => false, 'sort_order' => 0,
        'product_type' => 'real',
        // Only the untouched variant stays in the form — the ordered one
        // is dropped.
        'variants' => [
            ['id' => $keptVariant->id, 'sku' => $keptVariant->sku, 'barcode' => null, 'price' => null, 'sale_price' => null, 'cost_price' => null, 'status' => true, 'attribute_value_ids' => []],
        ],
    ])->assertRedirect();

    // Deactivated, not deleted — the row (and the order referencing it)
    // survives intact.
    expect(ProductVariant::find($orderedVariant->id))->not->toBeNull()
        ->and($orderedVariant->fresh()->status)->toBeFalse()
        ->and(Order::first()->items()->first()->product_variant_id)->toBe($orderedVariant->id);
});

it('never lets a client set inventory_tracking_enabled directly — it always follows product_type', function () {
    [$viceChairman] = p4cEmployee('Vice Chairman');

    // Advertisement product — even though the raw request body carries no
    // inventory_tracking_enabled key at all (it isn't a validated field),
    // the derived value must be false to match the Advertisement type.
    $this->actingAs($viceChairman, 'employee')->post(route('admin.products.store'), [
        'name' => ['en' => 'Preorder Item'],
        'sku' => 'PRE-001',
        'price' => 500,
        'status' => true, 'is_featured' => false, 'is_new' => false, 'is_on_sale' => false, 'sort_order' => 0,
        'product_type' => 'advertisement',
        'variants' => [
            ['id' => null, 'sku' => 'PRE-001-V', 'barcode' => null, 'price' => null, 'sale_price' => null, 'cost_price' => null, 'status' => true, 'attribute_value_ids' => []],
        ],
    ])->assertRedirect();

    expect(Product::where('sku', 'PRE-001')->firstOrFail()->inventory_tracking_enabled)->toBeFalse();
});

it('lets Vice Chairman manage categories with parent nesting and attributes with values', function () {
    [$viceChairman] = p4cEmployee('Vice Chairman');

    $this->actingAs($viceChairman, 'employee')
        ->post(route('admin.categories.store'), ['name' => ['en' => 'Clothing'], 'status' => true, 'sort_order' => 0])
        ->assertRedirect();
    $parent = Category::where('slug', 'clothing')->firstOrFail();

    $this->actingAs($viceChairman, 'employee')
        ->post(route('admin.categories.store'), ['parent_id' => $parent->id, 'name' => ['en' => 'Shirts'], 'status' => true, 'sort_order' => 0])
        ->assertRedirect();
    expect(Category::where('slug', 'shirts')->firstOrFail()->parent_id)->toBe($parent->id);

    $this->actingAs($viceChairman, 'employee')
        ->post(route('admin.attributes.store'), ['name' => ['en' => 'Color'], 'sort_order' => 0])
        ->assertRedirect();
    $attribute = Attribute::where('name->en', 'Color')->firstOrFail();

    $this->actingAs($viceChairman, 'employee')
        ->post(route('admin.attributes.values.store', $attribute), ['value' => ['en' => 'Red'], 'color_hex' => '#ff0000', 'sort_order' => 0])
        ->assertRedirect();
    expect($attribute->values()->count())->toBe(1);
});

it('blocks a Checking employee from the catalog screens (no products.view)', function () {
    [$checker] = p4cEmployee('Checking');

    $this->actingAs($checker, 'employee')->get(route('admin.products.index'))->assertForbidden();
});

it('walks a post-delivery return through the full admin workflow — approve, receive (restocks), refund', function () {
    $geo = p4cGeo();
    $warehouse = Warehouse::create(['name' => 'Main', 'address' => 'Cairo', 'phone' => '1']);
    ShippingRate::create(['geo_type' => 'governorate', 'geo_id' => $geo['governorate']->id, 'price' => 0]);
    $variant = p4cVariant($warehouse->id, stock: 5);
    $customer = p4cCustomer();
    $this->seed(ReturnReasonSeeder::class);
    $reason = ReturnReason::first();

    [$checker] = p4cEmployee('Checking');
    [$deliveryManager] = p4cEmployee('Delivery Manager');
    [$accountant] = p4cEmployee('Accounting');
    [$warehouseManager] = p4cEmployee('Warehouse Manager');

    // Get one order all the way to Delivered via the Actions directly
    // (already covered end-to-end via HTTP in Phase4AdminOperationsTest;
    // here it's just fixture setup for the return flow under test).
    $order = app(CreateOrderAction::class)->execute(
        $customer, [['product_variant_id' => $variant->id, 'quantity' => 1]], $warehouse,
        $geo['governorate']->id, $geo['city']->id, null, $geo['area']->id,
        '1 Test St', $customer->name, $customer->phone,
    );
    app(ConfirmOrderAction::class)->execute($order, $checker);
    $rep = DeliveryRepresentative::create(['name' => 'Ahmed', 'phone' => '1']);
    app(AssignDeliveryAction::class)->execute($order, $deliveryManager, DeliveryAssignmentType::Representative, $rep);
    $treasury = Treasury::create(['name' => 'Cash', 'type' => 'cash', 'current_balance' => 0]);
    app(ConfirmDeliveryResultAction::class)->confirmDelivered($order, $accountant, $treasury, CollectedMethod::Cash);

    $stockAfterDelivery = WarehouseInventory::where('product_variant_id', $variant->id)->first()->quantity;
    expect($stockAfterDelivery)->toBe(4); // 5 seeded - 1 delivered

    $orderItem = $order->items()->firstOrFail();

    // File the return on the customer's behalf (Customer Service, phone call).
    [$csAgent] = p4cEmployee('Customer Service');
    $this->actingAs($csAgent, 'employee')
        ->post(route('admin.returns.store'), [
            'order_id' => $order->id,
            'primary_reason_id' => $reason->id,
            'items' => [['order_item_id' => $orderItem->id, 'quantity' => 1]],
        ])
        ->assertRedirect();
    $return = OrderReturn::firstOrFail();
    expect($return->status->value)->toBe('requested')
        ->and($return->stage->value)->toBe('post_delivery');

    // Consent is required before approval, post-delivery (Question 6).
    $this->actingAs($warehouseManager, 'employee')
        ->post(route('admin.returns.approve', $return))
        ->assertStatus(500); // RuntimeException surfaces as a 500 — no consent recorded yet.

    $this->actingAs($csAgent, 'employee')
        ->post(route('admin.returns.accept-shipping-fee', $return), ['return_shipping_fee' => 10])
        ->assertRedirect();
    expect($return->fresh()->customer_accepted_return_shipping_fee_at)->not->toBeNull();

    $this->actingAs($warehouseManager, 'employee')
        ->post(route('admin.returns.approve', $return))
        ->assertRedirect();
    expect($return->fresh()->status->value)->toBe('approved');

    $this->actingAs($warehouseManager, 'employee')
        ->post(route('admin.returns.receive', $return), ['warehouse_id' => $warehouse->id])
        ->assertRedirect();
    $return->refresh();
    expect($return->status->value)->toBe('inspected')
        ->and(WarehouseInventory::where('product_variant_id', $variant->id)->first()->quantity)->toBe(5); // restocked

    $this->actingAs($accountant, 'employee')
        ->post(route('admin.returns.refund', $return), [
            'treasury_id' => $treasury->id, 'method' => 'bank_transfer', 'reference_number' => 'REF-1',
        ])
        ->assertRedirect();

    $return->refresh();
    expect($return->status->value)->toBe('refunded')
        ->and($return->refund->net_amount)->toEqual('90.00') // 100 item - 10 accepted shipping fee
        // Treasury: +100 from the original COD collection, then -90 for
        // this refund — 10.00 net, not a standalone -90.
        ->and($treasury->fresh()->current_balance)->toEqual('10.00');
});
