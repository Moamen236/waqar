<?php

namespace App\Notifications\Orders;

use App\Models\Order;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Order placed" — the first of Section 23's notification events, on the
 * database + mail channels.
 *
 * Deliberately *not* ShouldQueue yet: the compose stack has no
 * Supervisor-managed queue worker until Phase 8, so queueing these would
 * leave them sitting in Redis undelivered — including the database
 * channel, which is what the customer account's Notifications tab reads.
 * Adding `implements ShouldQueue` to this class and its siblings is the
 * one-line change to make at that point.
 */
class OrderPlacedNotification extends Notification
{
    public function __construct(private readonly Order $order) {}

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
            ->subject(__("We've got your order #:number", ['number' => $this->order->order_number]))
            ->greeting(__('Thanks, :name!', ['name' => $this->order->shipping_recipient_name]))
            ->line(__('Your order #:number has been received and is being processed.', ['number' => $this->order->order_number]))
            ->line(__('Payment is cash on delivery — you pay when it reaches you, nothing before.'))
            ->line(__('Total to pay on delivery: :amount', ['amount' => 'EGP '.number_format((float) $this->order->total, 2)]))
            ->action(__('Track your order'), route('order-tracking.index'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'order_placed',
            'order_number' => $this->order->order_number,
            'title' => __('Order #:number received', ['number' => $this->order->order_number]),
            'message' => __('We have your order and will start processing it shortly.'),
            'url' => route('order-tracking.index'),
        ];
    }
}
