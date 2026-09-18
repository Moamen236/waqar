<?php

namespace App\Actions\Orders;

use App\Enums\CustomerOrderStatus;
use App\Enums\DeliveryAssignmentType;
use App\Enums\OrderStatus;
use App\Models\DeliveryRepresentative;
use App\Models\Employee;
use App\Models\Order;
use App\Models\ShippingCompany;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Delivery Manager assigns a confirmed order to a representative or
 * shipping company (Section 03, 11). Suggesting who to assign based on
 * DeliveryRepresentativeArea coverage is Phase 4 admin-UI work — this
 * Action only records the assignment once made.
 */
class AssignDeliveryAction
{
    public function execute(
        Order $order,
        Employee $assignedBy,
        DeliveryAssignmentType $type,
        DeliveryRepresentative|ShippingCompany $assignee,
    ): Order {
        if (($type === DeliveryAssignmentType::Representative) !== ($assignee instanceof DeliveryRepresentative)) {
            throw new InvalidArgumentException(__('Assignment type must match the assignee given.'));
        }

        return DB::transaction(function () use ($order, $assignedBy, $type, $assignee) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($order->status !== OrderStatus::Confirmed) {
                throw new RuntimeException("Order #{$order->order_number} must be Confirmed before it can be assigned.");
            }

            $order->deliveryAssignments()->create([
                'assignment_type' => $type,
                'delivery_representative_id' => $assignee instanceof DeliveryRepresentative ? $assignee->id : null,
                'shipping_company_id' => $assignee instanceof ShippingCompany ? $assignee->id : null,
                'assigned_by' => $assignedBy->id,
                'assigned_at' => now(),
            ]);

            $order->update([
                'status' => OrderStatus::Assigned,
                'customer_status' => CustomerOrderStatus::Shipping,
                'delivery_assignment_type' => $type,
                'delivery_representative_id' => $assignee instanceof DeliveryRepresentative ? $assignee->id : null,
                'shipping_company_id' => $assignee instanceof ShippingCompany ? $assignee->id : null,
            ]);

            $order->statusHistory()->create([
                'from_status' => OrderStatus::Confirmed->value,
                'to_status' => OrderStatus::Assigned->value,
                'changed_by' => $assignedBy->id,
                'reason' => 'Assigned for delivery',
            ]);

            return $order->fresh();
        });
    }
}
