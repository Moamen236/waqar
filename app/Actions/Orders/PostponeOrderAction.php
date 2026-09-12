<?php

namespace App\Actions\Orders;

use App\Enums\CustomerOrderStatus;
use App\Enums\OrderStatus;
use App\Models\Employee;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Checking postpones an order pending more information (Section 03's
 * status table: "Postponed — Checking employee — no inventory/payment
 * change"). No stock movement either way — whatever was reserved stays
 * reserved. See ConfirmOrderAction for the way back to Confirmed.
 */
class PostponeOrderAction
{
    private const POSTPONABLE_FROM = [
        OrderStatus::New,
        OrderStatus::Checking,
        OrderStatus::Confirmed,
    ];

    public function execute(Order $order, Employee $checkingEmployee, string $reason): Order
    {
        return DB::transaction(function () use ($order, $checkingEmployee, $reason) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if (! in_array($order->status, self::POSTPONABLE_FROM, true)) {
                throw new RuntimeException("Order #{$order->order_number} can't be postponed from status {$order->status->value}.");
            }

            $fromStatus = $order->status;

            $order->update([
                'status' => OrderStatus::Postponed,
                'customer_status' => CustomerOrderStatus::Postponed,
            ]);

            $order->statusHistory()->create([
                'from_status' => $fromStatus->value,
                'to_status' => OrderStatus::Postponed->value,
                'changed_by' => $checkingEmployee->id,
                'reason' => $reason,
            ]);

            return $order->fresh();
        });
    }
}
