<?php

namespace App\Reports\Inventory;

use App\Enums\InventoryMovementType;
use App\Models\Employee;
use App\Models\WarehouseInventory;
use App\Reports\Concerns\AppliesStandardFilters;
use App\Reports\ReportColumn;
use App\Reports\ReportDefinition;
use Illuminate\Database\Eloquent\Builder;

/**
 * INV-07 · Reservation Integrity — reservations that never got released or
 * deducted.
 *
 * A data-health report rather than a business one, and the only thing in
 * the module that exists to catch a bug rather than describe the business.
 * It earns its place because the failure it detects is invisible and
 * expensive: `reserved_quantity` that no live order is holding makes stock
 * unsellable, and the storefront reports "out of stock" for goods sitting
 * on the shelf.
 *
 * Two independent views of the same number are compared — the stored
 * `warehouse_inventory.reserved_quantity`, and the sum of reservation
 * movements minus releases and sales. They should always agree; a non-zero
 * variance is the report's entire point.
 */
class ReservationIntegrityReport extends ReportDefinition
{
    use AppliesStandardFilters;

    public function key(): string
    {
        return 'inventory.reservations';
    }

    public function group(): string
    {
        return 'inventory';
    }

    public function title(): string
    {
        return 'reports.inventory.reservations.title';
    }

    public function description(): string
    {
        return 'reports.inventory.reservations.description';
    }

    public function permission(): string
    {
        return 'reports.inventory.view';
    }

    /**
     * @return list<string>
     */
    public function filters(): array
    {
        return ['warehouse_id', 'variant_id', 'variance_only'];
    }

    /**
     * @return list<ReportColumn>
     */
    public function columns(): array
    {
        return [
            ReportColumn::text('warehouse', 'reports.columns.warehouse'),
            ReportColumn::text('sku', 'reports.columns.sku'),
            ReportColumn::number('reserved_stored', 'reports.columns.reserved_stored'),
            ReportColumn::number('reserved_movements', 'reports.columns.reserved_movements'),
            ReportColumn::number('variance', 'reports.columns.variance'),
        ];
    }

    public function defaultSort(): ?array
    {
        return ['variance', 'desc'];
    }

    /**
     * @return list<string>
     */
    public function notes(): array
    {
        return ['reports.notes.reservation_variance'];
    }

    public function query(Employee $employee, array $filters): Builder
    {
        $reservation = InventoryMovementType::Reservation->value;
        $release = InventoryMovementType::Release->value;
        $sale = InventoryMovementType::Sale->value;

        // Reservations are stored positive; releases and sales negative
        // (InventoryService::recordMovement). Summing all three signed
        // gives what should still be held.
        $fromMovements = "(SELECT COALESCE(SUM(
                                CASE WHEN im.type = '{$reservation}' THEN im.quantity
                                     WHEN im.type IN ('{$release}', '{$sale}') THEN im.quantity
                                     ELSE 0 END), 0)
                           FROM inventory_movements im
                           WHERE im.warehouse_id = warehouse_inventory.warehouse_id
                             AND im.product_variant_id = warehouse_inventory.product_variant_id
                             AND im.type IN ('{$reservation}', '{$release}', '{$sale}'))";

        $query = WarehouseInventory::query()
            ->join('warehouses', 'warehouses.id', '=', 'warehouse_inventory.warehouse_id')
            ->join('product_variants', 'product_variants.id', '=', 'warehouse_inventory.product_variant_id')
            ->when($this->listOf($filters, 'warehouse_id'), fn ($q, array $v) => $q->whereIn('warehouse_inventory.warehouse_id', $v))
            ->when($this->listOf($filters, 'variant_id'), fn ($q, array $v) => $q->whereIn('warehouse_inventory.product_variant_id', $v))
            ->selectRaw('warehouses.name as warehouse')
            ->selectRaw('product_variants.sku as sku')
            ->selectRaw('warehouse_inventory.reserved_quantity as reserved_stored')
            ->selectRaw("{$fromMovements} as reserved_movements")
            ->selectRaw("(warehouse_inventory.reserved_quantity - {$fromMovements}) as variance");

        // Default to problems only: a clean system produces thousands of
        // zero-variance rows, and a report nobody can skim is a report
        // nobody opens.
        if (($filters['variance_only'] ?? '1') !== '0') {
            $query->havingRaw('variance <> 0');
        }

        return $query->orderByRaw('ABS(variance) DESC');
    }

    /**
     * @return array<string, mixed>
     */
    public function map(object $row): array
    {
        return [
            'warehouse' => $row->warehouse,
            'sku' => $row->sku,
            'reserved_stored' => (int) $row->reserved_stored,
            'reserved_movements' => (int) $row->reserved_movements,
            'variance' => (int) $row->variance,
        ];
    }
}
