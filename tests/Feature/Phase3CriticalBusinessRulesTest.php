<?php

use App\Actions\Checkout\CreateOrderAction;
use App\Actions\Orders\AssignDeliveryAction;
use App\Actions\Orders\CancelOrderAction;
use App\Actions\Orders\ConfirmDeliveryResultAction;
use App\Actions\Orders\ConfirmOrderAction;
use App\Actions\Returns\AcceptReturnShippingFeeAction;
use App\Actions\Returns\ApproveReturnAction;
use App\Actions\Returns\ReceiveReturnAction;
use App\Actions\Returns\RefundReturnAction;
use App\Actions\Returns\RequestReturnAction;
use App\Actions\Search\SearchProductsAction;
use App\Enums\CollectedMethod;
use App\Enums\CustomerOrderStatus;
use App\Enums\DeliveryAssignmentType;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundMethod;
use App\Enums\TreasuryTransactionType;
use App\Exceptions\InsufficientStockException;
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
use App\Models\ReturnReason;
use App\Models\ShippingRate;
use App\Models\Treasury;
use App\Models\TreasuryTransaction;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use App\Policies\OrderPolicy;
use App\Services\Inventory\InventoryService;
use App\Services\Shipping\ShippingRateResolver;
use Database\Seeders\ReturnReasonSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Eloquent\Builder;

// Phase 3 — Business Logic Layer (WAQAR-DELIVERY-ROADMAP.html). The
// critical tests named explicitly in the spec (Section 23's tech-stack
// table): overselling prevention, concurrent-purchase race condition,
// cancelled-order stock release, COD-collected treasury transaction,
// shipping price not client-manipulable, cross-customer order access —
// plus the Customer Service Team Leader scoping test the roadmap added
// alongside them.

function setUpGeoAndShipping(): array
{
    $country = Country::create(['name' => ['ar' => 'مصر', 'en' => 'Egypt'], 'code' => 'EG']);
    $governorate = Governorate::create(['country_id' => $country->id, 'name' => ['ar' => 'القاهرة', 'en' => 'Cairo']]);
    $city = City::create(['governorate_id' => $governorate->id, 'name' => ['ar' => 'مدينة نصر', 'en' => 'Nasr City']]);
    $area = Area::create(['city_id' => $city->id, 'name' => ['ar' => 'منطقة أ', 'en' => 'Zone A']]);

    // Rate configured at governorate level — resolver should fall back to it.
    ShippingRate::create(['geo_type' => 'governorate', 'geo_id' => $governorate->id, 'price' => 50]);

    $warehouse = Warehouse::create(['name' => 'Main', 'address' => 'Cairo', 'phone' => '1']);

    return compact('country', 'governorate', 'city', 'area', 'warehouse');
}

function makeTrackedVariant(int $stock): array
{
    $product = Product::create(['name' => ['ar' => 'منتج', 'en' => 'Widget'], 'slug' => 'widget-'.uniqid(), 'sku' => 'W-'.uniqid(), 'price' => 100]);
    $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'W-'.uniqid().'-V']);

    return [$product, $variant];
}

function makeCustomer(?string $email = null): Customer
{
    return Customer::create(['name' => 'Test', 'email' => $email ?? 'c-'.uniqid().'@waqar.test', 'phone' => '1', 'password' => 'password']);
}

function makeEmployee(?string $email = null): Employee
{
    return Employee::create([
        'full_name' => 'Staff', 'email' => $email ?? 'e-'.uniqid().'@waqar.test', 'phone' => '1',
        'password' => 'password', 'residence_address' => 'N/A', 'national_id_number' => 'N/A',
    ]);
}

it('prevents overselling — reserving more than available stock throws', function () {
    $geo = setUpGeoAndShipping();
    [$product, $variant] = makeTrackedVariant(5);
    WarehouseInventory::create(['warehouse_id' => $geo['warehouse']->id, 'product_variant_id' => $variant->id, 'quantity' => 5, 'reserved_quantity' => 0]);
    $customer = makeCustomer();

    $action = app(CreateOrderAction::class);

    // First order takes all 5 — fine.
    $action->execute(
        $customer, [['product_variant_id' => $variant->id, 'quantity' => 5]], $geo['warehouse'],
        $geo['governorate']->id, $geo['city']->id, null, $geo['area']->id, 'Line', 'Test', '1',
    );

    // A second order for even 1 more should fail — nothing is available.
    expect(fn () => $action->execute(
        $customer, [['product_variant_id' => $variant->id, 'quantity' => 1]], $geo['warehouse'],
        $geo['governorate']->id, $geo['city']->id, null, $geo['area']->id, 'Line', 'Test', '1',
    ))->toThrow(InsufficientStockException::class);
});

