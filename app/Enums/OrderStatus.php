<?php

namespace App\Enums;

/**
 * Internal operational status (spec Section 10). See CustomerOrderStatus
 * for the customer-facing mapping — these are deliberately separate
 * concerns (Section 02, 10), never collapsed into one field.
 */
enum OrderStatus: string
{
    case New = 'New';
    case Checking = 'Checking';
    case Confirmed = 'Confirmed';
    case Postponed = 'Postponed';
    case Backorder = 'Backorder';
    case Cancelled = 'Cancelled';
    case Assigned = 'Assigned';
    case OutForDelivery = 'Out for Delivery';
    case Delivered = 'Delivered';
    case PartiallyReturned = 'Partially Returned';
    case Returned = 'Returned';

    /**
     * Section 03's status table. The post-delivery return chain (Return
     * Requested → … → Refunded) lives on the OrderReturn/stage instead —
     * it isn't reachable from an OrderStatus value alone, since a
     * delivered order's status doesn't change while its return is
     * in progress.
     */
    public function customerStatus(): CustomerOrderStatus
    {
        return match ($this) {
            self::New => CustomerOrderStatus::OrderReceived,
            self::Checking, self::Confirmed => CustomerOrderStatus::Processing,
            self::Postponed => CustomerOrderStatus::Postponed,
            self::Cancelled => CustomerOrderStatus::Cancelled,
            self::Backorder => CustomerOrderStatus::Backordered,
            self::Assigned => CustomerOrderStatus::Shipping,
            self::OutForDelivery => CustomerOrderStatus::OutForDelivery,
            self::Delivered => CustomerOrderStatus::Delivered,
            self::PartiallyReturned => CustomerOrderStatus::PartiallyReturned,
            self::Returned => CustomerOrderStatus::Returned,
        };
    }
}
