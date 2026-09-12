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
    case NotCollected = 'not_collected';
    case Refunded = 'refunded';
}
