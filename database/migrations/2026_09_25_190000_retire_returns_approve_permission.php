<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * A return is now approved only through Checking's call (returns.check →
 * Confirm), so the separate "Approve Return" route and its permission are
 * gone. Holders simply lose it: approving without the call is exactly the
 * shortcut being removed, so it is deliberately not remapped.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ids = DB::table('permissions')->where('name', 'returns.approve')->where('guard_name', 'employee')->pluck('id');

        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Restores the grant to whoever could receive a return — the roles that
     * held returns.approve before (Warehouse Manager, Accounting).
     */
    public function down(): void
    {
        $approveId = DB::table('permissions')->insertGetId([
            'name' => 'returns.approve',
            'guard_name' => 'employee',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $receiveId = DB::table('permissions')->where('name', 'returns.receive')->where('guard_name', 'employee')->value('id');

        foreach (DB::table('role_has_permissions')->where('permission_id', $receiveId)->pluck('role_id') as $roleId) {
            DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $approveId, 'role_id' => $roleId]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
