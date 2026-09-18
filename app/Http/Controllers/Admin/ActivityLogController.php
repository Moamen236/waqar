<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

/**
 * /admin/activity-log — the audit trail, read-only (Section 23).
 *
 * The roadmap's bar for Phase 7 is that a deleted product, a manual stock
 * adjustment and a role change each leave a *readable* entry. Readable
 * means someone can find it, so this screen exists: without it the log is
 * a table only someone with database access can consult, which is not the
 * audience an audit trail is for.
 *
 * Entries are rendered from stored keys rather than stored sentences —
 * `description` holds the raw event (`created`/`role_attached`/…) and the
 * UI translates it, the same way Phase 6 handled order statuses. Both the
 * subject label and, for access changes, the role/permission names are
 * snapshotted into the entry's properties at write time, so an entry
 * stays readable after its subject is gone.
 */
class ActivityLogController extends Controller implements HasMiddleware
{
    /**
     * The audit domains, matching the `activityLogName()` each model
     * declares. Offered as a filter rather than free text so the list
     * can't silently go stale against a typo'd log name.
     *
     * @var list<string>
     */
    private const LOGS = ['catalog', 'orders', 'inventory', 'treasury', 'customers', 'access'];

    public static function middleware(): array
    {
        return [
            new Middleware('permission:activity.view', only: ['index']),
        ];
    }

    public function index(Request $request): Response
    {
        $log = $request->string('log')->toString();
        $event = $request->string('event')->toString();
        $search = trim((string) $request->string('search'));

        $activities = Activity::query()
            ->with(['causer', 'subject'])
            ->when(in_array($log, self::LOGS, true), fn ($query) => $query->where('log_name', $log))
            ->when($event !== '', fn ($query) => $query->where('event', $event))
            // The label snapshot is what survives the subject, so it is
            // also the only thing worth searching — joining out to each
            // subject table would miss exactly the deleted rows someone
            // opens this screen to find.
            ->when($search !== '', fn ($query) => $query->where('properties->label', 'like', "%{$search}%"))
            ->latest('id')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (Activity $activity) => [
                'id' => $activity->id,
                'log_name' => $activity->log_name,
                'event' => $activity->event ?? $activity->description,
                'label' => $activity->properties['label'] ?? null,
                'names' => $activity->properties['names'] ?? null,
                'subject_type' => $activity->subject_type !== null ? class_basename($activity->subject_type) : null,
                'subject_id' => $activity->subject_id,
                'changes' => $this->changes($activity),
                'causer' => $activity->causer?->getAttribute('full_name')
                    ?? $activity->causer?->getAttribute('name'),
                'causer_type' => $activity->causer_type !== null ? class_basename($activity->causer_type) : null,
                'at' => $activity->created_at?->toDateTimeString(),
            ]);

        return Inertia::render('ActivityLog/Index', [
            'activities' => $activities,
            'logs' => self::LOGS,
            'events' => Activity::query()->distinct()->orderBy('event')->pluck('event')->filter()->values(),
            'filters' => ['log' => $log, 'event' => $event, 'search' => $search],
        ]);
    }

    /**
     * Flattened old → new pairs, which is what a reader wants; the raw
     * properties payload nests them under `old`/`attributes` and repeats
     * every unchanged key on a create.
     *
     * @return list<array{field: string, old: string|null, new: string|null}>
     */
    private function changes(Activity $activity): array
    {
        $properties = $activity->properties;
        $new = $properties['attributes'] ?? [];
        $old = $properties['old'] ?? [];

        if (! is_array($new) || ! is_array($old)) {
            return [];
        }

        $fields = array_unique(array_merge(array_keys($new), array_keys($old)));
        $changes = [];

        foreach ($fields as $field) {
            $changes[] = [
                'field' => (string) $field,
                'old' => $this->scalar($old[$field] ?? null),
                'new' => $this->scalar($new[$field] ?? null),
            ];
        }

        return $changes;
    }

    /**
     * Translatable columns are JSON and enum casts are objects by the
     * time they reach the log, so neither renders as a plain string.
     */
    private function scalar(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE) ?: null;
        }

        return (string) $value;
    }
}
