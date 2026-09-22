<?php

use App\Actions\Checkout\CreateOrderAction;
use App\Actions\Orders\AssignDeliveryAction;
use App\Actions\Orders\ConfirmDeliveryResultAction;
use App\Actions\Orders\ConfirmOrderAction;
use App\Actions\Returns\AcceptReturnShippingFeeAction;
use App\Actions\Returns\ApproveReturnAction;
use App\Actions\Returns\RequestReturnAction;
use App\Enums\CollectedMethod;
use App\Enums\DeliveryAssignmentType;
use App\Enums\ReturnStatus;
use App\Models\DeliveryRepresentative;
use App\Models\Employee;
use App\Models\OrderReturn;
use App\Models\ReturnReason;
use App\Models\ShippingCompany;
use App\Models\Treasury;
use App\Models\WarehouseInventory;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ReturnReasonSeeder;
use Database\Seeders\RoleSeeder;
use Spatie\Permission\Models\Role;

// E1 — returns go through Checking (feature-backlog-plan.md).
//
// Checking phones the customer, confirms the reason they gave, and for a
// replacement agrees the swap the way it would a new order. Three
// outcomes, the same three verbs Checking already has on an order.
//
// rc* prefix: Pest loads every Feature file into one global namespace.

function rcEmployee(string $role): Employee
{
    $employee = Employee::create([
        'full_name' => $role.' User', 'email' => 'rc-'.uniqid().'@waqar.test', 'phone' => '01012345678',
        'password' => 'password', 'residence_address' => 'N/A', 'national_id_number' => 'N/A',
    ]);
    $employee->assignRole(Role::findOrCreate($role, 'employee'));

    return $employee;
}

/**
 * A delivered order with a return filed against it, sitting at Requested.
 */
function rcReturn(): OrderReturn
{
    $geo = setUpGeoAndShipping();
    [, $variant] = makeTrackedVariant(10);
    WarehouseInventory::create([
        'warehouse_id' => $geo['warehouse']->id, 'product_variant_id' => $variant->id,
        'quantity' => 10, 'reserved_quantity' => 0,
    ]);

    $customer = makeCustomer();
    $staff = makeEmployee();

    $order = app(CreateOrderAction::class)->execute(
        $customer, [['product_variant_id' => $variant->id, 'quantity' => 2]], $geo['warehouse'],
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

    $item = $order->items()->firstOrFail();

    return app(RequestReturnAction::class)->execute(
        $order->fresh(),
        $customer,
        [['order_item_id' => $item->id, 'quantity' => 1]],
        ReturnReason::query()->firstOrFail()->id,
        'Too small',
    );
}

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class, ReturnReasonSeeder::class]);
});

it('records the call and approves the return when Checking confirms it', function () {
    $return = rcReturn();
    $checker = rcEmployee('Checking');

    // Consent to the return shipping fee first — ApproveReturnAction
    // requires it on a post-delivery return, and confirm() delegates to
    // that rule rather than restating it.
    app(AcceptReturnShippingFeeAction::class)
        ->execute($return, $return->customer, 25.0);

    $this->actingAs($checker, 'employee')->post(route('admin.returns.check', $return->fresh()), [
        'outcome' => 'confirm',
        'notes' => 'Customer confirmed the size was wrong.',
    ])->assertRedirect();

    $return->refresh();
    expect($return->status)->toBe(ReturnStatus::Approved)
        ->and($return->checked_by_employee_id)->toBe($checker->id)
        ->and($return->checked_at)->not->toBeNull()
        ->and($return->checking_notes)->toBe('Customer confirmed the size was wrong.');
});

it('writes ReturnStatus::Rejected when Checking cancels — the first code path that ever has', function () {
    $return = rcReturn();
    $checker = rcEmployee('Checking');

    // `rejected` has been in the enum since Phase 1 with no writer.
    expect(OrderReturn::where('status', ReturnStatus::Rejected)->count())->toBe(0);

    $this->actingAs($checker, 'employee')->post(route('admin.returns.check', $return), [
        'outcome' => 'cancel',
        'notes' => 'Customer changed their mind.',
    ])->assertRedirect();

    expect($return->fresh()->status)->toBe(ReturnStatus::Rejected)
        ->and($return->fresh()->checking_notes)->toBe('Customer changed their mind.');
});

it('refuses to cancel a return without saying why', function () {
    $return = rcReturn();
    $checker = rcEmployee('Checking');

    // Rejecting a customer's return with no recorded reason is how a
    // dispute becomes unanswerable.
    $this->actingAs($checker, 'employee')->post(route('admin.returns.check', $return), [
        'outcome' => 'cancel',
    ])->assertSessionHasErrors('notes');

    expect($return->fresh()->status)->toBe(ReturnStatus::Requested);
});

it('leaves a rescheduled return in the queue but records that the call was tried', function () {
    $return = rcReturn();
    $checker = rcEmployee('Checking');

    $this->actingAs($checker, 'employee')->post(route('admin.returns.check', $return), [
        'outcome' => 'reschedule',
        'notes' => 'No answer, calling back tomorrow.',
    ])->assertRedirect();

    $return->refresh();
    expect($return->status)->toBe(ReturnStatus::Requested)
        ->and($return->checked_at)->not->toBeNull()
        ->and($return->checking_notes)->toBe('No answer, calling back tomorrow.');
});

