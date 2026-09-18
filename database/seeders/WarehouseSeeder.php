<?php

namespace Database\Seeders;

use App\Models\Warehouse;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Every stock reservation/deduction needs a warehouse to move against
 * (InventoryService, Phase 3) — a real store needs at least one, so this
 * is operational baseline data, not demo/fake content. A second warehouse
 * is seeded too, purely so StockTransferSeeder has somewhere to move
 * stock to and from.
 */
class WarehouseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        Warehouse::firstOrCreate(
            ['name' => 'Main Warehouse'],
            ['address' => 'Cairo, Egypt', 'phone' => '+201000000001', 'is_active' => true],
        );

        Warehouse::firstOrCreate(
            ['name' => 'Alexandria Hub'],
            ['address' => 'Alexandria, Egypt', 'phone' => '+201000000002', 'is_active' => true],
        );
    }
}
