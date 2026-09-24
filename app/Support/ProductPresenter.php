<?php

namespace App\Support;

use App\Models\AttributeValue;
use App\Models\Collection;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

/**
 * One place that decides what a storefront product looks like as JSON,
 * so the listing card, the search results, the wishlist and the related
 * rail all render from the same shape (the Anvogue `product-item` markup
 * in Components/ProductCard.tsx).
 *
 * cost_price is never included anywhere here — it's internal-only
 * (Section 05, and the note on Product::casts()).
 */
class ProductPresenter
{
    /**
     * @return array{
     *     id: int, slug: string, sku: string, name: string, short_description: string|null,
     *     price: float, origin_price: float|null, sale_percent: int,
     *     images: array<int, string>, images_by_color: array<string, list<string>>,
     *     colors: array<int, array{id: int, name: string, hex: string|null}>,
     *     sizes: array<int, string>, categories: array<int, string>,
     *     is_new: bool, is_on_sale: bool, rating: float, review_count: int,
     *     in_stock: bool, tracked: bool
     * }
     */
    public static function card(Product $product): array
    {
        $locale = app()->getLocale();
        $price = self::effectivePrice($product);
        $origin = self::originPrice($product);

        return [
            'id' => $product->id,
            'slug' => (string) $product->slug,
            'sku' => (string) $product->sku,
            'name' => $product->getTranslation('name', $locale),
            'short_description' => $product->getTranslation('short_description', $locale) ?: null,
            'price' => $price,
            'origin_price' => $origin > $price ? $origin : null,
            'sale_percent' => $origin > $price ? (int) floor(100 - (($price / $origin) * 100)) : 0,
            'images' => $product->getMedia('product_images')->map(fn ($media) => $media->getUrl())->values()->all(),
            // Lets the card swap photos when a colour swatch is clicked,
            // without leaving the listing.
            'images_by_color' => self::imagesByColour($product),
            'colors' => self::optionValues($product, 'color'),
            'sizes' => array_column(self::optionValues($product, 'size'), 'name'),
            'categories' => $product->categories->map(fn ($category) => $category->getTranslation('name', $locale))->values()->all(),
            'is_new' => (bool) $product->is_new,
            'is_on_sale' => (bool) $product->is_on_sale,
            'rating' => round((float) ($product->reviews_avg_rating ?? 0), 1),
            'review_count' => (int) ($product->reviews_count ?? 0),
            'in_stock' => self::inStock($product),
            'tracked' => (bool) $product->inventory_tracking_enabled,
        ];
    }

