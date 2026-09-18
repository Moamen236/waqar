<?php

use App\Enums\CustomerOrderStatus;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\ReturnStage;
use App\Models\Area;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\City;
use App\Models\Collection as CollectionModel;
use App\Models\Country;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Governorate;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ReturnReason;
use App\Models\Warehouse;
use App\Notifications\Orders\ReturnRequestedNotification;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;

// Phase 7 addendum — the admin's translatable-name crash.
//
// `spatie/laravel-translatable` overrides `getAttributeValue()` but not
// `attributesToArray()`, so every Inertia prop built from a model shipped
// the raw {"ar": …, "en": …} map. React cannot render an object as a
// child, so twelve admin screens rendered a **blank page** — and every
// backend gate stayed green the whole time, because nothing server-side
// was wrong.
//
// These tests are written against the payload rather than the markup,
// since that is where the defect actually lives.
//
// p7r* prefix: Pest loads every Feature file into one global namespace.

function p7rEmployee(string $role): Employee
{
    $employee = Employee::create([
        'full_name' => $role.' User', 'email' => 'e7r-'.uniqid().'@waqar.test', 'phone' => '1',
        'password' => 'password', 'residence_address' => 'N/A', 'national_id_number' => '29001010100000',
    ]);
    $employee->assignRole(Role::findOrCreate($role, 'employee'));

    return $employee;
}

function p7rProduct(string $en = 'Linen Shirt', string $ar = 'قميص كتان'): Product
{
    return Product::create([
        'name' => ['en' => $en, 'ar' => $ar],
        'description' => ['en' => "About {$en}", 'ar' => "عن {$ar}"],
        'slug' => 'p7r-'.uniqid(),
        'sku' => 'P7R-'.strtoupper(uniqid()),
        'price' => 250,
        'status' => true,
    ]);
}

/**
 * An order needs a resolved shipping destination — the geography columns
 * are NOT NULL (Section 24), so a bare Order::create() cannot stand in.
 */
/**
 * `$createdBy` makes the order a Customer Service one placed by that
 * employee — needed whenever the test acts as a CS agent, who is scoped
 * to their own orders (Order::scopeVisibleTo) and cannot see a website
 * order at all.
 */
function p7rOrder(Customer $customer, OrderStatus $status = OrderStatus::New, ?Employee $createdBy = null): Order
{
    $country = Country::firstOrCreate(['code' => 'EG'], ['name' => ['ar' => 'مصر', 'en' => 'Egypt']]);
    $governorate = Governorate::firstOrCreate(['country_id' => $country->id, 'name->en' => 'Cairo'], ['name' => ['ar' => 'القاهرة', 'en' => 'Cairo']]);
    $city = City::firstOrCreate(['governorate_id' => $governorate->id, 'name->en' => 'Nasr City'], ['name' => ['ar' => 'مدينة نصر', 'en' => 'Nasr City']]);
    $area = Area::firstOrCreate(['city_id' => $city->id, 'name->en' => 'Zone A'], ['name' => ['ar' => 'منطقة أ', 'en' => 'Zone A']]);

    return Order::create([
        'order_number' => (string) random_int(10000, 99999),
        'customer_id' => $customer->id,
        'order_source' => $createdBy === null ? OrderSource::Website : OrderSource::CustomerService,
        'created_by_employee_id' => $createdBy?->id,
        'customer_status' => CustomerOrderStatus::Processing,
        'status' => $status,
        'subtotal' => 100, 'shipping_amount' => 0, 'discount_amount' => 0, 'total' => 100,
        'shipping_recipient_name' => 'Nour', 'shipping_phone' => '1', 'shipping_address_line' => 'Cairo',
        'shipping_governorate_id' => $governorate->id,
        'shipping_city_id' => $city->id,
        'shipping_area_id' => $area->id,
    ]);
}

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
});

/*
|--------------------------------------------------------------------------
| The serialization contract
|--------------------------------------------------------------------------
*/

it('serializes a translatable column as one string, not a locale map', function () {
    $product = p7rProduct();

    app()->setLocale('ar');
    expect($product->fresh()->toArray()['name'])->toBe('قميص كتان');

    app()->setLocale('en');
    expect($product->fresh()->toArray()['name'])->toBe('Linen Shirt');
});

