<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\Governorate;
use App\Models\ShippingRate;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Sample shipping rates for local development only — the business enters
 * its real coverage itself. Without at least one rate the storefront
 * checkout refuses every order ("No shipping rate is configured for this
 * address"), by design: CreateOrderAction would rather reject an order
 * than ship it for free (Section 11).
 *
 * Deliberately mixes levels so the most-specific-match fallback (Area →
 * District → City → Governorate) is exercised by real data rather than
 * only by tests: both governorates get a base rate, and one area gets a
 * cheaper override that should win for addresses inside it.
 */
class ShippingRateSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        foreach (Governorate::all() as $governorate) {
            ShippingRate::firstOrCreate(
                ['geo_type' => 'governorate', 'geo_id' => $governorate->id],
                ['price' => 60.00, 'free_shipping_threshold' => 1500.00, 'is_active' => true],
            );
        }

        $area = Area::query()->orderBy('id')->first();

        if ($area !== null) {
            ShippingRate::firstOrCreate(
                ['geo_type' => 'area', 'geo_id' => $area->id],
                ['price' => 35.00, 'free_shipping_threshold' => 1000.00, 'is_active' => true],
            );
        }
    }
}
