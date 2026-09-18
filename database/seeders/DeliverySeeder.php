<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\DeliveryRepresentative;
use App\Models\ShippingCompany;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * The two assignee types AssignDeliveryAction can hand an order to
 * (Section 11) — without at least one of each, the Delivery Manager demo
 * account has nothing to assign a Confirmed order to.
 */
class DeliverySeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $rep = DeliveryRepresentative::firstOrCreate(
            ['name' => 'Ahmed Kamal'],
            ['phone' => '+201200000001', 'status' => 'active', 'notes' => 'Covers Cairo east.'],
        );

        DeliveryRepresentative::firstOrCreate(
            ['name' => 'Mona Farouk'],
            ['phone' => '+201200000002', 'status' => 'active', 'notes' => 'Covers Giza.'],
        );

        foreach (Area::query()->limit(2)->get() as $area) {
            $rep->areas()->firstOrCreate(['geo_type' => 'area', 'geo_id' => $area->id]);
        }

        ShippingCompany::firstOrCreate(
            ['name' => 'Bosta'],
            [
                'phone' => '+201300000001',
                'address' => 'Smart Village, Giza, Egypt',
                'delivery_fee' => 40.00,
                'return_fee' => 20.00,
                'status' => 'active',
                'contact_person' => 'Karim Hassan',
                'bank_name' => 'CIB',
                'bank_account_number' => '100200300400',
            ],
        );

        ShippingCompany::firstOrCreate(
            ['name' => 'Mylerz'],
            [
                'phone' => '+201300000002',
                'address' => 'New Cairo, Egypt',
                'delivery_fee' => 45.00,
                'return_fee' => 25.00,
                'status' => 'active',
                'contact_person' => 'Dina Salem',
                'bank_name' => 'NBE',
                'bank_account_number' => '500600700800',
            ],
        );
    }
}
