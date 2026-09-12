<?php

namespace App\Notifications\Orders;

use App\Models\OrderReturn;
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
class ReturnRequestedNotification extends Notification implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public function __construct(private readonly OrderReturn $return)
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
            ->subject(__('Return requested for order #:number', ['number' => $this->return->order->order_number]))
            ->line(__("We've logged your return request for order #:number.", ['number' => $this->return->order->order_number]))
            ->line(__('We will review it and be in touch about collection and any return-shipping fee before anything is collected.'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'return_requested',
            'order_number' => $this->return->order->order_number,
            'return_id' => $this->return->id,
            'title' => __('Return requested for order #:number', ['number' => $this->return->order->order_number]),
            'message' => __('We have logged your return request and will review it shortly.'),
            'url' => route('account.orders.show', ['locale' => app()->getLocale(), 'order' => $this->return->order->order_number]),
        ];
    }
}
