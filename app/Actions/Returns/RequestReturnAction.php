<?php

namespace App\Actions\Returns;

use App\Enums\OrderStatus;
use App\Enums\ReturnStage;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderReturn;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Customer-initiated, post-delivery (Section 12): Delivered → Return
 * Requested → Approved → Received → Inspected → Refunded.
 */
class RequestReturnAction
{
    /**
     * @param  array<int, array{order_item_id: int, quantity: int, reason_id?: int}>  $items
     *                                                                                        a multi-item return can carry a different reason per item (Section 12)
     */
    public function execute(Order $order, Customer $customer, array $items, int $primaryReasonId, ?string $customerNotes = null): OrderReturn
    {
        if ($order->customer_id !== $customer->id) {
            throw new InvalidArgumentException('This order does not belong to this customer.');
        }
        if ($order->status !== OrderStatus::Delivered) {
            throw new InvalidArgumentException('Only a delivered order can be returned this way — an at-delivery refusal is handled by Accounting instead.');
        }
        if (empty($items)) {
            throw new InvalidArgumentException('A return needs at least one item.');
        }

        return DB::transaction(function () use ($order, $customer, $items, $primaryReasonId, $customerNotes) {
            $return = OrderReturn::create([
                'order_id' => $order->id,
                'customer_id' => $customer->id,
                'stage' => ReturnStage::PostDelivery,
                'reason_id' => $primaryReasonId,
                'customer_notes' => $customerNotes,
            ]);

            foreach ($items as $item) {
                $orderItem = $order->items()->findOrFail($item['order_item_id']);
                $return->items()->create([
                    'order_item_id' => $orderItem->id,
                    'product_variant_id' => $orderItem->product_variant_id,
                    'quantity' => $item['quantity'],
                    'reason_id' => $item['reason_id'] ?? $primaryReasonId,
                ]);
            }

            return $return->fresh('items');
        });
    }
}
