<?php

namespace App\Services\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\ProductVariant;
use App\Models\WarehouseInventory;
use App\Services\Checkout\CouponService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The storefront cart (spec Section 08). Guest carts are keyed by a
 * session_token, a signed-in customer's by customer_id — exactly one of
 * the two is ever set on a row (Section 24) — and the guest cart merges
 * into the customer's on login, duplicate lines folded together rather
 * than duplicated.
 *
 * No line price is ever stored: `summary()` recomputes every unit price
 * from product_variants on each read, so a price change mid-session is
 * reflected immediately (Section 24's explicit confirmation on
 * cart_items). The applied coupon code lives in the session for the same
 * reason — carts carry no coupon column, and the discount is re-derived
 * from the code every time.
 */
class CartService
{
    /** Session key holding the guest cart token. */
    public const TOKEN_KEY = 'cart_token';

    /** Session key holding the applied coupon code. */
    public const COUPON_KEY = 'cart_coupon';

    public function __construct(private readonly CouponService $coupons) {}

    /**
     * The current request's cart, created on first touch.
     */
    public function current(Request $request): Cart
    {
        $customer = $request->user('customer');

        if ($customer !== null) {
            return Cart::firstOrCreate(['customer_id' => $customer->id]);
        }

        $token = $request->session()->get(self::TOKEN_KEY);
        if (! is_string($token) || $token === '') {
            $token = (string) Str::uuid();
            $request->session()->put(self::TOKEN_KEY, $token);
        }

        return Cart::firstOrCreate(['session_token' => $token]);
    }

    /**
     * Existing line quantities add up rather than replacing each other,
     * and the *combined* quantity is what gets stock-checked — otherwise
     * adding 1 at a time could walk past available stock one request at
     * a time.
     */
    public function add(Cart $cart, ProductVariant $variant, int $quantity): CartItem
    {
        if ($quantity < 1) {
            throw new InvalidArgumentException(__('Quantity must be at least 1.'));
        }

        $item = $cart->items()->where('product_variant_id', $variant->id)->first();
        $newQuantity = ($item->quantity ?? 0) + $quantity;

        $this->assertAvailable($variant, $newQuantity);

        if ($item === null) {
            return $cart->items()->create([
                'product_variant_id' => $variant->id,
                'quantity' => $newQuantity,
            ]);
        }

        $item->update(['quantity' => $newQuantity]);

        return $item;
    }

    public function updateQuantity(CartItem $item, int $quantity): void
    {
        if ($quantity < 1) {
            $item->delete();

            return;
        }

        $this->assertAvailable($item->productVariant, $quantity);

        $item->update(['quantity' => $quantity]);
    }

    /**
     * Login handover (Section 08). The guest cart's lines move onto the
     * customer's cart — a line for a variant they already had is summed,
     * not duplicated — and the now-empty guest cart is dropped.
     */
    public function mergeGuestCart(Request $request, Customer $customer): void
    {
        $token = $request->session()->pull(self::TOKEN_KEY);
        if (! is_string($token) || $token === '') {
            return;
        }

        $guestCart = Cart::where('session_token', $token)->with('items')->first();
        if ($guestCart === null) {
            return;
        }

        $customerCart = Cart::firstOrCreate(['customer_id' => $customer->id]);

        foreach ($guestCart->items as $guestItem) {
            $existing = $customerCart->items()->where('product_variant_id', $guestItem->product_variant_id)->first();

            if ($existing === null) {
                $customerCart->items()->create([
                    'product_variant_id' => $guestItem->product_variant_id,
                    'quantity' => $guestItem->quantity,
                ]);

                continue;
            }

            $existing->update(['quantity' => $existing->quantity + $guestItem->quantity]);
        }

        $guestCart->items()->delete();
        $guestCart->delete();
    }

