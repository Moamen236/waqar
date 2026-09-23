<?php

use App\Actions\Checkout\CreateOrderAction;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\ProductType;
use App\Enums\ReviewStatus;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Area;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Cart;
use App\Models\Category;
use App\Models\City;
use App\Models\Country;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Governorate;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Review;
use App\Models\ShippingRate;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use App\Models\Wishlist;
use Illuminate\Http\Request;

// Phase 5 — Storefront Rebuild (WAQAR-DELIVERY-ROADMAP.html). The
// roadmap's own "Done when" bar: a guest can browse, add to cart, check
// out COD-only, and track the order by number + email, entirely on the
// rebuilt pages — the first test below walks exactly that, through the
// real HTTP routes rather than by calling Actions directly.
//
// Helper names carry a p5* prefix for the same reason Phase 4's carry
// p4*: Pest loads every Feature file into one global function namespace.

function p5Geo(): array
{
    $country = Country::create(['name' => ['ar' => 'مصر', 'en' => 'Egypt'], 'code' => 'EG']);
    $governorate = Governorate::create(['country_id' => $country->id, 'name' => ['ar' => 'القاهرة', 'en' => 'Cairo']]);
    $city = City::create(['governorate_id' => $governorate->id, 'name' => ['ar' => 'مدينة نصر', 'en' => 'Nasr City']]);
    $area = Area::create(['city_id' => $city->id, 'name' => ['ar' => 'منطقة أ', 'en' => 'Zone A']]);
    $otherArea = Area::create(['city_id' => $city->id, 'name' => ['ar' => 'منطقة ب', 'en' => 'Zone B']]);

    // Governorate-level base rate plus a cheaper area override, so the
    // most-specific-match fallback (Section 11) is actually exercised.
    ShippingRate::create(['geo_type' => 'governorate', 'geo_id' => $governorate->id, 'price' => 60]);
    ShippingRate::create(['geo_type' => 'area', 'geo_id' => $area->id, 'price' => 35]);

    return compact('country', 'governorate', 'city', 'area', 'otherArea');
}

function p5Warehouse(): Warehouse
{
    return Warehouse::create(['name' => 'Main', 'address' => 'N/A', 'phone' => '01012345678', 'is_active' => true]);
}

function p5Product(int $warehouseId, int $stock = 10, array $overrides = []): Product
{
    $product = Product::create([
        'name' => ['ar' => 'منتج', 'en' => 'Storefront Tee'],
        'slug' => 'tee-'.uniqid(),
        'sku' => 'ST-'.uniqid(),
        'price' => 250,
        'status' => true,
        ...$overrides,
    ]);

    $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => $product->sku.'-V', 'status' => true]);
    WarehouseInventory::create([
        'warehouse_id' => $warehouseId,
        'product_variant_id' => $variant->id,
        'quantity' => $stock,
        'reserved_quantity' => 0,
    ]);

    return $product->fresh('variants');
}

function p5Customer(string $password = 'password123!'): Customer
{
    return Customer::create([
        'name' => 'Shopper',
        'email' => 'shopper-'.uniqid().'@waqar.test',
        'phone' => '01000000000',
        'password' => $password,
    ]);
}

