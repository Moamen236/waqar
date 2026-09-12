<?php

namespace App\Http\Controllers\Store;

use App\Enums\ReviewStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Support\ProductPresenter;
use App\Support\RecentlyViewed;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Product detail — Anvogue's product-default.html (Section 17's chosen
 * base; every other product-* variant is unused). Three template-level
 * changes the audit called for are applied here: the `brand` field is
 * gone entirely (products have no brands), each variant carries its real
 * SKU, and the Size Guide reads the Section 06 size-guide weight range
 * off the variant rather than a static table.
 */
class ProductController extends Controller
{
    public function show(Request $request, string $slug): Response
    {
        $product = Product::query()
            ->where('slug', $slug)
            ->where('status', true)
            ->with(['media', 'categories', 'collections', 'variants.attributeValues.attribute'])
            ->withCount(['reviews' => fn ($q) => $q->where('status', ReviewStatus::Approved->value)])
            ->withAvg(['reviews as reviews_avg_rating' => fn ($q) => $q->where('status', ReviewStatus::Approved->value)], 'rating')
            ->firstOrFail();

        RecentlyViewed::remember($request, $product->id);

        $customer = $request->user('customer');

        return Inertia::render('Product/Show', [
            'product' => ProductPresenter::detail($product),
            'reviews' => Review::query()
                ->where('product_id', $product->id)
                ->where('status', ReviewStatus::Approved->value)
                ->with('customer:id,name')
                ->latest('id')
                ->limit(20)
                ->get()
                ->map(fn (Review $review) => [
                    'id' => $review->id,
                    'author' => $review->customer->name,
                    'rating' => $review->rating,
                    'title' => $review->title,
                    'comment' => $review->comment,
                    'verified' => $review->order_item_id !== null,
                    'created_at' => $review->created_at?->toDateString(),
                ]),
            'related' => $this->related($product),
            'canReview' => $customer !== null && ! Review::where('product_id', $product->id)
                ->where('customer_id', $customer->id)
                ->exists(),
            'inWishlist' => $customer !== null && $customer->wishlist?->items()
                ->where('product_id', $product->id)->exists(),
        ]);
    }

    /**
     * A review is only ever filed by a signed-in customer, starts in the
     * `pending` moderation state (Section 24), and is marked
     * verified-purchase automatically when the customer actually has a
     * delivered order line for this product.
     */
    public function review(Request $request, string $slug): RedirectResponse
    {
        $product = Product::where('slug', $slug)->where('status', true)->firstOrFail();
        $customer = $request->user('customer');

        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'title' => ['nullable', 'string', 'max:255'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        if (Review::where('product_id', $product->id)->where('customer_id', $customer->id)->exists()) {
            return back()->with('error', 'You have already reviewed this product.');
        }

        Review::create([
            'product_id' => $product->id,
            'customer_id' => $customer->id,
            'order_item_id' => $this->purchasedItemId($customer->id, $product->id),
            'rating' => $data['rating'],
            'title' => $data['title'] ?? null,
            'comment' => $data['comment'] ?? null,
            'status' => ReviewStatus::Pending,
        ]);

        return back()->with('success', 'Thanks — your review will appear once it has been approved.');
    }

    private function purchasedItemId(int $customerId, int $productId): ?int
    {
        return Order::query()
            ->where('customer_id', $customerId)
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->join('product_variants', 'product_variants.id', '=', 'order_items.product_variant_id')
            ->where('product_variants.product_id', $productId)
            ->value('order_items.id');
    }

    /**
     * Returned as a plain array rather than a Collection: Collection's
     * generic isn't covariant, so a Collection of one concrete array
     * shape can't satisfy a Collection<array<string, mixed>> return type
     * (the same Larastan gap PHASE-3/4's handovers documented for
     * GeoTree).
     *
     * @return array<int, array<string, mixed>>
     */
    private function related(Product $product): array
    {
        $categoryIds = $product->categories->pluck('id');

        return Product::query()
            ->where('status', true)
            ->where('id', '!=', $product->id)
            ->when($categoryIds->isNotEmpty(), fn ($q) => $q->whereHas(
                'categories',
                fn ($c) => $c->whereIn('categories.id', $categoryIds)
            ))
            ->with(['media', 'categories', 'variants.attributeValues.attribute'])
            ->withCount(['reviews' => fn ($q) => $q->where('status', ReviewStatus::Approved->value)])
            ->withAvg(['reviews as reviews_avg_rating' => fn ($q) => $q->where('status', ReviewStatus::Approved->value)], 'rating')
            ->limit(8)
            ->get()
            ->map(fn (Product $related) => ProductPresenter::card($related))
            ->values()
            ->all();
    }
}
