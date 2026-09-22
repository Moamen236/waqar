<?php

namespace App\Services\Treasury;

use App\Enums\OrderStatus;
use App\Enums\TreasuryTransactionType;
use App\Models\Employee;
use App\Models\Order;
use App\Models\ShippingCompany;
use App\Models\ShippingCompanyStatement;
use App\Models\Treasury;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Section 11's shipping-company reconciliation: a periodic statement of
 * what a company delivered, what it owes in delivery/return fees, and
 * what's actually been transferred back — settled via
 * TreasuryService::recordTransaction() the same way a COD collection is.
 */
class ReconciliationService
{
    public function __construct(private readonly TreasuryService $treasury) {}

    /**
     * Computes a draft statement from delivered/returned orders in the
     * period — the employee can still adjust before saving (Accounting
     * knows about discrepancies this can't see, e.g. a company dispute).
     *
     * @return array<string, mixed>
     */
    public function buildDraft(ShippingCompany $company, CarbonInterface $periodStart, CarbonInterface $periodEnd): array
    {
        $orders = Order::query()
            ->where('shipping_company_id', $company->id)
            ->whereIn('status', [OrderStatus::Delivered, OrderStatus::PartiallyReturned, OrderStatus::Returned])
            ->whereHas('statusHistory', function ($query) use ($periodStart, $periodEnd) {
                $query->whereIn('to_status', [OrderStatus::Delivered->value, OrderStatus::PartiallyReturned->value, OrderStatus::Returned->value])
                    ->whereBetween('created_at', [$periodStart, $periodEnd]);
            })
            ->with('payments')
            ->get();

        $deliveredCount = $orders->whereIn('status', [OrderStatus::Delivered, OrderStatus::PartiallyReturned])->count();
        $returnedCount = $orders->where('status', OrderStatus::Returned)->count();

        $expectedCollection = $orders->sum(fn (Order $order) => (float) $order->payments->sum('collected_amount'));

        // The courier takes their fee out of the cash at the door — the
        // shipping the customer pays on a delivery, and the same fee on a
        // refused one — so collected_amount is ALREADY net of everything
        // they are owed. Subtracting a fee here would charge them twice.
        // shipping_companies.delivery_fee / return_fee are dead columns
        // kept only so historical statements still read back.
        $deliveryFeesOwed = 0.0;
        $returnFeesOwed = 0.0;
        $net = round($expectedCollection - $deliveryFeesOwed - $returnFeesOwed, 2);

        return [
            'delivered_orders_count' => $deliveredCount,
            'expected_customer_collection' => $expectedCollection,
            'delivery_fees_owed' => $deliveryFeesOwed,
            'return_fees_owed' => $returnFeesOwed,
            'net_amount_expected' => $net,
            'transferred_amount' => 0,
            'outstanding_amount' => $net,
        ];
    }

    /**
     * Records the company's actual transfer into a treasury, updating the
     * statement's running totals — a statement can be settled over
     * multiple partial transfers, so this doesn't assume one shot.
     */
    public function recordTransfer(
        ShippingCompanyStatement $statement,
        float $amount,
        Treasury $treasury,
        Employee $employee,
    ): ShippingCompanyStatement {
        return DB::transaction(function () use ($statement, $amount, $treasury, $employee) {
            $statement = ShippingCompanyStatement::query()->lockForUpdate()->findOrFail($statement->id);

            $transferred = (float) $statement->transferred_amount + $amount;
            $outstanding = round((float) $statement->net_amount_expected - $transferred, 2);

            $statement->update([
                'transferred_amount' => $transferred,
                'outstanding_amount' => $outstanding,
                // Only a balanced statement is settled. A NEGATIVE
                // outstanding means we owe the company, not that they
                // have finished paying us — closing it here would leave
                // them silently unpaid with no row saying so.
                'status' => abs($outstanding) < 0.01 ? 'settled' : 'open',
            ]);

            $this->treasury->recordTransaction(
                $treasury, TreasuryTransactionType::Income, $amount, $employee, $statement,
                "Shipping company reconciliation transfer — statement #{$statement->id}",
            );

            return $statement->fresh();
        });
    }
}