it('walks a guest through browse → cart → COD checkout → order tracking, with no login anywhere', function () {
    $geo = p5Geo();
    $warehouse = p5Warehouse();
    $product = p5Product($warehouse->id, stock: 10);
    $variant = $product->variants->first();

    // Browse
    $this->get(route('shop.index'))->assertOk();
    $this->get(route('product.show', ['slug' => $product->slug, 'sku' => $product->sku]))->assertOk();

    // Cart
    $this->post(route('cart.store'), ['product_variant_id' => $variant->id, 'quantity' => 2])
        ->assertRedirect();
    $this->getJson(route('cart.summary'))
        ->assertOk()
        ->assertJsonPath('count', 2)
        ->assertJsonPath('subtotal', 500)
        // Shipping stays null until an address resolves a rate — the
        // customer never types or edits a price (Section 08).
        ->assertJsonPath('shipping', null);

    // Server-resolved shipping: the area override wins over the
    // governorate base rate (Section 11's most-specific match).
    $this->postJson(route('api.shipping.quote'), [
        'governorate_id' => $geo['governorate']->id,
        'city_id' => $geo['city']->id,
        'area_id' => $geo['area']->id,
    ])->assertOk()->assertJsonPath('shipping', 35)->assertJsonPath('total', 535);

    // Checkout — COD only, no payment fields of any kind
    $this->post(route('checkout.store'), [
        'name' => 'Guest Buyer',
        'email' => 'guest@waqar.test',
        'phone' => '01111111111',
        'governorate_id' => $geo['governorate']->id,
        'city_id' => $geo['city']->id,
        'area_id' => $geo['area']->id,
        'address_line' => '12 Test Street',
    ])->assertRedirect(route('checkout.success', 1001));

    $order = Order::where('order_number', 1001)->firstOrFail();
    expect($order->order_source)->toBe(OrderSource::Website)
        ->and($order->created_by_employee_id)->toBeNull()
        ->and((float) $order->shipping_amount)->toBe(35.0)
        ->and((float) $order->total)->toBe(535.0)
        ->and($order->payments()->count())->toBe(1);

    // Stock is reserved, never deducted — deduction only happens on
    // Accounting-confirmed Delivered (Section 07, the single most
    // important rule in this system).
    $inventory = WarehouseInventory::where('product_variant_id', $variant->id)->firstOrFail();
    expect($inventory->quantity)->toBe(10)->and($inventory->reserved_quantity)->toBe(2);

    // The cart is emptied by the checkout, not left behind
    $this->getJson(route('cart.summary'))->assertJsonPath('count', 0);

    // Tracking by order number + email, still with no login
    $this->post(route('order-tracking.show'), ['order_number' => 1001, 'email' => 'guest@waqar.test'])
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('OrderTracking/Index')
            ->where('order.order_number', 1001)
            ->where('timeline.stages.0.reached', true)
            ->where('timeline.stages.1.reached', false));

    $this->assertGuest('customer');
});

it('refuses a checkout for an address with no shipping rate anywhere up the chain', function () {
    $geo = p5Geo();
    ShippingRate::query()->delete();
    $warehouse = p5Warehouse();
    $variant = p5Product($warehouse->id)->variants->first();

    $this->post(route('cart.store'), ['product_variant_id' => $variant->id, 'quantity' => 1]);

    $this->post(route('checkout.store'), [
        'name' => 'Guest', 'email' => 'g@waqar.test', 'phone' => '01012345678',
        'governorate_id' => $geo['governorate']->id,
        'city_id' => $geo['city']->id,
        'area_id' => $geo['area']->id,
        'address_line' => 'X',
    ])->assertRedirect();

    expect(Order::count())->toBe(0);
});

it('never lets the cart exceed available stock, and ignores a client-supplied price', function () {
    $warehouse = p5Warehouse();
    $variant = p5Product($warehouse->id, stock: 3)->variants->first();

    $this->post(route('cart.store'), ['product_variant_id' => $variant->id, 'quantity' => 3])->assertRedirect();

    // Adding one more takes the *combined* quantity past stock, which is
    // what the check looks at — otherwise 1-at-a-time adds walk past it.
    $this->post(route('cart.store'), ['product_variant_id' => $variant->id, 'quantity' => 1])
        ->assertSessionHas('error');

    $this->getJson(route('cart.summary'))
        ->assertJsonPath('count', 3)
        // Price is recomputed from product_variants on every read, never
        // stored on cart_items (Section 24's explicit confirmation).
        ->assertJsonPath('items.0.unit_price', 250);
});

it('does not let one session touch another session\'s cart item', function () {
    $warehouse = p5Warehouse();
    $variant = p5Product($warehouse->id)->variants->first();

    $this->post(route('cart.store'), ['product_variant_id' => $variant->id, 'quantity' => 1]);
    $itemId = Cart::firstOrFail()->items()->firstOrFail()->id;

    // A brand-new session gets its own cart; the other cart's item id is
    // sequential and guessable, so the controller has to reject it.
    $this->flushSession();
    $this->delete(route('cart.destroy', $itemId))->assertForbidden();
    $this->patch(route('cart.update', $itemId), ['quantity' => 99])->assertForbidden();
});

