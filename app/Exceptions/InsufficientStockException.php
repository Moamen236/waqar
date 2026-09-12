<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown when a reservation would oversell a variant's available stock
 * (physical - reserved). This is the guard the "overselling prevention"
 * and "concurrent-purchase race condition" critical tests (spec Section
 * 23) exercise directly.
 */
class InsufficientStockException extends Exception
{
    public function __construct(int $variantId, int $warehouseId, int $requested, int $available)
    {
        parent::__construct(
            "Cannot reserve {$requested} of variant #{$variantId} in warehouse #{$warehouseId} — only {$available} available."
        );
    }
}
