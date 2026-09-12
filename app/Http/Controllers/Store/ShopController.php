<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\Collection;
use App\Models\Product;
use App\Services\Catalog\ProductFilterService;
use App\Support\ProductPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Product listing — Anvogue's shop-breadcrumb1.html (Section 17's chosen
 * base for *all* listing pages; every other shop-* variant is unused).
 * Category and collection pages are the same screen with a scope applied,
 * not separate templates.
 */
class ShopController extends Controller
{
    public function __construct(private readonly ProductFilterService $filters) {}

    public function index(Request $request): Response
    {
        return $this->render($request, [], null, null);
    }

    public function category(Request $request, string $slug): Response
    {
        $category = Category::where('slug', $slug)->where('status', true)->firstOrFail();

        return $this->render($request, ['category' => $slug], [
            'type' => 'category',
            'slug' => $slug,
            'name' => $category->getTranslation('name', app()->getLocale()),
            'description' => $category->getTranslation('description', app()->getLocale()),
        ], null);
    }

    public function collection(Request $request, string $slug): Response
    {
        $collection = Collection::where('slug', $slug)->where('is_active', true)->firstOrFail();

        return $this->render($request, ['collection' => $slug], [
            'type' => 'collection',
            'slug' => $slug,
            'name' => $collection->getTranslation('name', app()->getLocale()),
            'description' => $collection->getTranslation('description', app()->getLocale()),
        ], null);
    }

    /**
     * @param  array<string, mixed>  $scope
     * @param  array<string, mixed>|null  $heading
     */
    private function render(Request $request, array $scope, ?array $heading, ?string $term): Response
    {
        $filters = [
            ...$scope,
            'q' => $term,
            'color' => $request->input('color', []),
            'size' => $request->input('size', []),
            'price_min' => $request->input('price_min'),
            'price_max' => $request->input('price_max'),
            'rating' => $request->input('rating'),
            'availability' => $request->input('availability'),
            'sale' => $request->boolean('sale'),
            'sort' => $request->input('sort'),
        ];

        $paginator = $this->filters->paginate($filters);

        return Inertia::render('Shop/Index', [
            'heading' => $heading,
            'products' => collect($paginator->items())
                ->map(fn (Product $product) => ProductPresenter::card($product))
                ->values(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
            ],
            'filters' => $filters,
            'facets' => $this->facets(),
        ]);
    }

    /**
     * Its own method with an explicit return shape rather than a closure
     * nested inside facets() — see ProductPresenter::variant() for why.
     *
     * @return array<int, array{id: int, name: string, hex: string|null}>
     */
    private static function attributeOptions(Attribute $attribute): array
    {
        $locale = app()->getLocale();

        return $attribute->values
            ->map(fn (AttributeValue $value) => [
                'id' => $value->id,
                'name' => $value->getTranslation('value', $locale),
                'hex' => $value->color_hex,
            ])
            ->values()
            ->all();
    }

    /**
     * The sidebar's own option lists. Colour/size come from the real
     * attribute catalog rather than the template's hard-coded swatches;
     * price bounds from the actual catalog range so the range slider
     * can't be dragged past anything that exists.
     *
     * @return array<string, mixed>
     */
    private function facets(): array
    {
        $locale = app()->getLocale();

        $attributes = Attribute::query()
            ->with(['values' => fn ($q) => $q->orderBy('sort_order')])
            ->get()
            ->mapWithKeys(fn (Attribute $attribute) => [
                // Keyed on the English attribute name so the sidebar can
                // ask for 'color'/'size' regardless of the display locale.
                strtolower($attribute->getTranslation('name', 'en')) => self::attributeOptions($attribute),
            ]);

        return [
            'categories' => Category::query()
                ->where('status', true)
                ->withCount(['products' => fn ($q) => $q->where('status', true)])
                ->orderBy('sort_order')
                ->get()
                ->map(fn (Category $category) => [
                    'slug' => (string) $category->slug,
                    'name' => $category->getTranslation('name', $locale),
                    'count' => $category->products_count,
                ])->values(),
            'colors' => $attributes->get('color', []),
            'sizes' => $attributes->get('size', []),
            'price' => [
                'min' => (int) floor((float) (Product::where('status', true)->min('price') ?? 0)),
                'max' => (int) ceil((float) (Product::where('status', true)->max('price') ?? 0)),
            ],
        ];
    }
}
