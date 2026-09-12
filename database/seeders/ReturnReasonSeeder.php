<?php

namespace Database\Seeders;

use App\Models\ReturnReason;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Seeded from the Internal Operations document's list (spec Section 12)
 * — admin-extendable without a schema change, this is just the starting
 * set.
 */
class ReturnReasonSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        foreach ([
            ['ar' => 'مقاس خاطئ', 'en' => 'Wrong Size'],
            ['ar' => 'منتج معيب', 'en' => 'Defective Product'],
            ['ar' => 'العميل غيّر رأيه', 'en' => 'Customer Changed Mind'],
            ['ar' => 'منتج خاطئ', 'en' => 'Wrong Product'],
            ['ar' => 'منتج تالف', 'en' => 'Product Damaged'],
            ['ar' => 'أخرى', 'en' => 'Other'],
        ] as $i => $name) {
            ReturnReason::create(['name' => $name, 'sort_order' => $i]);
        }
    }
}
