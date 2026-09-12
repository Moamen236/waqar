<?php

namespace App\Services\Catalog;

use App\Enums\ReviewStatus;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The listing query behind /shop, /category/{slug}, /collection/{slug}
 * and /search — one builder so every listing page filters and sorts
 * identically (Section 17: shop-breadcrumb1.html is the single base for
 * all of them).
 *
 * Two filters here exist in the spec but not in the template and were
 * added for this build (Section 20 #15): `rating` and `availability`.
 * Two that exist in the template but not the business rules — brand, and
 * the stray height/weight filters left over from other niche Anvogue
 * demos — are deliberately absent.
 */
class ProductFilterService
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters, int $perPage = 9): LengthAwarePaginator
    {
        $query = Product::query()
            ->where('status', true)
            ->with(['media', 'categories', 'variants.attributeValues.attribute'])
            ->withCount(['reviews' => fn ($q) => $q->where('status', ReviewStatus::Approved->value)])
            ->withAvg(['reviews as reviews_avg_rating' => fn ($q) => $q->where('status', ReviewStatus::Approved->value)], 'rating');

        $this->applyScope($query, $filters);
        $this->applyFacets($query, $filters);
        $this->applySort($query, $filters['sort'] ?? null);

        return $query->paginate($perPage)->withQueryString();
    }

    /**
     * @param  Builder<Product>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyScope(Builder $query, array $filters): void
    {
        if (! empty($filters['category'])) {
            $query->whereHas('categories', fn ($q) => $q->where('categories.slug', $filters['category']));
        }

        if (! empty($filters['collection'])) {
            $query->whereHas('collections', fn ($q) => $q->where('collections.slug', $filters['collection']));
        }

        if (! empty($filters['q'])) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], (string) $filters['q']).'%';
            $locale = app()->getLocale();

            $query->where(function (Builder $q) use ($like, $locale) {
                $q->where('sku', 'like', $like)
                    ->orWhere("name->{$locale}", 'like', $like)
                    ->orWhere("description->{$locale}", 'like', $like);
            });
        }
    }

    /**
     * @param  Builder<Product>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFacets(Builder $query, array $filters): void
    {
        foreach (['color' => 'color', 'size' => 'size'] as $key => $attribute) {
            $values = array_filter((array) ($filters[$key] ?? []));
            if ($values === []) {
                continue;
            }

            // Every selected value of one attribute is an OR within that
            // attribute, but the attributes AND with each other — picking
            // "Red" + "M" means a variant that is both, not either.
            $query->whereHas('variants.attributeValues', function ($q) use ($values, $attribute) {
                $q->whereIn('attribute_values.id', $values)
                    ->whereHas('attribute', fn ($a) => $a->whereRaw('LOWER(JSON_UNQUOTE(JSON_EXTRACT(name, "$.en"))) = ?', [$attribute]));
            });
        }

        if (isset($filters['price_min']) && $filters['price_min'] !== '') {
            $query->whereRaw('COALESCE(sale_price, price) >= ?', [(float) $filters['price_min']]);
        }

        if (isset($filters['price_max']) && $filters['price_max'] !== '') {
            $query->whereRaw('COALESCE(sale_price, price) <= ?', [(float) $filters['price_max']]);
        }

        if (! empty($filters['sale'])) {
            $query->where('is_on_sale', true);
        }

        // Section 20 #15 — absent from the template, required by the spec.
        if (! empty($filters['rating'])) {
            $query->having('reviews_avg_rating', '>=', (float) $filters['rating']);
        }

        if (! empty($filters['availability'])) {
            $inStock = $filters['availability'] === 'in_stock';

            $query->where(function (Builder $q) use ($inStock) {
                // An Advertisement product has no stock to be in or out of
                // — it always counts as available (Section 05).
                if ($inStock) {
                    $q->where('inventory_tracking_enabled', false)
                        ->orWhereIn('id', $this->productIdsWithStock());

                    return;
                }

                $q->where('inventory_tracking_enabled', true)
                    ->whereNotIn('id', $this->productIdsWithStock());
            });
        }
    }

    /**
     * @param  Builder<Product>  $query
     */
    private function applySort(Builder $query, ?string $sort): void
    {
        match ($sort) {
            'priceLowToHigh' => $query->orderByRaw('COALESCE(sale_price, price) asc'),
            'priceHighToLow' => $query->orderByRaw('COALESCE(sale_price, price) desc'),
            'discountHighToLow' => $query->orderByRaw('((price - COALESCE(sale_price, price)) / price) desc'),
            'soldQuantityHighToLow' => $query->orderByDesc($this->soldQuantitySubquery()),
            default => $query->latest('id'),
        };
    }

    /**
     * Units actually sold, summed across a product's variants — the
     * "Best Selling" sort the template's own sort dropdown already
     * offers. Counts every order line regardless of status, matching
     * what a merchandising sort is for; it is not an accounting figure.
     */
    private function soldQuantitySubquery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('order_items')
            ->selectRaw('COALESCE(SUM(order_items.quantity), 0)')
            ->join('product_variants', 'product_variants.id', '=', 'order_items.product_variant_id')
            ->whereColumn('product_variants.product_id', 'products.id');
    }

    private function productIdsWithStock(): \Illuminate\Database\Query\Builder
    {
        return DB::table('warehouse_inventory')
            ->join('product_variants', 'product_variants.id', '=', 'warehouse_inventory.product_variant_id')
            ->select('product_variants.product_id')
            ->groupBy('product_variants.product_id')
            ->havingRaw('SUM(warehouse_inventory.quantity - warehouse_inventory.reserved_quantity) > 0');
    }
}
