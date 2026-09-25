<?php

use App\Actions\Checkout\CreateOrderAction;
use App\Actions\Orders\AssignDeliveryAction;
use App\Actions\Orders\ConfirmDeliveryResultAction;
use App\Actions\Orders\ConfirmOrderAction;
use App\Actions\Returns\AcceptReturnShippingFeeAction;
use App\Actions\Returns\ApproveReturnAction;
use App\Actions\Returns\AssignReturnPickupAction;
use App\Actions\Returns\ReceiveReturnAction;
use App\Actions\Returns\RequestReturnAction;
use App\Enums\CollectedMethod;
use App\Enums\DeliveryAssignmentType;
use App\Enums\OrderStatus;
use App\Enums\ReturnStatus;
use App\Models\DeliveryRepresentative;
use App\Models\Employee;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ReturnReason;
use App\Models\Treasury;
use App\Models\TreasuryTransaction;
use App\Models\WarehouseInventory;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ReturnReasonSeeder;
use Database\Seeders\RoleSeeder;
use Spatie\Permission\Models\Role;

// E2 — the replacement cycle (feature-backlog-plan.md).
//
// The customer sends an item back and receives a different one. They pay
// the shipping either way, plus the difference if they trade up. The
// value of what they returned becomes a credit.
//
// rp* prefix: Pest loads every Feature file into one global namespace.

function rpEmployee(string $role): Employee
{
    $employee = Employee::create([
        'full_name' => $role.' User', 'email' => 'rp-'.uniqid().'@waqar.test', 'phone' => '01012345678',
        'password' => 'password', 'residence_address' => 'N/A', 'national_id_number' => 'N/A',
    ]);
    $employee->assignRole(Role::findOrCreate($role, 'employee'));

    return $employee;
}

function rpVariant(int $warehouseId, float $price, int $stock = 10): ProductVariant
{
    $product = Product::create([
        'name' => ['ar' => 'منتج', 'en' => 'Widget'],
        'slug' => 'w-'.uniqid(), 'sku' => 'W-'.uniqid(), 'price' => $price,
    ]);
    $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'W-'.uniqid().'-V']);
    WarehouseInventory::create([
        'warehouse_id' => $warehouseId, 'product_variant_id' => $variant->id,
        'quantity' => $stock, 'reserved_quantity' => 0,
    ]);

    return $variant;
}

/**
 * A delivered order for one 100 EGP item, with a return filed against it
 * and walked up to step 5 — approved, collected and received — the only
 * point a replacement may be sent. Shipping is 50 (setUpGeoAndShipping's
 * governorate rate).
 *
 * @return array{0: OrderReturn, 1: Employee, 2: array<string, mixed>}
 */
function rpReceivedReturn(): array
{
    $geo = setUpGeoAndShipping();
    $variant = rpVariant($geo['warehouse']->id, 100.0);
    $customer = makeCustomer();
    $staff = rpEmployee('Accounting');

    $order = app(CreateOrderAction::class)->execute(
        $customer, [['product_variant_id' => $variant->id, 'quantity' => 1]], $geo['warehouse'],
        $geo['governorate']->id, $geo['city']->id, null, $geo['area']->id, 'Line', 'Test', '01012345678',
    );

    app(ConfirmOrderAction::class)->execute($order, $staff);
    app(AssignDeliveryAction::class)->execute(
        $order->fresh(), $staff, DeliveryAssignmentType::Representative,
        DeliveryRepresentative::create(['name' => 'Rep', 'phone' => '01012345678']),
    );
    app(ConfirmDeliveryResultAction::class)->confirmDelivered(
        $order->fresh(), $staff,
        Treasury::create(['name' => 'Cash', 'type' => 'cash']),
        CollectedMethod::Cash,
    );

    $return = app(RequestReturnAction::class)->execute(
        $order->fresh(), $customer,
        [['order_item_id' => $order->items()->firstOrFail()->id, 'quantity' => 1]],
        ReturnReason::query()->firstOrFail()->id,
        'Wrong size',
    );

    app(AcceptReturnShippingFeeAction::class)->execute($return, $customer, 0.0);
    app(ApproveReturnAction::class)->execute($return->fresh(), $staff);
    app(AssignReturnPickupAction::class)->execute(
        $return->fresh(), $staff, DeliveryAssignmentType::Representative, DeliveryRepresentative::firstOrFail(),
    );
    app(ReceiveReturnAction::class)->execute($return->fresh(), $staff, $geo['warehouse']);

    return [$return->fresh(), $staff, $geo];
}

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class, ReturnReasonSeeder::class]);
});

