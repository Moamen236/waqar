<?php

namespace App\Actions\Returns;

use App\Enums\PaymentStatus;
use App\Enums\RefundMethod;
use App\Enums\RefundStatus;
use App\Enums\ReturnStatus;
use App\Enums\TreasuryTransactionType;
use App\Models\Employee;
use App\Models\OrderReturn;
use App\Models\Refund;
use App\Models\Treasury;
use App\Services\Treasury\TreasuryService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Manual bank/wallet transfer, recorded by Accounting (Question 5) — no
 * store credit, no card reversal. net_amount deducts the return shipping
 * fee the customer already accepted (Question 6).
 */
class RefundReturnAction
{
    public function __construct(private readonly TreasuryService $treasury) {}

    public function execute(
        OrderReturn $return,
        Employee $accountant,
        Treasury $treasury,
        RefundMethod $method,
        string $referenceNumber,
    ): Refund {
        if ($return->status !== ReturnStatus::Inspected) {
            throw new RuntimeException(__('Only an inspected return can be refunded.'));
        }

        return DB::transaction(function () use ($return, $accountant, $treasury, $method, $referenceNumber) {
            $amount = $return->items()
                ->with('orderItem')
                ->get()
                ->sum(fn ($item) => (float) $item->orderItem->unit_price * $item->quantity);

            $returnShippingFee = (float) ($return->return_shipping_fee ?? 0);
            $netAmount = $amount - $returnShippingFee;

            $refund = Refund::create([
                'return_id' => $return->id,
                'order_id' => $return->order_id,
                'amount' => $amount,
                'return_shipping_fee' => $returnShippingFee,
                'net_amount' => $netAmount,
                'method' => $method,
                'status' => RefundStatus::Completed,
                'reference_number' => $referenceNumber,
                'processed_by' => $accountant->id,
                'processed_at' => now(),
            ]);

            $this->treasury->recordTransaction(
                $treasury, TreasuryTransactionType::Expense, -$netAmount, $accountant, $refund,
                "Refund for return #{$return->id}",
            );

            $return->update(['status' => ReturnStatus::Refunded]);
            $return->order()->update(['payment_status' => PaymentStatus::Refunded]);

            return $refund->fresh();
        });
    }
}
