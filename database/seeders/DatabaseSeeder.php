<?php

namespace Database\Seeders;

use App\Models\Employee;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            PermissionSeeder::class,
            GeoSeeder::class,
            ReturnReasonSeeder::class,
            WarehouseSeeder::class,
            ProductSeeder::class,
            ShippingRateSeeder::class,
            StorefrontDemoSeeder::class,
        ]);

        $superAdmin = Employee::create([
            'full_name' => 'Super Admin',
            'email' => 'admin@waqar.test',
            'phone' => '+201000000000',
            'password' => 'password', // local dev only — hashed via the model's casts()
            'residence_address' => 'N/A',
            'national_id_number' => 'N/A',
        ]);
        $superAdmin->assignRole('Super Admin');
    }
}