it('charges only the shipping when the replacement costs the same', function () {
    [$return, $staff, $geo] = rpReceivedReturn();
    $swap = rpVariant($geo['warehouse']->id, 100.0);

    $this->actingAs($staff, 'employee')->post(route('admin.returns.replace', $return), [
        'items' => [['product_variant_id' => $swap->id, 'quantity' => 1]],
    ])->assertRedirect();

    $replacement = Order::where('replaces_order_id', $return->order_id)->firstOrFail();

    expect((float) $replacement->subtotal)->toBe(100.0)
        // The returned item's value, credited back.
        ->and((float) $replacement->discount_amount)->toBe(100.0)
        ->and((float) $replacement->shipping_amount)->toBe(50.0)
        ->and((float) $replacement->total)->toBe(50.0)
        // total = subtotal − discount + shipping still holds, which is
        // why nothing downstream needs a special case.
        ->and((float) $replacement->payments()->firstOrFail()->amount)->toBe(50.0)
        // Line items keep their real price: zeroing unit_price would make
        // subtotal 0 and break dueForKeptItems()'s discount share.
        ->and((float) $replacement->items()->firstOrFail()->unit_price)->toBe(100.0);
});

it('charges the difference when the customer trades up', function () {
    [$return, $staff, $geo] = rpReceivedReturn();
    $dearer = rpVariant($geo['warehouse']->id, 200.0);

    $this->actingAs($staff, 'employee')->post(route('admin.returns.replace', $return), [
        'items' => [['product_variant_id' => $dearer->id, 'quantity' => 1]],
    ])->assertRedirect();

    $replacement = Order::where('replaces_order_id', $return->order_id)->firstOrFail();

    expect((float) $replacement->subtotal)->toBe(200.0)
        ->and((float) $replacement->discount_amount)->toBe(100.0)
        ->and((float) $replacement->total)->toBe(150.0)
        // 100 of goods reaches the treasury; the courier keeps the 50.
        ->and($replacement->netDueToTreasury())->toBe(100.0);
});

it('never goes negative when the customer trades down, and refunds no difference', function () {
    [$return, $staff, $geo] = rpReceivedReturn();
    $cheaper = rpVariant($geo['warehouse']->id, 80.0);

    $this->actingAs($staff, 'employee')->post(route('admin.returns.replace', $return), [
        'items' => [['product_variant_id' => $cheaper->id, 'quantity' => 1]],
    ])->assertRedirect();

    $replacement = Order::where('replaces_order_id', $return->order_id)->firstOrFail();

    // Credit capped at the outgoing subtotal — the 20 difference is not
    // refunded, and the total is the shipping alone rather than -20.
    expect((float) $replacement->discount_amount)->toBe(80.0)
        ->and((float) $replacement->total)->toBe(50.0);
});

it('writes no treasury row when a same-price replacement is delivered', function () {
    [$return, $staff, $geo] = rpReceivedReturn();
    $swap = rpVariant($geo['warehouse']->id, 100.0);
    $treasury = Treasury::firstOrFail();
    $before = TreasuryTransaction::count();

    $this->actingAs($staff, 'employee')->post(route('admin.returns.replace', $return), [
        'items' => [['product_variant_id' => $swap->id, 'quantity' => 1]],
    ])->assertRedirect();

    $replacement = Order::where('replaces_order_id', $return->order_id)->firstOrFail();

    app(ConfirmOrderAction::class)->execute($replacement, $staff);
    app(AssignDeliveryAction::class)->execute(
        $replacement->fresh(), $staff, DeliveryAssignmentType::Representative,
        DeliveryRepresentative::firstOrFail(),
    );
    app(ConfirmDeliveryResultAction::class)->confirmDelivered(
        $replacement->fresh(), $staff, $treasury, CollectedMethod::Cash,
    );

    // Owes shipping only, which the courier keeps — so nothing reaches
    // the treasury and no zero-amount row is written. This is Phase C's
    // `if ($amount > 0)` guard doing its job.
    expect($replacement->fresh()->netDueToTreasury())->toBe(0.0)
        ->and(TreasuryTransaction::count())->toBe($before)
        ->and($replacement->fresh()->payment_status->value)->toBe('collected')
        ->and($replacement->fresh()->status)->toBe(OrderStatus::Delivered);
});

it('reserves stock for the outgoing item and completes the return without a refund', function () {
    [$return, $staff, $geo] = rpReceivedReturn();
    $swap = rpVariant($geo['warehouse']->id, 100.0, stock: 5);

    // Completed has been in the enum since Phase 1 with no writer.
    expect(OrderReturn::where('status', ReturnStatus::Completed)->count())->toBe(0);

    $this->actingAs($staff, 'employee')->post(route('admin.returns.replace', $return), [
        'items' => [['product_variant_id' => $swap->id, 'quantity' => 1]],
    ])->assertRedirect();

    $stock = WarehouseInventory::where('product_variant_id', $swap->id)->firstOrFail();
    expect($stock->quantity)->toBe(5)          // not deducted yet
        ->and($stock->reserved_quantity)->toBe(1)
        ->and($return->fresh()->status)->toBe(ReturnStatus::Completed)
        // No refund was paid, so the ORIGINAL order must not read as
        // refunded — that is why RefundReturnAction is bypassed.
        ->and($return->fresh()->order->payment_status->value)->not->toBe('refunded');
});

