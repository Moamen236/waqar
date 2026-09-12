<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * /admin/roles — the permission-matrix editor (Section 15: "actual
 * access is 100% permission-driven ... every screen or action is gated
 * by a granular permission, never by role name directly"). PermissionSeeder
 * only sets day-one defaults; this is how a Super Admin changes them
 * afterward, and how a genuinely new role gets its own grants.
 */
class RoleController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Roles/Index', [
            'roles' => Role::query()->where('guard_name', 'employee')->withCount('permissions')->orderBy('name')->get(),
        ]);
    }

    public function edit(Role $role): Response
    {
        return Inertia::render('Roles/Edit', [
            'role' => $role,
            'assigned' => $role->permissions()->pluck('name'),
            'allPermissions' => Permission::query()->where('guard_name', 'employee')->orderBy('name')->pluck('name'),
        ]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $data = $request->validate([
            'permissions' => ['array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        $role->syncPermissions($data['permissions'] ?? []);

        return redirect()->route('admin.roles.index')->with('success', "Permissions updated for {$role->name}.");
    }
}
