<?php

use App\Models\Employee;
use App\Models\Product;
use App\Services\Catalog\SkuGenerator;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Product SKUs are assigned, never typed
|--------------------------------------------------------------------------
|
| /admin/products/create shows the SKU field disabled and pre-filled, so
| the value can only come from somewhere the request has no say over —
| SkuGenerator, called again inside store()'s transaction rather than
| trusted from the form. Three things have to hold for that to be safe,
| and each has its own block below: the number continues the catalog's
| existing sequence (it is not a row count), two creates never land on
| the same number however close together they are, and the field is
| assigned on create but editable on update.
|
| AdminDashboardTest.php already pins the three cases that came with the
| feature — the create form's prop, store() ignoring a posted `sku`, and
| a soft-deleted product keeping its number. Those are not repeated here.
|
| psg* prefix: Pest loads every Feature file into one global namespace,
| and these helpers are deliberately self-contained rather than borrowed
| from a sibling file, so this one still runs on its own via --filter.
*/

function psgEmployee(string $role = 'Vice Chairman'): Employee
{
    $employee = Employee::create([
        'full_name' => $role.' User',
        'email' => 'psg-'.uniqid().'@waqar.test',
        'phone' => '1',
        'password' => 'password',
        'residence_address' => 'N/A',
        'national_id_number' => '29001010100000',
    ]);
    $employee->assignRole(Role::findOrCreate($role, 'employee'));

    return $employee;
}

/**
 * A catalog row that exists only to occupy a SKU.
 */
function psgProduct(string $sku, string $name = 'Existing'): Product
{
    return Product::create([
        'name' => ['en' => $name, 'ar' => 'منتج'],
        'slug' => Str::slug($name).'-'.uniqid(),
        'sku' => $sku,
        'price' => 100,
    ]);
}

/**
 * A complete, valid product form submission. `sku` is left out: the
 * create form does not send one, and that is the case under test.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function psgPayload(array $overrides = []): array
{
    return [
        'name' => ['en' => 'Linen Shirt', 'ar' => 'قميص كتان'],
        'price' => '250',
        'status' => true,
        'is_featured' => false,
        'is_new' => false,
        'is_on_sale' => false,
        'sort_order' => 0,
        'product_type' => 'real',
        'variants' => [['sku' => 'V-'.strtoupper(uniqid()), 'status' => true]],
        ...$overrides,
    ];
}

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
});

/*
|--------------------------------------------------------------------------
| The number continues the sequence
|--------------------------------------------------------------------------
*/

it('starts at PRD-001 when the catalog is empty', function () {
    expect(app(SkuGenerator::class)->nextProductSku())->toBe('PRD-001');
});

it('continues from the highest number in the catalog, whichever prefix carries it', function () {
    // The seeded catalog's prefixes are hand-picked mnemonics (MSH = Mesh
    // Shirt), and nothing parses them — the sequence lives in the number,
    // so a generated SKU has to step past the highest number in use
    // rather than past the highest PRD- one.
    psgProduct('MSH-001');
    psgProduct('RAG-007');
    psgProduct('BLU-004');

    expect(app(SkuGenerator::class)->nextProductSku())->toBe('PRD-008');
});

it('does not reissue a number freed in the middle of the sequence', function () {
    // The distinction between "highest + 1" and "count + 1". With
    // PRD-002 gone, a row count says 3 — which is taken, and products.sku
    // is UNIQUE, so the insert would throw rather than quietly collide.
    psgProduct('PRD-001');
    psgProduct('PRD-002')->forceDelete();
    psgProduct('PRD-003');

    expect(app(SkuGenerator::class)->nextProductSku())->toBe('PRD-004');
});

it('ignores a SKU with no trailing number when reading the sequence', function () {
    // Nothing constrains the shape of a hand-entered SKU, and a row that
    // carries no number at the end simply has no place in the sequence —
    // it must not be read as one and must not stop the count.
    psgProduct('LEGACY-SHIRT-BLUE');
    psgProduct('PRD-005');

    expect(app(SkuGenerator::class)->nextProductSku())->toBe('PRD-006');
});

it('keeps the seeded three-digit width and simply grows past 999', function () {
    psgProduct('PRD-008');
    expect(app(SkuGenerator::class)->nextProductSku())->toBe('PRD-009');

    psgProduct('PRD-999');
    expect(app(SkuGenerator::class)->nextProductSku())->toBe('PRD-1000');
});

/*
|--------------------------------------------------------------------------
| Two creates never collide
|--------------------------------------------------------------------------
*/

