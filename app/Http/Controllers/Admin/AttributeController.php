<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\AttributeValue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /admin/attributes (Vice Chairman) — Color/Size and their values, the
 * prerequisite picker data the product variant form (Products/Form.tsx)
 * needs to differentiate SKUs. One page, not a full CRUD set — attributes
 * themselves rarely change once seeded, values are the day-to-day edit.
 */
class AttributeController extends Controller implements HasMiddleware
{
    /**
     * Value-level create/update/delete folds into the matching
     * attribute-level permission — there is one screen, not two.
     */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:attributes.view', only: ['index']),
            new Middleware('permission:attributes.create', only: ['store', 'storeValue']),
            new Middleware('permission:attributes.update', only: ['update', 'updateValue']),
            new Middleware('permission:attributes.delete', only: ['destroy', 'destroyValue']),
        ];
    }

    public function index(): Response
    {
        return Inertia::render('Attributes/Index', [
            'attributes' => Attribute::query()->with('values')->orderBy('sort_order')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'array'],
            'name.en' => ['required', 'string', 'max:255'],
            'name.ar' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['required', 'integer', 'min:0'],
        ]);

        Attribute::create($data);

        return back()->with('success', __('Attribute created.'));
    }

    public function update(Request $request, Attribute $attribute): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'array'],
            'name.en' => ['required', 'string', 'max:255'],
            'name.ar' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['required', 'integer', 'min:0'],
        ]);

        $attribute->update($data);

        return back()->with('success', __('Attribute updated.'));
    }

    /**
     * Refused while it still has values — same reasoning as destroyValue
     * below: deleting the attribute out from under them wouldn't remove
     * anything (they're a separate table), it would just orphan them from
     * the one screen that manages them.
     */
    public function destroy(Attribute $attribute): RedirectResponse
    {
        if ($attribute->values()->exists()) {
            return back()->with('error', __('Remove this attribute\'s values first.'));
        }

        $attribute->delete();

        return back()->with('success', __('Attribute removed.'));
    }

    public function storeValue(Request $request, Attribute $attribute): RedirectResponse
    {
        $data = $request->validate([
            'value' => ['required', 'array'],
            'value.en' => ['required', 'string', 'max:255'],
            'value.ar' => ['nullable', 'string', 'max:255'],
            'color_hex' => ['nullable', 'string', 'max:7'],
            'sort_order' => ['required', 'integer', 'min:0'],
        ]);

        $attribute->values()->create($data);

        return back()->with('success', __('Value added.'));
    }

    public function updateValue(Request $request, Attribute $attribute, AttributeValue $value): RedirectResponse
    {
        abort_unless($value->attribute_id === $attribute->id, 404);

        $data = $request->validate([
            'value' => ['required', 'array'],
            'value.en' => ['required', 'string', 'max:255'],
            'value.ar' => ['nullable', 'string', 'max:255'],
            'color_hex' => ['nullable', 'string', 'max:7'],
            'sort_order' => ['required', 'integer', 'min:0'],
        ]);

        $value->update($data);

        return back()->with('success', __('Value updated.'));
    }

    /**
     * Removing an attribute value — refused while anything still uses it.
     *
     * `variant_attribute_values` and `product_attribute_values` both cascade
     * on delete, so removing "Black" used to strip it from every variant
     * carrying it — silently, and with no undo. The variants survived but
     * stopped being distinguishable: `MSH-001-BLACK-S` was no longer
     * *Black / S*, just *S*, and meant the same thing as the red one.
     *
     * Same resolution the catalog already uses for an ordered variant
     * (Phase 4: deactivate, never delete) — refuse, and say how many rows
     * are in the way so the operator knows what to unpick first. The delete
     * is a soft one now too, so an unused value that is removed by mistake
     * is recoverable rather than gone.
     */
    public function destroyValue(Attribute $attribute, AttributeValue $value): RedirectResponse
    {
        abort_unless($value->attribute_id === $attribute->id, 404);

        $variants = $value->variants()->count();
        $products = $value->products()->count();

        if ($variants > 0 || $products > 0) {
            return back()->with('error', __(
                'That value is still used by :variants variant(s) and :products product(s). Remove it from those first.',
                ['variants' => $variants, 'products' => $products],
            ));
        }

        $value->delete();

        return back()->with('success', __('Value removed.'));
    }
}
