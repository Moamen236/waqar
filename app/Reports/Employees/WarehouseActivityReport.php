<?php

namespace App\Reports\Employees;

use App\Enums\InventoryMovementType;
use App\Models\Employee;
use App\Models\InventoryMovement;
use App\Reports\Concerns\AppliesStandardFilters;
use App\Reports\ReportColumn;
use App\Reports\ReportDefinition;
use Illuminate\Database\Eloquent\Builder;

/**
 * EMP-06 · Warehouse Activity — inventory work per employee.
 *
 * Only movements with a human behind them: `inventory_movements.created_by`
 * is null for the reservations and deductions an order triggers, and
 * counting those would credit whoever happens to be null with the entire
 * order book's stock traffic.
 *
 * Adjustments and write-offs are broken out separately from the total,
 * because those are the discretionary ones — the movements an employee
 * chose to make rather than ones a return or transfer required.
 */
class WarehouseActivityReport extends ReportDefinition
{
    use AppliesStandardFilters;

    public function key(): string
    {
        return 'employees.warehouse';
    }

    public function group(): string
    {
        return 'employees';
    }

    public function title(): string
    {
        return 'reports.employees.warehouse.title';
    }

    public function description(): string
    {
        return 'reports.employees.warehouse.description';
    }

    public function permission(): string
    {
        return 'reports.employees.view';
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
        return ['preset', 'date_from', 'date_to', 'employee_id', 'warehouse_id', 'movement_type'];
    }

    /**
     * @return list<ReportColumn>
     */
    public function columns(): array
    {
        return [
            ReportColumn::text('employee', 'reports.columns.employee'),
            ReportColumn::text('warehouse', 'reports.columns.warehouse'),
            ReportColumn::number('movements', 'reports.columns.movements'),
            ReportColumn::number('units_in', 'reports.columns.units_in'),
            ReportColumn::number('units_out', 'reports.columns.units_out'),
            ReportColumn::number('adjustments', 'reports.columns.adjustments'),
            ReportColumn::number('write_offs', 'reports.columns.write_offs'),
            ReportColumn::number('returns_received', 'reports.columns.returns_received'),
        ];
    }

    public function defaultSort(): ?array
    {
        return ['movements', 'desc'];
    }

    public function query(Employee $employee, array $filters): Builder
    {
        $period = $this->resolvePeriod($filters);

        $adjustment = InventoryMovementType::Adjustment->value;
        $damaged = InventoryMovementType::Damaged->value;
        $lost = InventoryMovementType::Lost->value;
        $returnStock = InventoryMovementType::ReturnStock->value;

        return InventoryMovement::query()
            ->join('employees', 'employees.id', '=', 'inventory_movements.created_by')
            ->join('warehouses', 'warehouses.id', '=', 'inventory_movements.warehouse_id')
            ->whereNotNull('inventory_movements.created_by')
            ->where('inventory_movements.created_at', '>=', $period['from'])
            ->where('inventory_movements.created_at', '<', $period['to'])
            ->when($this->listOf($filters, 'employee_id'), fn ($q, array $v) => $q->whereIn('inventory_movements.created_by', $v))
            ->when($this->listOf($filters, 'warehouse_id'), fn ($q, array $v) => $q->whereIn('inventory_movements.warehouse_id', $v))
            ->when($this->listOf($filters, 'movement_type'), fn ($q, array $v) => $q->whereIn('inventory_movements.type', $v))
            ->selectRaw('employees.full_name as employee')
            ->selectRaw('warehouses.name as warehouse')
            ->selectRaw('COUNT(*) as movements')
            ->selectRaw('COALESCE(SUM(CASE WHEN inventory_movements.quantity > 0 THEN inventory_movements.quantity ELSE 0 END), 0) as units_in')
            ->selectRaw('COALESCE(SUM(CASE WHEN inventory_movements.quantity < 0 THEN ABS(inventory_movements.quantity) ELSE 0 END), 0) as units_out')
            ->selectRaw("SUM(CASE WHEN inventory_movements.type = '{$adjustment}' THEN 1 ELSE 0 END) as adjustments")
            ->selectRaw("SUM(CASE WHEN inventory_movements.type IN ('{$damaged}', '{$lost}') THEN 1 ELSE 0 END) as write_offs")
            ->selectRaw("SUM(CASE WHEN inventory_movements.type = '{$returnStock}' THEN 1 ELSE 0 END) as returns_received")
            ->groupBy('employees.id', 'employee', 'warehouses.id', 'warehouse')
            ->orderByDesc('movements');
    }

    /**
     * @return array<string, mixed>
     */
    public function map(object $row): array
    {
        return [
            'employee' => $row->employee,
            'warehouse' => $row->warehouse,
            'movements' => (int) $row->movements,
            'units_in' => (int) $row->units_in,
            'units_out' => (int) $row->units_out,
            'adjustments' => (int) $row->adjustments,
            'write_offs' => (int) $row->write_offs,
            'returns_received' => (int) $row->returns_received,
        ];
    }
}
