<?php

namespace App\Support;

use App\Models\Area;
use App\Models\City;
use App\Models\Country;
use App\Models\District;
use App\Models\Governorate;

/**
 * The full active geo tree (Governorate → City → District/Area), shared
 * by every admin screen that needs a cascading location picker
 * (Customer Service's order-create address fields, a delivery
 * representative's coverage areas). Small dataset (Section 11's sample
 * Egypt geography) — cheap to send whole rather than paginating levels
 * over AJAX.
 *
 * Broken into one static method per level (rather than nested closures)
 * so each has its own explicit return-shape docblock — Larastan's
 * structural inference across several levels of nested `->map()`
 * closures produces mismatched-but-structurally-identical array shapes
 * otherwise, since Collection's generic isn't covariant.
 */
class GeoTree
{
    /**
     * The same tree rooted one level higher, at `countries` (Section 11's
     * fourth level, and the first field of the checkout cascade). The
     * storefront uses this so Country is a real select driven by the
     * countries table rather than a hard-coded single option; admin
     * screens keep using tree(), which is already scoped to the one
     * country they operate in.
     *
     * @return array<int, array{id: int, code: string, name: string, governorates: array<int, array{id: int, name: string, cities: array<int, array{id: int, name: string, districts: array<int, array{id: int, name: string}>, areas: array<int, array{id: int, district_id: int|null, name: string}>}>}>}>
     */
    public static function countries(): array
    {
        return Country::query()
            ->where('is_active', true)
            ->with(['governorates' => function ($query) {
                $query->where('is_active', true)->with(['cities' => function ($cityQuery) {
                    $cityQuery->where('is_active', true)->with([
                        'districts' => fn ($q) => $q->where('status', true),
                        'areas' => fn ($q) => $q->where('is_active', true),
                    ]);
                }]);
            }])
            ->get()
            ->map(fn (Country $country) => [
                'id' => $country->id,
                'code' => (string) $country->code,
                'name' => $country->getTranslation('name', app()->getLocale()),
                'governorates' => $country->governorates
                    ->map(fn (Governorate $governorate) => self::governorate($governorate))
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id: int, name: string, cities: array<int, array{id: int, name: string, districts: array<int, array{id: int, name: string}>, areas: array<int, array{id: int, district_id: int|null, name: string}>}>}>
     */
    public static function tree(): array
    {
        return Governorate::query()
            ->where('is_active', true)
            ->with(['cities' => function ($query) {
                $query->where('is_active', true)->with([
                    'districts' => fn ($q) => $q->where('status', true),
                    'areas' => fn ($q) => $q->where('is_active', true),
                ]);
            }])
            ->get()
            ->map(fn (Governorate $governorate) => self::governorate($governorate))
            ->values()
            ->all();
    }

    /**
     * @return array{id: int, name: string, cities: array<int, array{id: int, name: string, districts: array<int, array{id: int, name: string}>, areas: array<int, array{id: int, district_id: int|null, name: string}>}>}
     */
    public static function governorate(Governorate $governorate): array
    {
        return [
            'id' => $governorate->id,
            'name' => $governorate->getTranslation('name', app()->getLocale()),
            'cities' => $governorate->cities->map(fn (City $city) => self::city($city))->values()->all(),
        ];
    }

    /**
     * @return array{id: int, name: string, districts: array<int, array{id: int, name: string}>, areas: array<int, array{id: int, district_id: int|null, name: string}>}
     */
    private static function city(City $city): array
    {
        return [
            'id' => $city->id,
            'name' => $city->getTranslation('name', app()->getLocale()),
            'districts' => $city->districts->map(fn (District $district) => self::district($district))->values()->all(),
            'areas' => $city->areas->map(fn (Area $area) => self::area($area))->values()->all(),
        ];
    }

    /**
     * @return array{id: int, name: string}
     */
    private static function district(District $district): array
    {
        return [
            'id' => $district->id,
            'name' => $district->getTranslation('name', app()->getLocale()),
        ];
    }

    /**
     * @return array{id: int, district_id: int|null, name: string}
     */
    private static function area(Area $area): array
    {
        return [
            'id' => $area->id,
            'district_id' => $area->district_id,
            'name' => $area->getTranslation('name', app()->getLocale()),
        ];
    }
}