it('applies the same contract to every translatable model, not just products', function () {
    app()->setLocale('ar');

    $category = Category::create(['name' => ['en' => 'Coats', 'ar' => 'معاطف'], 'slug' => 'c-'.uniqid(), 'status' => true, 'sort_order' => 0]);
    $collection = CollectionModel::create(['name' => ['en' => 'Summer', 'ar' => 'صيف'], 'slug' => 's-'.uniqid(), 'is_active' => true, 'sort_order' => 0]);
    $reason = ReturnReason::create(['name' => ['en' => 'Wrong size', 'ar' => 'مقاس خاطئ']]);

    expect($category->fresh()->toArray()['name'])->toBe('معاطف')
        ->and($collection->fresh()->toArray()['name'])->toBe('صيف')
        ->and($reason->fresh()->toArray()['name'])->toBe('مقاس خاطئ');
});

it('leaves a column out of the payload rather than inventing one when the query did not select it', function () {
    p7rProduct();

    // ->get(['id', 'sku']) must not gain an empty `name` key.
    $row = Product::query()->get(['id', 'sku'])->first()->toArray();

    expect($row)->not->toHaveKey('name')->toHaveKey('sku');
});

/*
|--------------------------------------------------------------------------
| The screens that were rendering blank
|--------------------------------------------------------------------------
*/

