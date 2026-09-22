<?php

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Employee;
use App\Models\Product;
use App\Models\ProductVariant;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Permission\Models\Role;

// Phase G — picking a colour swaps the product gallery
// (feature-backlog-plan.md, request #4).
//
// Product images are product-level: `Product implements HasMedia`,
// `ProductVariant` does not. Rather than give variants their own media,
// each image carries the attribute_value_id of the colour it shows in
// MediaLibrary's existing custom_properties JSON — no migration, no
// column, and an untagged catalogue behaves exactly as it did.
//
// pic* prefix: Pest loads every Feature file into one global namespace.

function picEmployee(string $role): Employee
{
    $employee = Employee::create([
        'full_name' => $role.' User', 'email' => 'pic-'.uniqid().'@waqar.test', 'phone' => '01012345678',
        'password' => 'password', 'residence_address' => 'N/A', 'national_id_number' => 'N/A',
    ]);
    $employee->assignRole(Role::findOrCreate($role, 'employee'));

    return $employee;
}

/**
 * A product made in two colours, each with its own variant.
 *
 * @return array{0: Product, 1: AttributeValue, 2: AttributeValue}
 */
function picProduct(): array
{
    $product = Product::create([
        'name' => ['ar' => 'قميص', 'en' => 'Oxford Shirt'],
        'slug' => 'oxford-'.uniqid(), 'sku' => 'OX-'.strtoupper(uniqid()),
        'price' => 250, 'status' => true,
    ]);

    $colour = Attribute::create(['name' => ['ar' => 'اللون', 'en' => 'Color'], 'sort_order' => 1]);
    $red = AttributeValue::create([
        'attribute_id' => $colour->id, 'value' => ['ar' => 'أحمر', 'en' => 'Red'], 'color_hex' => '#ff0000',
    ]);
    $blue = AttributeValue::create([
        'attribute_id' => $colour->id, 'value' => ['ar' => 'أزرق', 'en' => 'Blue'], 'color_hex' => '#0000ff',
    ]);

    foreach ([$red, $blue] as $value) {
        $variant = ProductVariant::create([
            'product_id' => $product->id, 'sku' => 'OX-'.strtoupper(uniqid()).'-V', 'status' => true,
        ]);
        $variant->attributeValues()->attach($value->id);
    }

    return [$product->fresh(), $red, $blue];
}

function picImage(Product $product, ?int $attributeValueId = null): Media
{
    $media = $product->addMedia(UploadedFile::fake()->image(uniqid().'.jpg'))
        ->toMediaCollection('product_images');

    if ($attributeValueId !== null) {
        $media->setCustomProperty('attribute_value_id', $attributeValueId);
        $media->save();
    }

    return $media;
}

beforeEach(function () {
    Storage::fake('public');
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
});

