<?php

use App\Models\Employee;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Spatie\Permission\Models\Role;

function whEmployee(array $permissions = [], ?string $role = null): Employee
{
    $employee = Employee::create([
        'full_name' => 'Warehouse User',
        'email' => 'wh-'.uniqid().'@waqar.test',
        'phone' => '01012345678',
        'password' => 'password',
        'residence_address' => 'N/A',
        'national_id_number' => '29001010100000',
    ]);

    if ($role !== null) {
        $employee->assignRole(Role::findByName($role, 'employee'));
    }

    $employee->givePermissionTo($permissions);

    return $employee;
}

function whPayload(array $overrides = []): array
{
    return $overrides + ['name' => 'Giza Hub', 'address' => '5 Pyramids Rd', 'phone' => '01000000000', 'manager_employee_id' => null, 'is_active' => true];
}

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
});

it('gives the Warehouse Manager all four warehouse grants', function () {
    expect(Role::findByName('Warehouse Manager', 'employee')->permissions->pluck('name')->all())
        ->toContain('warehouses.view', 'warehouses.create', 'warehouses.update', 'warehouses.delete')
        ->not->toContain('warehouses.manage');
});

it('gates each warehouse action behind its own permission', function () {
    $warehouse = Warehouse::create(whPayload(['name' => 'Main Warehouse']));
    $viewer = whEmployee(['warehouses.view']);

    $this->actingAs($viewer, 'employee')->get(route('admin.warehouses.index'))->assertOk();
    $this->actingAs($viewer, 'employee')->get(route('admin.warehouses.create'))->assertForbidden();
    $this->actingAs($viewer, 'employee')->put(route('admin.warehouses.update', $warehouse), whPayload())->assertForbidden();
    $this->actingAs($viewer, 'employee')->delete(route('admin.warehouses.destroy', $warehouse))->assertForbidden();

    $this->actingAs(whEmployee(['warehouses.create']), 'employee')
        ->post(route('admin.warehouses.store'), whPayload())
        ->assertRedirect(route('admin.warehouses.index'));
    expect(Warehouse::where('name', 'Giza Hub')->exists())->toBeTrue();

    $this->actingAs(whEmployee(['warehouses.update']), 'employee')
        ->put(route('admin.warehouses.update', $warehouse), whPayload(['name' => 'Renamed', 'is_active' => false]))
        ->assertRedirect(route('admin.warehouses.index'));
    expect($warehouse->fresh())->name->toBe('Renamed')->is_active->toBeFalse();
});

it('refuses to delete a warehouse that still holds stock', function () {
    $warehouse = Warehouse::create(whPayload());
    $empty = Warehouse::create(whPayload(['name' => 'Empty']));
    $product = Product::create(['name' => ['ar' => 'منتج', 'en' => 'Widget'], 'slug' => 'w-'.uniqid(), 'sku' => 'W-'.uniqid(), 'price' => 100]);
    $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'W-'.uniqid().'-V']);
    $warehouse->inventory()->create(['product_variant_id' => $variant->id, 'quantity' => 3, 'reserved_quantity' => 0]);
    $deleter = whEmployee(['warehouses.delete']);

    $this->actingAs($deleter, 'employee')->delete(route('admin.warehouses.destroy', $warehouse))->assertSessionHas('error');
    expect($warehouse->fresh())->not->toBeNull();

    $this->actingAs($deleter, 'employee')->delete(route('admin.warehouses.destroy', $empty))->assertRedirect(route('admin.warehouses.index'));
    expect($empty->fresh())->toBeNull();
});