    /**
     * Live-priced cart contents plus the Section 08 totals
     * (Subtotal − Discount + Shipping = Grand Total, no tax). `$shipping`
     * is null until the customer has picked an address far enough down
     * the geo cascade for ShippingRateResolver to return a rate — the
     * customer never types or edits it.
     *
     * @return array{
     *     items: array<int, array{id: int, variant_id: int, product_id: int, slug: string, name: string, sku: string, image: string|null, options: string, unit_price: float, quantity: int, subtotal: float, available: int|null}>,
     *     subtotal: float, discount: float, shipping: float|null, total: float|null,
     *     coupon: array{code: string, type: string, value: float}|null, coupon_error: string|null, count: int
     * }
     */
    public function summary(Cart $cart, ?float $shipping = null, ?Customer $customer = null, ?string $couponCode = null): array
    {
        $cart->loadMissing(['items.productVariant.product.media', 'items.productVariant.attributeValues.attribute']);

        $items = [];
        $subtotal = 0.0;

        foreach ($cart->items as $item) {
            $variant = $item->productVariant;

            $unitPrice = $variant->effectivePrice();
            $lineTotal = round($unitPrice * $item->quantity, 2);
            $subtotal += $lineTotal;

            $items[] = [
                'id' => $item->id,
                'variant_id' => $variant->id,
                'product_id' => $variant->product->id,
                'slug' => (string) $variant->product->slug,
                'name' => $variant->product->getTranslation('name', app()->getLocale()),
                'sku' => (string) $variant->sku,
                'image' => $variant->product->getFirstMediaUrl('product_images') ?: null,
                'options' => $variant->attributeValues
                    ->map(fn ($value) => $value->getTranslation('value', app()->getLocale()))
                    ->implode(' / '),
                'unit_price' => $unitPrice,
                'quantity' => $item->quantity,
                'subtotal' => $lineTotal,
                'available' => $variant->product->inventory_tracking_enabled ? $this->availableFor($variant) : null,
            ];
        }

        $subtotal = round($subtotal, 2);
        $discount = 0.0;
        $coupon = null;
        $couponError = null;

        if ($couponCode !== null && $couponCode !== '' && $customer !== null) {
            try {
                $resolved = $this->coupons->resolve($couponCode, $customer, $subtotal);
                $discount = $this->coupons->discountFor($resolved, $subtotal);
                if ($resolved->type === 'free_shipping') {
                    $shipping = 0.0;
                }
                $coupon = [
                    'code' => (string) $resolved->code,
                    'type' => (string) $resolved->type,
                    'value' => (float) $resolved->value,
                ];
            } catch (InvalidArgumentException $e) {
                $couponError = $e->getMessage();
            }
        }

        return [
            'items' => $items,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'shipping' => $shipping,
            'total' => $shipping === null ? null : round($subtotal - $discount + $shipping, 2),
            'coupon' => $coupon,
            'coupon_error' => $couponError,
            'count' => array_sum(array_column($items, 'quantity')),
        ];
    }

    /**
     * Available stock across every warehouse (quantity − reserved). The
     * cart only *previews* this; the authoritative check is
     * InventoryService::reserve()'s row-locked one at order time, which
     * is what actually prevents overselling under concurrency.
     */
    public function availableFor(ProductVariant $variant): int
    {
        return (int) WarehouseInventory::query()
            ->where('product_variant_id', $variant->id)
            ->sum(DB::raw('quantity - reserved_quantity'));
    }

    private function assertAvailable(ProductVariant $variant, int $quantity): void
    {
        // Advertisement products are never stock-checked (Section 05) —
        // same bypass CreateOrderAction applies at order time.
        if (! $variant->product->inventory_tracking_enabled) {
            return;
        }

        $available = $this->availableFor($variant);

        if ($available < $quantity) {
            throw new InvalidArgumentException(
                $available === 0
                    ? __('This item is out of stock.')
                    : __('Only :count left in stock.', ['count' => $available])
            );
        }
    }
}
