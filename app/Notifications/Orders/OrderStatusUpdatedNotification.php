<?php

namespace App\Notifications\Orders;

use App\Enums\CustomerOrderStatus;
use App\Models\Order;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Every customer-facing order status change after placement — Section
 * 23's confirmed / shipped / delivered events, plus the states the
 * customer also needs told about (Postponed, Backordered, Cancelled,
 * Returned).
 *
 * One parameterised class rather than one per status: the customer-facing
 * status *is* the event (Section 03's mapping table), and the copy is the
 * only thing that differs. See OrderPlacedNotification for why these
 * aren't queued yet.
 */
class OrderStatusUpdatedNotification extends Notification
{
    public function __construct(
        private readonly Order $order,
        private readonly CustomerOrderStatus $status,
    ) {}

    /**
     * Which statuses are worth telling a customer about at all. Silence
     * is deliberate for the rest: "Processing" fires twice internally
     * (Checking opens the order, then confirms it) and nothing the
     * customer can act on changes in between.
     *
     * @return array<int, CustomerOrderStatus>
     */
    public static function notifiableStatuses(): array
    {
        return [
            CustomerOrderStatus::Shipping,
            CustomerOrderStatus::OutForDelivery,
            CustomerOrderStatus::Delivered,
            CustomerOrderStatus::Cancelled,
            CustomerOrderStatus::Postponed,
            CustomerOrderStatus::Backordered,
            CustomerOrderStatus::Returned,
            CustomerOrderStatus::PartiallyReturned,
        ];
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Order #:number — :status', [
                'number' => $this->order->order_number,
                'status' => __($this->status->value),
            ]))
            ->greeting(__('Hi :name,', ['name' => $this->order->shipping_recipient_name]))
            ->line($this->message())
            ->action(__('Track your order'), route('order-tracking.index'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'order_status_updated',
            'order_number' => $this->order->order_number,
            'status' => $this->status->value,
            'title' => __('Order #:number — :status', [
                'number' => $this->order->order_number,
                'status' => __($this->status->value),
            ]),
            'message' => $this->message(),
            'url' => route('order-tracking.index'),
        ];
    }

    private function message(): string
    {
        return match ($this->status) {
            CustomerOrderStatus::Shipping => __('Your order is on its way to the courier.'),
            CustomerOrderStatus::OutForDelivery => __('Your order is out for delivery today. Please have the cash payment ready.'),
            CustomerOrderStatus::Delivered => __('Your order has been delivered. Thank you for shopping with us.'),
            CustomerOrderStatus::Cancelled => __('Your order has been cancelled. Nothing has been charged.'),
            CustomerOrderStatus::Postponed => __('Your delivery has been postponed. We will contact you to rearrange it.'),
            CustomerOrderStatus::Backordered => __('One of your items is being restocked. Your order is paused until it arrives.'),
            CustomerOrderStatus::Returned => __('Your order was returned and nothing has been charged.'),
            CustomerOrderStatus::PartiallyReturned => __('Part of your order was returned — you were only charged for what you kept.'),
            default => __('Your order is now: :status.', ['status' => __($this->status->value)]),
        };
    }
}
