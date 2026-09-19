<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Route as RouteFacade;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The staff inbox. Deliberately the one admin controller with no
 * `permission:` middleware and no HasMiddleware at all — every
 * authenticated employee has an inbox, the same way every one of them
 * has a dashboard. There is nothing here to gate: the rows are already
 * scoped to the reader by the notifiable relation, and what put them
 * there was gated at dispatch (see StaffNotifier).
 */
class NotificationController extends Controller
{
    /**
     * The shape the admin bell and this page both read. One mapper so
     * the dropdown and the full list can never disagree on a field.
     *
     * `route`/`route_params` are deliberately *not* exposed: the client
     * never builds these URLs. See open() for why.
     *
     * @return array<string, mixed>
     */
    public static function row(DatabaseNotification $notification): array
    {
        /** @var array<string, mixed> $data */
        $data = $notification->data;

        return [
            'id' => $notification->id,
            'type' => $data['type'] ?? 'unknown',
            'params' => $data['params'] ?? [],
            'linked' => ($data['route'] ?? null) !== null,
            'read_at' => $notification->read_at?->toDateTimeString(),
            'created_at' => $notification->created_at?->diffForHumans(),
        ];
    }

    public function index(Request $request): Response
    {
        $notifications = $request->user('employee')
            ->notifications()
            ->paginate(30)
            ->through(fn (DatabaseNotification $notification) => self::row($notification));

        return Inertia::render('Notifications/Index', [
            'notifications' => $notifications,
        ]);
    }

    /**
     * Mark one notification read and go wherever it points.
     *
     * Both halves in one request, on purpose. The payload stores a route
     * *name* and its params rather than a URL (see StaffNotification for
     * why), and resolving it here — inside a request that has already
     * been through SetLocale — is what makes the destination come out in
     * the reader's own language rather than the language whoever
     * triggered the event happened to be using.
     */
    public function open(Request $request, string $notification): RedirectResponse
    {
        $employee = $request->user('employee');

        /** @var DatabaseNotification|null $row */
        $row = $employee->notifications()->whereKey($notification)->first();

        if ($row === null) {
            return back();
        }

        $row->markAsRead();

        /** @var array<string, mixed> $data */
        $data = $row->data;
        $name = $data['route'] ?? null;

        // A notification whose target screen doesn't exist (or has been
        // renamed since it was written) should not 500 someone's bell.
        if (! is_string($name) || ! RouteFacade::has($name)) {
            return back();
        }

        /** @var array<string, mixed> $params */
        $params = $data['route_params'] ?? [];

        return redirect()->route($name, $params);
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $request->user('employee')->unreadNotifications->markAsRead();

        return back();
    }
}
