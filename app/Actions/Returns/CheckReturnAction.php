<?php

namespace App\Actions\Returns;

use App\Enums\ReturnStatus;
use App\Models\Employee;
use App\Models\OrderReturn;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Checking phones the customer before a return goes any further: it
 * confirms the reason they gave, and — when the return is headed for a
 * replacement — agrees the swap with them the way it would a new order.
 *
 * Three outcomes, three methods rather than one branchy execute(), the
 * same shape ConfirmDeliveryResultAction uses for its three delivery
 * outcomes, and the same three verbs Checking already has on an order:
 *
 *   confirm    → Approved   (delegated, so the consent guard stays in
 *                            one place)
 *   reschedule → Requested  (stays put; the call is recorded so the next
 *                            person can see it was already attempted)
 *   cancel     → Rejected   (the first writer of that status — it has
 *                            existed in the enum since Phase 1 with no
 *                            code path reaching it)
 *
 * Deliberately not a gate: Warehouse Manager and Accounting keep their
 * existing `returns.approve` route, so a return can still be approved
 * without a call. This is a verification step, like the delivery
 * handover, not a lock in front of the warehouse.
 */
class CheckReturnAction
{
    public function __construct(private readonly ApproveReturnAction $approve) {}

    public function confirm(OrderReturn $return, Employee $checker, ?string $notes = null): OrderReturn
    {
        return DB::transaction(function () use ($return, $checker, $notes) {
            $return = $this->lockRequested($return);
            $this->recordCall($return, $checker, $notes);

            // ApproveReturnAction owns the post-delivery shipping-fee
            // consent rule. Re-stating it here would be a second copy to
            // keep in sync, so the call record is written first and the
            // transition is delegated — a refusal rolls both back.
            return $this->approve->execute($return, $checker);
        });
    }

    /**
     * The customer could not be reached, or asked for a later pickup.
     * Status does not move: the return stays in the queue, but the next
     * person can see it was already tried and what was said.
     */
    public function reschedule(OrderReturn $return, Employee $checker, ?string $notes = null): OrderReturn
    {
        return DB::transaction(function () use ($return, $checker, $notes) {
            $return = $this->lockRequested($return);
            $this->recordCall($return, $checker, $notes);

            return $return->fresh();
        });
    }

    /**
     * The customer changed their mind, or the reason does not hold.
     */
    public function cancel(OrderReturn $return, Employee $checker, string $reason): OrderReturn
    {
        return DB::transaction(function () use ($return, $checker, $reason) {
            $return = $this->lockRequested($return);
            $this->recordCall($return, $checker, $reason);
            $return->update(['status' => ReturnStatus::Rejected]);

            return $return->fresh();
        });
    }

    private function recordCall(OrderReturn $return, Employee $checker, ?string $notes): void
    {
        $return->update([
            'checked_by_employee_id' => $checker->id,
            'checked_at' => now(),
            'checking_notes' => $notes,
        ]);
    }

    private function lockRequested(OrderReturn $return): OrderReturn
    {
        $return = OrderReturn::query()->lockForUpdate()->findOrFail($return->id);

        if ($return->status !== ReturnStatus::Requested) {
            throw new RuntimeException(__('Only a requested return can be checked.'));
        }

        return $return;
    }
}
