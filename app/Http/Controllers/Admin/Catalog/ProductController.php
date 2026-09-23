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
use App\Support\ProductPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Collection;
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
            new Middleware('permission:products.update', only: ['edit', 'update', 'destroyImage', 'updateImage']),
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
            $this->syncVariants($product, $data['variants'], $data['size_guides'] ?? []);
            $this->attachImages($product, $request, $data);

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
                    // Which colour this photo shows, if anyone has said.
                    // Null means "every colour" — see updateImage().
                    'attribute_value_id' => $media->getCustomProperty('attribute_value_id'),
                ]),
                // Only the colours this product is actually made in, from
                // the same presenter the storefront swatches come from.
                'colors' => ProductPresenter::colours($product),
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
            $this->syncVariants($product, $data['variants'], $data['size_guides'] ?? []);
            $this->attachImages($product, $request, $data);
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
     * Say which colour a photo shows, so the storefront gallery can swap
     * when a swatch is picked.
     *
     * No migration and no column: MediaLibrary already keeps a
     * custom_properties JSON blob on every media row, and one id is all
     * this needs. Clearing it forgets the key outright rather than
     * storing a null, so an untagged image reads the same whether it was
     * never tagged or tagged and cleared.
     */
    public function updateImage(Request $request, Product $product, Media $media): RedirectResponse
    {
        abort_unless($media->model_id === $product->id, 404);

        $data = $request->validate([
            'attribute_value_id' => ['nullable', 'integer', 'exists:attribute_values,id'],
        ]);

        if (($data['attribute_value_id'] ?? null) !== null) {
            $media->setCustomProperty('attribute_value_id', (int) $data['attribute_value_id']);
        } else {
            $media->forgetCustomProperty('attribute_value_id');
        }

        $media->save();

        return back()->with('success', __('Image colour updated.'));
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
            // Assigned by SkuGenerator in syncVariants(), never read from
            // the request — same reason as the product SKU above: the
            // form's field is disabled, which is a UI affordance and not
            // a guarantee.
            'variants.*.sku' => ['nullable'],
            'variants.*.barcode' => ['nullable', 'string', 'max:100'],
            'variants.*.price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.sale_price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.cost_price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.status' => ['required', 'boolean'],
            'variants.*.attribute_value_ids' => ['array'],
            'variants.*.attribute_value_ids.*' => ['exists:attribute_values,id'],
            // Per size, not per variant. The columns live on
            // product_variants, but a weight range is a property of the
            // size — "M fits 60–70 kg" is true of every colour it is made
            // in — so the form edits one row per size and syncVariants()
            // fans the value out. Keyed by the size's attribute_value_id
            // rather than its name, which is translatable.
            'size_guides' => ['array'],
            'size_guides.*.attribute_value_id' => ['required', 'exists:attribute_values,id'],
            'size_guides.*.weight_min' => ['nullable', 'numeric', 'min:0', 'max:999.99'],
            'size_guides.*.weight_max' => ['nullable', 'numeric', 'min:0', 'max:999.99', 'gte:size_guides.*.weight_min'],
            'images' => ['array'],
            'images.*' => ImageUpload::RULES,
            // Parallel to `images` by index: which colour each new photo
            // shows. Null/empty means every colour. Validated as ids only —
            // whether the colour is actually on this product's variants is
            // a UX concern (the form only offers those), not a 422.
            'image_attribute_value_ids' => ['nullable', 'array'],
            'image_attribute_value_ids.*' => ['nullable', 'integer', 'exists:attribute_values,id'],
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
            ...collect($data)->except(['category_ids', 'collection_ids', 'variants', 'images', 'image_attribute_value_ids'])->all(),
            'slug' => ($data['slug'] ?? null) ?: Str::slug($data['name']['en']),
            'inventory_tracking_enabled' => $type === ProductType::Real,
        ];
    }

    /**
     * `sku` is dropped from the incoming fields on both paths: a new
     * variant gets a generated one, an existing variant keeps the one it
     * already has. Nothing the request says about a variant SKU is ever
     * written.
     *
     * The size guide is written here too, from the per-size rows rather
     * than from the variant row — see the `size_guides` rules. It is set
     * on every variant on every save, including back to null, so the form
     * stays the whole truth about it: a size cleared on the form clears
     * on every variant made in that size.
     *
     * @param  array<int, array<string, mixed>>  $variants
     * @param  array<int, array<string, mixed>>  $sizeGuides
     */
    private function syncVariants(Product $product, array $variants, array $sizeGuides = []): void
    {
        $weights = collect($sizeGuides)->keyBy('attribute_value_id');
        $keepIds = [];

        foreach ($variants as $variantData) {
            $fields = [
                ...collect($variantData)->except(['id', 'attribute_value_ids', 'sku'])->all(),
                ...$this->sizeGuideFor($variantData['attribute_value_ids'] ?? [], $weights),
            ];

            $variant = isset($variantData['id'])
                ? tap(ProductVariant::findOrFail($variantData['id']), fn ($v) => $v->update($fields))
                : $product->variants()->create([...$fields, 'sku' => $this->skus->nextVariantSku($product)]);

            $variant->attributeValues()->sync($variantData['attribute_value_ids'] ?? []);
            $this->seedStockRows($variant);
            $keepIds[] = $variant->id;
        }

        $this->removeDroppedVariants($product, $keepIds);
    }

    /**
     * The weight range for whichever of a variant's attribute values has
     * a size-guide row, or a pair of nulls when none does. Matching on
     * the posted rows rather than on "which attribute is Size" keeps this
     * from needing a second lookup: only sizes get rows in the first
     * place, because only sizes are rendered in the form's size table.
     *
     * @param  array<int, mixed>  $attributeValueIds
     * @param  Collection<int, array<string, mixed>>  $weights
     * @return array{size_guide_weight_min: mixed, size_guide_weight_max: mixed}
     */
    private function sizeGuideFor(array $attributeValueIds, Collection $weights): array
    {
        foreach ($attributeValueIds as $id) {
            if ($weights->has((int) $id)) {
                return [
                    'size_guide_weight_min' => $weights[(int) $id]['weight_min'] ?? null,
                    'size_guide_weight_max' => $weights[(int) $id]['weight_max'] ?? null,
                ];
            }
        }

        return ['size_guide_weight_min' => null, 'size_guide_weight_max' => null];
    }

    /**
     * /admin/inventory lists warehouse_inventory rows and its Adjust
     * button is per-row — so a variant with no row is invisible there and
     * its stock can never be set at all. Nothing else creates that first
     * row (InventoryService only makes one inside restock/adjust, both of
     * which already need the variant to be reachable), so a product
     * created through the admin was stuck at "no stock, no way to add
     * any". Give every variant a zero row in the main warehouse at save
     * time — other warehouses get their rows on first adjustment there,
     * not up front. The real quantity still arrives through an audited
     * adjustment, never from this form.
     */
    private function seedStockRows(ProductVariant $variant): void
    {
        $warehouse = Warehouse::main();

        if ($warehouse === null) {
            return;
        }

        WarehouseInventory::query()->firstOrCreate(
            ['warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id],
            ['quantity' => 0, 'reserved_quantity' => 0],
        );
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

    /**
     * New files arrive with no media row yet, so unlike updateImage()
     * (a PATCH on an existing row) their colour tag has to ride along
     * with the upload itself — `image_attribute_value_ids[i]` belongs to
     * `images[i]`. A missing entry, an empty string (how FormData sends
     * null), or an explicit null all mean "every colour" and store
     * nothing, exactly like an untagged image from updateImage().
     *
     * @param  array<string, mixed>  $data  Validated payload.
     */
    private function attachImages(Product $product, Request $request, array $data = []): void
    {
        $colourIds = $request->input('image_attribute_value_ids', $data['image_attribute_value_ids'] ?? []);
        if (! is_array($colourIds)) {
            $colourIds = [];
        }

        foreach (array_values($request->file('images', [])) as $index => $file) {
            $media = $product->addMedia($file)->toMediaCollection('product_images');

            $raw = $colourIds[$index] ?? null;
            $attributeValueId = $raw === '' || $raw === null ? null : (int) $raw;

            if ($attributeValueId !== null && $attributeValueId > 0) {
                $media->setCustomProperty('attribute_value_id', $attributeValueId);
                $media->save();
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function pickerOptions(): array
    {
        $attributes = Attribute::query()->with('values')->orderBy('sort_order')->get();

        return [
            'categories' => Category::query()->orderBy('sort_order')->get(['id', 'name']),
            'collections' => CollectionModel::query()->orderBy('sort_order')->get(['id', 'name']),
            'attributes' => $attributes,
            // Which attributes count as "colour". The storefront and
            // ProductPresenter::colours() key on the English attribute name,
            // which the form never sees (names arrive in the current
            // locale) — so the form gets the ids outright and filters the
            // variant-selected values to just these for image tagging.
            'colorAttributeIds' => $attributes
                ->filter(fn (Attribute $attribute) => strtolower($attribute->getTranslation('name', 'en')) === 'color')
                ->map(fn (Attribute $attribute) => $attribute->id)
                ->values()
                ->all(),
            // Same trick for sizes: the size-guide table needs to know
            // which selected values are sizes, and the English name is
            // the only stable handle for that.
            'sizeAttributeIds' => $attributes
                ->filter(fn (Attribute $attribute) => strtolower($attribute->getTranslation('name', 'en')) === 'size')
                ->map(fn (Attribute $attribute) => $attribute->id)
                ->values()
                ->all(),
        ];
    }
}