it('gives each product created in a row its own SKU', function () {
    $actor = psgEmployee();

    foreach (['First Shirt', 'Second Shirt', 'Third Shirt'] as $name) {
        $this->actingAs($actor, 'employee')
            ->post(route('admin.products.store'), psgPayload(['name' => ['en' => $name, 'ar' => 'قميص']]))
            ->assertRedirect();
    }

    expect(Product::query()->orderBy('id')->pluck('sku')->all())
        ->toBe(['PRD-001', 'PRD-002', 'PRD-003']);
});

it('does not hand the same SKU to two operators who opened the create form together', function () {
    // Both forms are rendered before either is saved, so both show the
    // same pre-filled value. What keeps them apart is that store()
    // generates its own inside the transaction instead of taking the
    // one the page was opened with.
    $first = psgEmployee();
    $second = psgEmployee();

    foreach ([$first, $second] as $actor) {
        $this->actingAs($actor, 'employee')
            ->get(route('admin.products.create'))
            ->assertInertia(fn ($page) => $page->where('nextSku', 'PRD-001')->etc());
    }

    $this->actingAs($first, 'employee')
        ->post(route('admin.products.store'), psgPayload(['name' => ['en' => 'Shirt One', 'ar' => 'قميص']]))
        ->assertRedirect();

    $this->actingAs($second, 'employee')
        ->post(route('admin.products.store'), psgPayload(['name' => ['en' => 'Shirt Two', 'ar' => 'قميص']]))
        ->assertRedirect();

    expect(Product::query()->where('name->en', 'Shirt One')->value('sku'))->toBe('PRD-001')
        ->and(Product::query()->where('name->en', 'Shirt Two')->value('sku'))->toBe('PRD-002');
});

it('advances the sequence on the save, not on the read', function () {
    // The generator hands out the next free number; it does not reserve
    // it. That is exactly why store() calls it inside the transaction
    // that inserts, rather than when the form is rendered.
    $skus = app(SkuGenerator::class);

    expect($skus->nextProductSku())->toBe('PRD-001')
        ->and($skus->nextProductSku())->toBe('PRD-001');

    psgProduct('PRD-001');

    expect($skus->nextProductSku())->toBe('PRD-002');
});

/*
|--------------------------------------------------------------------------
| Assigned on create, editable on update
|--------------------------------------------------------------------------
*/

it('ships the next SKU to the create form and nothing to the edit form', function () {
    $actor = psgEmployee();
    $product = psgProduct('PRD-001');

    $this->actingAs($actor, 'employee')
        ->get(route('admin.products.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Products/Form')->where('nextSku', 'PRD-002')->etc());

    // The edit form enables the field and fills it from the record, so a
    // suggested SKU would be meaningless there — and a stale one in the
    // payload is something the page could accidentally submit.
    $this->actingAs($actor, 'employee')
        ->get(route('admin.products.edit', $product))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Products/Form')
            ->where('product.sku', 'PRD-001')
            ->missing('nextSku')
            ->etc());
});

it('keeps the assigned SKU through an edit that posts it back unchanged', function () {
    $actor = psgEmployee();
    $product = psgProduct('PRD-001');

    $this->actingAs($actor, 'employee')
        ->put(route('admin.products.update', $product), psgPayload([
            'sku' => 'PRD-001',
            'name' => ['en' => 'Renamed Shirt', 'ar' => 'قميص'],
        ]))
        ->assertRedirect();

    expect($product->fresh()->sku)->toBe('PRD-001');
});

it('lets an edit correct a SKU, unlike a create', function () {
    // The field is disabled on create because an unsaved product has no
    // SKU worth arguing about; once assigned, a correction is a normal
    // catalog edit, so update() takes the posted value.
    $actor = psgEmployee();
    $product = psgProduct('PRD-001');

    $this->actingAs($actor, 'employee')
        ->put(route('admin.products.update', $product), psgPayload(['sku' => 'MSH-001']))
        ->assertRedirect();

    expect($product->fresh()->sku)->toBe('MSH-001');
});

it('rejects an edit whose SKU is already held by another product', function () {
    $actor = psgEmployee();
    psgProduct('PRD-001', 'Taken');
    $product = psgProduct('PRD-002');

    $this->actingAs($actor, 'employee')
        ->put(route('admin.products.update', $product), psgPayload(['sku' => 'PRD-001']))
        ->assertSessionHasErrors('sku');

    expect($product->fresh()->sku)->toBe('PRD-002');
});

it('rejects an edit that drops the SKU', function () {
    $actor = psgEmployee();
    $product = psgProduct('PRD-001');

    $this->actingAs($actor, 'employee')
        ->put(route('admin.products.update', $product), psgPayload(['sku' => '']))
        ->assertSessionHasErrors('sku');

    expect($product->fresh()->sku)->toBe('PRD-001');
});
