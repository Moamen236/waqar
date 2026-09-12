<?php

namespace App\Http\Controllers\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Support\ImageUpload;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /admin/categories (Vice Chairman + Chairman) — self-nesting via
 * parent_id, unlimited depth (Section 05). Not in the roadmap's original
 * Phase 4 task table, added after the fact alongside Products.
 */
class CategoryController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Categories/Index', [
            'categories' => Category::query()->with('parent:id,name')->orderBy('sort_order')->paginate(30),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Categories/Form', ['category' => null, 'categories' => $this->parentOptions()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['slug'] = ($data['slug'] ?? null) ?: Str::slug($data['name']['en']);
        $data['image'] = $this->storeImage($request) ?? $data['image'] ?? null;

        Category::create($data);

        return redirect()->route('admin.categories.index')->with('success', 'Category created.');
    }

    public function edit(Category $category): Response
    {
        return Inertia::render('Categories/Form', [
            // An authoring screen, so it needs *both* languages rather
            // than the current-locale string models now serialize to
            // (see Concerns\SerializesTranslations). Sending the whole
            // model and overriding the two translatable fields keeps the
            // form's other inputs working off the plain serialization.
            'category' => [
                ...$category->toArray(),
                'name' => $category->getTranslations('name'),
                'description' => $category->getTranslations('description'),
            ],
            'categories' => $this->parentOptions($category),
        ]);
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        $data = $this->validated($request, $category);
        $data['slug'] = ($data['slug'] ?? null) ?: Str::slug($data['name']['en']);

        if ($image = $this->storeImage($request)) {
            $data['image'] = $image;
        }

        $category->update($data);

        return redirect()->route('admin.categories.index')->with('success', 'Category updated.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Category $category = null): array
    {
        return $request->validate([
            // Excluded from its own parent-picker options on the edit
            // form (parentOptions()) rather than enforced here — good
            // enough to stop the normal-use case; a determined API
            // caller forcing a self-parent is a data-quality nuisance,
            // not a security concern, so not worth a full cycle-check.
            'parent_id' => ['nullable', 'exists:categories,id'],
            'name' => ['required', 'array'],
            'name.en' => ['required', 'string', 'max:255'],
            'name.ar' => ['nullable', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'array'],
            'description.en' => ['nullable', 'string'],
            'description.ar' => ['nullable', 'string'],
            'status' => ['required', 'boolean'],
            // Raster images only — see ImageUpload for why `image` alone
            // is not enough (it permits SVG, which is scriptable and is
            // served back from this application's own origin).
            'image' => ImageUpload::optional(),
            'sort_order' => ['required', 'integer', 'min:0'],
        ]);
    }

    private function storeImage(Request $request): ?string
    {
        if (! $request->hasFile('image')) {
            return null;
        }

        return $request->file('image')->store('categories', 'public');
    }

    /**
     * @return Collection<int, Category>
     */
    private function parentOptions(?Category $excluding = null): Collection
    {
        return Category::query()
            ->when($excluding, fn ($query) => $query->where('id', '!=', $excluding->id))
            ->orderBy('sort_order')
            ->get(['id', 'parent_id', 'name']);
    }
}