it('ships string names to every admin list that renders one', function () {
    $product = p7rProduct();
    $product->categories()->attach(
        Category::create(['name' => ['en' => 'Shirts', 'ar' => 'قمصان'], 'slug' => 'sh-'.uniqid(), 'status' => true, 'sort_order' => 0])
    );
    $actor = p7rEmployee('Vice Chairman');

    $this->actingAs($actor, 'employee')
        ->withLocale('ar')
        ->get(route('admin.products.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('products.data.0.name', 'قميص كتان')
            // The nested relation matters just as much — it was the
            // categories column that blanked the products index.
            ->where('products.data.0.categories.0.name', 'قمصان')
        );

    $this->actingAs($actor, 'employee')
        ->withLocale('ar')
        ->get(route('admin.categories.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('categories.data.0.name', 'قمصان'));
});

it('ships string names to the attributes screen, including nested attribute values', function () {
    $attribute = Attribute::create(['name' => ['en' => 'Colour', 'ar' => 'اللون'], 'sort_order' => 0]);
    $attribute->values()->create(['value' => ['en' => 'Red', 'ar' => 'أحمر'], 'sort_order' => 0]);

    $this->actingAs(p7rEmployee('Vice Chairman'), 'employee')
        ->withLocale('ar')
        ->get(route('admin.attributes.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('attributes.0.name', 'اللون')
            ->where('attributes.0.values.0.value', 'أحمر')
        );
});

/*
|--------------------------------------------------------------------------
| …without breaking the screens that author translations
|--------------------------------------------------------------------------
*/

it('still sends both languages to the screens that edit translations', function () {
    $product = p7rProduct();
    $category = Category::create(['name' => ['en' => 'Coats', 'ar' => 'معاطف'], 'slug' => 'c-'.uniqid(), 'status' => true, 'sort_order' => 0]);
    $collection = CollectionModel::create(['name' => ['en' => 'Summer', 'ar' => 'صيف'], 'slug' => 's-'.uniqid(), 'is_active' => true, 'sort_order' => 0]);
    $actor = p7rEmployee('Vice Chairman');

    $this->actingAs($actor, 'employee')->withLocale('ar')
        ->get(route('admin.products.edit', $product))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('product.name.en', 'Linen Shirt')
            ->where('product.name.ar', 'قميص كتان')
        );

    $this->actingAs($actor, 'employee')->withLocale('ar')
        ->get(route('admin.categories.edit', $category))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('category.name.en', 'Coats')->where('category.name.ar', 'معاطف'));

    $this->actingAs($actor, 'employee')->withLocale('ar')
        ->get(route('admin.collections.edit', $collection))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('collection.name.en', 'Summer')->where('collection.name.ar', 'صيف'));
});

it('keeps the other language when a collection is edited', function () {
    // The form typed `name` as a string and posted `ar: ''` back over it,
    // so every edit silently wiped the Arabic name.
    $collection = CollectionModel::create([
        'name' => ['en' => 'Summer', 'ar' => 'صيف'], 'slug' => 'keep-'.uniqid(), 'is_active' => true, 'sort_order' => 0,
    ]);

    $this->actingAs(p7rEmployee('Vice Chairman'), 'employee')
        ->put(route('admin.collections.update', $collection), [
            'name' => ['en' => 'Summer Sale', 'ar' => 'تخفيضات الصيف'],
            'is_active' => true,
            'sort_order' => 0,
        ])->assertRedirect();

    expect($collection->fresh()->getTranslation('name', 'ar'))->toBe('تخفيضات الصيف');
});

/*
|--------------------------------------------------------------------------
| A soft-deleted product must not take a screen down with it
|--------------------------------------------------------------------------
*/

it('drops a soft-deleted product out of the variant pickers instead of throwing', function () {
    $live = p7rProduct('Live Shirt');
    ProductVariant::create(['product_id' => $live->id, 'sku' => 'LIVE-'.strtoupper(uniqid()), 'status' => true]);

    $deleted = p7rProduct('Deleted Shirt');
    ProductVariant::create(['product_id' => $deleted->id, 'sku' => 'GONE-'.strtoupper(uniqid()), 'status' => true]);
    $deleted->delete();

    // Both screens read $variant->product->getTranslation(...), which is
    // null for a trashed product — a 500, not a blank page.
    $actor = p7rEmployee('Super Admin');

    $this->actingAs($actor, 'employee')->get(route('admin.orders.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('variants', 1));

    $this->actingAs($actor, 'employee')->get(route('admin.promotions.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('variants', 1));
});

/*
|--------------------------------------------------------------------------
| Order detail screens read the snapshot, not the live product
|--------------------------------------------------------------------------
*/

it('renders an order line from its snapshot so a deleted product cannot blank the screen', function () {
    $customer = Customer::create(['name' => 'Nour', 'email' => 'n7r@waqar.test', 'phone' => '1', 'password' => 'password']);
    $product = p7rProduct('Doomed Shirt');
    $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'SNAP-1', 'status' => true]);

    $order = p7rOrder($customer);
    OrderItem::create([
        'order_id' => $order->id, 'product_variant_id' => $variant->id,
        'product_name_snapshot' => 'Doomed Shirt', 'variant_sku_snapshot' => 'SNAP-1',
        'quantity' => 1, 'unit_price' => 100, 'subtotal' => 100,
    ]);

    $product->delete();

    $this->actingAs(p7rEmployee('Checking'), 'employee')
        ->get(route('admin.checking.show', $order))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // The line is still identifiable after the product is gone.
            ->where('order.items.0.product_name_snapshot', 'Doomed Shirt')
            ->where('order.items.0.variant_sku_snapshot', 'SNAP-1')
        );
});

it('sends the customer to the return-filing screen it is rendered on', function () {
    $customer = Customer::create(['name' => 'Nour', 'email' => 'n7r2@waqar.test', 'phone' => '1', 'password' => 'password']);
    $agent = p7rEmployee('Customer Service');
    $order = p7rOrder($customer, OrderStatus::Delivered, $agent);

    $this->actingAs($agent, 'employee')
        ->get(route('admin.returns.create', ['order_number' => $order->order_number]))
        ->assertOk()
        // Rendered as `order.customer.name`; absent, the page threw
        // "Cannot read properties of undefined" and rendered nothing.
        ->assertInertia(fn ($page) => $page->where('order.customer.name', 'Nour'));
});

/*
|--------------------------------------------------------------------------
| Notification URLs outside a web request
|--------------------------------------------------------------------------
*/

it('builds notification URLs without a request to borrow the locale from', function () {
    // Every route lives under /{locale}/… and SetLocale registers that
    // default *during a request*. A queued notification has no request,
    // so a bare route() there throws UrlGenerationException — and worse,
    // a positional argument silently fills {locale} instead of {order}
    // (the same trap PHASE-6-HANDOVER.md documents). Phase 8 adds queue
    // workers, which is when this would have started firing.
    URL::defaults([]);

    $customer = Customer::create(['name' => 'Nour', 'email' => 'n7r3@waqar.test', 'phone' => '1', 'password' => 'password']);
    $order = p7rOrder($customer, OrderStatus::Delivered);

    $return = OrderReturn::create([
        'order_id' => $order->id, 'customer_id' => $customer->id,
        'stage' => ReturnStage::PostDelivery,
        'reason_id' => ReturnReason::create(['name' => ['en' => 'Wrong size', 'ar' => 'مقاس خاطئ']])->id,
    ]);

    $payload = (new ReturnRequestedNotification($return))->toArray($customer);

    expect($payload['url'])->toContain((string) $order->order_number)
        ->and($payload['url'])->toMatch('#/(ar|en)/account/orders/#');
});

it('keeps a warehouse list unaffected — only translatable columns change shape', function () {
    $warehouse = Warehouse::create(['name' => 'Main', 'address' => 'Cairo', 'phone' => '1', 'is_active' => true]);

    // `warehouses.name` is a plain column, not a translatable one.
    expect($warehouse->fresh()->toArray()['name'])->toBe('Main');
});
