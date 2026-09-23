<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\OrderTimeline;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Guest order tracking — Anvogue's order-tracking.html, in scope by
 * Question 8: a public, no-login lookup by order number + email. The
 * template's generic progress bar is replaced by the real five-stage
 * customer timeline plus the Postponed / Cancelled / Returned states
 * (Section 03), assembled by App\Support\OrderTimeline.
 */
class OrderTrackingController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('OrderTracking/Index', ['order' => null, 'timeline' => null]);
    }

    public function show(Request $request): Response
    {
        $data = $request->validate([
            'order_number' => ['required', 'numeric'],
            // Still named `email`, but it also accepts the phone the order was
            // placed with: checkout's email is optional, so for an email-less
            // guest the phone is the only other identifier they have.
            'email' => ['required', 'string', 'max:255'],
        ]);

        // Order numbers are sequential from 1001 and emails are guessable
        // — without a limit this endpoint is an order-enumeration oracle.
        $key = 'order-tracking:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 10)) {
            throw ValidationException::withMessages([
                'order_number' => __('Too many lookups. Please try again in a minute.'),
            ]);
        }
        RateLimiter::hit($key, 60);

        $order = Order::query()
            ->where('order_number', $data['order_number'])
            ->where(fn ($q) => $q
                ->where('shipping_phone', $data['email'])
                ->orWhereHas('customer', fn ($c) => $c->where('email', $data['email'])))
            ->with(['items', 'statusHistory'])
            ->first();

        if ($order === null) {
            throw ValidationException::withMessages([
                'order_number' => __('We couldn\'t find an order with that number and email or phone number.'),
            ]);
        }

        return Inertia::render('OrderTracking/Index', [
            'order' => [
                'order_number' => $order->order_number,
                'status' => $order->customer_status->value,
                'placed_at' => $order->created_at?->toDateString(),
                'total' => (float) $order->total,
                'recipient_name' => $order->shipping_recipient_name,
                'items' => $order->items->map(fn ($item) => [
                    'name' => $item->product_name_snapshot,
                    'sku' => $item->variant_sku_snapshot,
                    'quantity' => $item->quantity,
                    'subtotal' => (float) $item->subtotal,
                ]),
            ],
            'timeline' => OrderTimeline::for($order),
        ]);
    }
}
