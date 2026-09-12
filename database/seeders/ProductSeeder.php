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
 * A storefront-ready fashion catalogue derived from Frontend template's
 * Product.json. Its source images are blank placeholders, so this seeder
 * creates labelled SVG catalogue art in the real Media Library collection.
 */
class ProductSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $warehouse = Warehouse::first();
        $attributes = $this->attributes();
        $categories = $this->categories();
        $collections = $this->collections();

        foreach ($this->catalogue() as $sortOrder => $item) {
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
                    'sort_order' => $sortOrder + 1,
                    'meta_title' => ['en' => $item['name'], 'ar' => $item['ar_name']],
                    'meta_description' => ['en' => $item['short'], 'ar' => $item['ar_short']],
                    'product_type' => $item['type'],
                    'inventory_tracking_enabled' => $item['type'] === ProductType::Real,
                ],
            );

            $product->categories()->sync($this->ids($categories, $item['categories']));
            $product->collections()->sync($this->ids($collections, $item['collections']));

            $this->seedVariants($product, $item, $attributes, $warehouse);
            $this->attachPlaceholderImages($product, $item);
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
            ['Black', 'أسود', '#1F1F1F'], ['White', 'أبيض', '#F6EFDD'], ['Red', 'أحمر', '#DB4444'],
            ['Yellow', 'أصفر', '#ECB018'], ['Purple', 'بنفسجي', '#8684D4'], ['Pink', 'وردي', '#F4407D'],
            ['Green', 'أخضر', '#5B9A6A'], ['Blue', 'أزرق', '#5277B8'], ['Grey', 'رمادي', '#9AA0A6'],
            ['Camel', 'جملي', '#B5794A'], ['Navy', 'كحلي', '#243B64'], ['Olive', 'زيتي', '#737A42'],
        ] as $sortOrder => [$name, $ar, $hex]) {
            $values['color:'.$name] = AttributeValue::firstOrCreate(
                ['attribute_id' => $color->id, 'value->en' => $name],
                ['value' => ['en' => $name, 'ar' => $ar], 'color_hex' => $hex, 'sort_order' => $sortOrder + 1],
            );
        }

        foreach (['XS', 'S', 'M', 'L', 'XL', 'One Size'] as $sortOrder => $name) {
            $values['size:'.$name] = AttributeValue::firstOrCreate(
                ['attribute_id' => $size->id, 'value->en' => $name],
                ['value' => ['en' => $name, 'ar' => $name === 'One Size' ? 'مقاس واحد' : $name], 'sort_order' => $sortOrder + 1],
            );
        }

        return $values;
    }

    /** @return array<string, Category> */
    private function categories(): array
    {
        $definitions = [
            'tops' => ['Tops', 'توبات', 'Shirts, blouses and easy everyday layers.'],
            't-shirts' => ['T-Shirts', 'تي شيرتات', 'Comfortable wardrobe staples for every day.'],
            'dresses' => ['Dresses', 'فساتين', 'Effortless dresses for daytime and evenings out.'],
            'outerwear' => ['Outerwear', 'ملابس خارجية', 'Jackets and polished layers for cooler days.'],
            'bottoms' => ['Bottoms', 'بناطيل', 'Tailored trousers, denim and relaxed bottoms.'],
        ];

        $models = [];
        foreach ($definitions as $slug => [$name, $ar, $description]) {
            $models[$slug] = Category::updateOrCreate(
                ['slug' => $slug],
                ['name' => ['en' => $name, 'ar' => $ar], 'description' => ['en' => $description, 'ar' => $ar], 'status' => true, 'sort_order' => count($models) + 1],
            );
        }

        return $models;
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
                    ['sku' => $item['sku'].'-'.strtoupper(str_replace(' ', '-', $color)).'-'.strtoupper(str_replace(' ', '-', $size))],
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

                if ($warehouse !== null && $item['type'] === ProductType::Real) {
                    WarehouseInventory::updateOrCreate(
                        ['warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id],
                        ['quantity' => 12 + (($colorIndex + $sizeIndex) * 4), 'reserved_quantity' => 0],
                    );
                }
            }
        }
    }

    /** @return array{0: int|null, 1: int|null} */
    private function sizeRange(string $size): array
    {
        return match ($size) {
            'XS' => [40, 52], 'S' => [50, 62], 'M' => [60, 75], 'L' => [73, 88], 'XL' => [85, 105],
            default => [null, null],
        };
    }

    private function attachPlaceholderImages(Product $product, array $item): void
    {
        if ($product->getMedia('product_images')->isNotEmpty()) {
            return;
        }

        foreach ([$item['art'], $item['art_alt']] as $index => $color) {
            $product->addMediaFromString($this->productArt($item['name'], $item['short'], $color, $index === 1))
                ->usingFileName($product->slug.'-'.($index + 1).'.svg')
                ->toMediaCollection('product_images');
        }
    }

    private function productArt(string $name, string $short, string $color, bool $alternate): string
    {
        $safeName = htmlspecialchars($name, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $safeShort = htmlspecialchars($short, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $accent = $alternate ? '#F6EFDD' : '#FFFFFF';
        $shape = $alternate
            ? '<path d="M290 214l92-56 92 56 36 182H254l36-182z" fill="'.$accent.'" opacity=".92"/>'
            : '<path d="M275 195l42-52h98l42 52 56 41-33 191H252l-33-191 56-40z" fill="'.$accent.'" opacity=".92"/>';

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 768 1024" role="img" aria-label="'.$safeName.' demo image">'
            .'<rect width="768" height="1024" fill="'.$color.'"/><circle cx="622" cy="148" r="160" fill="#fff" opacity=".11"/>'
            .'<circle cx="90" cy="875" r="210" fill="#000" opacity=".06"/>'.$shape
            .'<text x="64" y="820" fill="#fff" font-family="Arial, sans-serif" font-size="35" font-weight="700">'.strtoupper($safeName).'</text>'
            .'<text x="64" y="868" fill="#fff" font-family="Arial, sans-serif" font-size="23" opacity=".84">'.$safeShort.'</text>'
            .'<text x="64" y="946" fill="#fff" font-family="Arial, sans-serif" font-size="18" letter-spacing="4" opacity=".72">WAQAR / DEMO EDIT</text></svg>';
    }

    /** @param array<string, Category|Collection> $models @param array<int, string> $keys @return array<int, int> */
    private function ids(array $models, array $keys): array
    {
        return array_map(fn (string $key) => $models[$key]->id, $keys);
    }

    /** @return array<int, array<string, mixed>> */
    private function catalogue(): array
    {
        return [
            $this->item('MSH-001', 'mesh-shirt', 'Mesh Shirt', 'قميص شبكي', 112, null, true, true, ['tops'], ['new-arrivals', 'occasion-ready'], ['Red', 'Yellow'], ['S', 'M', 'L', 'XL'], '#DB4444', '#ECB018'),
            $this->item('RAG-002', 'raglan-sleeve-t-shirt', 'Raglan Sleeve T-Shirt', 'تي شيرت بأكمام راغلان', 98, null, true, true, ['t-shirts'], ['new-arrivals', 'everyday-essentials'], ['White', 'Purple'], ['XS', 'S', 'M', 'L', 'XL'], '#F6EFDD', '#8684D4'),
            $this->item('BLU-003', 'off-the-shoulder-blouse', 'Off-the-Shoulder Blouse', 'بلوزة بأكتاف مكشوفة', 160, 128, false, true, ['tops'], ['occasion-ready'], ['Pink', 'Yellow', 'Purple'], ['One Size'], '#F4407D', '#ECB018'),
            $this->item('KIM-004', 'kimono-sleeve-top', 'Kimono Sleeve Top', 'توب بأكمام كيمونو', 132, 99, false, true, ['tops'], ['autumn-edit'], ['Red', 'White', 'Purple'], ['M', 'L', 'XL'], '#DB4444', '#F6EFDD'),
            $this->item('TSP-005', 't-shirt-pockets', 'T-Shirt Pockets', 'تي شيرت بجيوب', 120, 102, false, true, ['t-shirts'], ['everyday-essentials'], ['Green', 'Red', 'Yellow'], ['S', 'L', 'XL'], '#5B9A6A', '#DB4444'),
            $this->item('FLT-006', 'faux-leather-trousers', 'Faux-Leather Trousers', 'بنطال جلد صناعي', 250, 200, false, true, ['bottoms'], ['autumn-edit', 'occasion-ready'], ['Black', 'Camel'], ['S', 'M', 'L'], '#1F1F1F', '#B5794A'),
            $this->item('PMS-007', 'pleated-midi-skirt', 'Pleated Midi Skirt', 'تنورة ميدي بكسرات', 210, null, true, false, ['bottoms'], ['new-arrivals', 'occasion-ready'], ['Navy', 'Pink'], ['S', 'M', 'L'], '#243B64', '#F4407D'),
            $this->item('SSD-008', 'satin-slip-dress', 'Satin Slip Dress', 'فستان ساتان انسيابي', 340, 272, false, true, ['dresses'], ['occasion-ready'], ['Olive', 'Black'], ['S', 'M', 'L'], '#737A42', '#1F1F1F'),
            $this->item('MDS-009', 'floral-midi-dress', 'Floral Midi Dress', 'فستان ميدي مزهر', 310, null, true, true, ['dresses'], ['new-arrivals', 'occasion-ready'], ['Yellow', 'Blue'], ['S', 'M', 'L', 'XL'], '#ECB018', '#5277B8'),
            $this->item('OSJ-010', 'oversized-denim-jacket', 'Oversized Denim Jacket', 'جاكيت جينز واسع', 420, null, true, true, ['outerwear'], ['new-arrivals', 'autumn-edit'], ['Blue', 'Grey'], ['S', 'M', 'L'], '#5277B8', '#9AA0A6'),
            $this->item('RKC-011', 'ribbed-knit-cardigan', 'Ribbed Knit Cardigan', 'كارديجان محبوك مضلع', 290, 232, false, true, ['outerwear'], ['autumn-edit', 'everyday-essentials'], ['Camel', 'White'], ['S', 'M', 'L'], '#B5794A', '#F6EFDD'),
            $this->item('WLT-012', 'wide-leg-trousers', 'Tailored Wide-Leg Trousers', 'بنطال واسع مفصل', 280, null, false, true, ['bottoms'], ['autumn-edit', 'occasion-ready'], ['Black', 'Navy'], ['S', 'M', 'L', 'XL'], '#1F1F1F', '#243B64'),
            $this->item('CTS-013', 'classic-t-shirt', 'Classic T-Shirt', 'تي شيرت كلاسيكي', 250, null, true, true, ['t-shirts'], ['new-arrivals', 'everyday-essentials'], ['Black', 'White'], ['S', 'M', 'L'], '#1F1F1F', '#F6EFDD'),
            $this->item('PRJ-014', 'preorder-jacket', 'Pre-Order Jacket', 'جاكيت طلب مسبق', 800, 650, false, false, ['outerwear'], ['autumn-edit'], ['Black', 'Olive'], ['S', 'M', 'L'], '#1F1F1F', '#737A42', ProductType::Advertisement),
        ];
    }

    /** @return array<string, mixed> */
    private function item(string $sku, string $slug, string $name, string $arName, float $price, ?float $salePrice, bool $new, bool $featured, array $categories, array $collections, array $colors, array $sizes, string $art, string $artAlt, ProductType $type = ProductType::Real): array
    {
        return compact('sku', 'slug', 'name', 'price', 'new', 'featured', 'categories', 'collections', 'colors', 'sizes', 'art', 'type') + [
            'ar_name' => $arName,
            'sale_price' => $salePrice,
            'description' => 'A versatile '.$name.' designed for an effortless everyday wardrobe.',
            'ar_description' => $arName.' قطعة عملية لإطلالة يومية سهلة وأنيقة.',
            'short' => 'A considered everyday fashion staple.',
            'ar_short' => 'قطعة أساسية مدروسة لخزانة يومية أنيقة.',
            'art_alt' => $artAlt,
        ];
    }
}
