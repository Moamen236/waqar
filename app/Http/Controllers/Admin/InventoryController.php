<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Inventory\AdjustStockAction;
use App\Enums\InventoryMovementType;
use App\Exceptions\InsufficientStockException;
use App\Exports\InventoryExport;
use App\Http\Controllers\Controller;
use App\Models\InventoryMovement;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use App\Services\Inventory\InventoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * /admin/inventory — stock on hand, and the one screen that can change it
 * without an order behind it (Section 07's `adjustment`/`damaged`/`lost`
 * movement types).
 *
 * Phase 3 built every order-driven stock path and Phase 4 built the
 * screens that drive them, but a manual correction had no route at all:
 * the movement types existed in the enum with nothing able to write one.
 * Phase 7 needs it to exist before it can be audited — "a manual stock
 * adjustment leaves a readable, attributed log entry" is one of the
 * roadmap's three completion criteria for this phase.
 */
class InventoryController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:inventory.view', only: ['index']),
            new Middleware('permission:inventory.export', only: ['export']),
            new Middleware('permission:inventory.adjust', only: ['adjust']),
        ];
    }

    public function index(Request $request): Response
    {
        $warehouseId = $request->integer('warehouse') ?: null;
        $search = trim((string) $request->string('search'));

        $stock = WarehouseInventory::query()
            ->with(['warehouse:id,name', 'productVariant:id,product_id,sku', 'productVariant.product:id,name'])
            ->when($warehouseId, fn ($query) => $query->where('warehouse_id', $warehouseId))
            ->when($search !== '', fn ($query) => $query->whereHas(
                'productVariant',
                fn ($variant) => $variant->where('sku', 'like', "%{$search}%")
            ))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (WarehouseInventory $row) => [
                'id' => $row->id,
                'warehouse' => $row->warehouse?->name,
                'sku' => $row->productVariant?->sku,
                'product' => $row->productVariant?->product?->name,
                'variant_id' => $row->product_variant_id,
                'warehouse_id' => $row->warehouse_id,
                'quantity' => $row->quantity,
                'reserved_quantity' => $row->reserved_quantity,
                // Reserved stock belongs to confirmed orders — showing
                // only `quantity` is what makes someone adjust away units
                // that are already sold.
                'available' => $row->available,
            ]);

        return Inertia::render('Inventory/Index', [
            'stock' => $stock,
            'warehouses' => Warehouse::query()->where('is_active', true)->get(['id', 'name']),
            'movementTypes' => array_map(
                fn (InventoryMovementType $type) => $type->value,
                InventoryService::MANUAL_ADJUSTMENT_TYPES,
            ),
            'filters' => ['warehouse' => $warehouseId, 'search' => $search],
            'recentMovements' => InventoryMovement::query()
                ->with(['productVariant:id,sku', 'warehouse:id,name', 'createdBy:id,full_name'])
                ->whereIn('type', InventoryService::MANUAL_ADJUSTMENT_TYPES)
                ->latest('id')
                ->limit(15)
                ->get()
                ->map(fn (InventoryMovement $movement) => [
                    'id' => $movement->id,
                    'sku' => $movement->productVariant->sku,
                    'warehouse' => $movement->warehouse?->name,
                    'type' => $movement->type->value,
                    'quantity' => $movement->quantity,
                    'notes' => $movement->notes,
                    'by' => $movement->createdBy?->full_name,
                    'at' => $movement->created_at?->toDateTimeString(),
                ]),
        ]);
    }

    /**
     * The same stock rows index() renders — same warehouse/search filters
     * — as an .xlsx download.
     */
    public function export(Request $request): BinaryFileResponse
    {
        $export = new InventoryExport(
            $request->integer('warehouse') ?: null,
            trim((string) $request->string('search')),
        );

        return $export->download('inventory-'.now()->format('Y-m-d_His').'.xlsx');
    }

    public function adjust(Request $request, AdjustStockAction $action): RedirectResponse
    {
        $data = $request->validate([
            'product_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'quantity' => ['required', 'integer', 'not_in:0'],
            'type' => ['required', Rule::in(array_map(fn (InventoryMovementType $t) => $t->value, InventoryService::MANUAL_ADJUSTMENT_TYPES))],
            // A correction without a stated reason is unauditable, which
            // is the whole point of routing this through here.
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        try {
            $action->execute(
                ProductVariant::findOrFail($data['product_variant_id']),
                Warehouse::findOrFail($data['warehouse_id']),
                (int) $data['quantity'],
                InventoryMovementType::from($data['type']),
                $data['reason'],
                $request->user('employee'),
            );
        } catch (InsufficientStockException) {
            return back()->with('error', __('That adjustment would cut into stock already reserved for confirmed orders.'));
        }

        return back()->with('success', __('Stock adjusted.'));
    }
}
