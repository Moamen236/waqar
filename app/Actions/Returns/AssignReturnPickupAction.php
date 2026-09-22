<?php

namespace App\Actions\Returns;

use App\Enums\DeliveryAssignmentType;
use App\Enums\ReturnStatus;
use App\Models\DeliveryRepresentative;
use App\Models\Employee;
use App\Models\OrderReturn;
use App\Models\ShippingCompany;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Send someone to collect the goods coming back.
 *
 * Returns had no assignee at all: the warehouse restocked whenever
 * someone pressed Received, and nothing said who had been sent to fetch
 * the parcel or whether anyone had. A return is the outbound journey in
 * reverse, so it names a courier the same way an order does.
 *
 * One method for assign and reassign, unlike the order side: a return's
 * status does not move when a courier is named, so there is no initial
 * transition to distinguish the first assignment from a later change.
 */
class AssignReturnPickupAction
{
    /**
     * Once approved the goods are agreed and someone has to go and get
     * them; once received they are already here.
     */
    private const ASSIGNABLE_FROM = [
        ReturnStatus::Approved,
        ReturnStatus::Inspected,
    ];

    public function execute(
        OrderReturn $return,
        Employee $assignedBy,
        DeliveryAssignmentType $type,
        DeliveryRepresentative|ShippingCompany $assignee,
    ): OrderReturn {
        if (($type === DeliveryAssignmentType::Representative) !== ($assignee instanceof DeliveryRepresentative)) {
            throw new InvalidArgumentException(__('Assignment type must match the assignee given.'));
        }

        return DB::transaction(function () use ($return, $type, $assignee) {
            $return = OrderReturn::query()->lockForUpdate()->findOrFail($return->id);

            if (! in_array($return->status, self::ASSIGNABLE_FROM, true)) {
                throw new RuntimeException(__('Only an approved return can be assigned for collection.'));
            }

            $return->update([
                'delivery_assignment_type' => $type,
                'delivery_representative_id' => $assignee instanceof DeliveryRepresentative ? $assignee->id : null,
                'shipping_company_id' => $assignee instanceof ShippingCompany ? $assignee->id : null,
            ]);

            // The activity log records who changed these and when — the
            // columns are in OrderReturn::activityLogAttributes(), so a
            // reassignment is answerable without a second history table.
            return $return->fresh();
        });
    }
}
