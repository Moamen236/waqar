<?php

namespace App\Enums;

/**
 * Global payment_status (spec Section 09) — "Unpaid" in the Internal
 * Operations document's terminology is just Pending's internal-facing
 * label, not a separate value.
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Collected = 'collected';
    /**
     * The courier came back with less than the order is owed. The goods
     * are delivered and the stock is deducted — only the money is
     * outstanding, so the order stays on Accounting's books until the
     * balance is collected (AccountingController::collect()).
     */
    case PartiallyCollected = 'partially_collected';
    case NotCollected = 'not_collected';
    case Refunded = 'refunded';
}