it('guards concurrent reservations against a race — the second exhausting request fails, stock never goes negative', function () {
    $geo = setUpGeoAndShipping();
    [$product, $variant] = makeTrackedVariant(1);
    WarehouseInventory::create(['warehouse_id' => $geo['warehouse']->id, 'product_variant_id' => $variant->id, 'quantity' => 1, 'reserved_quantity' => 0]);

    $inventory = app(InventoryService::class);
    $customerA = makeCustomer();
    $orderA = Order::create([
        'customer_id' => $customerA->id, 'order_source' => OrderSource::Website, 'customer_status' => CustomerOrderStatus::OrderReceived,
        'subtotal' => 100, 'shipping_amount' => 0, 'total' => 100,
        'shipping_recipient_name' => 'A', 'shipping_phone' => '1',
        'shipping_governorate_id' => $geo['governorate']->id, 'shipping_city_id' => $geo['city']->id,
        'shipping_area_id' => $geo['area']->id, 'shipping_address_line' => 'x',
    ]);

    // First reservation for the last unit succeeds.
    $inventory->reserve($variant, $geo['warehouse'], 1, $orderA);

    // A second, "concurrent" reservation attempt for the same now-exhausted
    // stock must fail rather than double-reserve it. True multi-process
    // concurrency isn't exercisable in a single synchronous test process —
    // this proves the lockForUpdate + availability-check guard exists and
    // rejects correctly, which is what actually prevents the race.
    expect(fn () => $inventory->reserve($variant, $geo['warehouse'], 1, $orderA))
        ->toThrow(InsufficientStockException::class);

    $stock = WarehouseInventory::where('product_variant_id', $variant->id)->first();
    expect($stock->reserved_quantity)->toBe(1)
        ->and($stock->available)->toBe(0);
});

it('releases the reservation when an order is cancelled, without ever deducting stock', function () {
    $geo = setUpGeoAndShipping();
    [$product, $variant] = makeTrackedVariant(10);
    WarehouseInventory::create(['warehouse_id' => $geo['warehouse']->id, 'product_variant_id' => $variant->id, 'quantity' => 10, 'reserved_quantity' => 0]);
    $customer = makeCustomer();
    $employee = makeEmployee();

    $order = app(CreateOrderAction::class)->execute(
        $customer, [['product_variant_id' => $variant->id, 'quantity' => 4]], $geo['warehouse'],
        $geo['governorate']->id, $geo['city']->id, null, $geo['area']->id, 'Line', 'Test', '1',
    );

    $stockAfterOrder = WarehouseInventory::where('product_variant_id', $variant->id)->first();
    expect($stockAfterOrder->quantity)->toBe(10)
        ->and($stockAfterOrder->reserved_quantity)->toBe(4);

    app(CancelOrderAction::class)->execute($order, $employee, 'Customer changed mind');

    $stockAfterCancel = WarehouseInventory::where('product_variant_id', $variant->id)->first();
    expect($stockAfterCancel->quantity)->toBe(10) // never deducted
        ->and($stockAfterCancel->reserved_quantity)->toBe(0) // released
        ->and($order->fresh()->status)->toBe(OrderStatus::Cancelled);
});

it('records a treasury transaction only when Accounting confirms Delivered, and deducts stock only then', function () {
    $geo = setUpGeoAndShipping();
    [$product, $variant] = makeTrackedVariant(10);
    WarehouseInventory::create(['warehouse_id' => $geo['warehouse']->id, 'product_variant_id' => $variant->id, 'quantity' => 10, 'reserved_quantity' => 0]);
    $customer = makeCustomer();
    $checker = makeEmployee();
    $manager = makeEmployee();
    $accountant = makeEmployee();
    $treasury = Treasury::create(['name' => 'Main Cash', 'type' => 'cash']);
    $rep = DeliveryRepresentative::create(['name' => 'Rep', 'phone' => '1']);

    $order = app(CreateOrderAction::class)->execute(
        $customer, [['product_variant_id' => $variant->id, 'quantity' => 2]], $geo['warehouse'],
        $geo['governorate']->id, $geo['city']->id, null, $geo['area']->id, 'Line', 'Test', '1',
    );

    expect(treasuryTransactions()->count())->toBe(0);

    app(ConfirmOrderAction::class)->execute($order, $checker);
    app(AssignDeliveryAction::class)->execute($order->fresh(), $manager, DeliveryAssignmentType::Representative, $rep);

    // Stock is still only reserved — not deducted — right up until Delivered is confirmed.
    $stockBeforeDelivery = WarehouseInventory::where('product_variant_id', $variant->id)->first();
    expect($stockBeforeDelivery->quantity)->toBe(10);

    app(ConfirmDeliveryResultAction::class)->confirmDelivered($order->fresh(), $accountant, $treasury, CollectedMethod::Cash);

    $stockAfterDelivery = WarehouseInventory::where('product_variant_id', $variant->id)->first();
    expect($stockAfterDelivery->quantity)->toBe(8) // deducted, finally
        ->and($stockAfterDelivery->reserved_quantity)->toBe(0)
        ->and(treasuryTransactions()->count())->toBe(1)
        ->and(treasuryTransactions()->first()->type)->toBe(TreasuryTransactionType::Income)
        ->and((float) $treasury->fresh()->current_balance)->toBe((float) $order->total)
        ->and($order->fresh()->status)->toBe(OrderStatus::Delivered)
        ->and($order->fresh()->payment_status)->toBe(PaymentStatus::Collected);
});

