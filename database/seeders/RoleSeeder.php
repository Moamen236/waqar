<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The 9 base roles (spec Section 15) plus Store Orders, all on the
 * "employee" guard —
 * customers never carry a role of their own (Section 24, confirmation
 * #13). Just the roles themselves — see PermissionSeeder (Phase 4) for
 * the granular permission catalog and each role's default grants.
 */
class RoleSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([
            'Super Admin',
            'Chairman',
            'Vice Chairman',
            'Warehouse Manager',
            'Customer Service',
            'Customer Service Team Leader',
            'Checking',
            'Delivery Manager',
            'Accounting',
            // 10th role, added after the spec was resolved: Customer
            // Service is now scoped to each agent's own phone orders, so
            // storefront orders had nobody watching them. This role is
            // that watcher — read-only, and only over website orders
            // (Order::scopeVisibleTo).
            'Store Orders',
        ] as $role) {
            Role::findOrCreate($role, 'employee');
        }
    }
}
