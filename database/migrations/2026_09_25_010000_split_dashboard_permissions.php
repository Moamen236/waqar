<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Splits the bundled dashboard permissions so each page, tab and action has
 * its own grant (see PermissionSeeder). Existing grants are carried over
 * holder by holder — role or individual employee — so whatever the matrix
 * editor has set on a live system survives: a role that could confirm
 * orders before can still confirm them, it just holds `checking.confirm`
 * now instead of `orders.status.update`.
 *
 * `orders.view` deliberately maps only to the print grants: it used to open
 * Checking, Delivery and Accounting too, and not carrying that over is the
 * point of the split.
 *
 * Raw queries rather than the Spatie models, so the migration keeps working
 * whatever those models look like later.
 */
return new class extends Migration
{
    private const GUARD = 'employee';

    /**
     * Old grant => the grants every holder of it receives.
     *
     * @var array<string, list<string>>
     */
    private const MAP = [
        'orders.status.update' => ['checking.view', 'checking.confirm', 'checking.postpone', 'checking.cancel', 'checking.backorder'],
        'orders.assign' => ['delivery.view', 'delivery.assign', 'delivery.move'],
        'orders.confirm_delivery' => ['accounting.view', 'accounting.confirm_handover', 'accounting.confirm_delivered', 'accounting.confirm_returned', 'accounting.collect'],
        'orders.view' => ['orders.print_invoice', 'orders.print_label'],
        'returns.create' => ['returns.view'],
        'returns.check' => ['returns.view'],
        'returns.receive' => ['returns.assign_pickup'],
        'returns.refund' => ['returns.replace'],
        'products.update' => ['products.cost_price.view'],
        'geo.manage' => ['geo.create', 'geo.update', 'geo.delete'],
    ];

    /** @var list<string> */
    private const RETIRED = ['orders.status.update', 'orders.assign', 'orders.confirm_delivery', 'geo.manage'];

    public function up(): void
    {
        DB::transaction(function () {
            foreach (self::MAP as $old => $new) {
                $this->grantToHoldersOf($old, $new);
            }

            $this->deletePermissions(self::RETIRED);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::transaction(function () {
            $reverse = [
                'orders.status.update' => ['checking.confirm', 'checking.postpone', 'checking.cancel', 'checking.backorder'],
                'orders.assign' => ['delivery.assign', 'delivery.move'],
                'orders.confirm_delivery' => ['accounting.confirm_handover', 'accounting.confirm_delivered', 'accounting.confirm_returned', 'accounting.collect'],
                'geo.manage' => ['geo.create', 'geo.update', 'geo.delete'],
            ];

            foreach ($reverse as $old => $sources) {
                foreach ($sources as $source) {
                    $this->grantToHoldersOf($source, [$old]);
                }
            }

            $this->deletePermissions(array_values(array_unique(array_merge(...array_values(self::MAP)))));
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @param  list<string>  $targets
     */
    private function grantToHoldersOf(string $source, array $targets): void
    {
        $sourceId = $this->permissionId($source, create: false);

        foreach ($targets as $target) {
            $targetId = $this->permissionId($target, create: true);

            if ($sourceId === null) {
                continue;
            }

            foreach (DB::table('role_has_permissions')->where('permission_id', $sourceId)->pluck('role_id') as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $targetId, 'role_id' => $roleId]);
            }

            foreach (DB::table('model_has_permissions')->where('permission_id', $sourceId)->get(['model_type', 'model_id']) as $holder) {
                DB::table('model_has_permissions')->insertOrIgnore([
                    'permission_id' => $targetId,
                    'model_type' => $holder->model_type,
                    'model_id' => $holder->model_id,
                ]);
            }
        }
    }

    private function permissionId(string $name, bool $create): ?int
    {
        $id = DB::table('permissions')->where('name', $name)->where('guard_name', self::GUARD)->value('id');

        if ($id === null && $create) {
            $id = DB::table('permissions')->insertGetId([
                'name' => $name,
                'guard_name' => self::GUARD,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $id === null ? null : (int) $id;
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