it('refuses to check a return that has already moved on', function () {
    $return = rcReturn();
    $checker = rcEmployee('Checking');

    $this->actingAs($checker, 'employee')
        ->post(route('admin.returns.check', $return), ['outcome' => 'confirm'])
        ->assertRedirect();

    // A stale tab posting again gets a flash, not a 500.
    $this->actingAs($checker, 'employee')
        ->post(route('admin.returns.check', $return), ['outcome' => 'confirm'])
        ->assertSessionHas('error');
});

it('rolls the call record back when the approval it delegates to refuses', function () {
    $return = rcReturn();
    $checker = rcEmployee('Checking');

    // A post-delivery return needs the customer's shipping-fee consent
    // before it can be approved — ApproveReturnAction owns that rule, and
    // confirm() delegates to it rather than restating it.
    expect($return->stage->value)->toBe('post_delivery')
        ->and($return->customer_accepted_return_shipping_fee_at)->toBeNull();

    $this->actingAs($checker, 'employee')
        ->post(route('admin.returns.check', $return), ['outcome' => 'confirm', 'notes' => 'Agreed'])
        ->assertSessionHas('error');

    $return->refresh();
    // Both halves rolled back together — no call recorded against a
    // return that was never actually approved.
    expect($return->status)->toBe(ReturnStatus::Requested)
        ->and($return->checked_at)->toBeNull()
        ->and($return->checking_notes)->toBeNull();
});

it('lets Checking open a return but not file one', function () {
    $return = rcReturn();
    $checker = rcEmployee('Checking');

    $this->actingAs($checker, 'employee')->get(route('admin.returns.show', $return))->assertOk();
    $this->actingAs($checker, 'employee')->get(route('admin.returns.create'))->assertForbidden();
});

it('keeps the call behind returns.check', function () {
    $return = rcReturn();
    $warehouse = rcEmployee('Warehouse Manager'); // holds approve, not check

    $this->actingAs($warehouse, 'employee')
        ->post(route('admin.returns.check', $return), ['outcome' => 'confirm'])
        ->assertForbidden();
});

// D3 (returns side) — who collects the goods coming back. Returns named
// nobody at all before this: the warehouse restocked whenever someone
// pressed Received.

it('names a courier to collect an approved return, and lets that change', function () {
    $return = rcReturn();
    $warehouseManager = rcEmployee('Warehouse Manager');

    app(AcceptReturnShippingFeeAction::class)->execute($return, $return->customer, 0.0);
    app(ApproveReturnAction::class)->execute($return->fresh(), $warehouseManager);

    expect($return->fresh()->delivery_representative_id)->toBeNull();

    $ahmed = DeliveryRepresentative::create(['name' => 'Ahmed', 'phone' => '01012345678']);
    $sara = DeliveryRepresentative::create(['name' => 'Sara', 'phone' => '01112345678']);

    $this->actingAs($warehouseManager, 'employee')->post(route('admin.returns.assign-pickup', $return), [
        'assignment_type' => 'representative',
        'assignee_id' => $ahmed->id,
    ])->assertRedirect()->assertSessionHas('success');

    expect($return->fresh()->delivery_representative_id)->toBe($ahmed->id)
        ->and($return->fresh()->delivery_assignment_type->value)->toBe('representative');

    // Same endpoint changes it — a return's status does not move when a
    // courier is named, so there is no separate reassign.
    $this->actingAs($warehouseManager, 'employee')->post(route('admin.returns.assign-pickup', $return), [
        'assignment_type' => 'representative',
        'assignee_id' => $sara->id,
    ])->assertSessionHas('success');

    expect($return->fresh()->delivery_representative_id)->toBe($sara->id);
});

it('can send a shipping company to collect instead of a representative', function () {
    $return = rcReturn();
    $warehouseManager = rcEmployee('Warehouse Manager');

    app(AcceptReturnShippingFeeAction::class)->execute($return, $return->customer, 0.0);
    app(ApproveReturnAction::class)->execute($return->fresh(), $warehouseManager);

    $company = ShippingCompany::create([
        'name' => 'Courier Co', 'phone' => '01012345678', 'address' => 'Cairo',
        'delivery_fee' => 30, 'return_fee' => 20,
    ]);

    $this->actingAs($warehouseManager, 'employee')->post(route('admin.returns.assign-pickup', $return), [
        'assignment_type' => 'shipping_company',
        'assignee_id' => $company->id,
    ])->assertSessionHas('success');

    expect($return->fresh()->shipping_company_id)->toBe($company->id)
        // Exactly one of the two is ever set.
        ->and($return->fresh()->delivery_representative_id)->toBeNull();
});

it('refuses to send anyone for a return that has not been approved yet', function () {
    $return = rcReturn(); // still Requested
    $warehouseManager = rcEmployee('Warehouse Manager');
    $rep = DeliveryRepresentative::create(['name' => 'Ahmed', 'phone' => '01012345678']);

    $this->actingAs($warehouseManager, 'employee')->post(route('admin.returns.assign-pickup', $return), [
        'assignment_type' => 'representative',
        'assignee_id' => $rep->id,
    ])->assertSessionHas('error');

    expect($return->fresh()->delivery_representative_id)->toBeNull();
});