    /**
     * The card payload plus everything only the detail page needs — the
     * per-variant SKU/price/stock the size & colour selectors bind to
     * (Section 17: "bind real SKU per variant"), and the size-guide
     * weight range from Section 06.
     *
     * @return array<string, mixed>
     */
    public static function detail(Product $product): array
    {
        $locale = app()->getLocale();

        return [
            ...self::card($product),
            'description' => $product->getTranslation('description', $locale),
            'variants' => $product->variants
                ->where('status', true)
                ->map(fn (ProductVariant $variant) => self::variant($variant, (bool) $product->inventory_tracking_enabled))
                ->values()
                ->all(),
            'collections' => $product->collections
                ->map(fn (Collection $collection) => [
                    'slug' => (string) $collection->slug,
                    'name' => $collection->getTranslation('name', $locale),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Broken out of detail() with its own explicit return shape rather
     * than nested inside its map() closure — Larastan can't infer
     * several levels of nested array-shape closures (the gap PHASE-3/4's
     * handovers documented for GeoTree).
     *
     * @return array{id: int, sku: string, price: float, available: int|null, size_guide_weight_min: float|null, size_guide_weight_max: float|null, options: array<int, array{attribute: string, attribute_label: string, value: string, hex: string|null}>}
     */
    private static function variant(ProductVariant $variant, bool $tracked): array
    {
        return [
            'id' => $variant->id,
            'sku' => (string) $variant->sku,
            'price' => $variant->effectivePrice(),
            'available' => $tracked ? self::variantAvailable($variant) : null,
            'size_guide_weight_min' => $variant->size_guide_weight_min !== null ? (float) $variant->size_guide_weight_min : null,
            'size_guide_weight_max' => $variant->size_guide_weight_max !== null ? (float) $variant->size_guide_weight_max : null,
            'options' => $variant->attributeValues
                ->map(fn (AttributeValue $value) => self::option($value))
                ->values()
                ->all(),
        ];
    }

    /**
     * What an order-taking screen needs to pick a variant the way a
     * customer does: the product, its colours and sizes, and every
     * variant's own SKU, price and availability.
     *
     * Deliberately leaner than detail() — no media, categories,
     * collections or review averages, none of which help an agent on the
     * phone, and all of which would need eager loading per search hit.
     * It reuses the same option/variant shaping the storefront binds to,
     * so "choose a colour and size" means the same thing on both sides.
     *
     * @return array<string, mixed>
     */
    public static function picker(Product $product): array
    {
        $tracked = (bool) $product->inventory_tracking_enabled;

        return [
            'id' => $product->id,
            'name' => $product->getTranslation('name', app()->getLocale()),
            'sku' => (string) $product->sku,
            'price' => self::effectivePrice($product),
            'tracked' => $tracked,
            'colors' => self::optionValues($product, 'color'),
            'sizes' => array_column(self::optionValues($product, 'size'), 'name'),
            'variants' => $product->variants
                ->where('status', true)
                ->map(fn (ProductVariant $variant) => self::variant($variant, $tracked))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array{attribute: string, attribute_label: string, value: string, hex: string|null}
     */
    public static function option(AttributeValue $value): array
    {
        $locale = app()->getLocale();

        return [
            // Matched against the English attribute name so the React side
            // can key on a stable 'color'/'size' regardless of locale.
            'attribute' => strtolower($value->attribute->getTranslation('name', 'en')),
            'attribute_label' => $value->attribute->getTranslation('name', $locale),
            'value' => $value->getTranslation('value', $locale),
            'hex' => $value->color_hex,
        ];
    }

    public static function effectivePrice(Product $product): float
    {
        return (float) ($product->sale_price ?? $product->price);
    }

    public static function originPrice(Product $product): float
    {
        return (float) $product->price;
    }

    public static function inStock(Product $product): bool
    {
        // Advertisement products are never stock-tracked (Section 05) —
        // they're always orderable, and the Backorder status (Q14) is what
        // handles one that turns out to be unfulfillable.
        if (! $product->inventory_tracking_enabled) {
            return true;
        }

        return (int) DB::table('warehouse_inventory')
            ->join('product_variants', 'product_variants.id', '=', 'warehouse_inventory.product_variant_id')
            ->where('product_variants.product_id', $product->id)
            ->sum(DB::raw('warehouse_inventory.quantity - warehouse_inventory.reserved_quantity')) > 0;
    }

    public static function variantAvailable(ProductVariant $variant): int
    {
        return (int) DB::table('warehouse_inventory')
            ->where('product_variant_id', $variant->id)
            ->sum(DB::raw('quantity - reserved_quantity'));
    }

    /**
     * The colours this product actually comes in. Public because the
     * admin product form needs the same list to tag an image with a
     * colour — there is no point offering a colour the product isn't
     * made in, and no point deciding twice what "its colours" means.
     *
     * @return array<int, array{id: int, name: string, hex: string|null}>
     */
    public static function colours(Product $product): array
    {
        return self::optionValues($product, 'color');
    }

    /**
     * Distinct variant option values for one attribute (Color, Size),
     * in the order the attribute's own sort_order defines.
     *
     * @return array<int, array{id: int, name: string, hex: string|null}>
     */
    private static function optionValues(Product $product, string $attribute): array
    {
        $locale = app()->getLocale();
        $seen = [];

        foreach ($product->variants as $variant) {
            foreach ($variant->attributeValues as $value) {
                if (strtolower($value->attribute->getTranslation('name', 'en')) !== $attribute) {
                    continue;
                }

                $name = $value->getTranslation('value', $locale);
                $seen[$name] = ['id' => $value->id, 'name' => $name, 'hex' => $value->color_hex];
            }
        }

        return array_values($seen);
    }

    /**
     * Which images belong to which colour, so picking a swatch swaps the
     * gallery instead of leaving it on the first colour's photos.
     *
     * Keyed by the translated colour name the swatches already render, so
     * the client compares one string and nothing else. What's stored on
     * the media row is the attribute_value_id, though — a name is
     * translated and editable, an id is neither.
     *
     * Untagged images stay out of this map entirely, which is what makes
     * the client's fallback ("no images for this colour? show them all")
     * the behaviour for a product nobody has tagged.
     *
     * @return array<string, list<string>>
     */
    private static function imagesByColour(Product $product): array
    {
        $names = array_column(self::colours($product), 'name', 'id');
        $map = [];

        foreach ($product->getMedia('product_images') as $media) {
            $name = $names[(int) $media->getCustomProperty('attribute_value_id')] ?? null;

            if ($name !== null) {
                $map[$name][] = $media->getUrl();
            }
        }

        return $map;
    }

    /**
     * The photo to show for one variant (a cart/checkout line): the first
     * image tagged with the variant's own colour, else the product's first
     * image — the same fallback the gallery uses for an untagged colour.
     */
    public static function variantImage(ProductVariant $variant): ?string
    {
        $colourId = $variant->attributeValues
            ->first(fn (AttributeValue $value) => strtolower($value->attribute->getTranslation('name', 'en')) === 'color')
            ?->id;

        $media = $variant->product->getMedia('product_images');
        $tagged = $colourId === null
            ? null
            : $media->first(fn ($item) => (int) $item->getCustomProperty('attribute_value_id') === $colourId);

        return ($tagged ?? $media->first())?->getUrl();
    }
}
