<?php

use App\Enums\CollectedMethod;
use App\Enums\CustomerOrderStatus;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\TreasuryTransactionType;
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
use App\Models\TreasuryTransaction;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use App\Notifications\Orders\OrderPlacedNotification;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;

// Phase 8 — Infrastructure & Launch (WAQAR-DELIVERY-ROADMAP.html).
//
// The roadmap's own "Done when" bar:
//
//   "`docker compose up -d --build` serves the storefront … and a real
//    order placed reaches Delivered with stock, payment, and treasury all
//    correctly reflected."
//
// The first test is that sentence, walked through the real HTTP routes
// from the *storefront* — Section 04's flow begins at "Customer —
// Website", not at /admin/orders/create, which is where Phase 4's
// lifecycle test starts. The rest cover the infrastructure this phase
// added: queued notifications, the scheduler, and the preflight command.
//
// p8* prefix: Pest loads every Feature file into one global namespace.

function p8Geo(): array
{
    $country = Country::create(['name' => ['ar' => 'مصر', 'en' => 'Egypt'], 'code' => 'EG']);
    $governorate = Governorate::create(['country_id' => $country->id, 'name' => ['ar' => 'القاهرة', 'en' => 'Cairo']]);
    $city = City::create(['governorate_id' => $governorate->id, 'name' => ['ar' => 'مدينة نصر', 'en' => 'Nasr City']]);
    $area = Area::create(['city_id' => $city->id, 'name' => ['ar' => 'منطقة أ', 'en' => 'Zone A']]);

    ShippingRate::create(['geo_type' => 'governorate', 'geo_id' => $governorate->id, 'price' => 40]);

    return compact('country', 'governorate', 'city', 'area');
}

function p8Employee(string $role): Employee
{
    $employee = Employee::create([
        'full_name' => $role.' User', 'email' => 'e8-'.uniqid().'@waqar.test', 'phone' => '01012345678',
        'password' => 'password', 'residence_address' => 'N/A', 'national_id_number' => '29001010100000',
    ]);
    $employee->assignRole(Role::findOrCreate($role, 'employee'));

    return $employee;
}

function p8Stock(int $quantity = 10): ProductVariant
{
    $warehouse = Warehouse::create(['name' => 'Main', 'address' => 'N/A', 'phone' => '01012345678', 'is_active' => true]);
    $product = Product::create([
        'name' => ['ar' => 'قميص', 'en' => 'Cutover Shirt'],
        'slug' => 'cutover-'.uniqid(), 'sku' => 'CUT-'.strtoupper(uniqid()),
        'price' => 250, 'status' => true,
    ]);
    $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => $product->sku.'-V', 'status' => true]);
    WarehouseInventory::create([
        'warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id,
        'quantity' => $quantity, 'reserved_quantity' => 0,
    ]);

    return $variant;
}

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
});

/*
|--------------------------------------------------------------------------
| The roadmap's completion bar, end to end
|--------------------------------------------------------------------------
*/

it('walks a real storefront order all the way to Delivered with stock, payment and treasury correct', function () {
    $geo = p8Geo();
    $variant = p8Stock(quantity: 10);
    $treasury = Treasury::create(['name' => 'Main Safe', 'type' => 'cash', 'current_balance' => 0, 'is_active' => true]);

    // ── Customer — Website ────────────────────────────────────────────
    // Browse, then cart. No login: COD guest checkout is the default
    // journey (Section 04's blue path, Q8).
    $this->get(route('shop.index'))->assertOk();
    $this->post(route('cart.store'), ['product_variant_id' => $variant->id, 'quantity' => 2])->assertRedirect();

    // ── Checkout (COD only, server-calculated shipping) ───────────────
    $this->post(route('checkout.store'), [
        'name' => 'Cutover Buyer',
        'email' => 'cutover@waqar.test',
        'phone' => '01111111111',
        'governorate_id' => $geo['governorate']->id,
        'city_id' => $geo['city']->id,
        'area_id' => $geo['area']->id,
        'address_line' => '1 Launch Street',
    ])->assertRedirect();

    $order = Order::latest('id')->firstOrFail();

    // Money is server-side: 2 × 250 + 40 shipping. Nothing about price
    // was accepted from the request body.
    expect((float) $order->subtotal)->toBe(500.0)
        ->and((float) $order->shipping_amount)->toBe(40.0)
        ->and((float) $order->total)->toBe(540.0)
        ->and($order->status)->toBe(OrderStatus::New);

    // Stock is RESERVED, not deducted — Section 07's central rule.
    $inventory = WarehouseInventory::where('product_variant_id', $variant->id)->firstOrFail();
    expect($inventory->quantity)->toBe(10)->and($inventory->reserved_quantity)->toBe(2);

    // ── Checking confirms ─────────────────────────────────────────────
    $this->actingAs(p8Employee('Checking'), 'employee')
        ->post(route('admin.checking.confirm', $order))->assertRedirect();

    expect($order->fresh()->status)->toBe(OrderStatus::Confirmed);

    // Still only reserved after confirmation — a step people expect to
    // deduct, and which deliberately does not.
    $inventory->refresh();
    expect($inventory->quantity)->toBe(10)->and($inventory->reserved_quantity)->toBe(2);

    // ── Delivery Manager assigns ──────────────────────────────────────
    $representative = DeliveryRepresentative::create([
        'name' => 'Rep One', 'phone' => '01012345678', 'status' => true,
    ]);

    $this->actingAs(p8Employee('Delivery Manager'), 'employee')
        ->post(route('admin.delivery.assign', $order), [
            'assignment_type' => 'representative',
            'assignee_id' => $representative->id,
        ])->assertRedirect();

    expect($order->fresh()->status)->toBe(OrderStatus::Assigned);

    // ── Accounting confirms the delivery result ───────────────────────
    $this->actingAs(p8Employee('Accounting'), 'employee')
        ->post(route('admin.accounting.delivered', $order), [
            'collected_method' => CollectedMethod::Cash->value,
            'treasury_id' => $treasury->id,
        ])->assertRedirect();

    $order->refresh();

    // Stock: deducted at last, and the reservation released with it.
    $inventory->refresh();
    expect($inventory->quantity)->toBe(8)
        ->and($inventory->reserved_quantity)->toBe(0);

    // Payment: collected.
    expect($order->status)->toBe(OrderStatus::Delivered)
        ->and($order->payment_status)->toBe(PaymentStatus::Collected);

    // Treasury: the cash actually landed somewhere — the goods half of
    // it. The customer handed the courier all 540; the 40 of shipping is
    // the courier's fee and they kept it at the door.
    $transaction = TreasuryTransaction::where('treasury_id', $treasury->id)->latest('id')->firstOrFail();
    expect($transaction->type)->toBe(TreasuryTransactionType::Income)
        ->and((float) $transaction->amount)->toBe(500.0)
        ->and((float) $treasury->fresh()->current_balance)->toBe(500.0)
        ->and($order->netDueToTreasury())->toBe(500.0);
});

