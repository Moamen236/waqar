<?php

namespace App\Services\Checkout;

use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use InvalidArgumentException;

/**
 * Coupon validation + discount maths, extracted from Phase 3's
 * CreateOrderAction so the storefront cart can show the customer the same
 * discount the order will actually be created with (Section 08). The
 * Action still calls this itself at order time — the cart's preview is
 * never trusted as input, it's recomputed from the code alone.
 */
class CouponService
{
    /**
     * @throws InvalidArgumentException when the code is unusable — the
     *                                  message is customer-facing.
     */
    public function resolve(string $code, Customer $customer, float $subtotal): Coupon
    {
        $coupon = Coupon::where('code', $code)->where('is_active', true)->first();

        if ($coupon === null) {
            throw new InvalidArgumentException(__('Invalid or inactive coupon code.'));
        }

        $now = now();
        if (($coupon->starts_at && $now->lt($coupon->starts_at)) || ($coupon->ends_at && $now->gt($coupon->ends_at))) {
            throw new InvalidArgumentException(__('This coupon is not currently valid.'));
        }

        if ($coupon->minimum_order_amount !== null && $subtotal < (float) $coupon->minimum_order_amount) {
            throw new InvalidArgumentException(__('Order subtotal does not meet this coupon\'s minimum.'));
        }

        if ($coupon->usage_limit !== null && $coupon->times_used >= $coupon->usage_limit) {
            throw new InvalidArgumentException(__('This coupon has reached its usage limit.'));
        }

        if ($coupon->usage_limit_per_user !== null) {
            $usedByCustomer = Order::where('coupon_id', $coupon->id)->where('customer_id', $customer->id)->count();
            if ($usedByCustomer >= $coupon->usage_limit_per_user) {
                throw new InvalidArgumentException(__('You have already used this coupon the maximum number of times.'));
            }
        }

        return $coupon;
    }

    /**
     * free_shipping discounts nothing off the subtotal — the shipping
     * amount is zeroed separately by whoever is computing the total.
     */
    public function discountFor(Coupon $coupon, float $subtotal): float
    {
        return match ($coupon->type) {
            'percentage' => round($subtotal * ((float) $coupon->value / 100), 2),
            'fixed' => min((float) $coupon->value, $subtotal),
            'free_shipping' => 0.0,
            default => 0.0,
        };
    }
}
