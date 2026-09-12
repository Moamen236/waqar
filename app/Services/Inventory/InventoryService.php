<?php

namespace App\Services\Inventory;

use App\Enums\InventoryMovementType;
use App\Exceptions\InsufficientStockException;
use App\Models\Employee;
use App\Models\InventoryMovement;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The single place physical/reserved stock ever changes (spec Section
 * 07). Every method wraps its row lookup in `lockForUpdate()` inside a
 * transaction — two requests reserving the same variant at once queue on
 * the row lock rather than racing past each other's read.
 */
class InventoryService
{
    /**
     * Order created (Real product) → reserve stock, no physical deduction
     * (Section 02, 07). Throws if that would oversell.
     */
    public function reserve(
        ProductVariant $variant,
        Warehouse $warehouse,
        int $quantity,
        ?Model $reference = null,
        ?Employee $employee = null,
    ): WarehouseInventory {
        return DB::transaction(function () use ($variant, $warehouse, $quantity, $reference, $employee) {
            $inventory = WarehouseInventory::query()
                ->where('warehouse_id', $warehouse->id)
                ->where('product_variant_id', $variant->id)
                ->lockForUpdate()
                ->first();

            $available = $inventory ? $inventory->quantity - $inventory->reserved_quantity : 0;

            if ($inventory === null || $available < $quantity) {
                throw new InsufficientStockException($variant->id, $warehouse->id, $quantity, $available);
            }

            $inventory->increment('reserved_quantity', $quantity);

            $this->recordMovement($warehouse, $variant, InventoryMovementType::Reservation, $quantity, $reference, $employee);

            return $inventory->fresh();
        });
    }

    /**
     * Cancelled, or Returned-at-delivery → release the reservation, no
     * deduction ever happened (Section 02, 07).
     */
    public function release(
        ProductVariant $variant,
        Warehouse $warehouse,
        int $quantity,
        ?Model $reference = null,
        ?Employee $employee = null,
    ): WarehouseInventory {
        return DB::transaction(function () use ($variant, $warehouse, $quantity, $reference, $employee) {
            $inventory = WarehouseInventory::query()
                ->where('warehouse_id', $warehouse->id)
                ->where('product_variant_id', $variant->id)
                ->lockForUpdate()
                ->firstOrFail();

            $inventory->decrement('reserved_quantity', min($quantity, $inventory->reserved_quantity));

            $this->recordMovement($warehouse, $variant, InventoryMovementType::Release, -$quantity, $reference, $employee);

            return $inventory->fresh();
        });
    }

    /**
     * Accounting confirms Delivered → the only point that deducts
     * physical stock (Section 02, 07's central rule). Releases the
     * matching reservation at the same time — the unit is gone, not
     * still held.
     */
    public function deduct(
        ProductVariant $variant,
        Warehouse $warehouse,
        int $quantity,
        ?Model $reference = null,
        ?Employee $employee = null,
    ): WarehouseInventory {
        return DB::transaction(function () use ($variant, $warehouse, $quantity, $reference, $employee) {
            $inventory = WarehouseInventory::query()
                ->where('warehouse_id', $warehouse->id)
                ->where('product_variant_id', $variant->id)
                ->lockForUpdate()
                ->firstOrFail();

            $inventory->decrement('quantity', $quantity);
            $inventory->decrement('reserved_quantity', min($quantity, $inventory->reserved_quantity));

            $this->recordMovement($warehouse, $variant, InventoryMovementType::Sale, -$quantity, $reference, $employee);

            return $inventory->fresh();
        });
    }

    /**
     * Post-delivery return, received & inspected as sellable → add stock
     * back (Section 07).
     */
    public function restock(
        ProductVariant $variant,
        Warehouse $warehouse,
        int $quantity,
        ?Model $reference = null,
        ?Employee $employee = null,
    ): WarehouseInventory {
        return DB::transaction(function () use ($variant, $warehouse, $quantity, $reference, $employee) {
            $inventory = WarehouseInventory::query()
                ->where('warehouse_id', $warehouse->id)
                ->where('product_variant_id', $variant->id)
                ->lockForUpdate()
                ->first();

            if ($inventory === null) {
                $inventory = WarehouseInventory::create([
                    'warehouse_id' => $warehouse->id,
                    'product_variant_id' => $variant->id,
                    'quantity' => 0,
                    'reserved_quantity' => 0,
                ]);
            }

            $inventory->increment('quantity', $quantity);

            $this->recordMovement($warehouse, $variant, InventoryMovementType::ReturnStock, $quantity, $reference, $employee);

            return $inventory->fresh();
        });
    }

    /**
     * v1 reserves a whole order from a single warehouse (CreateOrderAction
     * takes one Warehouse for every line) — later Actions that need to
     * release or deduct the same stock (cancel, deliver, restock) look it
     * up from the movement ledger itself rather than needing it passed in
     * again, since order_items doesn't carry its own warehouse_id.
     */
    public function warehouseForReservation(Model $reference, ProductVariant $variant): ?Warehouse
    {
        $movement = InventoryMovement::query()
            ->where('reference_type', $reference->getMorphClass())
            ->where('reference_id', $reference->getKey())
            ->where('product_variant_id', $variant->id)
            ->where('type', InventoryMovementType::Reservation)
            ->latest('id')
            ->first();

        return $movement?->warehouse;
    }

    private function recordMovement(
        Warehouse $warehouse,
        ProductVariant $variant,
        InventoryMovementType $type,
        int $signedQuantity,
        ?Model $reference,
        ?Employee $employee,
    ): InventoryMovement {
        return InventoryMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id,
            'type' => $type,
            'quantity' => $signedQuantity,
            'reference_type' => $reference?->getMorphClass(),
            'reference_id' => $reference?->getKey(),
            'created_by' => $employee?->id,
        ]);
    }
}
