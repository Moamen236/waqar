<?php

namespace App\Enums;

/**
 * Customer-facing status (spec Section 03's mapping table) — computed
 * from OrderStatus, never stored independently of it. See
 * OrderStatus::customerStatus().
 */
enum CustomerOrderStatus: string
{
    case OrderReceived = 'Order Received';
    case Processing = 'Processing';
    case Postponed = 'Postponed';
    case Cancelled = 'Cancelled';
    case Backordered = 'Backordered';
    case Shipping = 'Shipping';
    case OutForDelivery = 'Out for Delivery';
    case Delivered = 'Delivered';
    case PartiallyReturned = 'Partially Returned';
    case Returned = 'Returned';
    case ReturnRequested = 'Return Requested';
    case ReturnApproved = 'Approved';
    case ReturnReceived = 'Received';
    case ReturnInspected = 'Inspected';
    case Refunded = 'Refunded';
}
