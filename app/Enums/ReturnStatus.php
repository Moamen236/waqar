<?php

namespace App\Enums;

enum ReturnStatus: string
{
    case Requested = 'requested';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Received = 'received';
    case Inspected = 'inspected';
    case Refunded = 'refunded';
    case Completed = 'completed';
}
