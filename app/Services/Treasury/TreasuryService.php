<?php

namespace App\Services\Treasury;

use App\Enums\TreasuryTransactionType;
use App\Models\Employee;
use App\Models\Treasury;
use App\Models\TreasuryTransaction;
use App\Models\TreasuryTransfer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The only place a treasury's current_balance ever changes (spec Section
 * 11, 14). $amount is always the *signed* delta to apply — positive
 * increases the balance, negative decreases it; $type only labels what
 * kind of movement it was, callers decide the sign from the business
 * event (a COD collection is positive income, a refund payout is
 * negative expense, etc.).
 */
class TreasuryService
{
    public function recordTransaction(
        Treasury $treasury,
        TreasuryTransactionType $type,
        float $amount,
        Employee $employee,
        ?Model $reference = null,
        ?string $description = null,
    ): TreasuryTransaction {
        return DB::transaction(function () use ($treasury, $type, $amount, $employee, $reference, $description) {
            $treasury = Treasury::query()->lockForUpdate()->findOrFail($treasury->id);
            $treasury->increment('current_balance', $amount);

            return TreasuryTransaction::create([
                'treasury_id' => $treasury->id,
                'type' => $type,
                'amount' => $amount,
                'created_by' => $employee->id,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
                'description' => $description,
            ]);
        });
    }

    /**
     * One row generates a matching transfer_out/transfer_in pair,
     * keeping transfers balanced (Section 24).
     */
    public function transfer(
        Treasury $from,
        Treasury $to,
        float $amount,
        Employee $employee,
        ?string $notes = null,
    ): TreasuryTransfer {
        return DB::transaction(function () use ($from, $to, $amount, $employee, $notes) {
            $transfer = TreasuryTransfer::create([
                'from_treasury_id' => $from->id,
                'to_treasury_id' => $to->id,
                'amount' => $amount,
                'notes' => $notes,
                'created_by' => $employee->id,
            ]);

            $this->recordTransaction($from, TreasuryTransactionType::TransferOut, -$amount, $employee, $transfer);
            $this->recordTransaction($to, TreasuryTransactionType::TransferIn, $amount, $employee, $transfer);

            return $transfer;
        });
    }
}
