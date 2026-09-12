<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /admin/collections (Vice Chairman, Section 20 #23) — no template
 * counterpart at all, built from scratch.
 */
class CollectionController extends Controller
{
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

        return redirect()->route('admin.collections.index')->with('success', 'Collection created.');
    }

    public function edit(Collection $collection): Response
    {
        return Inertia::render('Collections/Form', ['collection' => $collection]);
    }

    public function update(Request $request, Collection $collection): RedirectResponse
    {
        $data = $this->validated($request);
        $data['slug'] = ($data['slug'] ?? null) ?: Str::slug($data['name']['en'] ?? '');

        if ($image = $this->storeImage($request)) {
            $data['image'] = $image;
        }

        $collection->update($data);

        return redirect()->route('admin.collections.index')->with('success', 'Collection updated.');
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
