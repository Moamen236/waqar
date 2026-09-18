<?php

use App\Models\Address;
use App\Models\Area;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\City;
use App\Models\Country;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Governorate;
use App\Models\Product;
use App\Models\ProductVariant;
use Database\Seeders\RoleSeeder;
use Spatie\Permission\Models\Role;

// Phase 1 — Core Schema Foundation (WAQAR-DELIVERY-ROADMAP.html).
// "Done when a product with variants can be created and addressed
// against all four base geo levels, with no order/payment/inventory
// tables yet."

it('creates a product with a variant and translatable attributes', function () {
    $product = Product::create([
        'name' => ['ar' => 'قميص أحمر', 'en' => 'Red Shirt'],
        'slug' => 'red-shirt',
        'sku' => 'RS-001',
        'price' => 499.00,
    ]);

    $color = Attribute::create(['name' => ['ar' => 'اللون', 'en' => 'Color']]);
    $red = AttributeValue::create([
        'attribute_id' => $color->id,
        'value' => ['ar' => 'أحمر', 'en' => 'Red'],
        'color_hex' => '#FF0000',
    ]);

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'sku' => 'RS-001-RED-M',
        'size_guide_weight_min' => 70,
        'size_guide_weight_max' => 85,
    ]);
    $variant->attributeValues()->attach($red->id);

    expect($product->getTranslation('name', 'en'))->toBe('Red Shirt')
        ->and($product->getTranslation('name', 'ar'))->toBe('قميص أحمر')
        ->and($product->variants)->toHaveCount(1)
        ->and($variant->attributeValues->first()->getTranslation('value', 'en'))->toBe('Red');
});

it('addresses a customer against all four base geo levels', function () {
    $country = Country::create(['name' => ['ar' => 'مصر', 'en' => 'Egypt'], 'code' => 'EG']);
    $governorate = Governorate::create(['country_id' => $country->id, 'name' => ['ar' => 'القاهرة', 'en' => 'Cairo']]);
    $city = City::create(['governorate_id' => $governorate->id, 'name' => ['ar' => 'مدينة نصر', 'en' => 'Nasr City']]);
    $area = Area::create(['city_id' => $city->id, 'name' => ['ar' => 'الحي السابع', 'en' => 'District 7']]);

    $customer = Customer::create([
        'name' => 'Test Customer',
        'email' => 'customer@waqar.test',
        'phone' => '+201111111111',
        'password' => 'password',
    ]);

    $address = Address::create([
        'customer_id' => $customer->id,
        'recipient_name' => 'Test Customer',
        'phone' => '+201111111111',
        'governorate_id' => $governorate->id,
        'city_id' => $city->id,
        'area_id' => $area->id,
        'address_line' => '12 Test St, Building 4',
    ]);

    expect($address->governorate->getTranslation('name', 'en'))->toBe('Cairo')
        ->and($address->city->getTranslation('name', 'en'))->toBe('Nasr City')
        ->and($address->area->getTranslation('name', 'en'))->toBe('District 7')
        ->and($customer->addresses)->toHaveCount(1);
});

it('seeds the 9 base roles plus Store Orders on the employee guard and assigns one', function () {
    $this->seed(RoleSeeder::class);

    $employee = Employee::create([
        'full_name' => 'Ada Lovelace',
        'email' => 'ada@waqar.test',
        'phone' => '+201222222222',
        'password' => 'password',
        'residence_address' => 'N/A',
        'national_id_number' => 'N/A',
    ]);
    $employee->assignRole('Customer Service Team Leader');

    expect(Role::where('guard_name', 'employee')->count())->toBe(10)
        ->and($employee->hasRole('Customer Service Team Leader'))->toBeTrue();
});

it('scopes a team leader hierarchy via team_leader_id', function () {
    $lead = Employee::create([
        'full_name' => 'Team Lead', 'email' => 'lead@waqar.test', 'phone' => '1',
        'password' => 'password', 'residence_address' => 'N/A', 'national_id_number' => 'N/A',
    ]);
    $agent = Employee::create([
        'full_name' => 'Agent', 'email' => 'agent@waqar.test', 'phone' => '2',
        'password' => 'password', 'residence_address' => 'N/A', 'national_id_number' => 'N/A',
        'team_leader_id' => $lead->id,
    ]);

    expect($agent->teamLeader->is($lead))->toBeTrue()
        ->and($lead->teamMembers)->toHaveCount(1)
        ->and($lead->teamMembers->first()->is($agent))->toBeTrue();
});
