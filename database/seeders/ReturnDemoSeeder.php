<?php

namespace Database\Seeders;

use App\Actions\Returns\AcceptReturnShippingFeeAction;
use App\Actions\Returns\AssignReturnPickupAction;
use App\Actions\Returns\CheckReturnAction;
use App\Actions\Returns\ReceiveReturnAction;
use App\Actions\Returns\RefundReturnAction;
use App\Actions\Returns\RequestReturnAction;
use App\Enums\DeliveryAssignmentType;
use App\Enums\OrderStatus;
use App\Enums\RefundMethod;
use App\Models\Customer;
use App\Models\DeliveryRepresentative;
use App\Models\Employee;
use App\Models\Order;
use App\Models\ReturnReason;
use App\Models\Treasury;
use App\Models\Warehouse;
use App\Notifications\Orders\ReturnRequestedNotification;
use Database\Seeders\Concerns\SeedsNotifications;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Walks a post-delivery return through its full chain (Section 12):
 * Requested → shipping-fee consent → call confirmed (Approved) → courier
 * sent → Received (restocked) → Refunded. Runs against the Delivered order StorefrontDemoSeeder already
 * created (#2001) rather than placing a new one, since RequestReturnAction
 * only accepts an order that's actually Delivered.
 */
class ReturnDemoSeeder extends Seeder
{
    use SeedsNotifications, WithoutModelEvents;

    public function run(): void
    {
        $order = Order::where('order_number', 2001)->where('status', OrderStatus::Delivered)->first();
        $customer = Customer::where('email', 'demo@waqar.test')->first();
        $warehouseManager = Employee::where('email', 'warehouse@waqar.test')->first();
        $accountant = Employee::where('email', 'accounting@waqar.test')->first();
        $checker = Employee::where('email', 'checking@waqar.test')->first();
        $courier = DeliveryRepresentative::query()->first();
        $warehouse = Warehouse::where('name', 'Main Warehouse')->first();
        $bankTreasury = Treasury::where('name', 'Main Bank Account')->first();
        $reasons = ReturnReason::orderBy('sort_order')->get();

        if ($order === null || $customer === null || $warehouseManager === null || $accountant === null
            || $checker === null || $courier === null
            || $warehouse === null || $bankTreasury === null || $reasons->isEmpty()) {
            return;
        }

        $items = $order->items;
        $primaryItem = $items->first();
        if ($primaryItem === null) {
            return;
        }

        $wrongSize = $reasons->first(fn (ReturnReason $r) => $r->getTranslation('name', 'en') === 'Wrong Size') ?? $reasons->first();

        // Full cycle: requested → fee accepted → approved → received
        // (restocked) → refunded.
        $return = app(RequestReturnAction::class)->execute(
            $order,
            $customer,
            [['order_item_id' => $primaryItem->id, 'quantity' => 1]],
            $wrongSize->id,
            'The size runs smaller than expected.',
        );

        $this->notify($customer, new ReturnRequestedNotification($return));

        $return = app(AcceptReturnShippingFeeAction::class)->execute($return, $customer, 25.00);
        // The same five steps the admin screen walks, in order: the call's
        // Confirm approves it, a courier is sent, then it is received.
        $return = app(CheckReturnAction::class)->confirm($return, $checker, 'Customer confirmed the size issue.');
        $return = app(AssignReturnPickupAction::class)->execute($return, $warehouseManager, DeliveryAssignmentType::Representative, $courier);
        $return = app(ReceiveReturnAction::class)->execute($return, $warehouseManager, $warehouse);
        app(RefundReturnAction::class)->execute($return, $accountant, $bankTreasury, RefundMethod::BankTransfer, 'REF-'.$order->order_number);

        // A second line, still sitting in the Requested queue — gives the
        // Warehouse Manager/Accounting screens something pending to act on.
        $secondItem = $items->skip(1)->first();
        $defective = $reasons->first(fn (ReturnReason $r) => $r->getTranslation('name', 'en') === 'Defective Product') ?? $reasons->first();
        if ($secondItem !== null) {
            $pendingReturn = app(RequestReturnAction::class)->execute(
                $order,
                $customer,
                [['order_item_id' => $secondItem->id, 'quantity' => 1]],
                $defective->id,
                'One of the two arrived with a loose seam.',
            );

            $this->notify($customer, new ReturnRequestedNotification($pendingReturn));
        }
    }
}
