<?php

namespace App\Actions\Orders;

use App\Enums\CustomerOrderStatus;
use App\Enums\OrderStatus;
use App\Models\Employee;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Question 14's net-new status: a Confirmed order containing an
 * Advertisement (non-inventory-tracked) product that turns out to be
 * unfulfillable moves here instead of being forced into Postponed or
 * Cancelled (Section 02, 10). No inventory movement happens either way —
 * Advertisement products were never reserved in the first place
 * (CreateOrderAction's stock-check bypass). See ResumeBackorderAction for
 * the other half of this state.
 */
class MarkOrderBackorderAction
{
    public function execute(Order $order, Employee $employee, string $reason): Order
    {
        return DB::transaction(function () use ($order, $employee, $reason) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($order->status !== OrderStatus::Confirmed) {
                throw new RuntimeException("Order #{$order->order_number} must be Confirmed before it can be backordered.");
            }

            $order->update([
                'status' => OrderStatus::Backorder,
                'customer_status' => CustomerOrderStatus::Backordered,
            ]);

            $order->statusHistory()->create([
                'from_status' => OrderStatus::Confirmed->value,
                'to_status' => OrderStatus::Backorder->value,
                'changed_by' => $employee->id,
                'reason' => $reason,
            ]);

            return $order->fresh();
        });
    }
}
