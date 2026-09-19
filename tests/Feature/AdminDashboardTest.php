<?php

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Models\Area;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\City;
use App\Models\Country;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Governorate;
use App\Models\Order;
use App\Models\Product;
use App\Models\ShippingRate;
use App\Models\Treasury;
use App\Services\Catalog\SkuGenerator;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ProductSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\WarehouseSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| /admin — the operations overview
|--------------------------------------------------------------------------
|
| The dashboard was a static placeholder until the Larkon UI pass gave it
| the template's stat-tile grid to fill. What it fills the tiles with is
| the point of these tests: every figure is a real query, and each block
| is gated on the permission that guards the module it summarises.
|
| The gating matters more than the arithmetic. /admin is the post-login
| landing page for *every* employee — it is deliberately not behind a
| `permission:` middleware the way every other admin route is — so if a
| tile were computed unconditionally, a Checking employee's dashboard
| would carry the treasury balance in its Inertia payload whether or not
| the UI drew it.
|
| adt* prefix: Pest loads every Feature file into one global namespace.
*/

function adtEmployee(string $role): Employee
{
    $employee = Employee::create([
        'full_name' => $role.' User',
        'email' => 'adt-'.uniqid().'@waqar.test',
        'phone' => '1',
        'password' => 'password',
        'residence_address' => 'N/A',
        'national_id_number' => '29001010100000',
    ]);
    $employee->assignRole(Role::findOrCreate($role, 'employee'));

    return $employee;
}

function adtOrder(OrderStatus $status, string $total = '100.00'): Order
{
    // The shipping geography columns are NOT NULL (Section 24) — an order
    // is always bound for a resolved destination, so a bare Order::create()
    // cannot stand in.
    $country = Country::firstOrCreate(['code' => 'EG'], ['name' => ['ar' => 'مصر', 'en' => 'Egypt']]);
    $governorate = Governorate::firstOrCreate(
        ['country_id' => $country->id, 'name->en' => 'Cairo'],
        ['name' => ['ar' => 'القاهرة', 'en' => 'Cairo']]
    );
    $city = City::firstOrCreate(
        ['governorate_id' => $governorate->id, 'name->en' => 'Nasr City'],
        ['name' => ['ar' => 'مدينة نصر', 'en' => 'Nasr City']]
    );
    $area = Area::firstOrCreate(
        ['city_id' => $city->id, 'name->en' => 'Zone A'],
        ['name' => ['ar' => 'منطقة أ', 'en' => 'Zone A']]
    );

    $customer = Customer::create([
        'name' => 'Dashboard Customer',
        'email' => 'adt-c-'.uniqid().'@waqar.test',
        'phone' => '0100'.random_int(1000000, 9999999),
        'password' => 'password',
        'is_active' => true,
    ]);

    return Order::create([
        'order_number' => random_int(100000, 999999),
        'customer_id' => $customer->id,
        'order_source' => OrderSource::Website,
        'status' => $status,
        'customer_status' => $status->customerStatus(),
        'payment_status' => 'pending',
        'subtotal' => $total,
        'discount_amount' => '0.00',
        'shipping_amount' => '0.00',
        'total' => $total,
        'shipping_recipient_name' => 'Dashboard Customer',
        'shipping_phone' => '01000000000',
        'shipping_address_line' => 'Somewhere',
        'shipping_governorate_id' => $governorate->id,
        'shipping_city_id' => $city->id,
        'shipping_area_id' => $area->id,
    ]);
}

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
});

it('counts only the orders the viewer is allowed to see, by real status', function () {
    adtOrder(OrderStatus::New);
    adtOrder(OrderStatus::Checking);
    adtOrder(OrderStatus::OutForDelivery);
    adtOrder(OrderStatus::Delivered, '250.00');
    adtOrder(OrderStatus::Delivered, '150.00');

    $this->actingAs(adtEmployee('Chairman'), 'employee')
        ->withLocale('ar')
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->where('stats.orders.today', 5)
            ->where('stats.orders.awaiting_checking', 2)
            ->where('stats.orders.out_for_delivery', 1)
            ->where('stats.orders.delivered_this_month', 2)
            // Revenue is Delivered only — the point at which stock actually
            // deducts (Section 07). The three non-delivered orders above
            // must not appear in it.
            ->where('stats.orders.revenue_this_month', '400.00')
            ->etc()
        );
});

