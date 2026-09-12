<?php

namespace App\Http\Controllers\Store;

use App\Enums\ReviewStatus;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Catalog\ProductFilterService;
use App\Support\ProductPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Search — Anvogue's search-result.html. Plain MySQL LIKE against the
 * translatable name/description JSON columns and the SKU, no Laravel
 * Scout (Section 23's explicit decision); ProductFilterService carries
 * the same query so search results filter and sort like the shop page.
 */
class SearchController extends Controller
{
    public function __construct(private readonly ProductFilterService $filters) {}

    public function index(Request $request): Response
    {
        $term = trim((string) $request->input('q', ''));

        $paginator = $this->filters->paginate([
            'q' => $term === '' ? null : $term,
            'sort' => $request->input('sort'),
        ], perPage: 12);

        return Inertia::render('Search/Index', [
            'term' => $term,
            'products' => collect($paginator->items())
                ->map(fn (Product $product) => ProductPresenter::card($product))
                ->values(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
            ],
        ]);
    }

    /**
     * Type-ahead for the header's search modal — JSON rather than an
     * Inertia visit so the modal can preview results without navigating.
     */
    public function suggest(Request $request): JsonResponse
    {
        $term = trim((string) $request->input('q', ''));

        if ($term === '') {
            return response()->json(['products' => []]);
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
        $locale = app()->getLocale();

        $products = Product::query()
            ->where('status', true)
            ->where(fn ($q) => $q->where('sku', 'like', $like)->orWhere("name->{$locale}", 'like', $like))
            ->with(['media', 'categories', 'variants.attributeValues.attribute'])
            ->withCount(['reviews' => fn ($q) => $q->where('status', ReviewStatus::Approved->value)])
            ->withAvg(['reviews as reviews_avg_rating' => fn ($q) => $q->where('status', ReviewStatus::Approved->value)], 'rating')
            ->limit(4)
            ->get()
            ->map(fn (Product $product) => ProductPresenter::card($product));

        return response()->json(['products' => $products]);
    }
}
