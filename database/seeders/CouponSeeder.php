<?php

namespace Database\Seeders;

use App\Models\Coupon;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * One coupon per CouponService type (percentage/fixed/free_shipping,
 * Section 08) so checkout's discount math is exercised against real rows
 * rather than only unit tests.
 */
class CouponSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        Coupon::firstOrCreate(
            ['code' => 'WELCOME10'],
            [
                'type' => 'percentage',
                'value' => 10,
                'minimum_order_amount' => 200,
                'usage_limit' => 500,
                'usage_limit_per_user' => 1,
                'starts_at' => now()->subMonth(),
                'ends_at' => now()->addMonths(3),
                'is_active' => true,
            ],
        );

        Coupon::firstOrCreate(
            ['code' => 'SAVE50'],
            [
                'type' => 'fixed',
                'value' => 50,
                'minimum_order_amount' => 300,
                'usage_limit' => null,
                'usage_limit_per_user' => 2,
                'starts_at' => now()->subWeek(),
                'ends_at' => now()->addMonths(2),
                'is_active' => true,
            ],
        );

        Coupon::firstOrCreate(
            ['code' => 'FREESHIP'],
            [
                'type' => 'free_shipping',
                'value' => 0,
                'minimum_order_amount' => 150,
                'usage_limit' => null,
                'usage_limit_per_user' => null,
                'starts_at' => null,
                'ends_at' => null,
                'is_active' => true,
            ],
        );

        Coupon::firstOrCreate(
            ['code' => 'EXPIRED20'],
            [
                'type' => 'percentage',
                'value' => 20,
                'minimum_order_amount' => null,
                'usage_limit' => 100,
                'usage_limit_per_user' => 1,
                'starts_at' => now()->subMonths(2),
                'ends_at' => now()->subMonth(),
                'is_active' => false,
            ],
        );
    }
}
