<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Splits `warehouses.manage` into warehouses.view/create/update/delete, the
 * same CRUD split every other resource has (see PermissionSeeder). Every
 * holder of the old grant — role or individual employee — receives all
 * four, so nobody loses access; the matrix editor can then narrow it.
 *
 * Raw queries rather than the Spatie models, as in
 * 2026_09_25_010000_split_dashboard_permissions.
 */
return new class extends Migration
{
    private const GUARD = 'employee';

    private const OLD = 'warehouses.manage';

    /** @var list<string> */
    private const NEW = ['warehouses.view', 'warehouses.create', 'warehouses.update', 'warehouses.delete'];

    public function up(): void
    {
        DB::transaction(function () {
            $this->copyHolders([self::OLD], self::NEW);
            $this->deletePermissions([self::OLD]);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::transaction(function () {
            $this->copyHolders(self::NEW, [self::OLD]);
            $this->deletePermissions(self::NEW);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @param  list<string>  $sources
     * @param  list<string>  $targets
     */
    private function copyHolders(array $sources, array $targets): void
    {
        $sourceIds = DB::table('permissions')->whereIn('name', $sources)->where('guard_name', self::GUARD)->pluck('id');

        foreach ($targets as $target) {
            $targetId = DB::table('permissions')->where('name', $target)->where('guard_name', self::GUARD)->value('id')
                ?? DB::table('permissions')->insertGetId([
                    'name' => $target,
                    'guard_name' => self::GUARD,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            foreach (DB::table('role_has_permissions')->whereIn('permission_id', $sourceIds)->pluck('role_id')->unique() as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $targetId, 'role_id' => $roleId]);
            }

            foreach (DB::table('model_has_permissions')->whereIn('permission_id', $sourceIds)->get(['model_type', 'model_id']) as $holder) {
                DB::table('model_has_permissions')->insertOrIgnore([
                    'permission_id' => $targetId,
                    'model_type' => $holder->model_type,
                    'model_id' => $holder->model_id,
                ]);
            }
        }
    }

    /**
     * @param  list<string>  $names
     */
    private function deletePermissions(array $names): void
    {
        $ids = DB::table('permissions')->whereIn('name', $names)->where('guard_name', self::GUARD)->pluck('id');

        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
