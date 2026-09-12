<?php

namespace App\Http\Middleware;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Collection;
use App\Services\Cart\CartService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * Two separate frontends share this one backend — Tailwind storefront,
     * Bootstrap admin, never both loaded on the same page (Section 23).
     * The root view (and so the CSS/JS bundle) is picked per-request from
     * the URL rather than hardcoded, since routes will later also carry a
     * {locale} prefix (/ar/admin/…, /en/admin/…) in front of "admin".
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     */
    public function rootView(Request $request): string
    {
        return $request->is('admin', 'admin/*', '*/admin', '*/admin/*')
            ? 'admin'
            : 'storefront';
    }

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $employee = $request->user('employee');
        $customer = $request->user('customer');
        $isAdmin = $this->rootView($request) === 'admin';

        return [
            ...parent::share($request),
            'auth' => [
                // The storefront shell needs the signed-in customer on
                // every page (header account menu, wishlist/cart counts)
                // — never the employee, and vice versa: the two guards
                // are separate identities (Section 15, Q19).
                'customer' => $customer ? [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'email' => $customer->email,
                ] : null,
                'employee' => $employee ? [
                    'id' => $employee->id,
                    'full_name' => $employee->full_name,
                    'email' => $employee->email,
                    'roles' => $employee->getRoleNames(),
                    // Super Admin never holds explicit permission rows —
                    // it bypasses every check via the Gate::before hook
                    // in AppServiceProvider — so the React side needs
                    // this flag separately rather than inferring
                    // "can do everything" from an empty permissions list.
                    'is_super_admin' => $employee->hasRole('Super Admin'),
                    // Flattened once here rather than every admin page
                    // calling $employee->can() itself — the React side
                    // checks membership in this array to show/hide nav
                    // and actions (Section 15: "gated by a granular
                    // permission"). Server-side routes still enforce the
                    // real `permission:` middleware regardless of what
                    // this array says — it's a UI convenience, not the
                    // authorization boundary.
                    'permissions' => $employee->getAllPermissions()->pluck('name'),
                ] : null,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            // Storefront shell data only — computed lazily and skipped
            // entirely on admin requests, which never render the Anvogue
            // header/footer.
            'storefront' => $isAdmin ? null : [
                'cartCount' => fn () => $this->cartCount($request),
                'wishlistCount' => fn () => $customer?->wishlist?->items()->count() ?? 0,
                'notificationCount' => fn () => $customer?->unreadNotifications()->count() ?? 0,
                'nav' => fn () => $this->navigation(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function navigation(): array
    {
        $locale = app()->getLocale();

        return [
            // The template's hard-coded mega-menu columns (Demo, Features,
            // Shop, Blog…) are replaced by the real category tree; there
            // is no blog module in this system (Section 17).
            'categories' => Category::query()
                ->whereNull('parent_id')
                ->where('status', true)
                ->with(['children' => fn ($q) => $q->where('status', true)->orderBy('sort_order')])
                ->orderBy('sort_order')
                ->get()
                ->map(fn (Category $category) => [
                    'slug' => (string) $category->slug,
                    'name' => $category->getTranslation('name', $locale),
                    'children' => $category->children->map(fn (Category $child) => [
                        'slug' => (string) $child->slug,
                        'name' => $child->getTranslation('name', $locale),
                    ])->values(),
                ])->values(),
            'collections' => Collection::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->limit(8)
                ->get()
                ->map(fn (Collection $collection) => [
                    'slug' => (string) $collection->slug,
                    'name' => $collection->getTranslation('name', $locale),
                ])->values(),
        ];
    }

    /**
     * Read straight off cart_items rather than through CartService's full
     * summary — the header badge only needs a count, and building the
     * priced summary on every single page render would be wasteful.
     */
    private function cartCount(Request $request): int
    {
        $customer = $request->user('customer');
        $token = $request->session()->get(CartService::TOKEN_KEY);

        if ($customer === null && ! is_string($token)) {
            return 0;
        }

        $cart = Cart::query()
            ->when($customer !== null, fn ($q) => $q->where('customer_id', $customer->id))
            ->when($customer === null, fn ($q) => $q->where('session_token', $token))
            ->first();

        return (int) ($cart?->items()->sum('quantity') ?? 0);
    }
}
