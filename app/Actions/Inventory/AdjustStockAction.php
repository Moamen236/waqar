<?php

namespace App\Actions\Inventory;

use App\Enums\InventoryMovementType;
use App\Models\Employee;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use App\Services\Inventory\InventoryService;

/**
 * A Warehouse Manager correcting physical stock by hand (Section 07) —
 * a recount, breakage or write-off, gated by `inventory.adjust`.
 *
 * Thin on purpose: InventoryService is still the only place stock ever
 * moves, and the guards (a mandatory reason, never cutting into reserved
 * stock) live there so a future caller cannot route around them. What
 * this Action adds is the *attribution* — the acting employee is threaded
 * through to the movement's `created_by`, which is what makes the
 * resulting audit entry answer "who", not just "what".
 */
class AdjustStockAction
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function execute(
        ProductVariant $variant,
        Warehouse $warehouse,
        int $signedQuantity,
        InventoryMovementType $type,
        string $reason,
        Employee $employee,
    ): WarehouseInventory {
        return $this->inventory->adjust($variant, $warehouse, $signedQuantity, $type, $reason, $employee);
    }
}
