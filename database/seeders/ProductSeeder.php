<?php

namespace Database\Seeders;

use App\Enums\ProductType;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\Collection;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * A handful of demo products so the Customer Service order-create form
 * (Phase 4), promotions, and manual verification have something real to
 * search/select — full Product/Category/Attribute admin CRUD isn't a
 * Phase 4 deliverable (not in the roadmap's task table), so these are
 * created directly rather than through an admin screen. Includes one
 * Advertisement product specifically to exercise the stock-check bypass
 * path (Section 05) end to end.
 */
class ProductSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $warehouse = Warehouse::first();

        // Colour/size attributes so the storefront's variant selectors,
        // listing facets and size guide have real data to bind to — the
        // product page can't offer a choice the catalog doesn't define.
        $colorAttribute = Attribute::firstOrCreate(
            ['name->en' => 'Color'],
            ['name' => ['en' => 'Color', 'ar' => 'اللون'], 'sort_order' => 1],
        );
        $sizeAttribute = Attribute::firstOrCreate(
            ['name->en' => 'Size'],
            ['name' => ['en' => 'Size', 'ar' => 'المقاس'], 'sort_order' => 2],
        );

        $colors = [];
        foreach ([['Black', 'أسود', '#1F1F1F'], ['White', 'أبيض', '#F6EFDD']] as $index => [$en, $ar, $hex]) {
            $colors[$en] = AttributeValue::firstOrCreate(
                ['attribute_id' => $colorAttribute->id, 'value->en' => $en],
                ['value' => ['en' => $en, 'ar' => $ar], 'color_hex' => $hex, 'sort_order' => $index],
            );
        }

        $sizes = [];
        foreach ([['S', 45, 60], ['M', 60, 75], ['L', 75, 95]] as $index => [$label, $min, $max]) {
            $sizes[$label] = AttributeValue::firstOrCreate(
                ['attribute_id' => $sizeAttribute->id, 'value->en' => $label],
                ['value' => ['en' => $label, 'ar' => $label], 'sort_order' => $index],
            );
            $sizes[$label]->size_range = [$min, $max];
        }

        $collection = Collection::firstOrCreate(
            ['slug' => 'essentials'],
            [
                'name' => ['en' => 'Essentials', 'ar' => 'الأساسيات'],
                'description' => ['en' => 'Everyday pieces.', 'ar' => 'قطع يومية.'],
                'is_active' => true,
            ],
        );

        $category = Category::firstOrCreate(
            ['slug' => 't-shirts'],
            ['name' => ['en' => 'T-Shirts', 'ar' => 'تي شيرتات'], 'status' => true],
        );

        $classic = Product::firstOrCreate(
            ['sku' => 'TS-CLASSIC'],
            [
                'name' => ['en' => 'Classic T-Shirt', 'ar' => 'تي شيرت كلاسيك'],
                'description' => ['en' => 'A classic cotton t-shirt.', 'ar' => 'تي شيرت قطني كلاسيكي.'],
                'slug' => 'classic-t-shirt',
                'price' => 250.00,
                'status' => true,
                'product_type' => ProductType::Real,
                'inventory_tracking_enabled' => true,
            ],
        );
        $classic->categories()->syncWithoutDetaching([$category->id]);
        $classic->collections()->syncWithoutDetaching([$collection->id]);
        $classic->update(['is_new' => true, 'is_featured' => true]);
        $this->attachImages($classic, ['classic-t-shirt.svg', 'classic-t-shirt-2.svg']);

        foreach (['S', 'M', 'L'] as $size) {
            foreach (['Black', 'White'] as $color) {
                $variant = ProductVariant::firstOrCreate(
                    ['sku' => "TS-CLASSIC-{$color}-{$size}"],
                    [
                        'product_id' => $classic->id,
                        'price' => 250.00,
                        'status' => true,
                        // Section 06's size-guide weight range (display
                        // only — never a shipping weight, Q1).
                        'size_guide_weight_min' => $sizes[$size]->size_range[0],
                        'size_guide_weight_max' => $sizes[$size]->size_range[1],
                    ],
                );

                $variant->attributeValues()->syncWithoutDetaching([
                    $colors[$color]->id,
                    $sizes[$size]->id,
                ]);

                if ($warehouse !== null) {
                    WarehouseInventory::firstOrCreate(
                        ['warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id],
                        ['quantity' => 50, 'reserved_quantity' => 0],
                    );
                }
            }
        }

        $preorder = Product::firstOrCreate(
            ['sku' => 'JK-PREORDER'],
            [
                'name' => ['en' => 'Pre-Order Jacket', 'ar' => 'جاكيت طلب مسبق'],
                'description' => ['en' => 'Advertised ahead of stock arriving.', 'ar' => 'معلن عنه قبل وصول المخزون.'],
                'slug' => 'preorder-jacket',
                'price' => 800.00,
                'status' => true,
                'product_type' => ProductType::Advertisement,
                'inventory_tracking_enabled' => false,
            ],
        );

        $preorder->categories()->syncWithoutDetaching([$category->id]);
        $preorder->update(['is_on_sale' => true, 'sale_price' => 650.00]);
        $this->attachImages($preorder, ['preorder-jacket.svg']);

        ProductVariant::firstOrCreate(
            ['sku' => 'JK-PREORDER-ONE-SIZE'],
            ['product_id' => $preorder->id, 'price' => 800.00, 'sale_price' => 650.00, 'status' => true],
        );
    }

    /**
     * Imagery for the demo catalog, through the same
     * spatie/laravel-medialibrary `product_images` collection
     * /admin/products uploads into — so the storefront's cards, gallery
     * and hover-swap render against real media rather than the hard-coded
     * fallback, and the upload path is exercised end to end by seeding.
     *
     * These are generated placeholders, not photography: every image the
     * Anvogue template ships under assets/images/ is byte-identical (one
     * grey square — the licensed stock was stripped from the download), so
     * there was nothing real to lift. They are labelled as demo images
     * on their face so nobody mistakes them for the business's own.
     * Idempotent: re-seeding doesn't stack copies.
     *
     * @param  array<int, string>  $files
     */
    private function attachImages(Product $product, array $files): void
    {
        if ($product->getMedia('product_images')->isNotEmpty()) {
            return;
        }

        foreach ($files as $file) {
            $path = database_path('seeders/assets/'.$file);

            if (! is_file($path)) {
                continue;
            }

            // copy(), not move — the source files are checked into the
            // repo and a second `migrate:fresh --seed` needs them again.
            $product->addMedia($path)->preservingOriginal()->toMediaCollection('product_images');
        }
    }
}
