<?php

namespace Database\Seeders\Production;

use App\Models\Area;
use App\Models\City;
use App\Models\Country;
use App\Models\District;
use App\Models\Governorate;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Production Egypt geography seed — the full four-level hierarchy
 * (Governorates └─ Cities └─ Districts └─ Areas, spec Section 11) from
 * the business-provided dataset in this same folder
 * (egypt_governorates_cities_districts.json: 27 governorates,
 * ~239 cities, ~351 districts, ~5.7k areas).
 *
 * This is the real coverage GeoSeeder's hand-written Cairo/Giza sample
 * stands in for during local development. It is deliberately NOT called
 * from DatabaseSeeder — run it explicitly, once per environment:
 *
 *   php artisan db:seed --class="Database\Seeders\Production\EgyptGeoSeeder"
 *
 * Idempotent: existing rows are matched by (parent, ar/en name pair) and
 * reused, so re-running after a partial failure — or on a dev database
 * that already has GeoSeeder's Cairo/Giza sample — adds only what is
 * missing instead of duplicating. The JSON `id`/`code` fields are the
 * source system's identifiers; this schema has no code columns below
 * Country, so only the ar/en names are stored.
 */
class EgyptGeoSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $path = __DIR__.'/egypt_governorates_cities_districts.json';

        if (! is_file($path)) {
            throw new RuntimeException("Dataset not found: {$path}");
        }

        /** @var array<int, mixed>|null $governorates */
        $governorates = json_decode((string) file_get_contents($path), true);

        if (! is_array($governorates) || $governorates === []) {
            throw new RuntimeException("Dataset is empty or invalid JSON: {$path}");
        }

        DB::transaction(function () use ($governorates): void {
            $now = now()->toDateTimeString();

            $egypt = Country::query()->where('code', 'EG')->first();

            if ($egypt === null) {
                $egypt = Country::query()->create([
                    'name' => ['ar' => 'مصر', 'en' => 'Egypt'],
                    'code' => 'EG',
                    'is_active' => true,
                ]);
            }

            // Preload everything once so the ~6k-row import costs a handful
            // of SELECTs; the in-memory maps double as the idempotency
            // check (parent id + ar/en name pair) and are kept up to date as
            // new rows are created below. The key must include BOTH locales:
            // the dataset has same-city siblings with identical English
            // names but different Arabic ones (e.g. "أول الرمل" and
            // "ثان الرمل" are both "Raml"), so matching on English alone
            // would merge them and orphan one's areas.
            $governorateMap = [];
            foreach (Governorate::query()->where('country_id', $egypt->id)->get(['id', 'name']) as $governorate) {
                $governorateMap[$this->pairKey($governorate->getTranslation('name', 'ar'), $governorate->getTranslation('name', 'en'))] = $governorate->id;
            }

            $cityMap = [];
            $cityIds = [];
            if ($governorateMap !== []) {
                foreach (City::query()->whereIn('governorate_id', array_values($governorateMap))->get(['id', 'governorate_id', 'name']) as $city) {
                    $cityMap[$city->governorate_id.'|'.$this->pairKey($city->getTranslation('name', 'ar'), $city->getTranslation('name', 'en'))] = $city->id;
                    $cityIds[] = $city->id;
                }
            }

            $districtMap = [];
            $districtIds = [];
            if ($cityIds !== []) {
                foreach (District::query()->whereIn('city_id', $cityIds)->get(['id', 'city_id', 'name']) as $district) {
                    $districtMap[$district->city_id.'|'.$this->pairKey($district->getTranslation('name', 'ar'), $district->getTranslation('name', 'en'))] = $district->id;
                    $districtIds[] = $district->id;
                }
            }

            $areaKeys = [];
            if ($districtIds !== []) {
                foreach (Area::query()->whereIn('district_id', $districtIds)->get(['district_id', 'name']) as $area) {
                    $areaKeys[$area->district_id.'|'.$this->pairKey($area->getTranslation('name', 'ar'), $area->getTranslation('name', 'en'))] = true;
                }
            }

            $newGovernorates = 0;
            $newCities = 0;
            $newDistricts = 0;
            $newAreas = 0;

            // Areas bulk-insert in chunks; nothing references an area id at
            // seed time, so no per-row id lookup is needed afterwards.
            /** @var list<array<string, mixed>> $areaRows */
            $areaRows = [];
            $flushAreas = function () use (&$areaRows, &$newAreas): void {
                foreach (array_chunk($areaRows, 500) as $chunk) {
                    DB::table('areas')->insert($chunk);
                }

                $newAreas += count($areaRows);
                $areaRows = [];
            };

            foreach ($governorates as $governorateData) {
                if (! is_array($governorateData)) {
                    continue;
                }

                $nameEn = $this->name($governorateData, 'en');
                $nameAr = $this->name($governorateData, 'ar', $nameEn);

                if ($nameEn === '') {
                    continue;
                }

                $governorateKey = $this->pairKey($nameAr, $nameEn);
                $governorateId = $governorateMap[$governorateKey] ?? null;

                if ($governorateId === null) {
                    $governorateId = Governorate::query()->create([
                        'country_id' => $egypt->id,
                        'name' => ['ar' => $nameAr, 'en' => $nameEn],
                        'is_active' => true,
                    ])->id;
                    $governorateMap[$governorateKey] = $governorateId;
                    $newGovernorates++;
                }

                foreach ((array) ($governorateData['cities'] ?? []) as $cityData) {
                    if (! is_array($cityData)) {
                        continue;
                    }

                    $cityEn = $this->name($cityData, 'en');
                    $cityAr = $this->name($cityData, 'ar', $cityEn);

                    if ($cityEn === '') {
                        continue;
                    }

                    $cityKey = $governorateId.'|'.$this->pairKey($cityAr, $cityEn);
                    $cityId = $cityMap[$cityKey] ?? null;

                    if ($cityId === null) {
                        $cityId = City::query()->create([
                            'governorate_id' => $governorateId,
                            'name' => ['ar' => $cityAr, 'en' => $cityEn],
                            'is_active' => true,
                        ])->id;
                        $cityMap[$cityKey] = $cityId;
                        $newCities++;
                    }

                    foreach ((array) ($cityData['districts'] ?? []) as $districtData) {
                        if (! is_array($districtData)) {
                            continue;
                        }

                        $districtEn = $this->name($districtData, 'en');
                        $districtAr = $this->name($districtData, 'ar', $districtEn);

                        if ($districtEn === '') {
                            continue;
                        }

                        $districtKey = $cityId.'|'.$this->pairKey($districtAr, $districtEn);
                        $districtId = $districtMap[$districtKey] ?? null;

                        if ($districtId === null) {
                            $districtId = District::query()->create([
                                'city_id' => $cityId,
                                'name' => ['ar' => $districtAr, 'en' => $districtEn],
                                'status' => true,
                            ])->id;
                            $districtMap[$districtKey] = $districtId;
                            $newDistricts++;
                        }

                        foreach ((array) ($districtData['areas'] ?? []) as $areaData) {
                            if (! is_array($areaData)) {
                                continue;
                            }

                            $areaEn = $this->name($areaData, 'en');
                            $areaAr = $this->name($areaData, 'ar', $areaEn);

                            if ($areaEn === '') {
                                continue;
                            }

                            $areaKey = $districtId.'|'.$this->pairKey($areaAr, $areaEn);

                            if (isset($areaKeys[$areaKey])) {
                                continue;
                            }

                            $areaRows[] = [
                                'city_id' => $cityId,
                                'district_id' => $districtId,
                                'name' => json_encode(['ar' => $areaAr, 'en' => $areaEn], JSON_UNESCAPED_UNICODE),
                                'is_active' => true,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];
                            $areaKeys[$areaKey] = true;
                        }
                    }

                    // Keep memory flat on large governorates.
                    if (count($areaRows) >= 1500) {
                        $flushAreas();
                    }
                }
            }

            $flushAreas();

            // Invoked through `db:seed`, which always sets the command
            // instance (Seeder::setCommand), so this is never null here.
            $this->command->info(
                "Egypt geography seeded: +{$newGovernorates} governorates, +{$newCities} cities, ".
                "+{$newDistricts} districts, +{$newAreas} areas."
            );
        });
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function name(array $row, string $locale, string $fallback = ''): string
    {
        $value = $row['name_'.$locale] ?? $row[$locale] ?? $fallback;

        return trim((string) $value);
    }

    private function key(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    private function pairKey(string $nameAr, string $nameEn): string
    {
        return $this->key($nameAr).'|'.$this->key($nameEn);
    }
}
