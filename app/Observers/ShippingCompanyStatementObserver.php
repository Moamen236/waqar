<?php

namespace App\Observers;

use App\Models\ShippingCompanyStatement;
use App\Services\Notifications\StaffNotifier;
use Illuminate\Support\Facades\DB;

/**
 * Shipping-partner settlement (Section 11). A statement opening means
 * money is owed to the business; it settling means the money arrived.
 *
 * The link goes to the reconciliation screen for the *company*, not the
 * statement — /admin/accounting/reconciliation/{shippingCompany} is the
 * only page that renders one, there is no per-statement route.
 */
class ShippingCompanyStatementObserver
{
    public function __construct(private readonly StaffNotifier $staff) {}

    public function created(ShippingCompanyStatement $statement): void
    {
        $this->notify($statement, 'statement_created', $statement->created_by);
    }

    public function updated(ShippingCompanyStatement $statement): void
    {
        if (! $statement->wasChanged('status') || $statement->status !== 'settled') {
            return;
        }

        $this->notify($statement, 'statement_settled', StaffNotifier::actor());
    }

    private function notify(ShippingCompanyStatement $statement, string $type, ?int $actor): void
    {
        DB::afterCommit(fn () => $this->staff->toRoles(
            ['Accounting', 'Chairman'],
            $type,
            [
                'company' => $statement->shippingCompany?->name ?? '—',
                'amount' => number_format((float) $statement->net_amount_expected, 2),
            ],
            'admin.accounting.reconciliation.show',
            ['shippingCompany' => $statement->shipping_company_id],
            $actor,
        ));
    }
}
