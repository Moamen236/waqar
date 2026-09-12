<?php

namespace App\Notifications\Orders;

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
class OrderPlacedNotification extends Notification implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public function __construct(private readonly Order $order)
    {
        $this->locale = app()->getLocale();
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
            ->subject(__("We've got your order #:number", ['number' => $this->order->order_number]))
            ->greeting(__('Thanks, :name!', ['name' => $this->order->shipping_recipient_name]))
            ->line(__('Your order #:number has been received and is being processed.', ['number' => $this->order->order_number]))
            ->line(__('Payment is cash on delivery — you pay when it reaches you, nothing before.'))
            ->line(__('Total to pay on delivery: :amount', ['amount' => 'EGP '.number_format((float) $this->order->total, 2)]))
            ->action(__('Track your order'), route('order-tracking.index', ['locale' => app()->getLocale()]));
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
            'url' => route('order-tracking.index', ['locale' => app()->getLocale()]),
        ];
    }
}
