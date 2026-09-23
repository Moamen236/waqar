<?php

namespace Database\Seeders\Production;

use App\Enums\TreasuryType;
use App\Models\Employee;
use App\Models\Treasury;
use App\Models\Warehouse;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * First-time production bootstrap — the minimum operational baseline a
 * live store needs before it can do anything: one warehouse, the two
 * top-level logins, and the two treasuries money posts against.
 *
 * Run explicitly, once per production environment (NOT part of
 * DatabaseSeeder, which is the dev/demo dataset):
 *
 *   php artisan db:seed --class="Database\Seeders\Production\ProductionBaselineSeeder"
 *
 * Idempotent: roles/permissions resolve through findOrCreate + sync, and
 * every record below resolves through firstOrCreate, so re-running after
 * a partial failure only fills in what is missing. Employee passwords
 * are set on create only — re-runs never overwrite a password that was
 * changed after bootstrap.
 *
 * Credentials come from the environment so no real password lives in the
 * repo; change both immediately after first login:
 *
 *   PROD_SUPER_ADMIN_EMAIL / PROD_SUPER_ADMIN_PASSWORD
 *   PROD_CHAIRMAN_EMAIL / PROD_CHAIRMAN_PASSWORD
 */
class ProductionBaselineSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Roles and the permission matrix must exist before assignRole().
        // Both seeders are idempotent (findOrCreate + syncPermissions),
        // so calling them here is safe on re-runs too.
        $this->call([
            RoleSeeder::class,
            PermissionSeeder::class,
        ]);

        DB::transaction(function (): void {
            // Single default warehouse. Warehouse::main() resolves to the
            // oldest active warehouse when no "Main Warehouse" exists, so
            // this record automatically becomes the default for manual
            // orders and stock moves until more warehouses are added.
            $warehouse = Warehouse::firstOrCreate(
                ['name' => 'Warehouse'],
                [
                    'address' => $this->prodEnv('PROD_WAREHOUSE_ADDRESS', 'Cairo, Egypt'),
                    'phone' => $this->prodEnv('PROD_WAREHOUSE_PHONE', '+201000000000'),
                    'is_active' => true,
                ],
            );

            $superAdmin = Employee::firstOrCreate(
                ['email' => $this->prodEnv('PROD_SUPER_ADMIN_EMAIL', 'admin@waqar.store')],
                [
                    'full_name' => 'Super Admin',
                    'phone' => $this->prodEnv('PROD_SUPER_ADMIN_PHONE', '+201000000000'),
                    'password' => $this->prodEnv('PROD_SUPER_ADMIN_PASSWORD', 'ChangeMe123!'),
                    'residence_address' => $this->prodEnv('PROD_SUPER_ADMIN_ADDRESS', 'Cairo, Egypt'),
                    'national_id_number' => $this->prodEnv('PROD_SUPER_ADMIN_NATIONAL_ID', '00000000000000'),
                    'is_active' => true,
                ],
            );
            $superAdmin->assignRole('Super Admin');

            $chairman = Employee::firstOrCreate(
                ['email' => $this->prodEnv('PROD_CHAIRMAN_EMAIL', 'chairman@waqar.store')],
                [
                    'full_name' => 'Chairman',
                    'phone' => $this->prodEnv('PROD_CHAIRMAN_PHONE', '+201000000001'),
                    'password' => $this->prodEnv('PROD_CHAIRMAN_PASSWORD', 'ChangeMe123!'),
                    'residence_address' => $this->prodEnv('PROD_CHAIRMAN_ADDRESS', 'Cairo, Egypt'),
                    'national_id_number' => $this->prodEnv('PROD_CHAIRMAN_NATIONAL_ID', '00000000000000'),
                    'is_active' => true,
                ],
            );
            $chairman->assignRole('Chairman');

            // Zero opening balances — real money enters through treasury
            // transactions, never by editing the seed.
            Treasury::firstOrCreate(
                ['name' => 'Main Cash'],
                [
                    'type' => TreasuryType::Cash,
                    'account_number' => null,
                    'current_balance' => 0.00,
                    'is_active' => true,
                ],
            );

            Treasury::firstOrCreate(
                ['name' => 'Main Bank Account'],
                [
                    'type' => TreasuryType::Bank,
                    'account_number' => $this->prodEnvNullable('PROD_BANK_ACCOUNT_NUMBER'),
                    'current_balance' => 0.00,
                    'is_active' => true,
                ],
            );

            $this->command->info(
                "Production baseline ready: warehouse #{$warehouse->id}, employees #{$superAdmin->id} + #{$chairman->id}, treasuries seeded."
            );
        });
    }

    /**
     * Console-side env read. Uses getenv() rather than env() on purpose:
     * DEPLOYMENT.md caches the config in production, and env() returns
     * null outside config files once the config is cached — bootstrap
     * credentials would silently fall back to defaults.
     */
    private function prodEnv(string $key, string $default): string
    {
        $value = getenv($key);

        if (! is_string($value) || trim($value) === '') {
            return $default;
        }

        return $value;
    }

    private function prodEnvNullable(string $key): ?string
    {
        $value = getenv($key);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $value;
    }
}
