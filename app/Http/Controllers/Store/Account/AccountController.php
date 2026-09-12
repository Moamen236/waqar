<?php

namespace App\Http\Controllers\Store\Account;

use App\Enums\OrderStatus;
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
 * Customer account — Anvogue's my-account.html. The template's own
 * Dashboard / Orders / Address / Setting tabs are kept; the three tabs
 * the spec requires and the template lacks (Reviews, Notifications,
 * Recently Viewed — Section 13, Section 20 #16) are added, and the
 * Billing tab is dropped outright since no card data exists anywhere in
 * this system (Question 12).
 *
 * Each tab is its own route/page rather than the template's client-side
 * tab switching, so an order list or a notification list is linkable and
 * paginated server-side.
 */
class AccountController extends Controller
{
    public function dashboard(Request $request): Response
    {
        $customer = $request->user('customer');

        $orders = Order::where('customer_id', $customer->id);

        return Inertia::render('Account/Dashboard', [
            'stats' => [
                'awaiting' => (clone $orders)->whereIn('status', [
                    OrderStatus::New->value,
                    OrderStatus::Checking->value,
                    OrderStatus::Confirmed->value,
                    OrderStatus::Assigned->value,
                    OrderStatus::OutForDelivery->value,
                ])->count(),
                'cancelled' => (clone $orders)->where('status', OrderStatus::Cancelled->value)->count(),
                'total' => (clone $orders)->count(),
            ],
            'recentOrders' => Order::where('customer_id', $customer->id)
                ->with('items')
                ->latest('id')
                ->limit(6)
                ->get()
                ->map(fn (Order $order) => $this->orderRow($order)),
        ]);
    }

    public function reviews(Request $request): Response
    {
        $customer = $request->user('customer');

        return Inertia::render('Account/Reviews', [
            'reviews' => Review::where('customer_id', $customer->id)
                ->with(['product.media'])
                ->latest('id')
                ->get()
                ->map(fn (Review $review) => [
                    'id' => $review->id,
                    'rating' => $review->rating,
                    'title' => $review->title,
                    'comment' => $review->comment,
                    'status' => $review->status->value,
                    'created_at' => $review->created_at?->toDateString(),
                    'product' => [
                        'slug' => (string) $review->product->slug,
                        'name' => $review->product->getTranslation('name', app()->getLocale()),
                        'image' => $review->product->getFirstMediaUrl('product_images') ?: null,
                    ],
                ]),
        ]);
    }

    public function notifications(Request $request): Response
    {
        $customer = $request->user('customer');

        return Inertia::render('Account/Notifications', [
            'notifications' => $customer->notifications()->limit(50)->get()->map(fn ($notification) => [
                'id' => $notification->id,
                'type' => class_basename($notification->type),
                'data' => $notification->data,
                'read_at' => $notification->read_at?->toDateTimeString(),
                'created_at' => $notification->created_at?->diffForHumans(),
            ]),
        ]);
    }

    public function markNotificationsRead(Request $request): RedirectResponse
    {
        $request->user('customer')->unreadNotifications->markAsRead();

        return back();
    }

    /**
     * Cookie-backed rather than a table — Section 24 defines no
     * recently_viewed table, and the list has to survive a guest session
     * that later signs in. See App\Support\RecentlyViewed.
     */
    public function recentlyViewed(Request $request): Response
    {
        $ids = RecentlyViewed::ids($request);

        $products = Product::query()
            ->whereIn('id', $ids)
            ->where('status', true)
            ->with(['media', 'categories', 'variants.attributeValues.attribute'])
            ->withCount(['reviews' => fn ($q) => $q->where('status', ReviewStatus::Approved->value)])
            ->withAvg(['reviews as reviews_avg_rating' => fn ($q) => $q->where('status', ReviewStatus::Approved->value)], 'rating')
            ->get()
            ->sortBy(fn (Product $product) => array_search($product->id, $ids, true))
            ->map(fn (Product $product) => ProductPresenter::card($product))
            ->values();

        return Inertia::render('Account/RecentlyViewed', ['products' => $products]);
    }

    /**
     * @return array<string, mixed>
     */
    private function orderRow(Order $order): array
    {
        $first = $order->items->first();

        return [
            'order_number' => $order->order_number,
            'status' => $order->customer_status->value,
            'total' => (float) $order->total,
            'placed_at' => $order->created_at?->toDateString(),
            'product_name' => $first?->product_name_snapshot,
            'item_count' => $order->items->count(),
        ];
    }
}
