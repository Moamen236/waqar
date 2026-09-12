<?php

namespace App\Services\Shipping;

use App\Models\ShippingRate;

/**
 * Resolves the shipping price for an address by checking the most
 * specific configured level first and falling back upward — Area, then
 * District, then City, then Governorate (spec Section 11). The frontend
 * never sets or sees a price before this runs; CreateOrderAction always
 * calls this rather than trusting a client-supplied shipping amount.
 */
class ShippingRateResolver
{
    /**
     * @return ShippingRate|null null means no rate is configured at any
     *                           level for this address — the caller
     *                           decides how to handle that (e.g. reject
     *                           the checkout rather than charge nothing).
     */
    public function resolve(
        int $governorateId,
        int $cityId,
        ?int $districtId,
        int $areaId,
    ): ?ShippingRate {
        $candidates = [
            ['area', $areaId],
        ];

        if ($districtId !== null) {
            $candidates[] = ['district', $districtId];
        }

        $candidates[] = ['city', $cityId];
        $candidates[] = ['governorate', $governorateId];

        foreach ($candidates as [$geoType, $geoId]) {
            $rate = ShippingRate::query()
                ->where('geo_type', $geoType)
                ->where('geo_id', $geoId)
                ->where('is_active', true)
                ->first();

            if ($rate !== null) {
                return $rate;
            }
        }

        return null;
    }
}
