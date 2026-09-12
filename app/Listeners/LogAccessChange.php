<?php

namespace App\Listeners;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Spatie\Permission\Contracts\Permission as PermissionContract;
use Spatie\Permission\Contracts\Role as RoleContract;
use Spatie\Permission\Events\PermissionAttachedEvent;
use Spatie\Permission\Events\PermissionDetachedEvent;
use Spatie\Permission\Events\RoleAttachedEvent;
use Spatie\Permission\Events\RoleDetachedEvent;
use Spatie\Permission\PermissionRegistrar;

/**
 * Role and permission grants are pivot writes, so no Eloquent model event
 * ever fires for them and `RecordsActivity` cannot see them. Spatie emits
 * its own four events instead (enabled via `permission.events_enabled`),
 * and listening to those rather than logging inside RoleController /
 * EmployeeController catches *every* path — the seeders, tinker, and any
 * Action added later — not only the two screens that exist today.
 *
 * `syncRoles()` fires a detach for the outgoing roles followed by an
 * attach for the incoming ones, so a role change reads as a pair.
 *
 * The handlers are named `on*` rather than `handle*` deliberately:
 * Laravel 11+ auto-discovers any `handle*` method in `app/Listeners` that
 * type-hints an event, which would register these a second time on top of
 * the explicit bindings in AppServiceProvider and log every grant twice.
 */
class LogAccessChange
{
    public function onRoleAttached(RoleAttachedEvent $event): void
    {
        $this->record($event->model, 'role_attached', $this->roleNames($event->rolesOrIds));
    }

    public function onRoleDetached(RoleDetachedEvent $event): void
    {
        $this->record($event->model, 'role_detached', $this->roleNames($event->rolesOrIds));
    }

    public function onPermissionAttached(PermissionAttachedEvent $event): void
    {
        $this->record($event->model, 'permission_attached', $this->permissionNames($event->permissionsOrIds));
    }

    public function onPermissionDetached(PermissionDetachedEvent $event): void
    {
        $this->record($event->model, 'permission_detached', $this->permissionNames($event->permissionsOrIds));
    }

    /**
     * @param  list<string>  $names
     */
    private function record(Model $model, string $event, array $names): void
    {
        if ($names === []) {
            return;
        }

        activity('access')
            ->performedOn($model)
            ->event($event)
            ->withProperties([
                'names' => $names,
                'label' => $this->label($model),
            ])
            ->log($event);
    }

    /**
     * The payload is documented as "ids, or a model, or a collection of
     * either" — the trait passes whichever it happens to hold — so it has
     * to be normalised rather than assumed.
     *
     * @return list<string>
     */
    private function roleNames(mixed $rolesOrIds): array
    {
        return $this->names($rolesOrIds, app(PermissionRegistrar::class)->getRoleClass(), RoleContract::class);
    }

    /**
     * @return list<string>
     */
    private function permissionNames(mixed $permissionsOrIds): array
    {
        return $this->names($permissionsOrIds, app(PermissionRegistrar::class)->getPermissionClass(), PermissionContract::class);
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @return list<string>
     */
    private function names(mixed $subjects, string $modelClass, string $contract): array
    {
        $items = $subjects instanceof Collection ? $subjects->all() : (is_array($subjects) ? $subjects : [$subjects]);

        $names = [];
        $ids = [];

        foreach ($items as $item) {
            if ($item instanceof $contract) {
                $names[] = (string) $item->name;
            } elseif (is_int($item) || is_string($item)) {
                $ids[] = $item;
            }
        }

        if ($ids !== []) {
            $names = array_merge($names, $modelClass::query()->whereKey($ids)->pluck('name')->all());
        }

        return array_values(array_unique(array_map('strval', $names)));
    }

    private function label(Model $model): string
    {
        if (method_exists($model, 'activitySubjectLabel')) {
            return $model->activitySubjectLabel();
        }

        $name = $model->getAttribute('name');

        return is_string($name) && $name !== '' ? $name : class_basename($model).' #'.$model->getKey();
    }
}