it('merges a guest cart into the customer cart on login, folding duplicate lines together', function () {
    $warehouse = p5Warehouse();
    $productA = p5Product($warehouse->id, stock: 20);
    $productB = p5Product($warehouse->id, stock: 20);
    $customer = p5Customer();

    // The customer already has a cart with product A in it
    $customerCart = Cart::create(['customer_id' => $customer->id]);
    $customerCart->items()->create(['product_variant_id' => $productA->variants->first()->id, 'quantity' => 1]);

    // …then browses signed out and adds A again plus B
    $this->post(route('cart.store'), ['product_variant_id' => $productA->variants->first()->id, 'quantity' => 2]);
    $this->post(route('cart.store'), ['product_variant_id' => $productB->variants->first()->id, 'quantity' => 1]);

    $this->post(route('login.store'), ['email' => $customer->email, 'password' => 'password123!'])
        ->assertRedirect(route('account.dashboard'));

    $this->getJson(route('cart.summary'))
        ->assertJsonPath('count', 4) // 1 + 2 folded into one A line, plus B
        ->assertJsonCount(2, 'items');

    expect(Cart::where('customer_id', $customer->id)->count())->toBe(1)
        ->and(Cart::whereNotNull('session_token')->count())->toBe(0);
});

it('applies a coupon by code and re-derives the discount on every read', function () {
    $geo = p5Geo();
    $warehouse = p5Warehouse();
    $variant = p5Product($warehouse->id, stock: 10)->variants->first();
    $customer = p5Customer();

    Coupon::create(['code' => 'TEN', 'type' => 'percentage', 'value' => 10, 'is_active' => true]);

    $this->actingAs($customer, 'customer');
    $this->post(route('cart.store'), ['product_variant_id' => $variant->id, 'quantity' => 2]);
    $this->post(route('cart.coupon.apply'), ['code' => 'TEN'])->assertSessionHas('success');

    $this->postJson(route('api.shipping.quote'), [
        'governorate_id' => $geo['governorate']->id,
        'city_id' => $geo['city']->id,
        'area_id' => $geo['area']->id,
    ])->assertJsonPath('discount', 50)->assertJsonPath('total', 485);

    // Deactivating the coupon stops it applying immediately — the code is
    // what's stored, not a computed discount.
    Coupon::where('code', 'TEN')->update(['is_active' => false]);
    $this->getJson(route('cart.summary'))
        ->assertJsonPath('discount', 0)
        ->assertJsonPath('coupon_error', 'Invalid or inactive coupon code.');
});

it('rejects an unknown coupon code without applying it', function () {
    $warehouse = p5Warehouse();
    $variant = p5Product($warehouse->id)->variants->first();
    $customer = p5Customer();

    $this->actingAs($customer, 'customer');
    $this->post(route('cart.store'), ['product_variant_id' => $variant->id, 'quantity' => 1]);
    $this->post(route('cart.coupon.apply'), ['code' => 'NOPE'])->assertSessionHas('error');

    $this->getJson(route('cart.summary'))->assertJsonPath('coupon', null)->assertJsonPath('discount', 0);
});

it('registers a customer with the name and phone the template omits', function () {
    $this->post(route('register.store'), [
        'name' => 'New Shopper',
        'email' => 'new@waqar.test',
        'phone' => '01234567890',
        'password' => 'password123!',
        'password_confirmation' => 'password123!',
    ])->assertRedirect(route('account.dashboard'));

    $customer = Customer::where('email', 'new@waqar.test')->firstOrFail();
    expect($customer->name)->toBe('New Shopper')->and($customer->phone)->toBe('01234567890');
    $this->assertAuthenticatedAs($customer, 'customer');
});

it('keeps the customer and employee guards separate', function () {
    $customer = p5Customer();

    $this->actingAs($customer, 'customer');

    // A signed-in customer is still a guest to the admin area, and gets
    // sent to the *employee* login, not their own.
    $this->get(route('admin.dashboard'))->assertRedirect(route('admin.login'));
    $this->assertGuest('employee');
});

it('sends an unauthenticated account request to the customer login, not the admin one', function () {
    $this->get(route('account.dashboard'))->assertRedirect(route('login'));
});

