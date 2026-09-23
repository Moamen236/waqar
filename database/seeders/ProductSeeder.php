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
 * The storefront catalogue: five jackets in one "Jackets" category, with
 * real photos from database/seeders/products/product_<n>/. Photos are named
 * product<n>_color<c>_<i> — the colour index <c> maps to the product's
 * `colors` list below, and each photo is tagged with that colour's
 * attribute_value_id so the product page swaps images with the colour.
 */
class ProductSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $warehouse = Warehouse::first();
        $attributes = $this->attributes();
        $category = $this->category();
        $collections = $this->collections();

        foreach ($this->catalogue() as $index => $item) {
            $product = Product::updateOrCreate(
                ['sku' => $item['sku']],
                [
                    'name' => ['en' => $item['name'], 'ar' => $item['ar_name']],
                    'slug' => $item['slug'],
                    'description' => ['en' => $item['description'], 'ar' => $item['ar_description']],
                    'short_description' => ['en' => $item['short'], 'ar' => $item['ar_short']],
                    'price' => $item['price'],
                    'sale_price' => $item['sale_price'],
                    'cost_price' => round($item['price'] * 0.48, 2),
                    'status' => true,
                    'is_featured' => $item['featured'],
                    'is_new' => $item['new'],
                    'is_on_sale' => $item['sale_price'] !== null,
                    'sort_order' => $index + 1,
                    'meta_title' => ['en' => $item['name'], 'ar' => $item['ar_name']],
                    'meta_description' => ['en' => $item['short'], 'ar' => $item['ar_short']],
                    'product_type' => ProductType::Real,
                    'inventory_tracking_enabled' => true,
                ],
            );

            $product->categories()->sync([$category->id]);
            $product->collections()->sync(array_map(fn (string $key) => $collections[$key]->id, $item['collections']));

            $this->seedVariants($product, $item, $attributes, $warehouse);
            $this->attachImages($product, $index + 1, $item['colors'], $attributes);
        }
    }

    /** @return array<string, AttributeValue> */
    private function attributes(): array
    {
        $color = Attribute::firstOrCreate(
            ['name->en' => 'Color'],
            ['name' => ['en' => 'Color', 'ar' => 'اللون'], 'sort_order' => 1],
        );
        $size = Attribute::firstOrCreate(
            ['name->en' => 'Size'],
            ['name' => ['en' => 'Size', 'ar' => 'المقاس'], 'sort_order' => 2],
        );

        $values = [];
        foreach ([
            ['Black', 'أسود', '#1F1F1F'],
            ['Navy', 'كحلي', '#243B64'],
            ['Charcoal', 'فحمي', '#4A4E54'],
            ['Teal', 'أزرق مخضر', '#2F5D62'],
            ['Green', 'أخضر', '#1F5C3A'],
            ['Forest Green', 'أخضر غامق', '#2E4A34'],
            ['Olive', 'زيتي', '#4B5238'],
            ['Khaki', 'كاكي', '#7A7458'],
            ['Royal Blue', 'أزرق ملكي', '#1F4FB5'],
            ['Burgundy', 'نبيتي', '#6B1F2E'],
            ['Taupe', 'بيج رمادي', '#A58F7A'],
            ['Brown', 'بني', '#5A3423'],
        ] as $sortOrder => [$name, $ar, $hex]) {
            $values['color:'.$name] = AttributeValue::firstOrCreate(
                ['attribute_id' => $color->id, 'value->en' => $name],
                ['value' => ['en' => $name, 'ar' => $ar], 'color_hex' => $hex, 'sort_order' => $sortOrder + 1],
            );
        }

        foreach (['S', 'M', 'L', 'XL'] as $sortOrder => $name) {
            $values['size:'.$name] = AttributeValue::firstOrCreate(
                ['attribute_id' => $size->id, 'value->en' => $name],
                ['value' => ['en' => $name, 'ar' => $name], 'sort_order' => $sortOrder + 1],
            );
        }

        return $values;
    }

    private function category(): Category
    {
        return Category::updateOrCreate(
            ['slug' => 'jackets'],
            [
                'name' => ['en' => 'Jackets', 'ar' => 'جاكيتات'],
                'description' => ['en' => 'Coats, bombers and everyday jackets for every season.', 'ar' => 'معاطف وجاكيتات بومبر وجاكيتات يومية لكل المواسم.'],
                'status' => true,
                'sort_order' => 1,
            ],
        );
    }

    /** @return array<string, Collection> */
    private function collections(): array
    {
        $definitions = [
            'new-arrivals' => ['New Arrivals', 'وصل حديثاً', 'Fresh, easy-to-wear pieces just added to the shop.'],
            'everyday-essentials' => ['Everyday Essentials', 'أساسيات يومية', 'Reliable pieces to reach for every day.'],
            'autumn-edit' => ['Autumn Edit', 'تشكيلة الخريف', 'Warm textures and rich colours for the new season.'],
            'occasion-ready' => ['Occasion Ready', 'إطلالات المناسبات', 'Polished silhouettes for plans worth dressing up for.'],
        ];

        $models = [];
        foreach ($definitions as $slug => [$name, $ar, $description]) {
            $models[$slug] = Collection::updateOrCreate(
                ['slug' => $slug],
                ['name' => ['en' => $name, 'ar' => $ar], 'description' => ['en' => $description, 'ar' => $ar], 'is_active' => true, 'sort_order' => count($models) + 1],
            );
        }

        return $models;
    }

    /** @param array<string, AttributeValue> $attributes */
    private function seedVariants(Product $product, array $item, array $attributes, ?Warehouse $warehouse): void
    {
        foreach ($item['colors'] as $colorIndex => $color) {
            foreach ($item['sizes'] as $sizeIndex => $size) {
                $variant = ProductVariant::updateOrCreate(
                    ['sku' => $item['sku'].'-'.strtoupper(str_replace(' ', '-', $color)).'-'.$size],
                    [
                        'product_id' => $product->id,
                        'price' => $item['price'],
                        'sale_price' => $item['sale_price'],
                        'cost_price' => round($item['price'] * 0.48, 2),
                        'size_guide_weight_min' => $this->sizeRange($size)[0],
                        'size_guide_weight_max' => $this->sizeRange($size)[1],
                        'status' => true,
                    ],
                );

                $variant->attributeValues()->sync([$attributes['color:'.$color]->id, $attributes['size:'.$size]->id]);

                if ($warehouse !== null) {
                    WarehouseInventory::updateOrCreate(
                        ['warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id],
                        ['quantity' => 12 + (($colorIndex + $sizeIndex) * 4), 'reserved_quantity' => 0],
                    );
                }
            }
        }
    }

    /** @return array{0: int, 1: int} */
    private function sizeRange(string $size): array
    {
        return match ($size) {
            'S' => [55, 68], 'M' => [66, 80], 'L' => [78, 92], 'XL' => [90, 110],
        };
    }

    /**
     * @param  array<int, string>  $colors
     * @param  array<string, AttributeValue>  $attributes
     */
    private function attachImages(Product $product, int $number, array $colors, array $attributes): void
    {
        if ($product->getMedia('product_images')->isNotEmpty()) {
            return;
        }

        foreach ($colors as $colorIndex => $color) {
            $files = glob(__DIR__."/products/product_{$number}/product{$number}_color".($colorIndex + 1).'_*') ?: [];
            sort($files, SORT_NATURAL);

            foreach ($files as $file) {
                $product->addMedia($file)
                    ->preservingOriginal()
                    ->withCustomProperties(['attribute_value_id' => $attributes['color:'.$color]->id])
                    ->toMediaCollection('product_images');
            }
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function catalogue(): array
    {
        // Order matters: entry <n> uses the photos in products/product_<n>/.
        return [
            $this->item('PRD-001', 'classic-car-coat', 'Classic Car Coat', 'معطف كلاسيكي', 2200, null, false, true, ['everyday-essentials', 'autumn-edit'], ['Navy', 'Charcoal', 'Teal']),
            $this->item('PRD-002', 'retro-track-jacket', 'Retro Track Jacket', 'جاكيت رياضي ريترو', 1250, 999, true, true, ['new-arrivals', 'everyday-essentials'], ['Charcoal', 'Green', 'Royal Blue']),
            $this->item('PRD-003', 'utility-pocket-jacket', 'Utility Pocket Jacket', 'جاكيت يوتيليتي بجيوب', 1650, null, false, true, ['autumn-edit'], ['Burgundy', 'Forest Green']),
            $this->item('PRD-004', 'corduroy-field-jacket', 'Corduroy Field Jacket', 'جاكيت كوردروي ميداني', 1450, 1199, true, true, ['new-arrivals', 'autumn-edit'], ['Olive', 'Taupe', 'Navy', 'Khaki']),
            $this->item('PRD-005', 'leather-bomber-jacket', 'Leather Bomber Jacket', 'جاكيت بومبر جلد', 2400, null, true, true, ['new-arrivals', 'occasion-ready'], ['Brown', 'Black']),
        ];
    }

    /** @return array<string, mixed> */
    private function item(string $sku, string $slug, string $name, string $arName, float $price, ?float $salePrice, bool $new, bool $featured, array $collections, array $colors): array
    {
        return compact('sku', 'slug', 'name', 'price', 'new', 'featured', 'collections', 'colors') + [
            'ar_name' => $arName,
            'sale_price' => $salePrice,
            'sizes' => ['S', 'M', 'L', 'XL'],
            'description' => 'A versatile '.$name.' designed to layer easily over an everyday wardrobe.',
            'ar_description' => $arName.' قطعة عملية لإطلالة يومية سهلة وأنيقة.',
            'short' => 'A considered everyday outerwear staple.',
            'ar_short' => 'جاكيت أساسي مدروس لخزانة يومية أنيقة.',
        ];
    }
}