it('omits a block entirely when the viewer lacks the permission that guards it', function () {
    Treasury::create(['name' => 'Main', 'type' => 'cash', 'current_balance' => '5000.00']);

    // Checking holds orders.view but not treasury.view / customers.view.
    $this->actingAs(adtEmployee('Checking'), 'employee')
        ->withLocale('ar')
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->where('stats.treasury', null)
            ->where('stats.customers', null)
            ->where('stats.orders.today', 0)
            ->etc()
        );
});

it('computes a block once the viewer does hold its permission', function () {
    Treasury::create(['name' => 'Main', 'type' => 'cash', 'current_balance' => '5000.00']);
    Treasury::create(['name' => 'Bank', 'type' => 'bank', 'current_balance' => '2500.50']);

    $this->actingAs(adtEmployee('Chairman'), 'employee')
        ->withLocale('ar')
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('stats.treasury', '7500.50')->etc());
});

it('scopes the dashboard to the same orders the listing scope allows', function () {
    // A Customer Service Team Leader sees only their own teams
    // CustomerService-sourced orders — Order::visibleTo()'s one narrowing
    // case. A storefront order must not reach their counts.
    adtOrder(OrderStatus::New);

    $this->actingAs(adtEmployee('Customer Service Team Leader'), 'employee')
        ->withLocale('ar')
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.orders.today', 0)
            ->where('latestOrders', [])
            ->etc()
        );
});

it('ranks low stock rather than inventing a reorder threshold', function () {
    // warehouse_inventory has no reorder-point column (Section 24), so the
    // panel is a ranking: whatever is closest to running out, in order.
    $this->seed([WarehouseSeeder::class, ProductSeeder::class]);

    $this->actingAs(adtEmployee('Warehouse Manager'), 'employee')
        ->withLocale('ar')
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertInertia(function ($page) {
            $rows = collect($page->toArray()['props']['lowestStock']);

            expect($rows)->not->toBeEmpty();
            expect($rows->pluck('available')->all())
                ->toBe($rows->pluck('available')->sort()->values()->all());

            return $page;
        });
});

it('sends a thumbnail URL to the product list without disturbing its other props', function () {
    $product = Product::create([
        'name' => ['en' => 'Linen Shirt', 'ar' => 'قميص كتان'],
        'description' => ['en' => 'About', 'ar' => 'عن'],
        'slug' => 'adt-'.uniqid(),
        'sku' => 'ADT-'.strtoupper(uniqid()),
        'price' => '100.00',
        'product_type' => 'real',
        'status' => true,
    ]);

    $this->actingAs(adtEmployee('Vice Chairman'), 'employee')
        ->withLocale('ar')
        ->get(route('admin.products.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('products.data.0.id', $product->id)
            // No media attached, so the key is present and null rather than
            // missing — the page branches on it.
            ->where('products.data.0.thumbnail', null)
            ->where('products.data.0.name', 'قميص كتان')
            ->has('products.data.0.variants_count')
            ->etc()
        );
});

/*
|--------------------------------------------------------------------------
| /admin/products/{product} — the detail view
|--------------------------------------------------------------------------
*/

it('does not let the product wildcard swallow the create route', function () {
    // products/{product} and products/create are both two-segment GETs, and
    // Laravel matches in registration order — the wildcard registered first
    // would resolve /products/create as product="create" and 404.
    $this->actingAs(adtEmployee('Vice Chairman'), 'employee')
        ->withLocale('ar')
        ->get(route('admin.products.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Products/Form')->etc());
});

it('ships per-warehouse stock and delivered units with the product detail', function () {
    $this->seed([WarehouseSeeder::class, ProductSeeder::class]);

    $product = Product::query()->whereHas('variants')->first();

    $this->actingAs(adtEmployee('Vice Chairman'), 'employee')
        ->withLocale('ar')
        ->get(route('admin.products.show', $product))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Products/Show')
            ->where('product.id', $product->id)
            ->has('product.variants.0.stock')
            ->has('product.variants.0.units_sold')
            ->has('product.images')
            ->has('reviewSummary.count')
            ->etc()
        );
});

