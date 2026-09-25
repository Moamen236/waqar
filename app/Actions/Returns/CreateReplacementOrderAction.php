<?php

namespace App\Actions\Returns;

use App\Actions\Checkout\CreateOrderAction;
use App\Enums\OrderSource;
use App\Enums\ReturnStatus;
use App\Models\Employee;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\Warehouse;
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
    public function __construct(private readonly CreateOrderAction $createOrder) {}

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

            $credit = min($this->returnedValue($return), (float) $order->subtotal);
            $total = round((float) $order->subtotal - $credit + (float) $order->shipping_amount, 2);

            $order->update([
                'replaces_order_id' => $original->id,
                'discount_amount' => $credit,
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
