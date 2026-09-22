<?php

namespace App\Reports\Concerns;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;

/**
 * The money definitions every sales and finance report shares (spec A.4),
 * written once as SQL fragments.
 *
 * Post-Phase C, `payments.collected_amount` is **already net of shipping**
 * — the courier keeps their fee at the door and only the goods money
 * reaches a treasury. Two consequences this trait exists to enforce:
 *
 * - Recognised revenue is `SUM(collected_amount)` with **no** shipping
 *   subtraction. Subtracting again double-counts.
 * - Nothing is ever compared against the gross `payments.amount`, which is
 *   kept gross for the invoice. Comparing the two marks every correctly
 *   settled order short by exactly its shipping — the original Phase C bug,
 *   and just as easy to reintroduce in a report as it was in an Action.
 */
trait MeasuresSales
{
    /**
     * Statuses at which an order counts as sold. `Partially Returned`
     * belongs here: part of it was delivered and banked, and excluding it
     * would under-report revenue that is sitting in a treasury.
     *
     * @return list<string>
     */
    protected function soldStatuses(): array
    {
        return [OrderStatus::Delivered->value, OrderStatus::PartiallyReturned->value];
    }

    /**
     * @return list<string>
     */
    protected function bankedPaymentStatuses(): array
    {
        return [PaymentStatus::Collected->value, PaymentStatus::PartiallyCollected->value];
    }

    /**
     * Cash actually banked against an order, correlated to `orders.id`.
     *
     * Already net of shipping — see the class docblock.
     */
    protected function collectedSql(string $orderTable = 'orders'): string
    {
        $statuses = $this->quotedList($this->bankedPaymentStatuses());

        return "(SELECT COALESCE(SUM(p.collected_amount), 0) FROM payments p
                 WHERE p.order_id = {$orderTable}.id AND p.status IN ({$statuses}))";
    }

    /**
     * Completed refunds against an order, net of the return shipping fee
     * the customer bore — `refunds.net_amount` is that figure, stored.
     */
    protected function refundedSql(string $orderTable = 'orders'): string
    {
        return "(SELECT COALESCE(SUM(r.net_amount), 0) FROM refunds r
                 WHERE r.order_id = {$orderTable}.id AND r.status = 'completed')";
    }

    /**
     * Total pieces on an order. A correlated subquery rather than a join:
     * joining `order_items` multiplies the order row by its line count and
     * silently inflates every money column beside it.
     */
    protected function unitsSql(string $orderTable = 'orders'): string
    {
        return "(SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi
                 WHERE oi.order_id = {$orderTable}.id)";
    }

    /**
     * Unit cost for a variant, falling back to the product's own cost —
     * `product_variants.cost_price` is nullable and means "same as the
     * product" (spec Section 24).
     */
    protected function unitCostSql(string $variantTable = 'product_variants', string $productTable = 'products'): string
    {
        return "COALESCE({$variantTable}.cost_price, {$productTable}.cost_price, 0)";
    }

    /**
     * @param  list<string>  $values
     */
    protected function quotedList(array $values): string
    {
        // Enum-case values only — never user input.
        return implode(', ', array_map(fn (string $value) => "'".$value."'", $values));
    }

    protected function soldStatusList(): string
    {
        return $this->quotedList($this->soldStatuses());
    }
}