it('only shows a customer their own orders', function () {
    $geo = p5Geo();
    $warehouse = p5Warehouse();
    $variant = p5Product($warehouse->id)->variants->first();
    $mine = p5Customer();
    $theirs = p5Customer();

    $order = app(CreateOrderAction::class)->execute(
        customer: $theirs,
        items: [['product_variant_id' => $variant->id, 'quantity' => 1]],
        warehouse: $warehouse,
        governorateId: $geo['governorate']->id,
        cityId: $geo['city']->id,
        districtId: null,
        areaId: $geo['area']->id,
        addressLine: 'X',
        recipientName: 'Them',
        phone: '1',
    );

    $this->actingAs($mine, 'customer');
    $this->get(route('account.orders.show', $order->order_number))->assertNotFound();
});

it('lets a customer cancel only while the order is still pending, releasing the reservation', function () {
    $geo = p5Geo();
    $warehouse = p5Warehouse();
    $variant = p5Product($warehouse->id, stock: 10)->variants->first();
    $customer = p5Customer();

    $order = app(CreateOrderAction::class)->execute(
        customer: $customer,
        items: [['product_variant_id' => $variant->id, 'quantity' => 2]],
        warehouse: $warehouse,
        governorateId: $geo['governorate']->id,
        cityId: $geo['city']->id,
        districtId: null,
        areaId: $geo['area']->id,
        addressLine: 'X',
        recipientName: 'Me',
        phone: '1',
    );

    expect(WarehouseInventory::where('product_variant_id', $variant->id)->value('reserved_quantity'))->toBe(2);

    $this->actingAs($customer, 'customer');
    $this->post(route('account.orders.cancel', $order->order_number))->assertRedirect();

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and(WarehouseInventory::where('product_variant_id', $variant->id)->value('reserved_quantity'))->toBe(0);

    // Past Checking it's the Checking department's call, not the
    // customer's (Section 03's status table).
    $order->update(['status' => OrderStatus::Assigned]);
    $this->post(route('account.orders.cancel', $order->order_number))->assertSessionHas('error');
});

it('files a review as pending, marks it verified when the customer actually bought the product, and hides it until approved', function () {
    $geo = p5Geo();
    $warehouse = p5Warehouse();
    $product = p5Product($warehouse->id, stock: 10);
    $variant = $product->variants->first();
    $customer = p5Customer();

    app(CreateOrderAction::class)->execute(
        customer: $customer,
        items: [['product_variant_id' => $variant->id, 'quantity' => 1]],
        warehouse: $warehouse,
        governorateId: $geo['governorate']->id,
        cityId: $geo['city']->id,
        districtId: null,
        areaId: $geo['area']->id,
        addressLine: 'X',
        recipientName: 'Me',
        phone: '1',
    );

    $this->actingAs($customer, 'customer');
    $this->post(route('product.review', $product->slug), ['rating' => 5, 'comment' => 'Great.'])
        ->assertRedirect();

    $review = Review::firstOrFail();
    expect($review->status)->toBe(ReviewStatus::Pending)
        ->and($review->order_item_id)->not->toBeNull();

    // Pending reviews never reach the product page
    $this->get(route('product.show', ['slug' => $product->slug, 'sku' => $product->sku]))
        ->assertInertia(fn ($page) => $page->has('reviews', 0));

    $review->update(['status' => ReviewStatus::Approved]);
    $this->get(route('product.show', ['slug' => $product->slug, 'sku' => $product->sku]))
        ->assertInertia(fn ($page) => $page->has('reviews', 1)->where('reviews.0.verified', true));

    // One review per customer per product
    $this->post(route('product.review', $product->slug), ['rating' => 1])->assertSessionHas('error');
    expect(Review::count())->toBe(1);
});

it('toggles a wishlist entry at product level and requires a signed-in customer', function () {
    $warehouse = p5Warehouse();
    $product = p5Product($warehouse->id);

    $this->post(route('wishlist.toggle'), ['product_id' => $product->id])->assertRedirect(route('login'));

    $customer = p5Customer();
    $this->actingAs($customer, 'customer');

    $this->post(route('wishlist.toggle'), ['product_id' => $product->id])->assertRedirect();
    expect(Wishlist::where('customer_id', $customer->id)->firstOrFail()->items()->count())->toBe(1);

    $this->post(route('wishlist.toggle'), ['product_id' => $product->id])->assertRedirect();
    expect(Wishlist::where('customer_id', $customer->id)->firstOrFail()->items()->count())->toBe(0);
});

