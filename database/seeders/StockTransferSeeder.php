<?php

namespace Database\Seeders;

use App\Enums\InventoryMovementType;
use App\Enums\StockTransferStatus;
use App\Models\Employee;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * A completed inter-warehouse transfer (Section 07). No dedicated Action
 * exists for this yet (only AdjustStockAction/InventoryService's manual
 * adjustment path is built so far), so this seeder moves the stock the
 * same way that future Action will need to: decrement the source, credit
 * the destination, and leave a transfer_out/transfer_in movement pair
 * behind for the audit trail.
 */
class StockTransferSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $from = Warehouse::where('name', 'Main Warehouse')->first();
        $to = Warehouse::where('name', 'Alexandria Hub')->first();
        $manager = Employee::where('email', 'warehouse@waqar.test')->first();

        if ($from === null || $to === null || $manager === null) {
            return;
        }

        $variants = collect(['TSP-005', 'PMS-007'])
            ->map(fn (string $sku) => Product::where('sku', $sku)->first()?->variants()->first())
            ->filter();

        if ($variants->isEmpty()) {
            return;
        }

        $transfer = StockTransfer::create([
            'from_warehouse_id' => $from->id,
            'to_warehouse_id' => $to->id,
            'status' => StockTransferStatus::Completed,
            'requested_by' => $manager->id,
            'approved_by' => $manager->id,
            'notes' => 'Rebalancing stock ahead of the Alexandria Hub opening.',
        ]);

        foreach ($variants as $variant) {
            /** @var ProductVariant $variant */
            $sourceInventory = WarehouseInventory::where('warehouse_id', $from->id)
                ->where('product_variant_id', $variant->id)
                ->first();

            if ($sourceInventory === null) {
                continue;
            }

            $quantity = min(5, $sourceInventory->quantity);
            if ($quantity < 1) {
                continue;
            }

            $transfer->items()->create(['product_variant_id' => $variant->id, 'quantity' => $quantity]);

            $sourceInventory->decrement('quantity', $quantity);

            $destinationInventory = WarehouseInventory::firstOrCreate(
                ['warehouse_id' => $to->id, 'product_variant_id' => $variant->id],
                ['quantity' => 0, 'reserved_quantity' => 0],
            );
            $destinationInventory->increment('quantity', $quantity);

            InventoryMovement::create([
                'warehouse_id' => $from->id,
                'product_variant_id' => $variant->id,
                'type' => InventoryMovementType::TransferOut,
                'quantity' => -$quantity,
                'reference_type' => $transfer->getMorphClass(),
                'reference_id' => $transfer->id,
                'created_by' => $manager->id,
                'notes' => 'Transferred to Alexandria Hub',
            ]);

            InventoryMovement::create([
                'warehouse_id' => $to->id,
                'product_variant_id' => $variant->id,
                'type' => InventoryMovementType::TransferIn,
                'quantity' => $quantity,
                'reference_type' => $transfer->getMorphClass(),
                'reference_id' => $transfer->id,
                'created_by' => $manager->id,
                'notes' => 'Received from Main Warehouse',
            ]);
        }
    }
}
