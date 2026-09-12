<?php

namespace App\Actions\Orders;

use App\Enums\CustomerOrderStatus;
use App\Enums\OrderStatus;
use App\Models\Employee;
use App\Models\Order;
use App\Services\Inventory\InventoryService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Cancelled → release the reservation, no deduction ever happened
 * (Section 02, 07). Section 03: "Checking employee (or customer, if
 * Pending)" can cancel — this Action doesn't itself enforce who's
 * allowed, that's a Policy/permission concern (Phase 4); it assumes the
 * caller has already authorized the cancellation.
 */
class CancelOrderAction
{
    private const CANCELLABLE_FROM = [
        OrderStatus::New,
        OrderStatus::Checking,
        OrderStatus::Confirmed,
        OrderStatus::Postponed,
        OrderStatus::Backorder,
    ];

    public function __construct(private readonly InventoryService $inventory) {}

    public function execute(Order $order, ?Employee $changedBy, string $reason): Order
    {
        return DB::transaction(function () use ($order, $changedBy, $reason) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if (! in_array($order->status, self::CANCELLABLE_FROM, true)) {
                throw new RuntimeException("Order #{$order->order_number} can't be cancelled from status {$order->status->value}.");
            }

            $fromStatus = $order->status;

            foreach ($order->items()->with('productVariant.product')->get() as $item) {
                $variant = $item->productVariant;
                if (! $variant->product->inventory_tracking_enabled) {
                    continue;
                }

                $warehouse = $this->inventory->warehouseForReservation($order, $variant);
                if ($warehouse !== null) {
                    $this->inventory->release($variant, $warehouse, $item->quantity, $order, $changedBy);
                }
            }

            $order->update([
                'status' => OrderStatus::Cancelled,
                'customer_status' => CustomerOrderStatus::Cancelled,
            ]);

            $order->statusHistory()->create([
                'from_status' => $fromStatus->value,
                'to_status' => OrderStatus::Cancelled->value,
                'changed_by' => $changedBy?->id,
                'reason' => $reason,
            ]);

            return $order->fresh();
        });
    }
}
