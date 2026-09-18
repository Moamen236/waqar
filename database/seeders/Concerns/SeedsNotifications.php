<?php

namespace Database\Seeders\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Writes a notification row using a real Notification class's own
 * toArray() output, without going through ->notify() — every order/return
 * Notification in this app is ShouldQueue, and dispatching it for real
 * during seeding would either queue an undeliverable job or attempt to
 * actually send mail, neither of which belongs in demo data.
 */
trait SeedsNotifications
{
    private function notify(Model $notifiable, Notification $notification): void
    {
        DatabaseNotification::create([
            'id' => (string) Str::uuid(),
            'type' => $notification::class,
            'notifiable_type' => $notifiable->getMorphClass(),
            'notifiable_id' => $notifiable->getKey(),
            // @phpstan-ignore-next-line method.notFound — the base Notification
            // class doesn't declare toArray(), but every concrete Notification
            // this trait is ever called with (App\Notifications\Orders\*) does.
            'data' => $notification->toArray($notifiable),
            'read_at' => null,
        ]);
    }
}
