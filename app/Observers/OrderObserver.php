<?php

namespace App\Observers;

use App\Models\Order;
use App\Notifications\Orders\OrderPlacedNotification;
use App\Notifications\Orders\OrderStatusUpdatedNotification;
use Illuminate\Support\Facades\DB;

/**
 * Customer notifications for the order lifecycle (Section 23's event
 * list), driven off `orders.customer_status` rather than wired into each
 * Action individually.
 *
 * That choice is deliberate: the customer-facing status *is* the event
 * (Section 03's mapping table), it's set in exactly one column, and every
 * Action that moves an order already writes it. Hanging the notifications
 * off the Actions instead would mean five separate call sites that can
 * drift apart — and a sixth the next time someone adds a status
 * transition.
 *
 * Everything is dispatched through DB::afterCommit() because every one of
 * those Actions runs inside a transaction: without it, a reservation
 * failure rolling the order back would still have emailed the customer
 * about an order that no longer exists.
 */
class OrderObserver
{
    public function created(Order $order): void
    {
        DB::afterCommit(function () use ($order) {
            $order->customer?->notify(new OrderPlacedNotification($order));
        });
    }

    public function updated(Order $order): void
    {
        if (! $order->wasChanged('customer_status')) {
            return;
        }

        $status = $order->customer_status;

        if (! in_array($status, OrderStatusUpdatedNotification::notifiableStatuses(), true)) {
            return;
        }

        DB::afterCommit(function () use ($order, $status) {
            $order->customer?->notify(new OrderStatusUpdatedNotification($order, $status));
        });
    }
}