it('withholds cost price from a viewer who cannot edit the product', function () {
    $product = Product::create([
        'name' => ['en' => 'Margin Probe', 'ar' => 'فحص الهامش'],
        'slug' => 'adt-margin-'.uniqid(),
        'sku' => 'ADTM-'.strtoupper(uniqid()),
        'price' => '100.00',
        'cost_price' => '40.00',
        'product_type' => 'real',
        'status' => true,
    ]);

    // Checking holds neither products.view nor products.update.
    $this->actingAs(adtEmployee('Checking'), 'employee')
        ->withLocale('ar')
        ->get(route('admin.products.show', $product))
        ->assertForbidden();

    // No seeded role currently holds products.view without products.update
    // (only Chairman and Vice Chairman hold either), so the gate is built
    // explicitly here rather than borrowed from a role that happens to fit
    // today — it is meant to survive the permission matrix being re-cut.
    $viewer = adtEmployee('Catalog Viewer');
    $viewer->roles->first()->givePermissionTo('products.view');
    $viewer->forgetCachedPermissions();

    expect($viewer->can('products.view'))->toBeTrue()
        ->and($viewer->can('products.update'))->toBeFalse();

    $this->actingAs($viewer, 'employee')
        ->withLocale('ar')
        ->get(route('admin.products.show', $product))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('product.cost_price', null)->etc());

    $this->actingAs(adtEmployee('Vice Chairman'), 'employee')
        ->withLocale('ar')
        ->get(route('admin.products.show', $product))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('product.cost_price', '40.00')->etc());
});

/*
|--------------------------------------------------------------------------
| /admin/orders — the order book
|--------------------------------------------------------------------------
*/

it('lists every order regardless of which department currently holds it', function () {
    adtOrder(OrderStatus::New);
    adtOrder(OrderStatus::Confirmed);
    adtOrder(OrderStatus::OutForDelivery);
    adtOrder(OrderStatus::Delivered);
    adtOrder(OrderStatus::Cancelled);

    // Each department screen shows a slice; this one shows the book.
    $this->actingAs(adtEmployee('Chairman'), 'employee')
        ->withLocale('ar')
        ->get(route('admin.orders.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Orders/Index')
            ->has('orders.data', 5)
            ->where('summary.awaiting_checking', 1)
            ->where('summary.in_delivery', 1)
            ->where('summary.delivered', 1)
            ->where('summary.cancelled_or_returned', 1)
            ->etc()
        );
});

