<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The 9 base roles (spec Section 15), all on the "employee" guard —
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
        ] as $role) {
            Role::findOrCreate($role, 'employee');
        }
    }
}
