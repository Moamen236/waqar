<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Deliberately does NOT use WithoutModelEvents: that trait wraps this
 * entire run() — including every nested $this->call() — in
 * Model::withoutEvents(), which would silently disable Order's own
 * `creating` hook that assigns order_number (Order::booted()). Several
 * seeders below create orders through the real CreateOrderAction rather
 * than setting order_number by hand, so those events have to stay live.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            // Access control and the people who use it.
            RoleSeeder::class,
            PermissionSeeder::class,
            EmployeeSeeder::class,

            // Operational baseline data other seeders/Actions depend on.
            GeoSeeder::class,
            ReturnReasonSeeder::class,
            WarehouseSeeder::class,
            DeliverySeeder::class,
            TreasurySeeder::class,
            CouponSeeder::class,

            // Catalogue, then the marketing data that references it.
            ProductSeeder::class,
            PromotionSeeder::class,
            ShippingRateSeeder::class,

            // Demo content — customers, orders, returns and everything
            // else that walks the real Actions through every flow.
            StorefrontDemoSeeder::class,
            OrderOperationsDemoSeeder::class,
            ReturnDemoSeeder::class,
            StockTransferSeeder::class,
            ExpenseSeeder::class,
            CartSeeder::class,
        ]);
    }
}
