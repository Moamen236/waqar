<?php

namespace App\Reports\Inventory;

use App\Enums\InventoryMovementType;
use App\Models\Employee;
use App\Models\InventoryMovement;
use App\Reports\Concerns\AppliesStandardFilters;
use App\Reports\Concerns\MeasuresSales;
use App\Reports\ReportColumn;
use App\Reports\ReportDefinition;
use Illuminate\Database\Eloquent\Builder;

/**
 * INV-06 · Shrinkage & Adjustments — stock written off, and who wrote it off.
 *
 * A control report, deliberately separate from the full ledger (INV-03).
 * The ledger answers "what happened to this SKU"; this answers "what left
 * the building without being sold, and on whose authority" — which is a
 * question someone should be able to ask without wading through every
 * reservation and sale in the period.
 *
 * Grouped by employee and type rather than listed line by line, because
 * the pattern is the signal: one adjustment is an afternoon, forty from
 * the same person is a conversation.
 */
class ShrinkageReport extends ReportDefinition
{
    use AppliesStandardFilters, MeasuresSales;

    public function key(): string
    {
        return 'inventory.shrinkage';
    }

    public function group(): string
    {
        return 'inventory';
    }

    public function title(): string
    {
        return 'reports.inventory.shrinkage.title';
    }

    public function description(): string
    {
        return 'reports.inventory.shrinkage.description';
    }

    public function permission(): string
    {
        return 'reports.inventory.view';
    }

    public function isPaginated(): bool
    {
        return false;
    }

    /**
     * @return list<string>
     */
    public function filters(): array
    {
        return [
            'preset', 'date_from', 'date_to',
            'warehouse_id', 'movement_type', 'employee_id',
            'category_id', 'product_id', 'variant_id',
        ];
    }

    /**
     * @return list<ReportColumn>
     */
    public function columns(): array
    {
        return [
            ReportColumn::text('warehouse', 'reports.columns.warehouse'),
            ReportColumn::enum('type', 'reports.columns.movement_type', 'reports.movementType.'),
            ReportColumn::text('performed_by', 'reports.columns.performed_by'),
            ReportColumn::number('movements', 'reports.columns.events'),
            ReportColumn::number('units', 'reports.columns.units'),
            ReportColumn::money('cost_value', 'reports.columns.cost_value', true, 'reports.cost.view'),
        ];
    }

    public function defaultSort(): ?array
    {
        return ['units', 'desc'];
    }

    public function query(Employee $employee, array $filters): Builder
    {
        $period = $this->resolvePeriod($filters);
        $cost = $this->unitCostSql();

        return InventoryMovement::query()
            ->join('warehouses', 'warehouses.id', '=', 'inventory_movements.warehouse_id')
            ->join('product_variants', 'product_variants.id', '=', 'inventory_movements.product_variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->leftJoin('employees', 'employees.id', '=', 'inventory_movements.created_by')
            ->whereIn('inventory_movements.type', [
                InventoryMovementType::Adjustment->value,
                InventoryMovementType::Damaged->value,
                InventoryMovementType::Lost->value,
            ])
            ->where('inventory_movements.created_at', '>=', $period['from'])
            ->where('inventory_movements.created_at', '<', $period['to'])
            ->when($this->listOf($filters, 'warehouse_id'), fn ($q, array $v) => $q->whereIn('inventory_movements.warehouse_id', $v))
            ->when($this->listOf($filters, 'movement_type'), fn ($q, array $v) => $q->whereIn('inventory_movements.type', $v))
            ->when($this->listOf($filters, 'employee_id'), fn ($q, array $v) => $q->whereIn('inventory_movements.created_by', $v))
            ->when($this->listOf($filters, 'variant_id'), fn ($q, array $v) => $q->whereIn('inventory_movements.product_variant_id', $v))
            ->when($this->listOf($filters, 'product_id'), fn ($q, array $v) => $q->whereIn('products.id', $v))
            ->selectRaw('warehouses.name as warehouse')
            ->selectRaw('inventory_movements.type as type')
            ->selectRaw('employees.full_name as performed_by')
            ->selectRaw('COUNT(*) as movements')
            // ABS, because a write-off is stored as a negative movement and
            // a positive adjustment is a correction upward — summing them
            // raw would let the two cancel and report no shrinkage at all.
            ->selectRaw('COALESCE(SUM(ABS(inventory_movements.quantity)), 0) as units')
            ->selectRaw("COALESCE(SUM(ABS(inventory_movements.quantity) * {$cost}), 0) as cost_value")
            ->groupBy('warehouses.id', 'warehouse', 'inventory_movements.type', 'employees.id', 'performed_by')
            ->orderByDesc('units');
    }

    /**
     * @return array<string, mixed>
     */
    public function map(object $row): array
    {
        return [
            'warehouse' => $row->warehouse,
            'type' => $row->type instanceof \BackedEnum ? $row->type->value : $row->type,
            'performed_by' => $row->performed_by ?? __('reports.system'),
            'movements' => (int) $row->movements,
            'units' => (int) $row->units,
            'cost_value' => (float) $row->cost_value,
        ];
    }
}
