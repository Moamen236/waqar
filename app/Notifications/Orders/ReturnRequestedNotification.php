<?php

namespace App\Notifications\Orders;

use App\Models\OrderReturn;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Return requested" — Section 23's post-delivery return event (Section
 * 12's customer-initiated flow). See OrderPlacedNotification for why
 * these aren't queued yet.
 */
class ReturnRequestedNotification extends Notification
{
    public function __construct(private readonly OrderReturn $return) {}

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
            'url' => route('account.orders.show', $this->return->order->order_number),
        ];
    }
}
