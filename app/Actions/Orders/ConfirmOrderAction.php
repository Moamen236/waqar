<?php

namespace App\Actions\Orders;

use App\Enums\CustomerOrderStatus;
use App\Enums\OrderStatus;
use App\Models\Employee;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Checking reviews a New order and confirms it (Section 03). Also covers
 * resuming a Postponed order back to Confirmed — Postpone/cancel are
 * their own Actions (PostponeOrderAction, CancelOrderAction covers
 * cancel from this stage too), but this is the only path back out of
 * Postponed, so it accepts that status as a valid starting point too.
 */
class ConfirmOrderAction
{
    private const CONFIRMABLE_FROM = [
        OrderStatus::New,
        OrderStatus::Checking,
        OrderStatus::Postponed,
    ];

    public function execute(Order $order, Employee $checkingEmployee, ?string $notes = null): Order
    {
        return DB::transaction(function () use ($order, $checkingEmployee, $notes) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if (! in_array($order->status, self::CONFIRMABLE_FROM, true)) {
                throw new RuntimeException("Order #{$order->order_number} can't be confirmed from status {$order->status->value}.");
            }

            $fromStatus = $order->status;

            $order->update([
                'status' => OrderStatus::Confirmed,
                'customer_status' => CustomerOrderStatus::Processing,
            ]);

            $order->statusHistory()->create([
                'from_status' => $fromStatus->value,
                'to_status' => OrderStatus::Confirmed->value,
                'changed_by' => $checkingEmployee->id,
                'notes' => $notes,
            ]);

            return $order->fresh();
        });
    }
}