it('filters the order book by status and by search term', function () {
    adtOrder(OrderStatus::New);
    $delivered = adtOrder(OrderStatus::Delivered);

    $actor = adtEmployee('Chairman');

    $this->actingAs($actor, 'employee')
        ->withLocale('ar')
        ->get(route('admin.orders.index', ['status' => OrderStatus::Delivered->value]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('orders.data', 1)
            ->where('orders.data.0.id', $delivered->id)
            ->etc());

    $this->actingAs($actor, 'employee')
        ->withLocale('ar')
        ->get(route('admin.orders.index', ['q' => (string) $delivered->order_number]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('orders.data', 1)
            ->where('orders.data.0.id', $delivered->id)
            ->etc());
});

it('refuses a single order the viewer’s scope does not cover', function () {
    // visibleTo() narrows a Customer Service Team Leader to their own
    // team's CustomerService-sourced orders. On a *listing* that is a
    // filter; on a detail route reached by id it has to be a guard, or the
    // scope is bypassed by typing a number into the URL.
    $storefrontOrder = adtOrder(OrderStatus::New);

    $this->actingAs(adtEmployee('Customer Service Team Leader'), 'employee')
        ->withLocale('ar')
        ->get(route('admin.orders.show', $storefrontOrder))
        ->assertForbidden();

    $this->actingAs(adtEmployee('Chairman'), 'employee')
        ->withLocale('ar')
        ->get(route('admin.orders.show', $storefrontOrder))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Orders/Show')
            ->where('order.id', $storefrontOrder->id)
            ->where('workflow.checking', true)
            ->where('workflow.delivery', false)
            ->where('workflow.accounting', false)
            ->etc()
        );
});

it('keeps status transitions off the order book', function () {
    // The department screens own every *transition*. If a write route
    // appears under admin.orders.* beyond this list, it needs justifying.
    //
    // admin.orders.destroy was added deliberately and is not a transition:
    // it soft-deletes an already-Cancelled order, which is why it cannot
    // stand in for cancelling. The two tests below pin that.
    //
    // admin.orders.quote is POST only because it carries a whole draft
    // order in its body — it writes nothing and returns priced totals for
    // the create form.
    $writable = collect(app('router')->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->getName() ?? '', 'admin.orders.'))
        ->reject(fn ($route) => $route->methods() === ['GET', 'HEAD'])
        ->map(fn ($route) => $route->getName())
        ->sort()
        ->values()
        ->all();

    expect($writable)->toBe(['admin.orders.destroy', 'admin.orders.quote', 'admin.orders.store']);
});

it('soft-deletes an order rather than removing the row', function () {
    $order = adtOrder(OrderStatus::Cancelled);

    $this->actingAs(adtEmployee('Chairman'), 'employee')
        ->withLocale('ar')
        ->delete(route('admin.orders.destroy', $order))
        ->assertRedirect();

    expect(Order::query()->find($order->id))->toBeNull()
        ->and(Order::withTrashed()->find($order->id))->not->toBeNull()
        ->and(Order::withTrashed()->find($order->id)->deleted_at)->not->toBeNull();
});

it('refuses to delete an order that still holds a stock reservation', function () {
    // The real hazard: $order->delete() stamps deleted_at and releases
    // nothing, so deleting a live order would strand its reservation in
    // warehouse_inventory forever. Cancelling is what releases it, so
    // cancelling is a prerequisite rather than an alternative.
    foreach ([OrderStatus::New, OrderStatus::Confirmed, OrderStatus::Delivered] as $status) {
        $order = adtOrder($status);

        $this->actingAs(adtEmployee('Chairman'), 'employee')
            ->withLocale('ar')
            ->delete(route('admin.orders.destroy', $order))
            ->assertRedirect();

        expect(Order::query()->find($order->id))->not->toBeNull("{$status->value} should survive");
    }
});

it('gates order deletion behind its own permission, not the status one', function () {
    $order = adtOrder(OrderStatus::Cancelled);

    // Checking cancels orders daily (orders.status.update) but must not be
    // able to remove them from the book.
    $checking = adtEmployee('Checking');
    expect($checking->can('orders.status.update'))->toBeTrue()
        ->and($checking->can('orders.delete'))->toBeFalse();

    $this->actingAs($checking, 'employee')
        ->withLocale('ar')
        ->delete(route('admin.orders.destroy', $order))
        ->assertForbidden();

    expect(Order::query()->find($order->id))->not->toBeNull();
});

