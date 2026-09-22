<?php

use App\Enums\CustomerOrderStatus;
use App\Enums\OrderSource;
use App\Models\Area;
use App\Models\City;
use App\Models\Country;
use App\Models\Customer;
use App\Models\District;
use App\Models\Employee;
use App\Models\Governorate;
use App\Models\Order;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Spatie\Permission\Models\Role;

// B1 — the Governorate → City → District → Area screens
// (feature-backlog-plan.md). The tables shipped in Phase 1; until now
// adding a city meant a seeder or a SQL client.
//
// geo* prefix: Pest loads every Feature file into one global namespace.

function geoEmployee(string $role): Employee
{
    $employee = Employee::create([
        'full_name' => $role.' User', 'email' => 'geo-'.uniqid().'@waqar.test', 'phone' => '01012345678',
        'password' => 'password', 'residence_address' => 'N/A', 'national_id_number' => 'N/A',
    ]);
    $employee->assignRole(Role::findOrCreate($role, 'employee'));

    return $employee;
}

function geoCountry(): Country
{
    return Country::create(['name' => ['ar' => 'مصر', 'en' => 'Egypt'], 'code' => 'EG']);
}

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
});

it('creates a place at each of the four levels through the admin screens', function () {
    $manager = geoEmployee('Delivery Manager');
    $country = geoCountry();

    $this->actingAs($manager, 'employee')->post(route('admin.geo.store', 'governorates'), [
        'name' => ['ar' => 'القاهرة', 'en' => 'Cairo'],
        'country_id' => $country->id,
        'is_active' => true,
    ])->assertRedirect();

    $governorate = Governorate::firstOrFail();
    expect($governorate->getTranslation('name', 'en'))->toBe('Cairo')
        ->and($governorate->getTranslation('name', 'ar'))->toBe('القاهرة');

    $this->actingAs($manager, 'employee')->post(route('admin.geo.store', 'cities'), [
        'name' => ['ar' => 'مدينة نصر', 'en' => 'Nasr City'],
        'governorate_id' => $governorate->id,
        'is_active' => true,
    ])->assertRedirect();

    $city = City::firstOrFail();

    $this->actingAs($manager, 'employee')->post(route('admin.geo.store', 'districts'), [
        'name' => ['ar' => 'الحي الأول', 'en' => 'First District'],
        'city_id' => $city->id,
        'is_active' => true,
    ])->assertRedirect();

    $district = District::firstOrFail();
    // districts names its active flag `status`, not `is_active` — the
    // form posts one field name and the controller maps it.
    expect($district->status)->toBeTrue();

    $this->actingAs($manager, 'employee')->post(route('admin.geo.store', 'areas'), [
        'name' => ['ar' => 'المنطقة أ', 'en' => 'Zone A'],
        'city_id' => $city->id,
        'district_id' => $district->id,
        'is_active' => true,
    ])->assertRedirect();

    expect(Area::firstOrFail()->district_id)->toBe($district->id);
});

it('refuses an area whose district belongs to a different city', function () {
    $manager = geoEmployee('Delivery Manager');
    $country = geoCountry();
    $governorate = Governorate::create(['country_id' => $country->id, 'name' => ['ar' => 'ق', 'en' => 'Cairo']]);
    $cityA = City::create(['governorate_id' => $governorate->id, 'name' => ['ar' => 'أ', 'en' => 'A']]);
    $cityB = City::create(['governorate_id' => $governorate->id, 'name' => ['ar' => 'ب', 'en' => 'B']]);
    $districtOfB = District::create(['city_id' => $cityB->id, 'name' => ['ar' => 'ح', 'en' => 'D']]);

    // Left unchecked this builds an address the checkout cascade can
    // never rebuild: pick city A and the district simply is not there.
    $this->actingAs($manager, 'employee')->post(route('admin.geo.store', 'areas'), [
        'name' => ['ar' => 'م', 'en' => 'Zone'],
        'city_id' => $cityA->id,
        'district_id' => $districtOfB->id,
        'is_active' => true,
    ])->assertStatus(422);

    expect(Area::count())->toBe(0);
});

it('refuses to delete a place that still has children, and allows it once they are gone', function () {
    $manager = geoEmployee('Delivery Manager');
    $country = geoCountry();
    $governorate = Governorate::create(['country_id' => $country->id, 'name' => ['ar' => 'ق', 'en' => 'Cairo']]);
    $city = City::create(['governorate_id' => $governorate->id, 'name' => ['ar' => 'م', 'en' => 'Nasr']]);

    $this->actingAs($manager, 'employee')
        ->delete(route('admin.geo.destroy', ['governorates', $governorate->id]))
        ->assertSessionHas('error');

    expect(Governorate::whereKey($governorate->id)->exists())->toBeTrue();

    $city->delete();

    $this->actingAs($manager, 'employee')
        ->delete(route('admin.geo.destroy', ['governorates', $governorate->id]))
        ->assertSessionHas('success');

    expect(Governorate::whereKey($governorate->id)->exists())->toBeFalse();
});

it('refuses to delete an area an order was shipped to, with a readable message rather than a SQL error', function () {
    $manager = geoEmployee('Delivery Manager');
    $country = geoCountry();
    $governorate = Governorate::create(['country_id' => $country->id, 'name' => ['ar' => 'ق', 'en' => 'Cairo']]);
    $city = City::create(['governorate_id' => $governorate->id, 'name' => ['ar' => 'م', 'en' => 'Nasr']]);
    $area = Area::create(['city_id' => $city->id, 'name' => ['ar' => 'أ', 'en' => 'Zone A']]);

    $customer = Customer::create([
        'name' => 'Buyer', 'email' => 'geo-buyer@waqar.test',
        'phone' => '01012345678', 'password' => 'password',
    ]);

    Order::create([
        'customer_id' => $customer->id,
        'order_source' => OrderSource::Website,
        'customer_status' => CustomerOrderStatus::OrderReceived,
        'subtotal' => 100, 'shipping_amount' => 0, 'total' => 100,
        'shipping_recipient_name' => 'A', 'shipping_phone' => '01012345678',
        'shipping_governorate_id' => $governorate->id,
        'shipping_city_id' => $city->id,
        'shipping_area_id' => $area->id,
        'shipping_address_line' => 'x',
    ]);

    // The area has no children — the order's shipping snapshot is the
    // only thing pointing at it. There is a database FK here too, so
    // without this check the operator would get a raw QueryException;
    // the point of blockers() is that they get a sentence instead.
    $this->actingAs($manager, 'employee')
        ->delete(route('admin.geo.destroy', ['areas', $area->id]))
        ->assertSessionHas('error');

    expect(Area::whereKey($area->id)->exists())->toBeTrue();
});

it('keeps the screens behind the geo permissions', function () {
    $checker = geoEmployee('Checking'); // holds neither geo.view nor geo.manage
    $country = geoCountry();

    $this->actingAs($checker, 'employee')->get(route('admin.geo.index', 'governorates'))->assertForbidden();

    $this->actingAs($checker, 'employee')->post(route('admin.geo.store', 'governorates'), [
        'name' => ['ar' => 'ق', 'en' => 'Cairo'],
        'country_id' => $country->id,
    ])->assertForbidden();
});

it('404s on a level that is not one of the four', function () {
    $manager = geoEmployee('Delivery Manager');

    // Locale prefix included deliberately: without it the request is
    // redirected to add one, and the route constraint never gets asked.
    $this->actingAs($manager, 'employee')->get('/en/admin/geo/planets')->assertNotFound();
});