/*
|--------------------------------------------------------------------------
| The infrastructure this phase added
|--------------------------------------------------------------------------
*/

it('queues customer notifications instead of sending them inside the request', function () {
    Queue::fake();

    $geo = p8Geo();
    $variant = p8Stock();

    $this->post(route('cart.store'), ['product_variant_id' => $variant->id, 'quantity' => 1]);
    $this->post(route('checkout.store'), [
        'name' => 'Queued Buyer', 'email' => 'queued@waqar.test', 'phone' => '01012345678',
        'governorate_id' => $geo['governorate']->id,
        'city_id' => $geo['city']->id,
        'area_id' => $geo['area']->id,
        'address_line' => '1 Launch Street',
    ])->assertRedirect();

    // The customer's checkout no longer waits on an SMTP round-trip.
    Queue::assertPushed(SendQueuedNotifications::class);
});

it('captures the locale at dispatch so a worker does not mail in the wrong language', function () {
    // The worker has no request to inherit a locale from, and every route
    // lives under /{locale}/… — without capturing it at construction, a
    // customer shopping in English gets mailed in Arabic.
    $order = Order::create([
        'order_number' => (string) random_int(10000, 99999),
        'customer_id' => Customer::create([
            'name' => 'Nour', 'email' => 'loc@waqar.test', 'phone' => '01012345678', 'password' => 'password',
        ])->id,
        'order_source' => OrderSource::Website,
        'customer_status' => CustomerOrderStatus::Processing,
        'status' => OrderStatus::New,
        'subtotal' => 100, 'shipping_amount' => 0, 'discount_amount' => 0, 'total' => 100,
        'shipping_recipient_name' => 'Nour', 'shipping_phone' => '1', 'shipping_address_line' => 'Cairo',
        ...(function () {
            $geo = p8Geo();

            return [
                'shipping_governorate_id' => $geo['governorate']->id,
                'shipping_city_id' => $geo['city']->id,
                'shipping_area_id' => $geo['area']->id,
            ];
        })(),
    ]);

    app()->setLocale('en');
    $english = new OrderPlacedNotification($order);

    app()->setLocale('ar');
    $arabic = new OrderPlacedNotification($order);

    // Locale is bound to the notification, not read when the job runs.
    expect($english->locale)->toBe('en')->and($arabic->locale)->toBe('ar');
});

it('schedules the audit-log retention that Phase 7 configured but could not run', function () {
    $events = collect(app(Schedule::class)->events())
        ->map(fn ($event) => $event->command ?? '');

    // config/activitylog.php has set a 365-day retention since Phase 7;
    // until there was a scheduler, nothing enforced it.
    expect($events->contains(fn ($command) => str_contains($command, 'activitylog:clean')))->toBeTrue()
        ->and($events->contains(fn ($command) => str_contains($command, 'queue:prune-failed')))->toBeTrue();

    // Deliberately NOT queue:retry — a permanently-failing notification
    // would retry hourly forever and re-mail the customer each time.
    expect($events->contains(fn ($command) => str_contains($command, 'queue:retry')))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The preflight command — the cutover checklist, executable
|--------------------------------------------------------------------------
*/

it('passes preflight once the stack is seeded and configured', function () {
    p8Geo();
    p8Stock();
    Treasury::create(['name' => 'Main Safe', 'type' => 'cash', 'current_balance' => 0, 'is_active' => true]);
    p8Employee('Super Admin');

    $this->artisan('waqar:preflight --skip-queue')->assertSuccessful();
});

it('fails preflight when no shipping rate exists, because checkout would refuse every order', function () {
    p8Geo();
    p8Stock();
    p8Employee('Super Admin');

    // The exact state Phase 5 shipped in: everything looks healthy, and
    // the storefront silently cannot take a single order.
    ShippingRate::query()->delete();

    $this->artisan('waqar:preflight --skip-queue')->assertFailed();
});

it('fails preflight when nobody can administer the system', function () {
    p8Geo();
    p8Stock();

    // No Super Admin seeded at all.
    expect(Employee::role('Super Admin')->count())->toBe(0);

    $this->artisan('waqar:preflight --skip-queue')->assertFailed();
});
