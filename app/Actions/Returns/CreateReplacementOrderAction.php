<?php

namespace App\Actions\Returns;

use App\Actions\Checkout\CreateOrderAction;
use App\Enums\OrderSource;
use App\Enums\ReturnStatus;
use App\Models\Employee;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Services\Shipping\ShippingRateResolver;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The customer sends an item back and receives a different one.
 *
 * Shape: a NEW order linked to the original by `replaces_order_id`. The
 * original is Delivered — stock deducted, payment collected, treasury
 * written — so rewriting it would destroy the record of a real sale. The
 * incoming item genuinely is a return, so the existing return chain
 * handles what comes back and this handles what goes out.
 *
 * Pricing (the business rule, confirmed by the client): the customer
 * pays the shipping either way, plus the difference if they trade up.
 * The value of what they returned becomes a credit:
 *
 *   same price   100 → 100 : subtotal 100, credit 100, ship 50 = 50
 *   trade up     100 → 200 : subtotal 200, credit 100, ship 50 = 150
 *   trade down   100 →  80 : subtotal  80, credit  80, ship 50 = 50
 *
 * The credit is applied as `discount_amount` and the line items keep
 * their real `unit_price`, so `total = subtotal − discount + shipping`
 * still holds and nothing downstream needs a special case. Zeroing
 * `unit_price` instead would make `subtotal` 0, which short-circuits the
 * discount-share guard in ConfirmDeliveryResultAction::dueForKeptItems()
 * and would ask the customer for money on a partial return of a free
 * replacement — besides printing worthless goods on the pick list and
 * the shipping label.
 *
 * Capped at the outgoing subtotal so a trade-down is never negative. The
 * customer is not refunded the difference on a cheaper swap.
 */
class CreateReplacementOrderAction
{
    public function __construct(
        private readonly CreateOrderAction $createOrder,
        private readonly ShippingRateResolver $shippingRates,
    ) {}

    /**
     * What the replacement would cost the customer, without creating it —
     * the preview on the return screen. Priced the way execute() will price
     * it: the outgoing item at its current price, shipping from the rate
     * for the original order's address (the replacement goes to the same
     * place), and the returned goods as the credit.
     *
     * `shipping` is null when no rate covers that address; execute() would
     * refuse the same order, so the screen can say so before anyone clicks.
     *
     * @param  array<int, array{product_variant_id: int, quantity: int}>  $items
     * @return array{returned_value: float, subtotal: float, credit: float, difference: float, shipping: float|null, total: float|null}
     */
    public function quote(OrderReturn $return, array $items): array
    {
        $variants = ProductVariant::query()->whereIn('id', array_column($items, 'product_variant_id'))->get()->keyBy('id');

        $subtotal = round(array_sum(array_map(
            fn (array $item) => (float) $variants->get($item['product_variant_id'])?->effectivePrice() * $item['quantity'],
            $items,
        )), 2);

        $original = $return->order;
        $rate = $this->shippingRates->resolve(
            $original->shipping_governorate_id,
            $original->shipping_city_id,
            $original->shipping_district_id,
            $original->shipping_area_id,
        );

        // Same free-shipping threshold rule CreateOrderAction applies.
        $shipping = $rate === null
            ? null
            : ($rate->free_shipping_threshold !== null && $subtotal >= (float) $rate->free_shipping_threshold ? 0.0 : (float) $rate->price);

        $price = $this->price($this->returnedValue($return), $subtotal, $shipping ?? 0.0);

        return [
            'returned_value' => $this->returnedValue($return),
            'subtotal' => $subtotal,
            'credit' => $price['credit'],
            'difference' => round($subtotal - $price['credit'], 2),
            'shipping' => $shipping,
            'total' => $shipping === null ? null : $price['total'],
        ];
    }

    /**
     * @param  array<int, array{product_variant_id: int, quantity: int}>  $items  what goes out
     */
    public function execute(OrderReturn $return, array $items, Employee $employee): Order
    {
        return DB::transaction(function () use ($return, $items, $employee) {
            $return = OrderReturn::query()->lockForUpdate()->findOrFail($return->id);

            // Only once the goods are back and checked: a replacement is the
            // alternative to the refund, the last step of the return. Earlier
            // and nothing has come back yet; later and a refund may already
            // have been paid.
            if ($return->status !== ReturnStatus::Inspected) {
                throw new RuntimeException(__('Only a received return can be replaced.'));
            }

            if ($return->order->replaces_order_id !== null) {
                throw new RuntimeException(__('A replacement cannot itself be replaced.'));
            }

            $original = $return->order;
            $warehouse = Warehouse::main();

            if ($warehouse === null) {
                throw new RuntimeException(__('No active warehouse to ship a replacement from.'));
            }

            // Priced, reserved and addressed exactly like any other order
            // — CreateOrderAction is not modified and gets no replacement
            // flag, because a zero-total escape hatch inside it would
            // eventually be reachable from the storefront.
            $order = $this->createOrder->execute(
                $original->customer,
                $items,
                $warehouse,
                $original->shipping_governorate_id,
                $original->shipping_city_id,
                $original->shipping_district_id,
                $original->shipping_area_id,
                $original->shipping_address_line,
                $original->shipping_recipient_name,
                $original->shipping_phone,
                OrderSource::CustomerService,
                $employee,
            );

            $price = $this->price($this->returnedValue($return), (float) $order->subtotal, (float) $order->shipping_amount);
            $total = $price['total'];

            $order->update([
                'replaces_order_id' => $original->id,
                'discount_amount' => $price['credit'],
                'total' => $total,
            ]);

            // CreateOrderAction wrote the payment for the un-credited
            // total; the customer owes the credited one.
            $order->payments()->latest('id')->firstOrFail()->update(['amount' => $total]);

            // The return leaves the queue without RefundReturnAction ever
            // running, which is correct: no money is refunded, so the
            // original order's payment_status must NOT become Refunded.
            // This is also the first code path to write Completed.
            $return->update(['status' => ReturnStatus::Completed]);

            return $order->fresh();
        });
    }

    /**
     * The one pricing rule both quote() and execute() use: the returned
     * goods are credited up to the outgoing subtotal (never below zero on a
     * trade-down), and the customer pays the difference plus shipping.
     *
     * @return array{credit: float, total: float}
     */
    private function price(float $returnedValue, float $subtotal, float $shipping): array
    {
        $credit = min($returnedValue, $subtotal);

        return [
            'credit' => $credit,
            'total' => round($subtotal - $credit + $shipping, 2),
        ];
    }

    /**
     * What the customer is sending back, at the price they paid for it.
     */
    private function returnedValue(OrderReturn $return): float
    {
        return round(
            $return->items->reduce(
                fn (float $sum, $item) => $sum + (float) $item->orderItem->unit_price * $item->quantity,
                0.0,
            ),
            2,
        );
    }
}
