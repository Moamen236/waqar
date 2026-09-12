<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\City;
use App\Models\Country;
use App\Models\Governorate;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Sample geography for local development only — enough of the four-level
 * hierarchy (spec Section 11) to build and test addresses/shipping
 * against. This is not the business's real, complete Egypt coverage;
 * populating that is a data-entry task for the admin dashboard, not
 * schema work.
 */
class GeoSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $egypt = Country::create([
            'name' => ['ar' => 'مصر', 'en' => 'Egypt'],
            'code' => 'EG',
        ]);

        $cairo = Governorate::create([
            'country_id' => $egypt->id,
            'name' => ['ar' => 'القاهرة', 'en' => 'Cairo'],
        ]);

        $giza = Governorate::create([
            'country_id' => $egypt->id,
            'name' => ['ar' => 'الجيزة', 'en' => 'Giza'],
        ]);

        $nasrCity = City::create([
            'governorate_id' => $cairo->id,
            'name' => ['ar' => 'مدينة نصر', 'en' => 'Nasr City'],
        ]);

        $maadi = City::create([
            'governorate_id' => $cairo->id,
            'name' => ['ar' => 'المعادي', 'en' => 'Maadi'],
        ]);

        $sixthOctober = City::create([
            'governorate_id' => $giza->id,
            'name' => ['ar' => '٦ أكتوبر', 'en' => '6th of October'],
        ]);

        foreach ([
            [$nasrCity, 'ar' => 'الحي السابع', 'en' => 'District 7'],
            [$nasrCity, 'ar' => 'الحي العاشر', 'en' => 'District 10'],
            [$maadi, 'ar' => 'المعادي القديمة', 'en' => 'Old Maadi'],
            [$sixthOctober, 'ar' => 'الحي الأول', 'en' => 'First District'],
        ] as $row) {
            [$city] = $row;
            Area::create([
                'city_id' => $city->id,
                'name' => ['ar' => $row['ar'], 'en' => $row['en']],
            ]);
        }
    }
}
