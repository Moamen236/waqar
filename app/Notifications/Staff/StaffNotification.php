<?php

namespace App\Notifications\Staff;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Every staff notification, in one class — not one class per event type.
 *
 * Nothing is *rendered* here, which is the one real difference from the
 * customer notifications next door. The payload carries a type slug plus
 * its interpolation params, and the admin bell renders
 * `t('notification.<type>', params)` itself.
 *
 * That isn't a shortcut, it's the only correct option. `employees` has no
 * locale column, staff move between /ar/admin and /en/admin freely, and a
 * queue worker has no request to inherit a locale from. A title rendered
 * at dispatch time would be frozen in whichever language the *actor*
 * happened to be using and would read wrong for every other viewer, for
 * the life of the row. OrderPlacedNotification can capture a locale in
 * its constructor because a customer notification has exactly one reader;
 * these have many.
 *
 * The link is stored the same way and for the same reason: a route name
 * and its params, never a URL. NotificationController resolves it at
 * click time, inside a request that has been through SetLocale, so
 * {locale} comes out as the locale the reader is actually browsing in.
 *
 * `database` only — Section 23's staff events are work-queue signals, not
 * correspondence. Adding 'mail' here later is a one-line change.
 */
class StaffNotification extends Notification implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    /**
     * @param  array<string, string|int|float|null>  $params
     * @param  array<string, string|int>  $routeParams
     */
    public function __construct(
        private readonly string $type,
        private readonly array $params = [],
        private readonly ?string $route = null,
        private readonly array $routeParams = [],
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->type,
            'params' => $this->params,
            'route' => $this->route,
            'route_params' => $this->routeParams,
        ];
    }
}
