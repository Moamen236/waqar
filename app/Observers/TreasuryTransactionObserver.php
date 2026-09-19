<?php

namespace App\Observers;

use App\Enums\TreasuryTransactionType;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\ShippingCompanyStatement;
use App\Models\TreasuryTransaction;
use App\Models\TreasuryTransfer;
use App\Services\Notifications\StaffNotifier;
use Illuminate\Support\Facades\DB;

/**
 * Money movements worth telling the Chairman and Accounting about.
 *
 * The filtering here is the whole point of the class. TreasuryService is
 * the single writer for every balance change in the system, and most of
 * those changes are the automatic COD income posted by
 * ConfirmDeliveryResultAction — one per delivered order. Notifying on
 * those would put a row in the Chairman's bell for every parcel that
 * lands, which is a volume report, not a notification. They are excluded
 * by their reference: an order collection always points at a Payment.
 *
 * What is left is the exceptional traffic — someone posting a manual
 * entry, moving cash between treasuries, paying a refund out, or
 * settling a shipping company's statement.
 */
class TreasuryTransactionObserver
{
    public function __construct(private readonly StaffNotifier $staff) {}

    public function created(TreasuryTransaction $transaction): void
    {
        // A transfer writes a matching out/in pair against two
        // treasuries. One movement, one notification — the outgoing leg
        // carries it.
        if ($transaction->type === TreasuryTransactionType::TransferIn) {
            return;
        }

        $reference = $transaction->reference_type;

        // Automatic order income. See the class docblock.
        if ($reference === (new Payment)->getMorphClass()) {
            return;
        }

        $type = match ($reference) {
            (new TreasuryTransfer)->getMorphClass() => 'treasury_transfer',
            (new ShippingCompanyStatement)->getMorphClass() => 'reconciliation_transfer',
            (new Refund)->getMorphClass() => 'refund_paid',
            // No reference at all is a hand-posted entry — the one thing
            // in the ledger with no business event behind it.
            null => 'treasury_entry',
            default => null,
        };

        if ($type === null) {
            return;
        }

        $params = [
            'amount' => number_format(abs((float) $transaction->amount), 2),
            'treasury' => $transaction->treasury->name,
        ];

        DB::afterCommit(fn () => $this->staff->toRoles(
            ['Chairman', 'Accounting'],
            $type,
            $params,
            'admin.treasury.index',
            actorId: $transaction->created_by,
        ));
    }
}
