<?php

namespace App\Enums;

/**
 * Spec Section 07, adopted as-is from the E-Commerce document.
 */
enum InventoryMovementType: string
{
    case Purchase = 'purchase';
    case Sale = 'sale';
    case ReturnStock = 'return';
    case Adjustment = 'adjustment';
    case Damaged = 'damaged';
    case Lost = 'lost';
    case TransferIn = 'transfer_in';
    case TransferOut = 'transfer_out';
    case Reservation = 'reservation';
    case Release = 'release';
}