it('soft-deletes a product rather than removing the row', function () {
    $product = Product::create([
        'name' => ['en' => 'Soft Delete Probe', 'ar' => 'فحص الحذف'],
        'slug' => 'adt-sd-'.uniqid(),
        'sku' => 'ADTSD-'.strtoupper(uniqid()),
        'price' => '10.00',
        'product_type' => 'real',
        'status' => true,
    ]);

    $this->actingAs(adtEmployee('Vice Chairman'), 'employee')
        ->withLocale('ar')
        ->delete(route('admin.products.destroy', $product))
        ->assertRedirect();

    expect(Product::query()->find($product->id))->toBeNull()
        ->and(Product::withTrashed()->find($product->id))->not->toBeNull()
        ->and(Product::withTrashed()->find($product->id)->deleted_at)->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Soft deletes on the rest of the deletable surface
|--------------------------------------------------------------------------
*/

it('agrees between every model’s trait and its own table', function () {
    // The audit that found the gaps, kept as a gate. Each direction of
    // disagreement is its own bug: trait without column throws on delete,
    // column without trait deletes hard while the schema says otherwise.
    $mismatches = [];

    foreach (glob(app_path('Models/*.php')) as $file) {
        $class = 'App\\Models\\'.basename($file, '.php');
        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);
        if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
            continue;
        }

        $table = (new $class)->getTable();
        $hasTrait = in_array(SoftDeletes::class, class_uses_recursive($class), true);
        $hasColumn = Schema::hasTable($table) && Schema::hasColumn($table, 'deleted_at');

        if ($hasTrait !== $hasColumn) {
            $mismatches[$class] = $hasTrait ? 'trait without deleted_at column' : 'deleted_at column without trait';
        }
    }

    expect($mismatches)->toBe([]);
});

it('refuses to delete an attribute value that variants still depend on', function () {
    $this->seed([WarehouseSeeder::class, ProductSeeder::class]);

    $value = AttributeValue::query()->whereHas('variants')->first();
    $attribute = $value->attribute;
    $usedBy = $value->variants()->count();
    $sample = $value->variants()->first();
    $before = $sample->attributeValues()->count();

    expect($usedBy)->toBeGreaterThan(0);

    $this->actingAs(adtEmployee('Vice Chairman'), 'employee')
        ->withLocale('ar')
        ->delete(route('admin.attributes.values.destroy', [$attribute, $value]))
        ->assertRedirect();

    // Before the guard, the two cascading pivots stripped this value from
    // every variant carrying it — the variant survived but stopped being
    // distinguishable from its siblings.
    expect(AttributeValue::query()->find($value->id))->not->toBeNull()
        ->and($sample->fresh()->attributeValues()->count())->toBe($before);
});

it('soft-deletes an unused attribute value', function () {
    $attribute = Attribute::create(['name' => ['en' => 'Fit', 'ar' => 'القَصّة']]);
    $value = $attribute->values()->create(['value' => ['en' => 'Slim', 'ar' => 'ضيّقة'], 'sort_order' => 0]);

    $this->actingAs(adtEmployee('Vice Chairman'), 'employee')
        ->withLocale('ar')
        ->delete(route('admin.attributes.values.destroy', [$attribute, $value]))
        ->assertRedirect();

    expect(AttributeValue::query()->find($value->id))->toBeNull()
        ->and(AttributeValue::withTrashed()->find($value->id)->deleted_at)->not->toBeNull();
});

it('revives a deleted shipping rate instead of colliding with its unique index', function () {
    // shipping_rates is UNIQUE (geo_type, geo_id) and a trashed row keeps
    // occupying the pair — so without the revive, deleting a governorate's
    // rate would make a replacement impossible to insert, and checkout
    // refuses every order to an address with no rate.
    $governorate = Governorate::firstOrCreate(
        ['country_id' => Country::firstOrCreate(['code' => 'EG'], ['name' => ['ar' => 'مصر', 'en' => 'Egypt']])->id, 'name->en' => 'Cairo'],
        ['name' => ['ar' => 'القاهرة', 'en' => 'Cairo']]
    );

    $actor = adtEmployee('Delivery Manager');
    $payload = ['geo_type' => 'governorate', 'geo_id' => $governorate->id, 'price' => '40.00', 'is_active' => true];

    $this->actingAs($actor, 'employee')->withLocale('ar')
        ->post(route('admin.delivery.shipping-rates.store'), $payload)->assertRedirect();

    $rate = ShippingRate::query()->where('geo_id', $governorate->id)->firstOrFail();

    $this->actingAs($actor, 'employee')->withLocale('ar')
        ->delete(route('admin.delivery.shipping-rates.destroy', $rate))->assertRedirect();

    expect(ShippingRate::query()->find($rate->id))->toBeNull()
        ->and(ShippingRate::withTrashed()->find($rate->id))->not->toBeNull();

    // The replacement must succeed, and must reuse the same row.
    $this->actingAs($actor, 'employee')->withLocale('ar')
        ->post(route('admin.delivery.shipping-rates.store'), [...$payload, 'price' => '55.00'])
        ->assertRedirect();

    $revived = ShippingRate::query()->where('geo_id', $governorate->id)->firstOrFail();

    expect($revived->id)->toBe($rate->id)
        ->and((string) $revived->price)->toBe('55.00')
        ->and(ShippingRate::query()->where('geo_id', $governorate->id)->count())->toBe(1);
});