it('groups a product\'s images by the colour each one was tagged with', function () {
    [$product, $red, $blue] = picProduct();
    picImage($product, $red->id);
    picImage($product, $blue->id);

    $this->withLocale('en')->get(route('product.show', ['slug' => $product->slug, 'locale' => 'en']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Product/Show')
            // Keyed by the name the swatches render, so the client
            // compares one string and nothing else.
            ->count('product.images_by_color.Red', 1)
            ->count('product.images_by_color.Blue', 1)
            ->etc());
});

it('leaves an untagged image out of the map so every colour still shows it', function () {
    [$product, $red] = picProduct();
    picImage($product, $red->id);
    picImage($product); // No colour — a flat-lay, a size chart, a detail shot.

    $this->withLocale('en')->get(route('product.show', ['slug' => $product->slug, 'locale' => 'en']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // Two images on the product, only one of them spoken for.
            ->count('product.images', 2)
            ->count('product.images_by_color.Red', 1)
            ->count('product.images_by_color', 1)
            ->etc());
});

it('sends an empty map for a catalogue nobody has tagged, which is the fallback', function () {
    [$product] = picProduct();
    picImage($product);
    picImage($product);

    $this->withLocale('en')->get(route('product.show', ['slug' => $product->slug, 'locale' => 'en']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->count('product.images', 2)
            ->count('product.images_by_color', 0)
            ->etc());
});

it('keys the map on the translated colour name, matching the swatch beside it', function () {
    [$product, $red] = picProduct();
    picImage($product, $red->id);

    // The tag on the media row is an id, so the same image reaches an
    // Arabic reader under the Arabic name without being re-tagged.
    $this->withLocale('ar')->get(route('product.show', ['slug' => $product->slug, 'locale' => 'ar']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->count('product.images_by_color.أحمر', 1)
            ->where('product.colors.0.name', 'أحمر')
            ->etc());
});

it('tags an image with a colour from the admin product form', function () {
    [$product, $red] = picProduct();
    $media = picImage($product);

    $this->actingAs(picEmployee('Vice Chairman'), 'employee')
        ->patch(route('admin.products.images.update', [$product->id, $media->id]), [
            'attribute_value_id' => $red->id,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($media->fresh()->getCustomProperty('attribute_value_id'))->toBe($red->id);
});

it('forgets the tag rather than storing a null when an image is set back to all colours', function () {
    [$product, $red] = picProduct();
    $media = picImage($product, $red->id);

    $this->actingAs(picEmployee('Vice Chairman'), 'employee')
        ->patch(route('admin.products.images.update', [$product->id, $media->id]), [
            'attribute_value_id' => null,
        ])
        ->assertRedirect();

    // An image tagged then cleared must read the same as one never
    // tagged — otherwise the grouping has a null key to defend against.
    expect($media->fresh()->custom_properties)->not->toHaveKey('attribute_value_id');
});

it('refuses to tag an image that belongs to another product', function () {
    [$product, $red] = picProduct();
    [$other] = picProduct();
    $media = picImage($other);

    $this->actingAs(picEmployee('Vice Chairman'), 'employee')
        ->patch(route('admin.products.images.update', [$product->id, $media->id]), [
            'attribute_value_id' => $red->id,
        ])
        ->assertNotFound();

    expect($media->fresh()->getCustomProperty('attribute_value_id'))->toBeNull();
});

it('keeps image tagging behind products.update', function () {
    [$product, $red] = picProduct();
    $media = picImage($product);

    $this->actingAs(picEmployee('Checking'), 'employee')
        ->patch(route('admin.products.images.update', [$product->id, $media->id]), [
            'attribute_value_id' => $red->id,
        ])
        ->assertForbidden();
});

it('saves a colour tag per new photo when a product is created', function () {
    $colour = Attribute::create(['name' => ['ar' => 'اللون', 'en' => 'Color'], 'sort_order' => 1]);
    $red = AttributeValue::create([
        'attribute_id' => $colour->id, 'value' => ['ar' => 'أحمر', 'en' => 'Red'], 'color_hex' => '#ff0000',
    ]);
    $blue = AttributeValue::create([
        'attribute_id' => $colour->id, 'value' => ['ar' => 'أزرق', 'en' => 'Blue'], 'color_hex' => '#0000ff',
    ]);

    $this->actingAs(picEmployee('Vice Chairman'), 'employee')->post(route('admin.products.store'), [
        'name' => ['en' => 'Tagged Shirt'],
        'price' => 250,
        'status' => true, 'is_featured' => false, 'is_new' => false, 'is_on_sale' => false, 'sort_order' => 0,
        'product_type' => 'real',
        'variants' => [
            ['id' => null, 'sku' => null, 'barcode' => null, 'price' => null, 'sale_price' => null, 'cost_price' => null, 'status' => true, 'attribute_value_ids' => [$red->id]],
            ['id' => null, 'sku' => null, 'barcode' => null, 'price' => null, 'sale_price' => null, 'cost_price' => null, 'status' => true, 'attribute_value_ids' => [$blue->id]],
        ],
        'images' => [
            UploadedFile::fake()->image('red.jpg'),
            UploadedFile::fake()->image('flatlay.jpg'),
        ],
        // Parallel to `images` by index — null means every colour.
        'image_attribute_value_ids' => [$red->id, null],
    ])->assertRedirect();

    $product = Product::where('slug', 'tagged-shirt')->firstOrFail();
    $media = $product->getMedia('product_images');

    expect($media)->toHaveCount(2)
        ->and($media[0]->getCustomProperty('attribute_value_id'))->toBe($red->id)
        ->and($media[1]->getCustomProperty('attribute_value_id'))->toBeNull();
});

it('saves a colour tag for a photo added while editing', function () {
    [$product, $red] = picProduct();

    $this->actingAs(picEmployee('Vice Chairman'), 'employee')->put(route('admin.products.update', $product), [
        'name' => ['en' => 'Oxford Shirt'],
        'sku' => $product->sku,
        'price' => 250,
        'status' => true, 'is_featured' => false, 'is_new' => false, 'is_on_sale' => false, 'sort_order' => 0,
        'product_type' => 'real',
        'variants' => $product->variants->map(fn ($variant) => [
            'id' => $variant->id, 'sku' => $variant->sku, 'barcode' => null,
            'price' => null, 'sale_price' => null, 'cost_price' => null, 'status' => true,
            'attribute_value_ids' => $variant->attributeValues->pluck('id')->all(),
        ])->all(),
        'images' => [UploadedFile::fake()->image('red.jpg')],
        'image_attribute_value_ids' => [$red->id],
    ])->assertRedirect();

    expect($product->fresh()->getMedia('product_images'))->toHaveCount(1)
        ->and($product->fresh()->getMedia('product_images')[0]->getCustomProperty('attribute_value_id'))->toBe($red->id);
});

it('tells the create form which attributes are colours so new photos can be tagged', function () {
    $colour = Attribute::create(['name' => ['ar' => 'اللون', 'en' => 'Color'], 'sort_order' => 1]);
    $size = Attribute::create(['name' => ['ar' => 'المقاس', 'en' => 'Size'], 'sort_order' => 2]);

    $this->actingAs(picEmployee('Vice Chairman'), 'employee')->withLocale('en')
        ->get(route('admin.products.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('colorAttributeIds', [$colour->id])
            ->etc());

    expect($size->id)->not->toBe($colour->id);
});

it('offers the product form only the colours the product is actually made in', function () {
    [$product, $red, $blue] = picProduct();
    // A colour on no variant of this product — never worth tagging with.
    $green = AttributeValue::create([
        'attribute_id' => $red->attribute_id, 'value' => ['ar' => 'أخضر', 'en' => 'Green'], 'color_hex' => '#00ff00',
    ]);
    picImage($product, $red->id);

    $this->actingAs(picEmployee('Vice Chairman'), 'employee')->withLocale('en')
        ->get(route('admin.products.edit', $product))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->count('product.colors', 2)
            ->where('product.colors.0.id', $red->id)
            ->where('product.colors.1.id', $blue->id)
            ->where('product.images.0.attribute_value_id', $red->id)
            ->etc());

    expect($green->id)->not->toBeIn([$red->id, $blue->id]);
});
