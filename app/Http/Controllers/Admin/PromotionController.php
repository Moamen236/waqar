<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Collection;
use App\Models\ProductVariant;
use App\Models\Promotion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /admin/promotions (Vice Chairman, Question 17) — Bundle (every
 * `items` row required, no `rewards`) and Buy X Get Y (`items` are the
 * trigger, `rewards` only populated for this type). No template
 * counterpart, built from scratch (Section 20 #23).
 */
class PromotionController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:promotions.view', only: ['index']),
            new Middleware('permission:promotions.create', only: ['create', 'store']),
            new Middleware('permission:promotions.update', only: ['edit', 'update']),
            new Middleware('permission:promotions.delete', only: ['destroy']),
        ];
    }

    public function index(): Response
    {
        return Inertia::render('Promotions/Index', [
            'promotions' => Promotion::query()->latest('id')->paginate(20)->withQueryString(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Promotions/Form', ['promotion' => null, ...$this->pickerOptions()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        DB::transaction(function () use ($data) {
            $promotion = Promotion::create($data['promotion']);
            $promotion->items()->createMany($data['items']);
            if ($data['promotion']['type'] === 'buy_x_get_y') {
                $promotion->rewards()->createMany($data['rewards']);
            }
        });

        return redirect()->route('admin.promotions.index')->with('success', __('Promotion created.'));
    }

    public function edit(Promotion $promotion): Response
    {
        $promotion->load('items', 'rewards');

        return Inertia::render('Promotions/Form', ['promotion' => $promotion, ...$this->pickerOptions()]);
    }

    public function update(Request $request, Promotion $promotion): RedirectResponse
    {
        $data = $this->validated($request);

        DB::transaction(function () use ($promotion, $data) {
            $promotion->update($data['promotion']);
            $promotion->items()->delete();
            $promotion->items()->createMany($data['items']);
            $promotion->rewards()->delete();
            if ($data['promotion']['type'] === 'buy_x_get_y') {
                $promotion->rewards()->createMany($data['rewards']);
            }
        });

        return redirect()->route('admin.promotions.index')->with('success', __('Promotion updated.'));
    }

    /**
     * Soft delete — its items/rewards rows stay (they cascadeOnDelete only
     * on a real row delete, which a soft delete never triggers), so a
     * restored promotion comes back with its component list intact.
     */
    public function destroy(Promotion $promotion): RedirectResponse
    {
        $promotion->delete();

        return redirect()->route('admin.promotions.index')->with('success', __('Promotion deleted.'));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'array'],
            'name.en' => ['required', 'string', 'max:255'],
            'name.ar' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'array'],
            'description.en' => ['nullable', 'string'],
            'description.ar' => ['nullable', 'string'],
            'type' => ['required', Rule::in(['bundle', 'buy_x_get_y'])],
            'discount_type' => ['required', Rule::in(['percentage', 'fixed_amount', 'fixed_price', 'free'])],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'priority' => ['required', 'integer'],
            'stackable_with_coupons' => ['required', 'boolean'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'usage_limit_per_customer' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['required', 'boolean'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_variant_id' => ['nullable', 'exists:product_variants,id'],
            'items.*.category_id' => ['nullable', 'exists:categories,id'],
            'items.*.collection_id' => ['nullable', 'exists:collections,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'rewards' => ['required_if:type,buy_x_get_y', 'array'],
            'rewards.*.product_variant_id' => ['nullable', 'exists:product_variants,id'],
            'rewards.*.category_id' => ['nullable', 'exists:categories,id'],
            'rewards.*.collection_id' => ['nullable', 'exists:collections,id'],
            'rewards.*.quantity' => ['required_with:rewards', 'integer', 'min:1'],
        ]);

        return [
            'promotion' => collect($validated)->except(['items', 'rewards'])->all(),
            'items' => $validated['items'],
            'rewards' => $validated['rewards'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pickerOptions(): array
    {
        return [
            // whereHas(): a soft-deleted product's variants would
            // otherwise resolve `$v->product` to null and 500 this screen.
            'variants' => ProductVariant::query()->whereHas('product')->with('product:id,name')->get()
                ->map(fn ($v) => [
                    'id' => $v->id,
                    // app locale, not a hard-coded 'en' — staff run this
                    // admin in Arabic (Q2).
                    'label' => $v->product->getTranslation('name', app()->getLocale()).' — '.$v->sku,
                ]),
            'categories' => Category::query()->get(['id', 'name']),
            'collections' => Collection::query()->get(['id', 'name']),
        ];
    }
}