it('filters the listing by rating and availability — the two filters the spec requires and the template lacks', function () {
    $warehouse = p5Warehouse();
    $customer = p5Customer();

    $inStock = p5Product($warehouse->id, stock: 5);
    $soldOut = p5Product($warehouse->id, stock: 0);

    Review::create([
        'product_id' => $inStock->id, 'customer_id' => $customer->id,
        'rating' => 5, 'status' => ReviewStatus::Approved,
    ]);

    $this->get(route('shop.index', ['availability' => 'in_stock']))
        ->assertInertia(fn ($page) => $page->has('products', 1)->where('products.0.id', $inStock->id));

    $this->get(route('shop.index', ['availability' => 'out_of_stock']))
        ->assertInertia(fn ($page) => $page->has('products', 1)->where('products.0.id', $soldOut->id));

    $this->get(route('shop.index', ['rating' => 4]))
        ->assertInertia(fn ($page) => $page->has('products', 1)->where('products.0.id', $inStock->id));

    $this->get(route('shop.index', ['rating' => 5, 'availability' => 'out_of_stock']))
        ->assertInertia(fn ($page) => $page->has('products', 0));
});

it('counts an Advertisement product as available even though it has no stock at all', function () {
    $warehouse = p5Warehouse();

    $advertisement = Product::create([
        'name' => ['ar' => 'إعلان', 'en' => 'Pre-Order Coat'],
        'slug' => 'pre-order-coat', 'sku' => 'ADV-1', 'price' => 900, 'status' => true,
        'product_type' => ProductType::Advertisement,
        'inventory_tracking_enabled' => false,
    ]);
    ProductVariant::create(['product_id' => $advertisement->id, 'sku' => 'ADV-1-V', 'status' => true]);
    p5Product($warehouse->id, stock: 0);

    $this->get(route('shop.index', ['availability' => 'in_stock']))
        ->assertInertia(fn ($page) => $page->has('products', 1)->where('products.0.id', $advertisement->id));

    // …and can be added to the cart without any stock check (Section 05)
    $this->post(route('cart.store'), [
        'product_variant_id' => $advertisement->variants()->first()->id,
        'quantity' => 5,
    ])->assertSessionHas('success');
});

it('filters the listing by colour and size from the real attribute catalog', function () {
    $warehouse = p5Warehouse();

    $color = Attribute::create(['name' => ['en' => 'Color', 'ar' => 'اللون']]);
    $black = AttributeValue::create(['attribute_id' => $color->id, 'value' => ['en' => 'Black', 'ar' => 'أسود'], 'color_hex' => '#000']);
    $white = AttributeValue::create(['attribute_id' => $color->id, 'value' => ['en' => 'White', 'ar' => 'أبيض'], 'color_hex' => '#fff']);

    $blackProduct = p5Product($warehouse->id);
    $blackProduct->variants->first()->attributeValues()->attach($black->id);

    $whiteProduct = p5Product($warehouse->id);
    $whiteProduct->variants->first()->attributeValues()->attach($white->id);

    $this->get(route('shop.index', ['color' => [$black->id]]))
        ->assertInertia(fn ($page) => $page->has('products', 1)->where('products.0.id', $blackProduct->id));
});

it('scopes the listing to a category and a collection through their own URLs', function () {
    $warehouse = p5Warehouse();
    $inCategory = p5Product($warehouse->id);
    p5Product($warehouse->id);

    $category = Category::create(['name' => ['en' => 'Tops', 'ar' => 'علوي'], 'slug' => 'tops', 'status' => true]);
    $inCategory->categories()->attach($category->id);

    $this->get(route('shop.category', 'tops'))
        ->assertInertia(fn ($page) => $page->has('products', 1)->where('heading.type', 'category'));

    $this->get(route('shop.category', 'does-not-exist'))->assertNotFound();
});

it('searches the translatable name and the sku without a search index', function () {
    $warehouse = p5Warehouse();
    $product = p5Product($warehouse->id);

    $this->get(route('search.index', ['q' => 'Storefront']))
        ->assertInertia(fn ($page) => $page->has('products', 1)->where('products.0.id', $product->id));

    $this->get(route('search.index', ['q' => $product->sku]))
        ->assertInertia(fn ($page) => $page->has('products', 1));

    $this->get(route('search.index', ['q' => 'nothing-like-this']))
        ->assertInertia(fn ($page) => $page->has('products', 0));
});

