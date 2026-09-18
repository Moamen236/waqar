<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Support\ImageUpload;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /admin/collections (Vice Chairman, Section 20 #23) — no template
 * counterpart at all, built from scratch.
 */
class CollectionController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:collections.view', only: ['index']),
            new Middleware('permission:collections.create', only: ['create', 'store']),
            new Middleware('permission:collections.update', only: ['edit', 'update']),
            new Middleware('permission:collections.delete', only: ['destroy']),
        ];
    }

    public function index(): Response
    {
        return Inertia::render('Collections/Index', [
            'collections' => Collection::query()->withCount('products')->orderBy('sort_order')->paginate(20),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Collections/Form', ['collection' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['slug'] = ($data['slug'] ?? null) ?: Str::slug($data['name']['en'] ?? '');
        $data['image'] = $this->storeImage($request);

        Collection::create($data);

        return redirect()->route('admin.collections.index')->with('success', __('Collection created.'));
    }

    public function edit(Collection $collection): Response
    {
        return Inertia::render('Collections/Form', [
            // Both languages — see CategoryController::edit().
            'collection' => [
                ...$collection->toArray(),
                'name' => $collection->getTranslations('name'),
                'description' => $collection->getTranslations('description'),
            ],
        ]);
    }

    public function update(Request $request, Collection $collection): RedirectResponse
    {
        $data = $this->validated($request);
        $data['slug'] = ($data['slug'] ?? null) ?: Str::slug($data['name']['en'] ?? '');

        if ($image = $this->storeImage($request)) {
            $data['image'] = $image;
        }

        $collection->update($data);

        return redirect()->route('admin.collections.index')->with('success', __('Collection updated.'));
    }

    public function destroy(Collection $collection): RedirectResponse
    {
        $collection->delete();

        return redirect()->route('admin.collections.index')->with('success', __('Collection deleted.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'array'],
            'name.en' => ['required', 'string', 'max:255'],
            'name.ar' => ['nullable', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'array'],
            'description.en' => ['nullable', 'string'],
            'description.ar' => ['nullable', 'string'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0'],
            // Raster images only — see ImageUpload for why `image` alone
            // is not enough (it permits SVG, which is scriptable and is
            // served back from this application's own origin).
            'image' => ImageUpload::optional(),
        ]);
    }

    private function storeImage(Request $request): ?string
    {
        if (! $request->hasFile('image')) {
            return null;
        }

        return $request->file('image')->store('collections', 'public');
    }
}
