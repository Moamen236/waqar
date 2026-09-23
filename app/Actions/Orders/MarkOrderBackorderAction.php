<?php

namespace App\Actions\Orders;

use App\Enums\CustomerOrderStatus;
use App\Enums\OrderStatus;
use App\Models\Employee;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Question 14's net-new status: an order containing an Advertisement
 * (non-inventory-tracked) product that can't be fulfilled yet moves here
 * instead of being forced into Postponed or Cancelled (Section 02, 10).
 * Q14 had it follow Confirm; Checking now refuses to confirm such an
 * order at all, so it can also come here straight from the queue. No inventory movement happens either way —
 * Advertisement products were never reserved in the first place
 * (CreateOrderAction's stock-check bypass). See ResumeBackorderAction for
 * the other half of this state.
 */
class MarkOrderBackorderAction
{
    private const BACKORDERABLE_FROM = [
        OrderStatus::New,
        OrderStatus::Checking,
        OrderStatus::Postponed,
        OrderStatus::Confirmed,
    ];

    public function execute(Order $order, Employee $employee, string $reason): Order
    {
        return DB::transaction(function () use ($order, $employee, $reason) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            // Straight from the Checking queue too, not only after Confirm:
            // Confirm is blocked on anything stock can't cover (including
            // Advertisement lines), so Backorder is how such an order
            // leaves the queue to wait for stock. Resume confirms it.
            if (! in_array($order->status, self::BACKORDERABLE_FROM, true)) {
                throw new RuntimeException("Order #{$order->order_number} can't be backordered from status {$order->status->value}.");
            }

            $fromStatus = $order->status;

            $order->update([
                'status' => OrderStatus::Backorder,
                'customer_status' => CustomerOrderStatus::Backordered,
            ]);

            $order->statusHistory()->create([
                'from_status' => $fromStatus->value,
                'to_status' => OrderStatus::Backorder->value,
                'changed_by' => $employee->id,
                'reason' => $reason,
            ]);

            return $order->fresh();
        });
    }
}