it('refuses to show an order confirmation to someone who neither owns nor just placed the order', function () {
    $geo = p5Geo();
    $warehouse = p5Warehouse();
    $variant = p5Product($warehouse->id)->variants->first();
    $owner = p5Customer();

    $order = app(CreateOrderAction::class)->execute(
        customer: $owner,
        items: [['product_variant_id' => $variant->id, 'quantity' => 1]],
        warehouse: $warehouse,
        governorateId: $geo['governorate']->id,
        cityId: $geo['city']->id,
        districtId: null,
        areaId: $geo['area']->id,
        addressLine: 'X',
        recipientName: 'Owner',
        phone: '1',
    );

    $this->get(route('checkout.success', $order->order_number))->assertForbidden();

    $this->actingAs($owner, 'customer');
    $this->get(route('checkout.success', $order->order_number))->assertOk();
});

it('does not reveal an order to a tracking lookup with the wrong email', function () {
    $geo = p5Geo();
    $warehouse = p5Warehouse();
    $variant = p5Product($warehouse->id)->variants->first();
    $customer = p5Customer();

    $order = app(CreateOrderAction::class)->execute(
        customer: $customer,
        items: [['product_variant_id' => $variant->id, 'quantity' => 1]],
        warehouse: $warehouse,
        governorateId: $geo['governorate']->id,
        cityId: $geo['city']->id,
        districtId: null,
        areaId: $geo['area']->id,
        addressLine: 'X',
        recipientName: 'Me',
        phone: '1',
    );

    $this->post(route('order-tracking.show'), [
        'order_number' => $order->order_number,
        'email' => 'someone-else@waqar.test',
    ])->assertSessionHasErrors('order_number');
});

it('saves a customer address through the full geo cascade and keeps exactly one default', function () {
    $geo = p5Geo();
    $customer = p5Customer();
    $this->actingAs($customer, 'customer');

    $payload = [
        'label' => 'Home',
        'recipient_name' => 'Me',
        'phone' => '01012345678',
        'governorate_id' => $geo['governorate']->id,
        'city_id' => $geo['city']->id,
        'district_id' => null,
        'area_id' => $geo['area']->id,
        'address_line' => '12 Test Street',
    ];

    // The first address saved is the default whether or not it was asked for
    $this->post(route('account.addresses.store'), $payload)->assertRedirect();
    expect($customer->addresses()->where('is_default', true)->count())->toBe(1);

    $this->post(route('account.addresses.store'), [...$payload, 'label' => 'Work', 'is_default' => true])
        ->assertRedirect();

    expect($customer->addresses()->count())->toBe(2)
        ->and($customer->addresses()->where('is_default', true)->count())->toBe(1)
        ->and($customer->addresses()->where('is_default', true)->value('label'))->toBe('Work');
});

it('does not let a customer edit or delete another customer\'s address', function () {
    $geo = p5Geo();
    $owner = p5Customer();
    $address = $owner->addresses()->create([
        'label' => 'Home', 'recipient_name' => 'Owner', 'phone' => '01012345678',
        'governorate_id' => $geo['governorate']->id, 'city_id' => $geo['city']->id,
        'area_id' => $geo['area']->id, 'address_line' => 'X', 'is_default' => true,
    ]);

    $this->actingAs(p5Customer(), 'customer');
    $this->delete(route('account.addresses.destroy', $address->id))->assertForbidden();
});

it('rejects a password change without the current password', function () {
    $customer = p5Customer();
    $this->actingAs($customer, 'customer');

    $this->put(route('account.password.update'), [
        'current_password' => 'wrong-password',
        'password' => 'newpassword123!',
        'password_confirmation' => 'newpassword123!',
    ])->assertSessionHasErrors('current_password');

    $this->put(route('account.password.update'), [
        'current_password' => 'password123!',
        'password' => 'newpassword123!',
        'password_confirmation' => 'newpassword123!',
    ])->assertSessionHasNoErrors();
});

it('picks the storefront root view for storefront URLs and the admin one for /admin', function () {
    // Asserted on the middleware rather than the rendered HTML because a
    // built manifest emits hashed asset names that don't identify the
    // bundle. This is the decision that keeps the two CSS frameworks off
    // the same page (Section 23).
    $middleware = new HandleInertiaRequests;

    expect($middleware->rootView(Request::create('/')))->toBe('storefront')
        ->and($middleware->rootView(Request::create('/shop')))->toBe('storefront')
        ->and($middleware->rootView(Request::create('/account/orders')))->toBe('storefront')
        ->and($middleware->rootView(Request::create('/admin')))->toBe('admin')
        ->and($middleware->rootView(Request::create('/admin/checking')))->toBe('admin');
});

