<?php

namespace App\Services\Inventory;

use App\Enums\InventoryMovementType;
use App\Exceptions\InsufficientStockException;
use App\Models\Employee;
use App\Models\InventoryMovement;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use App\Services\Notifications\StaffNotifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The single place physical/reserved stock ever changes (spec Section
 * 07). Every method wraps its row lookup in `lockForUpdate()` inside a
 * transaction — two requests reserving the same variant at once queue on
 * the row lock rather than racing past each other's read.
 */
class InventoryService
{
    /**
     * The movement types a human may produce by hand. Everything else in
     * InventoryMovementType is written by the order flow itself.
     *
     * @var list<InventoryMovementType>
     */
    public const MANUAL_ADJUSTMENT_TYPES = [
        InventoryMovementType::Adjustment,
        InventoryMovementType::Damaged,
        InventoryMovementType::Lost,
    ];

    public function __construct(private readonly StaffNotifier $staff) {}

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

            $fresh = $inventory->fresh();

            $this->flagLowStock($variant, $warehouse, $available, $fresh);

            return $fresh;
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

            $available = $inventory->quantity - $inventory->reserved_quantity;

            $inventory->decrement('quantity', $quantity);
            $inventory->decrement('reserved_quantity', min($quantity, $inventory->reserved_quantity));

            $this->recordMovement($warehouse, $variant, InventoryMovementType::Sale, -$quantity, $reference, $employee);

            $fresh = $inventory->fresh();

            $this->flagLowStock($variant, $warehouse, $available, $fresh);

            return $fresh;
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
     * A manual correction by a warehouse-side employee — a recount, a
     * breakage, a shrinkage write-off (Section 07's `adjustment`,
     * `damaged` and `lost` movement types, none of which any order-flow
     * Action ever produces).
     *
     * This is the one entry point that changes physical stock without an
     * order behind it, so it carries the two guards the order flow gets
     * from its own shape: a reason is mandatory, and the correction may
     * never push physical quantity below what is already reserved for
     * confirmed orders — that stock is spoken for, and silently removing
     * it turns into an unfulfillable order later rather than an error now.
     */
    public function adjust(
        ProductVariant $variant,
        Warehouse $warehouse,
        int $signedQuantity,
        InventoryMovementType $type,
        string $reason,
        ?Employee $employee = null,
    ): WarehouseInventory {
        if (! in_array($type, self::MANUAL_ADJUSTMENT_TYPES, true)) {
            throw new InvalidArgumentException(
                "Movement type [{$type->value}] is not a manual adjustment; it belongs to the order flow."
            );
        }

        if ($signedQuantity === 0) {
            throw new InvalidArgumentException(__('A stock adjustment must move a non-zero quantity.'));
        }

        return DB::transaction(function () use ($variant, $warehouse, $signedQuantity, $type, $reason, $employee) {
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

            $resulting = $inventory->quantity + $signedQuantity;

            if ($resulting < $inventory->reserved_quantity) {
                throw new InsufficientStockException(
                    $variant->id,
                    $warehouse->id,
                    abs($signedQuantity),
                    $inventory->quantity - $inventory->reserved_quantity,
                );
            }

            $available = $inventory->quantity - $inventory->reserved_quantity;

            $inventory->update(['quantity' => $resulting]);

            $this->recordMovement($warehouse, $variant, $type, $signedQuantity, null, $employee, $reason);

            $fresh = $inventory->fresh();

            $this->flagLowStock($variant, $warehouse, $available, $fresh);

            return $fresh;
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

    /**
     * Low-stock and out-of-stock alerts (Section 23's "Low Stock").
     *
     * Edge-triggered: it fires on the transition past the line, not
     * while sitting below it. That is what makes this cheap enough to
     * run inline — there is no scheduled sweep, no "already alerted"
     * state to keep, and a variant that sells its last few units
     * produces one notification rather than one per order. Restocking
     * lifts availability back above the line and arms it again, with no
     * bookkeeping needed for that either.
     *
     * Only the three methods that *lower* availability call this; the
     * guard below makes that belt-and-braces.
     *
     * ponytail: one global threshold from config, per-variant reorder
     * points if merchandising ever asks. The dashboard's low-stock panel
     * deliberately has no reorder-point column, so inventing one here
     * would be the bigger change, not the smaller.
     */
    private function flagLowStock(
        ProductVariant $variant,
        Warehouse $warehouse,
        int $availableBefore,
        WarehouseInventory $after,
    ): void {
        $availableAfter = $after->quantity - $after->reserved_quantity;

        if ($availableAfter >= $availableBefore) {
            return;
        }

        $threshold = (int) config('inventory.low_stock_threshold');

        $ranOut = $availableBefore > 0 && $availableAfter <= 0;
        $ranLow = $availableBefore > $threshold && $availableAfter <= $threshold;

        if (! $ranOut && ! $ranLow) {
            return;
        }

        $params = [
            'sku' => $variant->sku,
            'warehouse' => $warehouse->name,
            'available' => max($availableAfter, 0),
        ];

        // afterCommit for the same reason every observer uses it: these
        // three methods all run inside a transaction that a later
        // failure in the same Action can still roll back.
        DB::afterCommit(fn () => $this->staff->toRoles(
            ['Warehouse Manager', 'Vice Chairman'],
            $ranOut ? 'stock_out' : 'stock_low',
            $params,
            'admin.inventory.index',
        ));
    }

    private function recordMovement(
        Warehouse $warehouse,
        ProductVariant $variant,
        InventoryMovementType $type,
        int $signedQuantity,
        ?Model $reference,
        ?Employee $employee,
        ?string $notes = null,
    ): InventoryMovement {
        return InventoryMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id,
            'type' => $type,
            'quantity' => $signedQuantity,
            'reference_type' => $reference?->getMorphClass(),
            'reference_id' => $reference?->getKey(),
            'created_by' => $employee?->id,
            'notes' => $notes,
        ]);
    }
}