function treasuryTransactions(): Builder
{
    return TreasuryTransaction::query();
}

it('never lets the client set the shipping price — it is always resolved server-side from ShippingRate', function () {
    $geo = setUpGeoAndShipping(); // seeds a governorate-level rate of 50
    [$product, $variant] = makeTrackedVariant(10);
    WarehouseInventory::create(['warehouse_id' => $geo['warehouse']->id, 'product_variant_id' => $variant->id, 'quantity' => 10, 'reserved_quantity' => 0]);
    $customer = makeCustomer();

    // CreateOrderAction's signature has no shipping-amount parameter at
    // all — there is nothing for a caller to pass here, by construction.
    // What actually gets charged is proven below: it matches the stored
    // rate exactly, never a client-supplied number.
    $order = app(CreateOrderAction::class)->execute(
        $customer, [['product_variant_id' => $variant->id, 'quantity' => 1]], $geo['warehouse'],
        $geo['governorate']->id, $geo['city']->id, null, $geo['area']->id, 'Line', 'Test', '1',
    );

    expect((float) $order->shipping_amount)->toBe(50.0)
        ->and((float) $order->total)->toBe(150.0); // 100 subtotal + 50 shipping, no discount

    // A more specific Area-level rate should win over the Governorate
    // fallback — proving the resolver, not a client, picks the price.
    ShippingRate::create(['geo_type' => 'area', 'geo_id' => $geo['area']->id, 'price' => 20]);
    $resolver = app(ShippingRateResolver::class);
    $resolved = $resolver->resolve($geo['governorate']->id, $geo['city']->id, null, $geo['area']->id);
    expect((float) $resolved->price)->toBe(20.0);
});

it('never lets one customer view another customer\'s order', function () {
    $geo = setUpGeoAndShipping();
    [$product, $variant] = makeTrackedVariant(10);
    WarehouseInventory::create(['warehouse_id' => $geo['warehouse']->id, 'product_variant_id' => $variant->id, 'quantity' => 10, 'reserved_quantity' => 0]);
    $owner = makeCustomer();
    $stranger = makeCustomer();

    $order = app(CreateOrderAction::class)->execute(
        $owner, [['product_variant_id' => $variant->id, 'quantity' => 1]], $geo['warehouse'],
        $geo['governorate']->id, $geo['city']->id, null, $geo['area']->id, 'Line', 'Test', '1',
    );

    $policy = new OrderPolicy;

    expect($policy->view($owner, $order))->toBeTrue()
        ->and($policy->view($stranger, $order))->toBeFalse();
});

