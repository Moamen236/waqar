<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\ProductVariant;
use App\Services\Cart\CartService;
use App\Support\GeoTree;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Cart — Anvogue's cart.html. The template's free-text "shipping
 * estimator" accordion is replaced by the real Country → Governorate →
 * City → District → Area cascade, which asks the server for the resolved
 * rate (Section 08's resolution, Section 11's most-specific-match
 * fallback) — the customer never types or edits a shipping price.
 */
class CartController extends Controller
{
    public function __construct(private readonly CartService $carts) {}

    public function index(Request $request): Response
    {
        $cart = $this->carts->current($request);

        return Inertia::render('Cart/Index', [
            'cart' => $this->carts->summary(
                $cart,
                customer: $request->user('customer'),
                couponCode: $request->session()->get(CartService::COUPON_KEY),
            ),
            'countries' => GeoTree::countries(),
            // The template's static voucher cards, wired to the real
            // active coupon catalog instead of hard-coded demo codes.
            'vouchers' => Coupon::query()
                ->where('is_active', true)
                ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
                ->orderBy('minimum_order_amount')
                ->limit(3)
                ->get(['code', 'type', 'value', 'minimum_order_amount']),
        ]);
    }

    /**
     * JSON contents for the header's mini-cart drawer — the same summary
     * the cart page renders, without a full Inertia visit.
     */
    public function summary(Request $request): JsonResponse
    {
        return response()->json($this->carts->summary(
            $this->carts->current($request),
            customer: $request->user('customer'),
            couponCode: $request->session()->get(CartService::COUPON_KEY),
        ));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'product_variant_id' => ['required', 'exists:product_variants,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:99'],
        ]);

        $variant = ProductVariant::with('product')->findOrFail($data['product_variant_id']);

        if (! $variant->status || ! $variant->product->status) {
            return back()->with('error', 'That item is no longer available.');
        }

        try {
            $this->carts->add($this->carts->current($request), $variant, (int) $data['quantity']);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Added to your cart.');
    }

    public function update(Request $request, CartItem $item): RedirectResponse
    {
        $this->authorizeItem($request, $item);

        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:0', 'max:99'],
        ]);

        try {
            $this->carts->updateQuantity($item, (int) $data['quantity']);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back();
    }

    public function destroy(Request $request, CartItem $item): RedirectResponse
    {
        $this->authorizeItem($request, $item);

        $item->delete();

        return back()->with('success', 'Item removed.');
    }

    /**
     * The code is stored, not the computed discount — every read
     * re-resolves it against the live cart (CartService::summary), so a
     * coupon that expires or stops qualifying mid-session simply stops
     * applying.
     */
    public function applyCoupon(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:50']]);

        if ($request->user('customer') === null) {
            return back()->with('error', 'Please sign in to use a discount code.');
        }

        $request->session()->put(CartService::COUPON_KEY, $data['code']);

        $summary = $this->carts->summary(
            $this->carts->current($request),
            customer: $request->user('customer'),
            couponCode: $data['code'],
        );

        if ($summary['coupon_error'] !== null) {
            $request->session()->forget(CartService::COUPON_KEY);

            return back()->with('error', $summary['coupon_error']);
        }

        return back()->with('success', 'Discount code applied.');
    }

    public function removeCoupon(Request $request): RedirectResponse
    {
        $request->session()->forget(CartService::COUPON_KEY);

        return back();
    }

    /**
     * A cart item is only ever reachable from the cart that owns it —
     * ids are sequential and guessable, so this is the real boundary,
     * not the fact that the UI never renders someone else's item.
     */
    private function authorizeItem(Request $request, CartItem $item): void
    {
        abort_unless($item->cart_id === $this->carts->current($request)->id, 403);
    }
}
