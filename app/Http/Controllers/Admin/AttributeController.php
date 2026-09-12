<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\AttributeValue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /admin/attributes (Vice Chairman) — Color/Size and their values, the
 * prerequisite picker data the product variant form (Products/Form.tsx)
 * needs to differentiate SKUs. One page, not a full CRUD set — attributes
 * themselves rarely change once seeded, values are the day-to-day edit.
 */
class AttributeController extends Controller
{
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

        return back()->with('success', 'Attribute created.');
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

        return back()->with('success', 'Attribute updated.');
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

        return back()->with('success', 'Value added.');
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

        return back()->with('success', 'Value updated.');
    }

    public function destroyValue(Attribute $attribute, AttributeValue $value): RedirectResponse
    {
        abort_unless($value->attribute_id === $attribute->id, 404);

        $value->delete();

        return back()->with('success', 'Value removed.');
    }
}
