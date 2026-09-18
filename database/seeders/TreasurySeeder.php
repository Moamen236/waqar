<?php

namespace Database\Seeders;

use App\Models\Treasury;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Every COD collection, refund and expense needs a treasury to post
 * against (TreasuryService, Section 11/14) — one cash till and one bank
 * account is enough to exercise both a collection and a transfer between
 * treasuries.
 */
class TreasurySeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        Treasury::firstOrCreate(
            ['name' => 'Main Cash Register'],
            ['type' => 'cash', 'current_balance' => 5000.00, 'is_active' => true],
        );

        Treasury::firstOrCreate(
            ['name' => 'Main Bank Account'],
            ['type' => 'bank', 'account_number' => '1234567890', 'current_balance' => 25000.00, 'is_active' => true],
        );
    }
}