it('hides a soft-deleted shipping rate from checkout rate resolution', function () {
    // The global scope has to reach the *storefront* path too, not just the
    // admin listing — a deleted rate that still priced orders would be worse
    // than no soft delete at all.
    $governorate = Governorate::firstOrCreate(
        ['country_id' => Country::firstOrCreate(['code' => 'EG'], ['name' => ['ar' => 'مصر', 'en' => 'Egypt']])->id, 'name->en' => 'Cairo'],
        ['name' => ['ar' => 'القاهرة', 'en' => 'Cairo']]
    );

    $rate = ShippingRate::create([
        'geo_type' => 'governorate', 'geo_id' => $governorate->id, 'price' => '40.00', 'is_active' => true,
    ]);

    expect(ShippingRate::query()->where('geo_id', $governorate->id)->exists())->toBeTrue();

    $rate->delete();

    expect(ShippingRate::query()->where('geo_id', $governorate->id)->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Product SKUs are assigned, not typed
|--------------------------------------------------------------------------
|
| The create form's SKU field is disabled, so the value has to come from
| somewhere the request cannot influence. These pin the two halves of
| that: the number continues the catalog's existing sequence, and store()
| ignores whatever the request happens to carry.
*/

it('offers the next SKU in the sequence on the create form', function () {
    $this->seed([WarehouseSeeder::class, ProductSeeder::class]);

    // The seeded catalog runs MSH-001 … PRJ-014.
    $this->actingAs(adtEmployee('Vice Chairman'), 'employee')
        ->withLocale('ar')
        ->get(route('admin.products.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Products/Form')->where('nextSku', 'PRD-015')->etc());
});

it('assigns the SKU on store and ignores one supplied by the request', function () {
    $this->seed([WarehouseSeeder::class, ProductSeeder::class]);

    $this->actingAs(adtEmployee('Vice Chairman'), 'employee')
        ->withLocale('ar')
        ->post(route('admin.products.store'), [
            'name' => ['en' => 'Linen Shirt', 'ar' => 'قميص كتان'],
            'sku' => 'HACKED-001',
            'price' => '250',
            'status' => true,
            'is_featured' => false,
            'is_new' => false,
            'is_on_sale' => false,
            'sort_order' => 0,
            'product_type' => 'real',
            'variants' => [['sku' => 'PRD-015-RED-S', 'status' => true]],
        ])
        ->assertRedirect();

    $product = Product::query()->where('name->en', 'Linen Shirt')->firstOrFail();

    expect($product->sku)->toBe('PRD-015')
        ->and(Product::query()->where('sku', 'HACKED-001')->exists())->toBeFalse();
});

it('does not reissue the SKU of a soft-deleted product', function () {
    // products.sku is UNIQUE and Product is soft-deleted, so a trashed row
    // still owns its SKU in the index — generating against live rows only
    // would hand out a number the insert then fails on.
    Product::create([
        'name' => ['en' => 'Retired', 'ar' => 'متوقف'], 'slug' => 'retired-'.uniqid(),
        'sku' => 'PRD-001', 'price' => 100,
    ])->delete();

    expect(app(SkuGenerator::class)->nextProductSku())->toBe('PRD-002');
});