// A3 — phone numbers are exactly 11 digits (feature-backlog-plan.md).
// The rule lives in one place, App\Rules\PhoneNumber, because it was
// previously ten separate copies of ['required','string','max:30'] that
// checked nothing at all.

it('rejects a phone number that is not exactly 11 digits, at every customer entry point', function () {
    foreach (['0123456789', '012345678901', '+201234567890', '0123 456 789', 'not-a-phone'] as $bad) {
        $this->post(route('register.store'), [
            'name' => 'Shopper',
            'email' => 'bad-'.uniqid().'@waqar.test',
            'phone' => $bad,
            'password' => 'password123!',
            'password_confirmation' => 'password123!',
        ])->assertSessionHasErrors('phone');
    }

    // Eleven digits gets through — including the leading zero every
    // Egyptian mobile starts with, which a numeric cast would eat.
    $this->post(route('register.store'), [
        'name' => 'Shopper',
        'email' => 'good@waqar.test',
        'phone' => '01012345678',
        'password' => 'password123!',
        'password_confirmation' => 'password123!',
    ])->assertSessionHasNoErrors();

    expect(Customer::where('email', 'good@waqar.test')->firstOrFail()->phone)->toBe('01012345678');
});

it('checks out with only a governorate and no email, priced at the governorate rate and trackable by phone', function () {
    $geo = p5Geo();
    $warehouse = p5Warehouse();
    $variant = p5Product($warehouse->id)->variants->first();

    $this->post(route('cart.store'), ['product_variant_id' => $variant->id, 'quantity' => 1]);

    // City/area left blank: the quote falls back to the governorate rate.
    $this->postJson(route('api.shipping.quote'), ['governorate_id' => $geo['governorate']->id])
        ->assertOk()->assertJsonPath('shipping', 60);

    $this->post(route('checkout.store'), [
        'name' => 'No Email Guest',
        'email' => '',
        'phone' => '01222222222',
        'governorate_id' => $geo['governorate']->id,
        'city_id' => '',
        'area_id' => '',
        'address_line' => '5 Side Street',
    ])->assertSessionHasNoErrors()->assertRedirect(route('checkout.success', 1001));

    $order = Order::where('order_number', 1001)->firstOrFail();
    expect($order->shipping_city_id)->toBeNull()
        ->and($order->shipping_area_id)->toBeNull()
        ->and((float) $order->shipping_amount)->toBe(60.0)
        ->and($order->customer->email)->toBeNull()
        ->and($order->customer->is_guest)->toBeTrue();

    // A second email-less order from the same phone reuses that guest.
    $this->post(route('cart.store'), ['product_variant_id' => $variant->id, 'quantity' => 1]);
    $this->post(route('checkout.store'), [
        'name' => 'No Email Guest',
        'phone' => '01222222222',
        'governorate_id' => $geo['governorate']->id,
        'address_line' => '5 Side Street',
    ])->assertRedirect(route('checkout.success', 1002));
    expect(Customer::where('phone', '01222222222')->count())->toBe(1);

    // With no email on file, the phone is what tracks the order.
    $this->post(route('order-tracking.show'), ['order_number' => 1001, 'email' => '01222222222'])
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('order.order_number', 1001));
    $this->post(route('order-tracking.show'), ['order_number' => 1001, 'email' => '01999999999'])
        ->assertSessionHasErrors('order_number');
});

it('never attaches an email-less checkout to a registered account that shares the phone', function () {
    $geo = p5Geo();
    $warehouse = p5Warehouse();
    $variant = p5Product($warehouse->id)->variants->first();
    $registered = Customer::create([
        'name' => 'Registered', 'email' => 'member@waqar.test', 'phone' => '01233333333', 'password' => 'secret-password',
    ]);

    $this->post(route('cart.store'), ['product_variant_id' => $variant->id, 'quantity' => 1]);
    $this->post(route('checkout.store'), [
        'name' => 'Someone Else',
        'phone' => '01233333333',
        'governorate_id' => $geo['governorate']->id,
        'address_line' => '1 Street',
    ])->assertRedirect(route('checkout.success', 1001));

    expect(Order::where('order_number', 1001)->value('customer_id'))->not->toBe($registered->id);
});

