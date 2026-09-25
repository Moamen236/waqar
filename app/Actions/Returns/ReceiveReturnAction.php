<?php

namespace App\Actions\Returns;

use App\Enums\ReturnStatus;
use App\Models\Employee;
use App\Models\OrderReturn;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Warehouse receives the returned items and restocks them (Section 12).
 * Simplified from the spec's Received → Inspected two-step into one
 * Action for Phase 3 — flagged in the handover, worth splitting later if
 * a real "received but failed inspection" path turns out to be needed.
 */
class ReceiveReturnAction
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function execute(OrderReturn $return, Employee $warehouseEmployee, Warehouse $warehouse): OrderReturn
    {
        if ($return->status !== ReturnStatus::Approved) {
            throw new RuntimeException(__('Only an approved return can be received.'));
        }

        // Somebody has to have been sent for the goods before they can be
        // booked in — the courier is who a missing parcel is traced to.
        if ($return->delivery_assignment_type === null) {
            throw new RuntimeException(__('Send a courier to collect this return before marking it received.'));
        }

        return DB::transaction(function () use ($return, $warehouseEmployee, $warehouse) {
            foreach ($return->items()->with('productVariant.product')->get() as $item) {
                if (! $item->productVariant->product->inventory_tracking_enabled) {
                    continue;
                }
                $this->inventory->restock($item->productVariant, $warehouse, $item->quantity, $return, $warehouseEmployee);
            }

            $return->update(['status' => ReturnStatus::Inspected]);

            return $return->fresh();
        });
    }
}
