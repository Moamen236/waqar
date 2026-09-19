<?php

namespace App\Observers;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Notifications\Orders\OrderPlacedNotification;
use App\Notifications\Orders\OrderStatusUpdatedNotification;
use App\Services\Notifications\StaffNotifier;
use Illuminate\Support\Facades\DB;

/**
 * Notifications for the order lifecycle (Section 23's event list),
 * driven off the order's own status columns rather than wired into each
 * Action individually.
 *
 * That choice is deliberate: the status *is* the event (Section 03's
 * mapping table), it's set in exactly one column, and every Action that
 * moves an order already writes it. Hanging the notifications off the
 * Actions instead would mean five separate call sites that can drift
 * apart — and a sixth the next time someone adds a status transition.
 *
 * Two audiences, two columns. Customers read `customer_status`, which
 * collapses several internal states into one (Confirmed and Assigned are
 * both just "Processing"/"Shipping" to them). Staff read `status`,
 * because for them Confirmed and Assigned are different departments'
 * work landing in different queues — which is the whole point.
 *
 * Everything is dispatched through DB::afterCommit() because every one of
 * those Actions runs inside a transaction: without it, a reservation
 * failure rolling the order back would still have emailed the customer
 * about an order that no longer exists.
 */
class OrderObserver
{
    /**
     * Which roles care about each internal status the order arrives at.
     *
     * "Customer Service" here means the agent who created this order and
     * their team leader, not the role at large — StaffNotifier::forOrder
     * resolves it through the order, mirroring Order::scopeVisibleTo.
     * "Store Orders" likewise only ever resolves for website orders.
     *
     * @var array<string, list<string>>
     */
    private const STATUS_RECIPIENTS = [
        // Confirmed → the assignment queue fills up.
        OrderStatus::Confirmed->value => ['Delivery Manager', 'Customer Service'],
        OrderStatus::Postponed->value => ['Customer Service'],
        OrderStatus::Cancelled->value => ['Customer Service', 'Store Orders'],
        // Backorder → a stock shortfall someone has to resolve.
        OrderStatus::Backorder->value => ['Warehouse Manager', 'Customer Service'],
        // Assigned → Accounting now waits on a delivery result.
        OrderStatus::Assigned->value => ['Accounting', 'Customer Service'],
        OrderStatus::Delivered->value => ['Customer Service', 'Store Orders'],
        // Refused at the door: the reservations just came back.
        OrderStatus::Returned->value => ['Warehouse Manager', 'Customer Service', 'Store Orders'],
        OrderStatus::PartiallyReturned->value => ['Warehouse Manager', 'Customer Service', 'Store Orders'],
    ];

    /** @var array<string, string> */
    private const STATUS_TYPES = [
        OrderStatus::Confirmed->value => 'order_confirmed',
        OrderStatus::Postponed->value => 'order_postponed',
        OrderStatus::Cancelled->value => 'order_cancelled',
        OrderStatus::Backorder->value => 'order_backorder',
        OrderStatus::Assigned->value => 'order_assigned',
        OrderStatus::Delivered->value => 'order_delivered',
        OrderStatus::Returned->value => 'order_returned',
        OrderStatus::PartiallyReturned->value => 'order_partially_returned',
    ];

    public function __construct(private readonly StaffNotifier $staff) {}

    public function created(Order $order): void
    {
        $actor = StaffNotifier::actor();

        DB::afterCommit(function () use ($order, $actor) {
            $order->customer?->notify(new OrderPlacedNotification($order));

            // Checking's inbound queue, every order. Store Orders only
            // sees it if it came from the website; the Customer Service
            // agent who just typed it in is suppressed as the actor.
            $this->staff->forOrder($order, ['Checking', 'Store Orders'], 'order_placed', actorId: $actor);
        });
    }

    public function updated(Order $order): void
    {
        $actor = StaffNotifier::actor();

        if ($order->wasChanged('customer_status')) {
            $this->notifyCustomer($order);
        }

        if ($order->wasChanged('status')) {
            $this->notifyStaffOfStatus($order, $actor);

            return;
        }

        // Only reachable from collectBalance(), the one Accounting action
        // that settles money without moving the order — every other
        // payment_status write rides along with a status change that has
        // already told the story above.
        if ($order->wasChanged('payment_status') && $order->payment_status === PaymentStatus::Collected) {
            DB::afterCommit(fn () => $this->staff->forOrder(
                $order, ['Customer Service'], 'payment_balance_collected', actorId: $actor,
            ));
        }
    }

    private function notifyCustomer(Order $order): void
    {
        $status = $order->customer_status;

        if (! in_array($status, OrderStatusUpdatedNotification::notifiableStatuses(), true)) {
            return;
        }

        DB::afterCommit(function () use ($order, $status) {
            $order->customer?->notify(new OrderStatusUpdatedNotification($order, $status));
        });
    }

    private function notifyStaffOfStatus(Order $order, ?int $actor): void
    {
        $status = $order->status->value;
        $roles = self::STATUS_RECIPIENTS[$status] ?? null;

        if ($roles === null) {
            return;
        }

        $previous = $order->getOriginal('status');
        $type = self::STATUS_TYPES[$status];

        // Backorder → Confirmed is ResumeBackorderAction, not Checking
        // confirming a fresh order. Same destination queue, different
        // news: the stock that was missing has arrived.
        if ($status === OrderStatus::Confirmed->value && $previous === OrderStatus::Backorder->value) {
            $type = 'order_resumed';
        }

        // Cancelling something already Confirmed is revenue lost after
        // the business committed to it — the only order event the
        // Chairman is told about, since volume alone would drown them.
        if ($status === OrderStatus::Cancelled->value && $previous === OrderStatus::Confirmed->value) {
            $roles = [...$roles, 'Chairman'];
        }

        DB::afterCommit(fn () => $this->staff->forOrder($order, $roles, $type, actorId: $actor));
    }
}
