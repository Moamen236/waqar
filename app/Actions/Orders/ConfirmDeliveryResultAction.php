<?php

namespace App\Actions\Orders;

use App\Enums\CollectedMethod;
use App\Enums\CollectionType;
use App\Enums\CustomerOrderStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\TreasuryTransactionType;
use App\Models\Employee;
use App\Models\Order;
use App\Models\Treasury;
use App\Services\Inventory\InventoryService;
use App\Services\Treasury\TreasuryService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Accounting confirms the delivery result (Section 03, 09) — the only
 * place physical stock is deducted (Section 07's central rule) and the
 * only place a COD collection becomes a treasury transaction. Three
 * distinct outcomes, three methods rather than one branchy execute() —
 * matches how /admin/accounting/{order} will actually present this
 * (three separate confirmations, not one dropdown).
 */
class ConfirmDeliveryResultAction
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly TreasuryService $treasury,
    ) {}

    /**
     * Delivered → deduct stock, collect cash (Section 03's status table).
     */
    public function confirmDelivered(
        Order $order,
        Employee $accountant,
        Treasury $treasury,
        CollectedMethod $collectedMethod,
        ?float $collectedAmount = null,
    ): Order {
        return DB::transaction(function () use ($order, $accountant, $treasury, $collectedMethod, $collectedAmount) {
            $order = $this->lockAssignedOrder($order);
            // The customer hands the courier the gross total; the courier
            // keeps the shipping as their fee and hands over the goods
            // money. That net figure is what Accounting actually banks.
            $collectedAmount ??= $order->netDueToTreasury();

            foreach ($order->items()->with('productVariant.product')->get() as $item) {
                $variant = $item->productVariant;
                if (! $variant->product->inventory_tracking_enabled) {
                    continue;
                }
                $warehouse = $this->inventory->warehouseForReservation($order, $variant);
                if ($warehouse !== null) {
                    $this->inventory->deduct($variant, $warehouse, $item->quantity, $order, $accountant);
                }
            }

            $payment = $order->payments()->latest('id')->firstOrFail();
            // Short collection → the goods still went out, but the order
            // keeps a balance Accounting has to come back for. Measured
            // against the net owed, never payment->amount: that stays
            // gross (what the customer paid) for the invoice's sake, so
            // comparing against it would mark every settled order short
            // by exactly the shipping.
            $paymentStatus = $collectedAmount < $order->netOfShipping((float) $payment->amount)
                ? PaymentStatus::PartiallyCollected
                : PaymentStatus::Collected;

            $payment->update([
                'status' => $paymentStatus,
                'collection_type' => $paymentStatus === PaymentStatus::Collected
                    ? CollectionType::Full
                    : CollectionType::Partial,
                'collected_method' => $collectedMethod,
                'collected_amount' => $collectedAmount,
                'collected_at' => now(),
            ]);

            // Zero is reachable without being an error: a same-price
            // replacement owes shipping only, and a 100%-coupon order
            // owes nothing at all. Neither should take a treasury lock to
            // write a row worth nothing.
            if ($collectedAmount > 0) {
                $this->treasury->recordTransaction(
                    $treasury, TreasuryTransactionType::Income, $collectedAmount, $accountant, $payment,
                    "COD collected for order #{$order->order_number}",
                );
            }

            return $this->transition(
                $order, OrderStatus::Delivered, CustomerOrderStatus::Delivered, $paymentStatus, $accountant,
                $paymentStatus === PaymentStatus::Collected
                    ? 'Delivered, cash collected'
                    : 'Delivered, part of the cash collected',
            );
        });
    }

    /**
     * Returned (at delivery) → release reservation only, stock never left
     * the warehouse (Section 03).
     */
    public function confirmReturnedAtDelivery(Order $order, Employee $accountant): Order
    {
        return DB::transaction(function () use ($order, $accountant) {
            $order = $this->lockAssignedOrder($order);

            foreach ($order->items()->with('productVariant.product')->get() as $item) {
                $variant = $item->productVariant;
                if (! $variant->product->inventory_tracking_enabled) {
                    continue;
                }
                $warehouse = $this->inventory->warehouseForReservation($order, $variant);
                if ($warehouse !== null) {
                    $this->inventory->release($variant, $warehouse, $item->quantity, $order, $accountant);
                }
            }

            $order->payments()->latest('id')->firstOrFail()->update(['status' => PaymentStatus::NotCollected]);

            return $this->transition($order, OrderStatus::Returned, CustomerOrderStatus::Returned, PaymentStatus::NotCollected, $accountant, 'Refused at delivery');
        });
    }

    /**
     * Partially Returned → deduct only the kept quantity, return the rest
     * (Section 03). $keptQuantities maps order_item_id => quantity kept.
     */
    public function confirmPartiallyReturned(
        Order $order,
        Employee $accountant,
        Treasury $treasury,
        CollectedMethod $collectedMethod,
        float $collectedAmount,
        array $keptQuantities,
    ): Order {
        return DB::transaction(function () use ($order, $accountant, $treasury, $collectedMethod, $collectedAmount, $keptQuantities) {
            $order = $this->lockAssignedOrder($order);

            foreach ($order->items()->with('productVariant.product')->get() as $item) {
                $variant = $item->productVariant;
                if (! $variant->product->inventory_tracking_enabled) {
                    continue;
                }

                $warehouse = $this->inventory->warehouseForReservation($order, $variant);
                if ($warehouse === null) {
                    continue;
                }

                $kept = $keptQuantities[$item->id] ?? 0;
                $returned = $item->quantity - $kept;

                if ($kept > 0) {
                    $this->inventory->deduct($variant, $warehouse, $kept, $order, $accountant);
                }
                if ($returned > 0) {
                    $this->inventory->release($variant, $warehouse, $returned, $order, $accountant);
                }
            }

            $payment = $order->payments()->latest('id')->firstOrFail();
            // What the kept goods are actually owed — recomputed here
            // rather than trusted from the caller, so a short collection
            // is measured against a figure this Action owns.
            $due = $this->dueForKeptItems($order, $keptQuantities);
            $paymentStatus = $collectedAmount < $order->netOfShipping($due)
                ? PaymentStatus::PartiallyCollected
                : PaymentStatus::Collected;

            $payment->update([
                'amount' => $due,
                'status' => $paymentStatus,
                'collection_type' => CollectionType::Partial,
                'collected_method' => $collectedMethod,
                'collected_amount' => $collectedAmount,
                'collected_at' => now(),
            ]);

            if ($collectedAmount > 0) {
                $this->treasury->recordTransaction(
                    $treasury, TreasuryTransactionType::Income, $collectedAmount, $accountant, $payment,
                    "Partial COD collected for order #{$order->order_number}",
                );
            }

            return $this->transition(
                $order, OrderStatus::PartiallyReturned, CustomerOrderStatus::PartiallyReturned, $paymentStatus, $accountant,
                $paymentStatus === PaymentStatus::Collected
                    ? 'Partially returned at delivery'
                    : 'Partially returned at delivery, part of the cash collected',
            );
        });
    }

    /**
     * A partial return is owed for what the customer kept: those lines at
     * their sold price, less the share of any whole-order discount that
     * sat on them (pro-rata — the returned lines take their share back
     * with them), plus the delivery charge, which was earned either way.
     *
     * This is the GROSS figure — what the customer hands the courier —
     * and it is what payment->amount is rewritten to. The courier keeps
     * the shipping out of it; callers take Order::netOfShipping() to get
     * the part that reaches the treasury.
     *
     * @param  array<int, int>  $keptQuantities  order_item_id => quantity kept
     */
    private function dueForKeptItems(Order $order, array $keptQuantities): float
    {
        $subtotal = (float) $order->subtotal;

        $keptValue = $order->items->reduce(
            fn (float $sum, $item) => $sum + (float) $item->unit_price * ($keptQuantities[$item->id] ?? 0),
            0.0,
        );

        $discountShare = $subtotal > 0
            ? (float) $order->discount_amount * $keptValue / $subtotal
            : 0.0;

        return max(0.0, round($keptValue - $discountShare + (float) $order->shipping_amount, 2));
    }

    /**
     * A later instalment against an order whose courier came back short.
     * Nothing about the goods changes here — the stock moved when the
     * delivery result was confirmed; this only moves money.
     *
     * @throws RuntimeException when the order owes nothing
     */
    public function collectBalance(
        Order $order,
        Employee $accountant,
        Treasury $treasury,
        CollectedMethod $collectedMethod,
        float $amount,
    ): Order {
        return DB::transaction(function () use ($order, $accountant, $treasury, $collectedMethod, $amount) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($order->payment_status !== PaymentStatus::PartiallyCollected) {
                throw new RuntimeException("Order #{$order->order_number} has no outstanding balance to collect.");
            }

            $payment = $order->payments()->latest('id')->firstOrFail();
            // Measured net: collected_amount only ever holds what reached
            // the treasury, so the shipping the courier kept must come off
            // payment->amount before the two are compared.
            $netDue = $order->netOfShipping((float) $payment->amount);
            $outstanding = round($netDue - (float) $payment->collected_amount, 2);

            if ($amount > $outstanding) {
                throw new RuntimeException("Order #{$order->order_number} only owes {$outstanding}.");
            }

            $collected = round((float) $payment->collected_amount + $amount, 2);
            $settled = $collected >= $netDue;

            $payment->update([
                'collected_amount' => $collected,
                'collected_method' => $collectedMethod,
                'collected_at' => now(),
                'status' => $settled ? PaymentStatus::Collected : PaymentStatus::PartiallyCollected,
            ]);

            $this->treasury->recordTransaction(
                $treasury, TreasuryTransactionType::Income, $amount, $accountant, $payment,
                "Balance collected for order #{$order->order_number}",
            );

            // The order's own status doesn't move — it was already
            // Delivered or Partially Returned — only what it still owes.
            $order->update(['payment_status' => $settled ? PaymentStatus::Collected : PaymentStatus::PartiallyCollected]);

            return $order->fresh();
        });
    }

    private function lockAssignedOrder(Order $order): Order
    {
        $order = Order::query()->lockForUpdate()->findOrFail($order->id);

        if (! in_array($order->status, [OrderStatus::Assigned, OrderStatus::OutForDelivery], true)) {
            throw new RuntimeException("Order #{$order->order_number} isn't out for delivery — can't confirm a delivery result from status {$order->status->value}.");
        }

        return $order;
    }

    private function transition(
        Order $order,
        OrderStatus $to,
        CustomerOrderStatus $customerStatus,
        PaymentStatus $paymentStatus,
        Employee $accountant,
        string $reason,
    ): Order {
        $from = $order->status;

        $order->update([
            'status' => $to,
            'customer_status' => $customerStatus,
            'payment_status' => $paymentStatus,
        ]);

        $order->statusHistory()->create([
            'from_status' => $from->value,
            'to_status' => $to->value,
            'changed_by' => $accountant->id,
            'reason' => $reason,
        ]);

        return $order->fresh();
    }
}
