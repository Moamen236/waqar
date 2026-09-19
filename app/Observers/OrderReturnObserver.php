<?php

namespace App\Observers;

use App\Enums\ReturnStatus;
use App\Models\OrderReturn;
use App\Notifications\Orders\ReturnRequestedNotification;
use App\Services\Notifications\StaffNotifier;
use Illuminate\Support\Facades\DB;

/**
 * The return lifecycle (Section 23's event list, Section 12's flow).
 * Same afterCommit reasoning as OrderObserver — every Returns action
 * writes the return, its items and often stock inside one transaction.
 *
 * Recipients route through the *order*, not the return, so the Customer
 * Service scoping stays identical to OrderReturn::scopeVisibleTo, which
 * delegates to the order for exactly the same reason: a return is only
 * ever as visible as the order it belongs to.
 */
class OrderReturnObserver
{
    /**
     * @var array<string, array{0: list<string>, 1: string}>
     */
    private const STATUS_RECIPIENTS = [
        // Approved → the warehouse should expect the goods.
        ReturnStatus::Approved->value => [['Warehouse Manager', 'Customer Service'], 'return_approved'],
        // Received and Inspected both mean the same thing to Accounting:
        // a refund is now due. ReceiveReturnAction currently writes only
        // Inspected; Received is mapped so a future writer is covered.
        ReturnStatus::Received->value => [['Accounting', 'Warehouse Manager'], 'return_inspected'],
        ReturnStatus::Inspected->value => [['Accounting', 'Warehouse Manager'], 'return_inspected'],
        // Money left the business.
        ReturnStatus::Refunded->value => [['Customer Service', 'Chairman'], 'return_refunded'],
        ReturnStatus::Rejected->value => [['Customer Service'], 'return_rejected'],
    ];

    public function __construct(private readonly StaffNotifier $staff) {}

    public function created(OrderReturn $return): void
    {
        $actor = StaffNotifier::actor();

        DB::afterCommit(function () use ($return, $actor) {
            $return->customer?->notify(new ReturnRequestedNotification($return));

            $this->notify($return, ['Warehouse Manager', 'Accounting', 'Customer Service'], 'return_requested', $actor);
        });
    }

    public function updated(OrderReturn $return): void
    {
        $actor = StaffNotifier::actor();

        // Consent to the return-shipping fee is what unblocks approval
        // (ApproveReturnAction refuses without it), so the people who do
        // the approving need to know it landed.
        if ($return->wasChanged('customer_accepted_return_shipping_fee_at')
            && $return->customer_accepted_return_shipping_fee_at !== null) {
            DB::afterCommit(fn () => $this->notify(
                $return, ['Warehouse Manager', 'Accounting'], 'return_fee_accepted', $actor,
            ));
        }

        if (! $return->wasChanged('status')) {
            return;
        }

        $mapped = self::STATUS_RECIPIENTS[$return->status->value] ?? null;

        if ($mapped === null) {
            return;
        }

        [$roles, $type] = $mapped;

        DB::afterCommit(fn () => $this->notify($return, $roles, $type, $actor));
    }

    /**
     * @param  list<string>  $roles
     */
    private function notify(OrderReturn $return, array $roles, string $type, ?int $actor): void
    {
        $order = $return->order;

        if ($order === null) {
            return;
        }

        $this->staff->forOrder(
            $order,
            $roles,
            $type,
            route: 'admin.returns.show',
            routeParams: ['return' => $return->id],
            actorId: $actor,
        );
    }
}
