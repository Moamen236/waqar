<?php

use App\Actions\Checkout\CreateOrderAction;
use App\Actions\Orders\CancelOrderAction;
use App\Actions\Orders\ConfirmOrderAction;
use App\Actions\Returns\RequestReturnAction;
use App\Enums\OrderStatus;
use App\Mail\ContactMessageMail;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Order;
use App\Models\ReturnReason;
use App\Models\ShippingRate;
use App\Notifications\Orders\OrderPlacedNotification;
use App\Notifications\Orders\OrderStatusUpdatedNotification;
use App\Notifications\Orders\ReturnRequestedNotification;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Permission\Models\Role;

// The gaps PHASE-5-HANDOVER.md flagged, closed: the /admin/shipping-rates
// screen, real order/return notifications, guest-checkout email
// collisions, the country level of the cascade, and a contact form that
// actually sends. Helpers carry a p5g* prefix — Pest loads every Feature
// file into one global function namespace.

function p5gEmployee(string $role): Employee
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

/*
|--------------------------------------------------------------------------
| /admin/shipping-rates — the gap that blocked real-world use
|--------------------------------------------------------------------------
*/

it('lets a Delivery Manager configure a shipping rate that checkout then actually resolves', function () {
    $geo = p5Geo();
    ShippingRate::query()->delete();
    $warehouse = p5Warehouse();
    $variant = p5Product($warehouse->id, stock: 5)->variants->first();

    // With no rate configured, checkout refuses the order — this is the
    // state the store ships in until someone uses this screen.
    $this->post(route('cart.store'), ['product_variant_id' => $variant->id, 'quantity' => 1]);
    $this->post(route('checkout.store'), [
        'name' => 'Guest', 'email' => 'g@waqar.test', 'phone' => '01012345678',
        'governorate_id' => $geo['governorate']->id,
        'city_id' => $geo['city']->id,
        'area_id' => $geo['area']->id,
        'address_line' => 'X',
    ])->assertSessionHas('error');
    expect(Order::count())->toBe(0);

    $this->actingAs(p5gEmployee('Delivery Manager'), 'employee');
    $this->get(route('admin.delivery.shipping-rates.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('uncoveredGovernorates', 1));

    $this->post(route('admin.delivery.shipping-rates.store'), [
        'geo_type' => 'governorate',
        'geo_id' => $geo['governorate']->id,
        'price' => 55,
        'free_shipping_threshold' => null,
        'is_active' => true,
    ])->assertRedirect(route('admin.delivery.shipping-rates.index'));

    // …and now the same checkout succeeds, at the rate just entered.
    $this->post(route('checkout.store'), [
        'name' => 'Guest', 'email' => 'g@waqar.test', 'phone' => '01012345678',
        'governorate_id' => $geo['governorate']->id,
        'city_id' => $geo['city']->id,
        'area_id' => $geo['area']->id,
        'address_line' => 'X',
    ])->assertRedirect();

    expect((float) Order::firstOrFail()->shipping_amount)->toBe(55.0);
});

it('lets a more specific rate override a broader one, and removing it fall back', function () {
    $geo = p5Geo();
    ShippingRate::query()->delete();
    $warehouse = p5Warehouse();
    $variant = p5Product($warehouse->id)->variants->first();
    $this->post(route('cart.store'), ['product_variant_id' => $variant->id, 'quantity' => 1]);

    $this->actingAs(p5gEmployee('Delivery Manager'), 'employee');
    $this->post(route('admin.delivery.shipping-rates.store'), [
        'geo_type' => 'governorate', 'geo_id' => $geo['governorate']->id, 'price' => 55,
    ]);
    $this->post(route('admin.delivery.shipping-rates.store'), [
        'geo_type' => 'area', 'geo_id' => $geo['area']->id, 'price' => 20,
    ]);

    $quote = fn () => $this->postJson(route('api.shipping.quote'), [
        'governorate_id' => $geo['governorate']->id,
        'city_id' => $geo['city']->id,
        'area_id' => $geo['area']->id,
    ])->json('shipping');

    expect($quote())->toBe(20);

    $areaRate = ShippingRate::where('geo_type', 'area')->firstOrFail();
    $this->delete(route('admin.delivery.shipping-rates.destroy', $areaRate->id))->assertRedirect();

    expect($quote())->toBe(55);
});

it('rejects a second rate for the same location and a rate pointing at a row that does not exist', function () {
    $geo = p5Geo();
    $this->actingAs(p5gEmployee('Delivery Manager'), 'employee');

    // p5Geo() already seeded a governorate-level rate
    $this->post(route('admin.delivery.shipping-rates.store'), [
        'geo_type' => 'governorate', 'geo_id' => $geo['governorate']->id, 'price' => 10,
    ])->assertStatus(422);

    // geo_id is a polymorphic pointer, so no `exists:` rule covers it —
    // the controller has to check it against the level's own table.
    $this->post(route('admin.delivery.shipping-rates.store'), [
        'geo_type' => 'district', 'geo_id' => 999999, 'price' => 10,
    ])->assertStatus(422);
});

it('keeps shipping rates behind their own permission', function () {
    // Checking has orders.view but nothing to do with delivery config.
    $this->actingAs(p5gEmployee('Checking'), 'employee');
    $this->get(route('admin.delivery.shipping-rates.index'))->assertForbidden();
    $this->post(route('admin.delivery.shipping-rates.store'), [
        'geo_type' => 'governorate', 'geo_id' => 1, 'price' => 10,
    ])->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Notifications — the tab was real but empty
|--------------------------------------------------------------------------
*/

it('notifies the customer when the order is placed and again when its customer-facing status moves', function () {
    Notification::fake();

    $geo = p5Geo();
    $warehouse = p5Warehouse();
    $variant = p5Product($warehouse->id, stock: 5)->variants->first();
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

    Notification::assertSentTo($customer, OrderPlacedNotification::class);

    // Checking confirming the order keeps the customer on "Processing" —
    // nothing they can act on changed, so nothing is sent.
    app(ConfirmOrderAction::class)->execute($order, p5gEmployee('Checking'));
    Notification::assertSentToTimes($customer, OrderStatusUpdatedNotification::class, 0);

    // Cancelling does change what they see, so it is.
    app(CancelOrderAction::class)->execute($order->fresh(), null, 'Changed mind');
    Notification::assertSentTo($customer, OrderStatusUpdatedNotification::class);
});

it('writes order notifications to the database channel so the account tab can read them', function () {
    $geo = p5Geo();
    $warehouse = p5Warehouse();
    $variant = p5Product($warehouse->id, stock: 5)->variants->first();
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

    expect($customer->notifications()->count())->toBe(1)
        ->and($customer->unreadNotifications()->count())->toBe(1);

    $this->actingAs($customer, 'customer');
    $this->get(route('account.notifications'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('notifications', 1));

    $this->post(route('account.notifications.read'))->assertRedirect();
    expect($customer->fresh()->unreadNotifications()->count())->toBe(0);
});

it('does not notify anyone when the order that triggered it never committed', function () {
    Notification::fake();

    $geo = p5Geo();
    $warehouse = p5Warehouse();
    // Only 1 in stock, but 5 ordered — the reservation throws inside
    // CreateOrderAction's transaction and the whole order rolls back.
    $variant = p5Product($warehouse->id, stock: 1)->variants->first();
    $customer = p5Customer();

    try {
        app(CreateOrderAction::class)->execute(
            customer: $customer,
            items: [['product_variant_id' => $variant->id, 'quantity' => 5]],
            warehouse: $warehouse,
            governorateId: $geo['governorate']->id,
            cityId: $geo['city']->id,
            districtId: null,
            areaId: $geo['area']->id,
            addressLine: 'X',
            recipientName: 'Me',
            phone: '1',
        );
    } catch (Throwable) {
        // expected
    }

    expect(Order::count())->toBe(0);
    Notification::assertNothingSent();
});

it('notifies the customer when a return is filed on their behalf', function () {
    Notification::fake();

    $geo = p5Geo();
    $warehouse = p5Warehouse();
    $variant = p5Product($warehouse->id, stock: 5)->variants->first();
    $customer = p5Customer();
    $reason = ReturnReason::create(['name' => ['en' => 'Wrong size', 'ar' => 'مقاس خاطئ'], 'is_active' => true]);

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
    $order->update(['status' => OrderStatus::Delivered]);

    app(RequestReturnAction::class)->execute(
        $order->fresh(),
        $customer,
        [['order_item_id' => $order->items->first()->id, 'quantity' => 1]],
        $reason->id,
    );

    Notification::assertSentTo($customer, ReturnRequestedNotification::class);
});

/*
|--------------------------------------------------------------------------
| Guest checkout — the email-collision concern
|--------------------------------------------------------------------------
*/

it('refuses to attach a guest order to somebody\'s registered account', function () {
    $geo = p5Geo();
    $warehouse = p5Warehouse();
    $variant = p5Product($warehouse->id, stock: 5)->variants->first();

    $registered = p5Customer(); // registers with a real password, is_guest = false

    $this->post(route('cart.store'), ['product_variant_id' => $variant->id, 'quantity' => 1]);
    $this->post(route('checkout.store'), [
        'name' => 'Impostor', 'email' => $registered->email, 'phone' => '01012345678',
        'governorate_id' => $geo['governorate']->id,
        'city_id' => $geo['city']->id,
        'area_id' => $geo['area']->id,
        'address_line' => 'X',
    ])->assertSessionHasErrors('email');

    expect(Order::count())->toBe(0);
});

it('reuses the same guest record across repeat guest orders instead of duplicating it', function () {
    $geo = p5Geo();
    $warehouse = p5Warehouse();
    $variant = p5Product($warehouse->id, stock: 10)->variants->first();

    $place = function (string $name) use ($geo, $variant) {
        $this->post(route('cart.store'), ['product_variant_id' => $variant->id, 'quantity' => 1]);

        return $this->post(route('checkout.store'), [
            'name' => $name, 'email' => 'repeat@waqar.test', 'phone' => '01000000001',
            'governorate_id' => $geo['governorate']->id,
            'city_id' => $geo['city']->id,
            'area_id' => $geo['area']->id,
            'address_line' => 'X',
        ]);
    };

    $place('Guest One')->assertRedirect();
    $place('Guest One Renamed')->assertRedirect();

    expect(Customer::where('email', 'repeat@waqar.test')->count())->toBe(1)
        ->and(Order::count())->toBe(2);

    $guest = Customer::where('email', 'repeat@waqar.test')->firstOrFail();
    expect($guest->is_guest)->toBeTrue()
        // The details the courier will use are the ones typed most recently
        ->and($guest->name)->toBe('Guest One Renamed');
});

it('lets someone who ordered as a guest register with that same email and keep their orders', function () {
    $geo = p5Geo();
    $warehouse = p5Warehouse();
    $variant = p5Product($warehouse->id, stock: 5)->variants->first();

    $this->post(route('cart.store'), ['product_variant_id' => $variant->id, 'quantity' => 1]);
    $this->post(route('checkout.store'), [
        'name' => 'Guest', 'email' => 'claimme@waqar.test', 'phone' => '01000000001',
        'governorate_id' => $geo['governorate']->id,
        'city_id' => $geo['city']->id,
        'area_id' => $geo['area']->id,
        'address_line' => 'X',
    ])->assertRedirect();

    $guestId = Customer::where('email', 'claimme@waqar.test')->value('id');

    $this->post(route('register.store'), [
        'name' => 'Real Name',
        'email' => 'claimme@waqar.test',
        'phone' => '01100000001',
        'password' => 'password123!',
        'password_confirmation' => 'password123!',
    ])->assertRedirect(route('account.dashboard'));

    // Same row claimed, not a duplicate — so the guest order is now in
    // their account rather than orphaned under an unreachable record.
    $customer = Customer::where('email', 'claimme@waqar.test')->firstOrFail();
    expect(Customer::where('email', 'claimme@waqar.test')->count())->toBe(1)
        ->and($customer->id)->toBe($guestId)
        ->and($customer->is_guest)->toBeFalse()
        ->and($customer->orders()->count())->toBe(1);

    $this->get(route('account.orders'))->assertInertia(fn ($page) => $page->has('orders', 1));
});

it('still blocks registering over a real account', function () {
    $registered = p5Customer();

    $this->post(route('register.store'), [
        'name' => 'Impostor',
        'email' => $registered->email,
        'phone' => '01012345678',
        'password' => 'password123!',
        'password_confirmation' => 'password123!',
    ])->assertSessionHasErrors('email');
});

/*
|--------------------------------------------------------------------------
| Country level + contact form
|--------------------------------------------------------------------------
*/

it('serves the cascade rooted at the countries table, not a hard-coded option', function () {
    p5Geo();

    $this->get(route('cart.index'))
        ->assertInertia(fn ($page) => $page
            ->has('countries', 1)
            ->where('countries.0.code', 'EG')
            ->has('countries.0.governorates', 1)
            ->has('countries.0.governorates.0.cities', 1)
            ->has('countries.0.governorates.0.cities.0.areas', 2));
});

it('sends the contact form to the support inbox, rejects the honeypot, and rate-limits it', function () {
    Mail::fake();
    RateLimiter::clear('contact-form:127.0.0.1');

    $payload = [
        'name' => 'Shopper',
        'email' => 'shopper@waqar.test',
        'order_number' => '1001',
        'message' => 'My order has not arrived yet, could you check?',
    ];

    $this->post(route('pages.contact.send'), $payload)->assertSessionHasNoErrors();
    Mail::assertSent(ContactMessageMail::class, fn (ContactMessageMail $mail) => $mail->hasTo(config('mail.support_address')));

    // A filled honeypot is a bot, and never reaches the mailer.
    $this->post(route('pages.contact.send'), [...$payload, 'website' => 'http://spam.test'])
        ->assertSessionHasErrors('website');
    Mail::assertSentCount(1);

    // Third send in the window is fine, fourth is throttled.
    $this->post(route('pages.contact.send'), $payload);
    $this->post(route('pages.contact.send'), $payload);
    $this->post(route('pages.contact.send'), $payload)->assertSessionHasErrors('message');
    Mail::assertSentCount(3);
});
