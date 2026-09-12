<?php

namespace App\Actions\Checkout;

use App\Enums\CustomerOrderStatus;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Services\Checkout\CouponService;
use App\Services\Inventory\InventoryService;
use App\Services\Shipping\ShippingRateResolver;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Shared by storefront checkout and Customer Service's /admin/orders/create
 * (Section 03, Flow 1 and Flow 2 converge here — same validation, same
 * server-side pricing, same COD payment record, same inventory
 * reservation, regardless of who is placing the order). Every price is
 * computed here from stored records; nothing about the total, shipping
 * amount, or discount is ever trusted from the caller.
 */
class CreateOrderAction
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly ShippingRateResolver $shippingRates,
        private readonly CouponService $coupons,
    ) {}

    /**
     * @param  array<int, array{product_variant_id: int, quantity: int}>  $items
     */
    public function execute(
        Customer $customer,
        array $items,
        Warehouse $warehouse,
        int $governorateId,
        int $cityId,
        ?int $districtId,
        int $areaId,
        string $addressLine,
        string $recipientName,
        string $phone,
        OrderSource $orderSource = OrderSource::Website,
        ?Employee $createdByEmployee = null,
        ?string $couponCode = null,
    ): Order {
        if (empty($items)) {
            throw new InvalidArgumentException('An order needs at least one item.');
        }

        if ($orderSource === OrderSource::CustomerService && $createdByEmployee === null) {
            throw new InvalidArgumentException('Customer Service orders must record which employee created them.');
        }

        return DB::transaction(function () use (
            $customer, $items, $warehouse, $governorateId, $cityId, $districtId, $areaId,
            $addressLine, $recipientName, $phone, $orderSource, $createdByEmployee, $couponCode,
        ) {
            $lines = $this->priceLines($items);
            $subtotal = array_sum(array_column($lines, 'subtotal'));

            $shippingRate = $this->shippingRates->resolve($governorateId, $cityId, $districtId, $areaId);
            if ($shippingRate === null) {
                throw new RuntimeException('No shipping rate is configured for this address.');
            }
            $shippingAmount = (float) $shippingRate->price;
            if ($shippingRate->free_shipping_threshold !== null && $subtotal >= (float) $shippingRate->free_shipping_threshold) {
                $shippingAmount = 0.0;
            }

            $coupon = null;
            $discountAmount = 0.0;
            if ($couponCode !== null) {
                $coupon = $this->coupons->resolve($couponCode, $customer, $subtotal);
                $discountAmount = $this->coupons->discountFor($coupon, $subtotal);
                if ($coupon->type === 'free_shipping') {
                    $shippingAmount = 0.0;
                }
            }

            $total = $subtotal - $discountAmount + $shippingAmount;

            $order = Order::create([
                'customer_id' => $customer->id,
                'created_by_employee_id' => $createdByEmployee?->id,
                'order_source' => $orderSource,
                'status' => OrderStatus::New,
                'customer_status' => CustomerOrderStatus::OrderReceived,
                'payment_status' => PaymentStatus::Pending,
                'subtotal' => $subtotal,
                'discount_amount' => $discountAmount,
                'shipping_amount' => $shippingAmount,
                'total' => $total,
                'coupon_id' => $coupon?->id,
                'shipping_recipient_name' => $recipientName,
                'shipping_phone' => $phone,
                'shipping_governorate_id' => $governorateId,
                'shipping_city_id' => $cityId,
                'shipping_district_id' => $districtId,
                'shipping_area_id' => $areaId,
                'shipping_address_line' => $addressLine,
            ]);

            foreach ($lines as $line) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_variant_id' => $line['variant']->id,
                    'product_name_snapshot' => $line['variant']->product->getTranslation('name', app()->getLocale()),
                    'variant_sku_snapshot' => $line['variant']->sku,
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'subtotal' => $line['subtotal'],
                ]);

                // Advertisement products bypass the stock check entirely
                // (Section 05) — inventory_tracking_enabled is what the
                // hot path actually branches on.
                if ($line['variant']->product->inventory_tracking_enabled) {
                    $this->inventory->reserve($line['variant'], $warehouse, $line['quantity'], $order, $createdByEmployee);
                }
            }

            Payment::create([
                'order_id' => $order->id,
                'amount' => $total,
            ]);

            $order->statusHistory()->create([
                'from_status' => null,
                'to_status' => OrderStatus::New->value,
                'changed_by' => $createdByEmployee?->id,
                'reason' => 'Order placed',
            ]);

            if ($coupon !== null) {
                $coupon->increment('times_used');
            }

            return $order->fresh(['items', 'payments']);
        });
    }

    /**
     * @param  array<int, array{product_variant_id: int, quantity: int}>  $items
     * @return array<int, array{variant: ProductVariant, quantity: int, unit_price: float, subtotal: float}>
     */
    private function priceLines(array $items): array
    {
        $lines = [];

        foreach ($items as $item) {
            if ($item['quantity'] < 1) {
                throw new InvalidArgumentException('Quantity must be at least 1.');
            }

            $variant = ProductVariant::with('product')->findOrFail($item['product_variant_id']);
            $unitPrice = $variant->effectivePrice();

            $lines[] = [
                'variant' => $variant,
                'quantity' => $item['quantity'],
                'unit_price' => $unitPrice,
                'subtotal' => round($unitPrice * $item['quantity'], 2),
            ];
        }

        return $lines;
    }
}
