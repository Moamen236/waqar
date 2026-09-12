<?php

namespace App\Notifications\Orders;

use App\Enums\CustomerOrderStatus;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Queued since Phase 8. Phase 5 deliberately left these synchronous
 * because the compose stack had no worker to drain Redis, so anything
 * pushed there would have sat undelivered — including the database
 * channel the customer account's Notifications tab reads. supervisord
 * now runs two workers, so an SMTP round-trip no longer happens inside
 * the customer's checkout request.
 *
 * The locale is captured in the constructor, not read when the job runs.
 * Every route lives under /{locale}/… (Q20) and the worker has no request
 * to inherit one from, so without this a customer shopping in English
 * would be mailed in Arabic by whichever worker happened to pick the job
 * up. Laravel wraps delivery in `withLocale($this->locale)`.
 */
class OrderStatusUpdatedNotification extends Notification implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public function __construct(
        private readonly Order $order,
        private readonly CustomerOrderStatus $status,
    ) {
        $this->locale = app()->getLocale();
    }

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
            ->action(__('Track your order'), route('order-tracking.index', ['locale' => app()->getLocale()]));
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
            'url' => route('order-tracking.index', ['locale' => app()->getLocale()]),
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
