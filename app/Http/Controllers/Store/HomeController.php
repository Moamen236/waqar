<?php

namespace App\Http\Controllers\Store;

use App\Enums\ReviewStatus;
use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\Product;
use App\Support\ProductPresenter;
use Illuminate\Database\Eloquent\Builder;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Homepage — Anvogue's index.html (Section 17's chosen base; every other
 * homepage variant is unused). The template's static demo data is
 * replaced with the real catalog: the "What's new" tabs are driven by
 * top-level categories, the collection rail by real collections, and the
 * Best Sellers / On Sale / New Arrivals tabs by real product queries.
 * The brand rail is dropped outright — "products do not have brands"
 * (Section 17's resolved conflict), so there is nothing to render in it.
 */
class HomeController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Home', [
            'newArrivals' => $this->products(fn ($query) => $query->where('is_new', true)->latest('id')),
            'bestSellers' => $this->products(fn ($query) => $query->where('is_featured', true)->latest('id')),
            'onSale' => $this->products(fn ($query) => $query->where('is_on_sale', true)->latest('id')),
            'collections' => Collection::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get()
                ->map(fn (Collection $collection) => [
                    'slug' => (string) $collection->slug,
                    'name' => $collection->getTranslation('name', app()->getLocale()),
                    'image' => $collection->image,
                ])
                ->values(),
        ]);
    }

    /**
     * @param  callable(Builder<Product>): mixed  $scope
     * @return array<int, array<string, mixed>>
     */
    private function products(callable $scope, int $limit = 8): array
    {
        $query = Product::query()
            ->where('status', true)
            ->with(['media', 'categories', 'variants.attributeValues.attribute'])
            ->withCount(['reviews' => fn ($q) => $q->where('status', ReviewStatus::Approved->value)])
            ->withAvg(['reviews as reviews_avg_rating' => fn ($q) => $q->where('status', ReviewStatus::Approved->value)], 'rating');

        $scope($query);

        return $query->limit($limit)->get()
            ->map(fn (Product $product) => ProductPresenter::card($product))
            ->all();
    }
}
