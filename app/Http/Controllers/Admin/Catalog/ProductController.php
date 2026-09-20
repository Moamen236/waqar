<?php

namespace App\Http\Controllers\Admin\Catalog;

use App\Enums\OrderStatus;
use App\Enums\ProductType;
use App\Exports\ProductsExport;
use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\Collection as CollectionModel;
use App\Models\InventoryMovement;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Review;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use App\Services\Catalog\SkuGenerator;
use App\Services\Content\RichTextSanitizer;
use App\Support\ImageUpload;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * /admin/products (Vice Chairman + Chairman, Section 15) — not in the
 * roadmap's original Phase 4 task table (added after the fact, see
 * PHASE-4-HANDOVER.md's flagged gap). Covers the full product record:
 * translatable copy, variants with their differentiating attributes
 * (Color/Size), category/collection assignment, and the
 * Advertisement↔Real conversion (Section 05).
 */
class ProductController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly RichTextSanitizer $sanitizer,
        private readonly SkuGenerator $skus,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('permission:products.view', only: ['index', 'show']),
            new Middleware('permission:products.export', only: ['export']),
            new Middleware('permission:products.create', only: ['create', 'store']),
            new Middleware('permission:products.update', only: ['edit', 'update', 'destroyImage']),
            new Middleware('permission:products.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request): Response
    {
        $products = Product::query()
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = "%{$request->query('q')}%";
                $query->where('sku', 'like', $term)->orWhere('name->en', 'like', $term);
            })
            ->with(['categories:id,name', 'media'])
            ->withCount('variants')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        // Larkon's product-list.html leads each row with the product's own
        // thumbnail. Adding it is presentation only: the query, its filter,
        // its ordering and its pagination are untouched — `media` is eager
        // loaded so the per-row URL lookup is not N+1, and the URL is
        // appended to each row rather than replacing anything already
        // serialised, so every existing prop the page reads is still there.
        $products->getCollection()->each(function (Product $product): void {
            $product->setAttribute('thumbnail', $product->getFirstMediaUrl('product_images') ?: null);
        });

        return Inertia::render('Products/Index', ['products' => $products, 'q' => $request->query('q')]);
    }

    /**
     * The same rows index() renders — same `q` search — as an .xlsx
     * download. cost_price rides along only for an employee who already
     * holds products.update, the same gate show() applies to that column.
     */
    public function export(Request $request): BinaryFileResponse
    {
        $export = new ProductsExport(
            trim((string) $request->string('q')),
            (bool) $request->user('employee')?->can('products.update'),
        );

        return $export->download('products-'.now()->format('Y-m-d_His').'.xlsx');
    }

    public function create(): Response
    {
        return Inertia::render('Products/Form', [
            'product' => null,
            // Shown in the form's disabled SKU field so the operator can
            // see what the product will be called before saving — and so
            // the variant SKUs they type on the same screen can be based
            // on it. store() generates its own rather than trusting this
            // one, so a stale value here can never become a duplicate.
            'nextSku' => $this->skus->nextProductSku(),
            ...$this->pickerOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $product = DB::transaction(function () use ($data, $request) {
            // Generated here, not read from the request: the form's SKU
            // field is disabled, and a disabled field is a UI affordance,
            // not a guarantee — anything can still POST a `sku`. Doing it
            // inside the transaction also means the sequence is read at
            // the moment of the insert rather than when the page was
            // opened, so two operators who opened the form together do
            // not both save the same number.
            $data['sku'] = $this->skus->nextProductSku();

            $product = Product::create($this->productFields($data));
            $product->categories()->sync($data['category_ids'] ?? []);
            $product->collections()->sync($data['collection_ids'] ?? []);
            $this->syncVariants($product, $data['variants']);
            $this->attachImages($product, $request);

            return $product;
        });

        return redirect()->route('admin.products.edit', $product)->with('success', __('Product created.'));
    }

    /**
     * /admin/products/{product} — the read-only detail view, ported from
     * Admin Template/product-details.html.
     *
     * Everything here is a read: no Action is invoked and nothing is
     * written. The template's page is a *storefront* product page (Add to
     * Cart, Buy Now, a quantity stepper), so the parts that only make
     * sense to a shopper are replaced with what an operator actually needs
     * on the same layout — stock per variant per warehouse where the
     * quantity stepper sat, and the catalog record's own fields in the
     * "Items Detail" spec list.
     */
    public function show(Request $request, Product $product): Response
    {
        $product->load([
            'categories:id,name',
            'collections:id,name',
            'variants.attributeValues.attribute',
            'media',
        ]);

        $variantIds = $product->variants->pluck('id');

        // Queried here rather than through a relation on ProductVariant —
        // the model has none, and a detail screen is no reason to add one.
        $stock = WarehouseInventory::query()
            ->whereIn('product_variant_id', $variantIds)
            ->with('warehouse:id,name')
            ->get()
            ->groupBy('product_variant_id');

        // Units sold is Delivered-only, matching the dashboard and the
        // rule that stock deducts on Accounting-confirmed delivery
        // (Section 07) — a pending order is not a sale.
        $sold = OrderItem::query()
            ->whereIn('product_variant_id', $variantIds)
            ->whereHas('order', fn ($order) => $order->where('status', OrderStatus::Delivered))
            ->selectRaw('product_variant_id, SUM(quantity) as units')
            ->groupBy('product_variant_id')
            ->pluck('units', 'product_variant_id');

        return Inertia::render('Products/Show', [
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'slug' => $product->slug,
                'sku' => $product->sku,
                'description' => $product->description,
                'short_description' => $product->short_description,
                'price' => $product->price,
                'sale_price' => $product->sale_price,
                'cost_price' => $request->user('employee')->can('products.update') ? $product->cost_price : null,
                'status' => $product->status,
                'is_featured' => $product->is_featured,
                'is_new' => $product->is_new,
                'is_on_sale' => $product->is_on_sale,
                'sort_order' => $product->sort_order,
                'product_type' => $product->product_type,
                'inventory_tracking_enabled' => $product->inventory_tracking_enabled,
                'created_at' => $product->created_at?->toIso8601String(),
                'updated_at' => $product->updated_at?->toIso8601String(),
                'categories' => $product->categories->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->values()->all(),
                'collections' => $product->collections->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->values()->all(),
                'images' => $product->getMedia('product_images')
                    ->map(fn (Media $media) => ['id' => $media->id, 'url' => $media->getUrl()])
                    ->values()
                    ->all(),
                'variants' => $product->variants
                    ->map(fn (ProductVariant $variant) => $this->variantDetail(
                        $variant,
                        $stock->get($variant->id)?->all() ?? [],
                        (int) ($sold[$variant->id] ?? 0),
                    ))
                    ->values()
                    ->all(),
            ],
            'reviews' => Review::query()
                ->where('product_id', $product->id)
                ->with('customer:id,name')
                ->latest('id')
                ->limit(6)
                ->get()
                ->map(fn (Review $review) => [
                    'id' => $review->id,
                    // Nullable in practice even though the relation is typed
                    // non-null: a review outlives a deleted customer.
                    'customer' => $review->customer->name ?? null,
                    'rating' => $review->rating,
                    'title' => $review->title,
                    'comment' => $review->comment,
                    'status' => $review->status,
                    'created_at' => $review->created_at?->toIso8601String(),
                ]),
            'reviewSummary' => [
                'count' => $product->reviews()->count(),
                'average' => round((float) $product->reviews()->avg('rating'), 1),
            ],
        ]);
    }

    /**
     * One variant row on the detail screen: what it is, what is on hand
     * for it in each warehouse, and how many have actually shipped.
     *
     * @param  list<WarehouseInventory>  $stock
     * @return array<string, mixed>
     */
    private function variantDetail(ProductVariant $variant, array $stock, int $unitsSold): array
    {
        return [
            'id' => $variant->id,
            'sku' => $variant->sku,
            'status' => $variant->status,
            'price' => $variant->price,
            'sale_price' => $variant->sale_price,
            'attributes' => $variant->attributeValues
                ->map(fn ($value) => [
                    'attribute' => $value->attribute->name,
                    'value' => $value->value,
                    'color_hex' => $value->color_hex,
                ])
                ->values()
                ->all(),
            'stock' => array_map(fn (WarehouseInventory $row) => [
                'warehouse' => $row->warehouse?->name,
                'quantity' => $row->quantity,
                'reserved_quantity' => $row->reserved_quantity,
                'available' => $row->available,
            ], $stock),
            'units_sold' => $unitsSold,
        ];
    }

    public function edit(Product $product): Response
    {
        $product->load(['categories:id,name', 'collections:id,name', 'variants.attributeValues.attribute']);

        return Inertia::render('Products/Form', [
            'product' => [
                ...$product->toArray(),
                // Both languages, not the serialized current-locale
                // string — this form authors translations. See
                // Concerns\SerializesTranslations.
                'name' => $product->getTranslations('name'),
                'description' => $product->getTranslations('description'),
                'short_description' => $product->getTranslations('short_description'),
                'meta_title' => $product->getTranslations('meta_title'),
                'meta_description' => $product->getTranslations('meta_description'),
                'images' => $product->getMedia('product_images')->map(fn (Media $media) => [
                    'id' => $media->id,
                    'url' => $media->getUrl(),
                ]),
            ],
            ...$this->pickerOptions(),
        ]);
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $data = $this->validated($request, $product);

        DB::transaction(function () use ($product, $data, $request) {
            $product->update($this->productFields($data));
            $product->categories()->sync($data['category_ids'] ?? []);
            $product->collections()->sync($data['collection_ids'] ?? []);
            $this->syncVariants($product, $data['variants']);
            $this->attachImages($product, $request);
        });

        return redirect()->route('admin.products.edit', $product)->with('success', __('Product updated.'));
    }

    public function destroy(Product $product): RedirectResponse
    {
        $product->delete();

        return redirect()->route('admin.products.index')->with('success', __('Product deleted.'));
    }

    public function destroyImage(Product $product, Media $media): RedirectResponse
    {
        abort_unless($media->model_id === $product->id, 404);

        $media->delete();

        return back()->with('success', __('Image removed.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Product $product = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'array'],
            'name.en' => ['required', 'string', 'max:255'],
            'name.ar' => ['nullable', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            // Only on update. On create the SKU is assigned by
            // SkuGenerator in store(), so requiring one from the request
            // would reject the very form that deliberately does not send
            // it.
            'sku' => $product === null
                ? ['nullable']
                : ['required', 'string', 'max:100', Rule::unique('products', 'sku')->ignore($product->id)],
            'description' => ['nullable', 'array'],
            'description.en' => ['nullable', 'string'],
            'description.ar' => ['nullable', 'string'],
            'short_description' => ['nullable', 'array'],
            'short_description.en' => ['nullable', 'string'],
            'short_description.ar' => ['nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0'],
            'sale_price' => ['nullable', 'numeric', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'status' => ['required', 'boolean'],
            'is_featured' => ['required', 'boolean'],
            'is_new' => ['required', 'boolean'],
            'is_on_sale' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0'],
            'product_type' => ['required', Rule::enum(ProductType::class)],
            'category_ids' => ['array'],
            'category_ids.*' => ['exists:categories,id'],
            'collection_ids' => ['array'],
            'collection_ids.*' => ['exists:collections,id'],
            'variants' => ['required', 'array', 'min:1'],
            'variants.*.id' => ['nullable', 'exists:product_variants,id'],
            'variants.*.sku' => ['required', 'string', 'max:100'],
            'variants.*.barcode' => ['nullable', 'string', 'max:100'],
            'variants.*.price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.sale_price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.cost_price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.status' => ['required', 'boolean'],
            'variants.*.attribute_value_ids' => ['array'],
            'variants.*.attribute_value_ids.*' => ['exists:attribute_values,id'],
            'images' => ['array'],
            'images.*' => ImageUpload::RULES,
        ]);

        // The two rich-text fields, scrubbed on the way in — this is the
        // single choke point both store() and update() pass through, and
        // sanitising here rather than at render protects every consumer
        // of the stored value, not just the storefront page that happens
        // to use dangerouslySetInnerHTML today. See RichTextSanitizer.
        $data['description'] = $this->sanitizer->cleanTranslations($data['description'] ?? null);
        $data['short_description'] = $this->sanitizer->cleanTranslations($data['short_description'] ?? null);

        return array_filter(
            $data,
            fn (mixed $value, string $key) => ! in_array($key, ['description', 'short_description'], true) || $value !== null,
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * inventory_tracking_enabled is never client-supplied (spec Section
     * 05: "Set automatically whenever product_type changes... never
     * edited independently by a user") — always derived here from
     * product_type instead.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function productFields(array $data): array
    {
        $type = ProductType::from($data['product_type']);

        return [
            ...collect($data)->except(['category_ids', 'collection_ids', 'variants', 'images'])->all(),
            'slug' => ($data['slug'] ?? null) ?: Str::slug($data['name']['en']),
            'inventory_tracking_enabled' => $type === ProductType::Real,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $variants
     */
    private function syncVariants(Product $product, array $variants): void
    {
        $keepIds = [];

        foreach ($variants as $variantData) {
            $fields = collect($variantData)->except(['id', 'attribute_value_ids'])->all();

            $variant = isset($variantData['id'])
                ? tap(ProductVariant::findOrFail($variantData['id']), fn ($v) => $v->update($fields))
                : $product->variants()->create($fields);

            $variant->attributeValues()->sync($variantData['attribute_value_ids'] ?? []);
            $this->seedStockRows($variant);
            $keepIds[] = $variant->id;
        }

        $this->removeDroppedVariants($product, $keepIds);
    }

    /**
     * /admin/inventory lists warehouse_inventory rows and its Adjust
     * button is per-row — so a variant with no row is invisible there and
     * its stock can never be set at all. Nothing else creates that first
     * row (InventoryService only makes one inside restock/adjust, both of
     * which already need the variant to be reachable), so a product
     * created through the admin was stuck at "no stock, no way to add
     * any". Give every variant a zero row in each active warehouse at
     * save time; the real quantity still arrives through an audited
     * adjustment, never from this form.
     */
    private function seedStockRows(ProductVariant $variant): void
    {
        foreach (Warehouse::query()->where('is_active', true)->pluck('id') as $warehouseId) {
            WarehouseInventory::query()->firstOrCreate(
                ['warehouse_id' => $warehouseId, 'product_variant_id' => $variant->id],
                ['quantity' => 0, 'reserved_quantity' => 0],
            );
        }
    }

    /**
     * A variant dropped from the form is hard-deleted only if nothing
     * else references it — order_items.product_variant_id and
     * inventory_movements.product_variant_id are both plain
     * `constrained()` (RESTRICT, no cascade/null), so deleting a variant
     * with order or stock-movement history would otherwise throw an
     * unhandled QueryException instead of failing gracefully. A variant
     * with history is deactivated instead — its historical
     * order_items/inventory_movements rows stay intact and correct
     * either way, since those already snapshot what they need.
     *
     * @param  array<int, int>  $keepIds
     */
    private function removeDroppedVariants(Product $product, array $keepIds): void
    {
        $dropped = $product->variants()->whereNotIn('id', $keepIds)->get();

        foreach ($dropped as $variant) {
            $hasHistory = OrderItem::where('product_variant_id', $variant->id)->exists()
                || InventoryMovement::where('product_variant_id', $variant->id)->exists();

            if ($hasHistory) {
                $variant->update(['status' => false]);
            } else {
                $variant->delete();
            }
        }
    }

    private function attachImages(Product $product, Request $request): void
    {
        foreach ($request->file('images', []) as $file) {
            $product->addMedia($file)->toMediaCollection('product_images');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function pickerOptions(): array
    {
        return [
            'categories' => Category::query()->orderBy('sort_order')->get(['id', 'name']),
            'collections' => CollectionModel::query()->orderBy('sort_order')->get(['id', 'name']),
            'attributes' => Attribute::query()->with('values')->orderBy('sort_order')->get(),
        ];
    }
}
