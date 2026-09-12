<?php

namespace App\Http\Controllers\Admin\Catalog;

use App\Enums\ProductType;
use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\Collection as CollectionModel;
use App\Models\InventoryMovement;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Content\RichTextSanitizer;
use App\Support\ImageUpload;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * /admin/products (Vice Chairman + Chairman, Section 15) — not in the
 * roadmap's original Phase 4 task table (added after the fact, see
 * PHASE-4-HANDOVER.md's flagged gap). Covers the full product record:
 * translatable copy, variants with their differentiating attributes
 * (Color/Size), category/collection assignment, and the
 * Advertisement↔Real conversion (Section 05).
 */
class ProductController extends Controller
{
    public function __construct(private readonly RichTextSanitizer $sanitizer) {}

    public function index(Request $request): Response
    {
        $products = Product::query()
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = "%{$request->query('q')}%";
                $query->where('sku', 'like', $term)->orWhere('name->en', 'like', $term);
            })
            ->with('categories:id,name')
            ->withCount('variants')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Products/Index', ['products' => $products, 'q' => $request->query('q')]);
    }

    public function create(): Response
    {
        return Inertia::render('Products/Form', ['product' => null, ...$this->pickerOptions()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $product = DB::transaction(function () use ($data, $request) {
            $product = Product::create($this->productFields($data));
            $product->categories()->sync($data['category_ids'] ?? []);
            $product->collections()->sync($data['collection_ids'] ?? []);
            $this->syncVariants($product, $data['variants']);
            $this->attachImages($product, $request);

            return $product;
        });

        return redirect()->route('admin.products.edit', $product)->with('success', 'Product created.');
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

        return redirect()->route('admin.products.edit', $product)->with('success', 'Product updated.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        $product->delete();

        return redirect()->route('admin.products.index')->with('success', 'Product deleted.');
    }

    public function destroyImage(Product $product, Media $media): RedirectResponse
    {
        abort_unless($media->model_id === $product->id, 404);

        $media->delete();

        return back()->with('success', 'Image removed.');
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
            'sku' => ['required', 'string', 'max:100', Rule::unique('products', 'sku')->ignore($product?->id)],
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
            $keepIds[] = $variant->id;
        }

        $this->removeDroppedVariants($product, $keepIds);
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