it('refuses to replace a return that has not been approved', function () {
    $geo = setUpGeoAndShipping();
    $variant = rpVariant($geo['warehouse']->id, 100.0);
    $customer = makeCustomer();
    $staff = rpEmployee('Accounting');

    $order = app(CreateOrderAction::class)->execute(
        $customer, [['product_variant_id' => $variant->id, 'quantity' => 1]], $geo['warehouse'],
        $geo['governorate']->id, $geo['city']->id, null, $geo['area']->id, 'Line', 'Test', '01012345678',
    );
    app(ConfirmOrderAction::class)->execute($order, $staff);
    app(AssignDeliveryAction::class)->execute(
        $order->fresh(), $staff, DeliveryAssignmentType::Representative,
        DeliveryRepresentative::create(['name' => 'Rep', 'phone' => '01012345678']),
    );
    app(ConfirmDeliveryResultAction::class)->confirmDelivered(
        $order->fresh(), $staff, Treasury::create(['name' => 'Cash', 'type' => 'cash']), CollectedMethod::Cash,
    );

    // Still Requested — the swap has not been agreed with the customer.
    $return = app(RequestReturnAction::class)->execute(
        $order->fresh(), $customer,
        [['order_item_id' => $order->items()->firstOrFail()->id, 'quantity' => 1]],
        ReturnReason::query()->firstOrFail()->id, 'Wrong size',
    );

    $this->actingAs($staff, 'employee')->post(route('admin.returns.replace', $return), [
        'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
    ])->assertSessionHas('error');

    expect(Order::whereNotNull('replaces_order_id')->count())->toBe(0);
});

it('finds the replacement by product name, with its colours and sizes, for whoever can replace', function () {
    [, $staff, $geo] = rpReceivedReturn();
    rpVariant($geo['warehouse']->id, 120.0);

    $this->actingAs($staff, 'employee')
        ->getJson(route('admin.returns.product-search', ['q' => 'Widget']))
        ->assertOk()
        ->assertJsonPath('products.0.name', 'Widget')
        ->assertJsonStructure(['products' => [['id', 'name', 'colors', 'sizes', 'variants' => [['id', 'sku', 'price', 'options']]]]]);

    // Customer Service files returns but does not send replacements.
    $this->actingAs(rpEmployee('Customer Service'), 'employee')
        ->getJson(route('admin.returns.product-search', ['q' => 'Widget']))
        ->assertForbidden();
});

it('quotes the replacement with the same credit, difference and location shipping the order is then created with', function () {
    [$return, $staff, $geo] = rpReceivedReturn();
    $dearer = rpVariant($geo['warehouse']->id, 200.0);

    // 200 new − 100 returned = 100 difference, + 50 shipping for the
    // original address's governorate (setUpGeoAndShipping's rate).
    $this->actingAs($staff, 'employee')
        ->getJson(route('admin.returns.replacement-quote', [
            $return,
            'items' => [['product_variant_id' => $dearer->id, 'quantity' => 1]],
        ]))
        ->assertOk()
        ->assertExactJson([
            'returned_value' => 100.0,
            'subtotal' => 200.0,
            'credit' => 100.0,
            'difference' => 100.0,
            'shipping' => 50.0,
            'total' => 150.0,
        ]);

    $this->actingAs($staff, 'employee')->post(route('admin.returns.replace', $return), [
        'items' => [['product_variant_id' => $dearer->id, 'quantity' => 1]],
    ])->assertRedirect();

    $replacement = Order::where('replaces_order_id', $return->order_id)->firstOrFail();
    expect((float) $replacement->total)->toBe(150.0)
        ->and((float) $replacement->shipping_amount)->toBe(50.0)
        ->and((float) $replacement->discount_amount)->toBe(100.0);
});

it('credits every returned unit when the same quantity is sent back out', function () {
    [$return, $staff, $geo] = rpReceivedReturn();
    $same = rpVariant($geo['warehouse']->id, 100.0);

    // One unit came back (100 credit); sending two out charges one unit's
    // price as the difference, plus shipping.
    $this->actingAs($staff, 'employee')
        ->getJson(route('admin.returns.replacement-quote', [
            $return,
            'items' => [['product_variant_id' => $same->id, 'quantity' => 2]],
        ]))
        ->assertOk()
        ->assertJsonPath('credit', 100)
        ->assertJsonPath('difference', 100)
        ->assertJsonPath('total', 150);
});
