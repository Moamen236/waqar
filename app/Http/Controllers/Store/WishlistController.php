<?php

namespace App\Http\Controllers\Store;

use App\Enums\ReviewStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Store\Concerns\ResolvesWishlist;
use App\Models\Product;
use App\Support\ProductPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Wishlist — Anvogue's wishlist.html, wired to the real wishlists /
 * wishlist_items tables. Items are held at product level, not variant
 * level ("a customer wishlists 'this T-shirt', not one specific
 * size/colour" — Section 24), which is why the page's Add-to-Cart still
 * goes through the product page rather than adding a variant directly.
 */
class WishlistController extends Controller
{
    use ResolvesWishlist;

    public function index(Request $request): Response
    {
        $wishlist = $this->wishlistFor($request->user('customer'));

        $products = Product::query()
            ->whereIn('id', $wishlist->items()->pluck('product_id'))
            ->where('status', true)
            ->with(['media', 'categories', 'variants.attributeValues.attribute'])
            ->withCount(['reviews' => fn ($q) => $q->where('status', ReviewStatus::Approved->value)])
            ->withAvg(['reviews as reviews_avg_rating' => fn ($q) => $q->where('status', ReviewStatus::Approved->value)], 'rating')
            ->get()
            ->map(fn (Product $product) => ProductPresenter::card($product));

        return Inertia::render('Wishlist/Index', ['products' => $products]);
    }

    public function toggle(Request $request): RedirectResponse
    {
        $data = $request->validate(['product_id' => ['required', 'exists:products,id']]);

        $wishlist = $this->wishlistFor($request->user('customer'));
        $existing = $wishlist->items()->where('product_id', $data['product_id'])->first();

        if ($existing !== null) {
            $existing->delete();

            return back()->with('success', __('Removed from your wishlist.'));
        }

        $wishlist->items()->create(['product_id' => $data['product_id']]);

        return back()->with('success', __('Added to your wishlist.'));
    }
}
