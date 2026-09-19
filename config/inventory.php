<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Low-stock threshold
    |--------------------------------------------------------------------------
    |
    | Available stock (quantity minus what is reserved) at or below this
    | figure notifies the Warehouse Manager and Vice Chairman — once, on
    | the way down. One number for the whole catalogue: spec Section 07
    | deliberately has no per-variant reorder point, and the dashboard's
    | low-stock panel is an ordered ranking for the same reason.
    |
    */
    'low_stock_threshold' => (int) env('INVENTORY_LOW_STOCK_THRESHOLD', 5),
];