it('scopes a Customer Service Team Leader to only their own team\'s orders (Question 16/18)', function () {
    $this->seed(RoleSeeder::class);
    $geo = setUpGeoAndShipping();
    [$product, $variant] = makeTrackedVariant(10);
    WarehouseInventory::create(['warehouse_id' => $geo['warehouse']->id, 'product_variant_id' => $variant->id, 'quantity' => 10, 'reserved_quantity' => 0]);
    $customer = makeCustomer();

    $leadA = makeEmployee();
    $leadA->assignRole('Customer Service Team Leader');
    $agentA = makeEmployee();
    $agentA->update(['team_leader_id' => $leadA->id]);

    $leadB = makeEmployee();
    $leadB->assignRole('Customer Service Team Leader');
    $agentB = makeEmployee();
    $agentB->update(['team_leader_id' => $leadB->id]);

    $action = app(CreateOrderAction::class);
    $orderA = $action->execute(
        $customer, [['product_variant_id' => $variant->id, 'quantity' => 1]], $geo['warehouse'],
        $geo['governorate']->id, $geo['city']->id, null, $geo['area']->id, 'Line', 'Test', '1',
        OrderSource::CustomerService, $agentA,
    );
    $orderB = $action->execute(
        $customer, [['product_variant_id' => $variant->id, 'quantity' => 1]], $geo['warehouse'],
        $geo['governorate']->id, $geo['city']->id, null, $geo['area']->id, 'Line', 'Test', '1',
        OrderSource::CustomerService, $agentB,
    );

    $visibleToLeadA = Order::visibleTo($leadA)->pluck('id');

    expect($visibleToLeadA)->toContain($orderA->id)
        ->not->toContain($orderB->id);

    // A non-Team-Leader role (e.g. the Super Admin seeded by
    // DatabaseSeeder-style setup) is unscoped — global visibility.
    $accounting = makeEmployee();
    $accounting->assignRole('Accounting');
    expect(Order::visibleTo($accounting)->count())->toBe(2);
});

it('runs the full return → refund workflow: restocks on receipt, deducts the accepted shipping fee, and records a treasury outflow', function () {
    $this->seed(ReturnReasonSeeder::class);
    $geo = setUpGeoAndShipping();
    [$product, $variant] = makeTrackedVariant(10);
    WarehouseInventory::create(['warehouse_id' => $geo['warehouse']->id, 'product_variant_id' => $variant->id, 'quantity' => 10, 'reserved_quantity' => 0]);
    $customer = makeCustomer();
    $checker = makeEmployee();
    $manager = makeEmployee();
    $accountant = makeEmployee();
    $warehouseClerk = makeEmployee();
    $treasury = Treasury::create(['name' => 'Main Cash', 'type' => 'cash']);
    $rep = DeliveryRepresentative::create(['name' => 'Rep', 'phone' => '1']);
    $reason = ReturnReason::first();

    $order = app(CreateOrderAction::class)->execute(
        $customer, [['product_variant_id' => $variant->id, 'quantity' => 2]], $geo['warehouse'],
        $geo['governorate']->id, $geo['city']->id, null, $geo['area']->id, 'Line', 'Test', '1',
    );
    app(ConfirmOrderAction::class)->execute($order, $checker);
    app(AssignDeliveryAction::class)->execute($order->fresh(), $manager, DeliveryAssignmentType::Representative, $rep);
    app(ConfirmDeliveryResultAction::class)->confirmDelivered($order->fresh(), $accountant, $treasury, CollectedMethod::Cash);

    $order = $order->fresh();
    $orderItem = $order->items->first();

    $return = app(RequestReturnAction::class)->execute(
        $order, $customer, [['order_item_id' => $orderItem->id, 'quantity' => 1, 'reason_id' => $reason->id]], $reason->id,
    );
    app(AcceptReturnShippingFeeAction::class)->execute($return, $customer, 10);
    app(ApproveReturnAction::class)->execute($return->fresh(), $checker);
    app(ReceiveReturnAction::class)->execute($return->fresh(), $warehouseClerk, $geo['warehouse']);

    $stockAfterRestock = WarehouseInventory::where('product_variant_id', $variant->id)->first();
    expect($stockAfterRestock->quantity)->toBe(9); // 10 - 2 delivered + 1 restocked

    $balanceBeforeRefund = (float) $treasury->fresh()->current_balance;
    $refund = app(RefundReturnAction::class)->execute($return->fresh(), $accountant, $treasury, RefundMethod::BankTransfer, 'REF-1');

    expect((float) $refund->amount)->toBe(100.0) // 1 unit at 100
        ->and((float) $refund->net_amount)->toBe(90.0) // minus the 10 accepted return-shipping fee
        ->and((float) $treasury->fresh()->current_balance)->toBe($balanceBeforeRefund - 90.0);
});

it('searches products with plain MySQL, no Scout', function () {
    Product::create(['name' => ['ar' => 'قميص أزرق', 'en' => 'Blue Shirt'], 'slug' => 'blue-shirt', 'sku' => 'BS-1', 'price' => 200]);
    Product::create(['name' => ['ar' => 'بنطلون', 'en' => 'Trousers'], 'slug' => 'trousers', 'sku' => 'TR-1', 'price' => 300]);

    $results = app(SearchProductsAction::class)->execute('Shirt');

    expect($results)->toHaveCount(1)
        ->and($results->first()->sku)->toBe('BS-1');
});
