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

            $this->record($order, $assignedBy, $type, $assignee);

            $order->update([
                'status' => OrderStatus::Assigned,
                'customer_status' => CustomerOrderStatus::Shipping,
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

    /**
     * Hand an order already on the road to a different courier — the first
     * one called in sick, or the round was rebalanced.
     *
     * The status does not move: the order was Assigned or Out for Delivery
     * before and still is. What changes is who is carrying it, and
     * `delivery_assignments` was built as the trail for exactly that
     * ("like order_status_history is to orders.status"), so this appends a
     * row rather than editing the last one. Who had it yesterday stays
     * answerable — which is the point when a parcel goes missing.
     */
    public function reassign(
        Order $order,
        Employee $assignedBy,
        DeliveryAssignmentType $type,
        DeliveryRepresentative|ShippingCompany $assignee,
        ?string $notes = null,
    ): Order {
        if (($type === DeliveryAssignmentType::Representative) !== ($assignee instanceof DeliveryRepresentative)) {
            throw new InvalidArgumentException(__('Assignment type must match the assignee given.'));
        }

        return DB::transaction(function () use ($order, $assignedBy, $type, $assignee, $notes) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if (! in_array($order->status, [OrderStatus::Assigned, OrderStatus::OutForDelivery], true)) {
                throw new RuntimeException("Order #{$order->order_number} isn't out with a courier — nothing to reassign from status {$order->status->value}.");
            }

            $sameCourier = $order->delivery_assignment_type === $type
                && $order->delivery_representative_id === ($assignee instanceof DeliveryRepresentative ? $assignee->id : null)
                && $order->shipping_company_id === ($assignee instanceof ShippingCompany ? $assignee->id : null);

            if ($sameCourier) {
                throw new RuntimeException("Order #{$order->order_number} is already with {$assignee->name}.");
            }

            $this->record($order, $assignedBy, $type, $assignee, $notes);

            return $order->fresh();
        });
    }

    /**
     * Append the assignment row and point the order's denormalized
     * columns at it. Shared so the initial assign and a later reassign
     * can never disagree about what "currently assigned" means.
     */
    private function record(
        Order $order,
        Employee $assignedBy,
        DeliveryAssignmentType $type,
        DeliveryRepresentative|ShippingCompany $assignee,
        ?string $notes = null,
    ): void {
        $representativeId = $assignee instanceof DeliveryRepresentative ? $assignee->id : null;
        $companyId = $assignee instanceof ShippingCompany ? $assignee->id : null;

        $order->deliveryAssignments()->create([
            'assignment_type' => $type,
            'delivery_representative_id' => $representativeId,
            'shipping_company_id' => $companyId,
            'assigned_by' => $assignedBy->id,
            'assigned_at' => now(),
            'notes' => $notes,
        ]);

        $order->update([
            'delivery_assignment_type' => $type,
            'delivery_representative_id' => $representativeId,
            'shipping_company_id' => $companyId,
        ]);
    }
}