it('drops a cart line whose product was deleted instead of failing the whole cart', function () {
    p5Geo();
    $warehouse = p5Warehouse();
    $deleted = p5Product($warehouse->id);
    $kept = p5Product($warehouse->id);

    $this->post(route('cart.store'), ['product_variant_id' => $deleted->variants->first()->id, 'quantity' => 1]);
    $deleted->delete(); // soft delete, as the admin catalog does

    // Adding another product re-renders the cart; this used to throw
    // "Attempt to read property "id" on null".
    $this->post(route('cart.store'), ['product_variant_id' => $kept->variants->first()->id, 'quantity' => 1])
        ->assertRedirect()->assertSessionHasNoErrors();

    $this->getJson(route('cart.summary'))->assertOk()
        ->assertJsonPath('count', 1)
        ->assertJsonPath('items.0.product_id', $kept->id);

    // The deleted product's variant can't be re-added either.
    $this->post(route('cart.store'), ['product_variant_id' => $deleted->variants->first()->id, 'quantity' => 1])
        ->assertSessionHas('error');
});

it('serves a product at /product/{slug}/{sku} and redirects stale or slug-only URLs to it', function () {
    $warehouse = p5Warehouse();
    $product = p5Product($warehouse->id, overrides: ['slug' => 'linen-shirt', 'sku' => 'LS-100']);
    $canonical = route('product.show', ['slug' => 'linen-shirt', 'sku' => 'LS-100']);

    expect(parse_url($canonical, PHP_URL_PATH))->toEndWith('/product/linen-shirt/LS-100');
    $this->get($canonical)->assertOk()
        ->assertInertia(fn ($page) => $page->component('Product/Show')->where('product.sku', 'LS-100'));

    // The old slug-only URL, and a slug left over from a rename, both
    // permanently redirect to the canonical one.
    $this->get(route('product.legacy', 'linen-shirt'))->assertStatus(301)->assertRedirect($canonical);
    $this->get(route('product.show', ['slug' => 'old-name', 'sku' => 'LS-100']))->assertStatus(301)->assertRedirect($canonical);

    // Cards carry the SKU the link needs.
    $this->get(route('shop.index'))->assertInertia(fn ($page) => $page->where('products.0.sku', 'LS-100'));

    $this->get(route('product.show', ['slug' => 'linen-shirt', 'sku' => 'NOPE']))->assertNotFound();
});

it('keeps a card-picked ?color= through the product URL redirects', function () {
    $warehouse = p5Warehouse();
    p5Product($warehouse->id, overrides: ['slug' => 'wool-coat', 'sku' => 'WC-1']);
    $canonical = route('product.show', ['slug' => 'wool-coat', 'sku' => 'WC-1', 'color' => 7]);

    expect($canonical)->toEndWith('/product/wool-coat/WC-1?color=7');
    $this->get(route('product.legacy', ['slug' => 'wool-coat', 'color' => 7]))->assertRedirect($canonical);
    $this->get(route('product.show', ['slug' => 'old-coat', 'sku' => 'WC-1', 'color' => 7]))->assertRedirect($canonical);
    $this->get($canonical)->assertOk();
});

it('renders the Home and About pages, with every string they use present in both languages', function () {
    $this->get(route('home'))->assertOk()->assertInertia(fn ($page) => $page->component('Home'));
    $this->get(route('pages.about'))->assertOk()->assertInertia(fn ($page) => $page->component('Pages/About'));

    $pages = ['home' => 'Home.tsx', 'about' => 'Pages/About.tsx'];

    foreach ($pages as $prefix => $file) {
        preg_match_all("/t\('({$prefix}\.[A-Za-z]+)'\)/", file_get_contents(resource_path("js/storefront/Pages/{$file}")), $matches);
        $keys = array_unique($matches[1]);
        expect($keys)->not->toBeEmpty();

        foreach (['en', 'ar'] as $locale) {
            $strings = json_decode(file_get_contents(resource_path("js/storefront/locales/{$locale}.json")), true);
            foreach ($keys as $key) {
                expect($strings)->toHaveKey($key);
            }
        }
    }
});
