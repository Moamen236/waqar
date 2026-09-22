<?php

namespace App\Actions\Orders;

use App\Enums\CustomerOrderStatus;
use App\Enums\OrderStatus;
use App\Models\Employee;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Accounting signs the goods and the cash out of the building: the
 * courier has physically taken the order, but has not yet reached the
 * customer (Section 03).
 *
 * `OrderStatus::OutForDelivery` has existed in the enum since Phase 1
 * and was never written by anything — eight filters and guards read it,
 * no code assigned it. This Action is what finally does.
 *
 * Deliberately NOT mandatory: ConfirmDeliveryResultAction still accepts
 * an order straight from Assigned, so a courier who skips the desk does
 * not strand the order. The step is a record of the handover, not a gate
 * in front of the money.
 *
 * Touches no stock and no treasury. The reservation taken at order
 * creation already holds the goods through this window, and deduction
 * stays exclusively at Delivered (Section 07's central rule).
 */
class ConfirmHandoverAction
{
    public function execute(Order $order, Employee $accountant, ?string $notes = null): Order
    {
        return DB::transaction(function () use ($order, $accountant, $notes) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($order->status !== OrderStatus::Assigned) {
                throw new RuntimeException("Order #{$order->order_number} can't be handed over from status {$order->status->value}.");
            }

            $order->update([
                'status' => OrderStatus::OutForDelivery,
                // Writing this is what lights up the "out for delivery,
                // have the cash ready" notification and the storefront
                // timeline — both were already written and unreachable.
                'customer_status' => CustomerOrderStatus::OutForDelivery,
            ]);

            $order->statusHistory()->create([
                'from_status' => OrderStatus::Assigned->value,
                'to_status' => OrderStatus::OutForDelivery->value,
                'changed_by' => $accountant->id,
                'notes' => $notes,
            ]);

            return $order->fresh();
        });
    }
}
