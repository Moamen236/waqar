<?php

namespace App\Actions\Orders;

use App\Enums\CustomerOrderStatus;
use App\Enums\OrderStatus;
use App\Models\Employee;
use App\Models\Order;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The other half of Question 14's Backorder state: once the
 * unfulfillable item's product has been converted Advertisement → Real
 * (Section 05) — meaning it's now inventory-tracked — this resumes the
 * order at Confirmed. Stock was never reserved for it while it was still
 * an Advertisement product, so resuming actually reserves it now; if
 * that reservation fails (still not enough real stock), the order stays
 * in Backorder and the caller sees why.
 */
class ResumeBackorderAction
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function execute(Order $order, Employee $employee, Warehouse $warehouse): Order
    {
        return DB::transaction(function () use ($order, $employee, $warehouse) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($order->status !== OrderStatus::Backorder) {
                throw new RuntimeException("Order #{$order->order_number} isn't in Backorder.");
            }

            foreach ($order->items()->with('productVariant.product')->get() as $item) {
                $variant = $item->productVariant;
                if (! $variant->product->inventory_tracking_enabled) {
                    // Still an Advertisement product — nothing converted
                    // yet, so this item still can't be reserved.
                    throw new RuntimeException("Product for order item #{$item->id} hasn't been converted to Real yet.");
                }

                $this->inventory->reserve($variant, $warehouse, $item->quantity, $order, $employee);
            }

            $order->update([
                'status' => OrderStatus::Confirmed,
                'customer_status' => CustomerOrderStatus::Processing,
            ]);

            $order->statusHistory()->create([
                'from_status' => OrderStatus::Backorder->value,
                'to_status' => OrderStatus::Confirmed->value,
                'changed_by' => $employee->id,
                'reason' => 'Resumed from backorder — stock now available',
            ]);

            return $order->fresh();
        });
    }
}
