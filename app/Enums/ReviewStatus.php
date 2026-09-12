<?php

namespace App\Enums;

/**
 * Moderation queue for customer product reviews (spec Section 24) — a
 * review is only ever rendered on the storefront once it's Approved.
 */
enum ReviewStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
