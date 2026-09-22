<?php

namespace App\Reports\Inventory;

use App\Models\Employee;
use App\Models\InventoryMovement;
use App\Reports\Concerns\AppliesStandardFilters;
use App\Reports\ReportColumn;
use App\Reports\ReportDefinition;
use Illuminate\Database\Eloquent\Builder;

/**
 * INV-03 · Inventory Movement Ledger — every stock change, with its cause
 * and its actor.
 *
 * The inventory counterpart of the audit trail, and more durable than it:
 * `inventory_movements` is never pruned, while `activity_log` keeps a year.
 * `created_by` is nullable by design — a null actor means the movement was
 * system-triggered by an order, not that attribution was lost — so the
 * report says "system" rather than leaving the cell blank.
 */
class MovementLedgerReport extends ReportDefinition
{
    use AppliesStandardFilters;

    public function key(): string
    {
        return 'inventory.movements';
    }

    public function group(): string
    {
        return 'inventory';
    }

    public function title(): string
    {
        return 'reports.inventory.movements.title';
    }

    public function description(): string
    {
        return 'reports.inventory.movements.description';
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
            ReportColumn::datetime('occurred_at', 'reports.columns.datetime'),
            ReportColumn::text('warehouse', 'reports.columns.warehouse'),
            ReportColumn::text('sku', 'reports.columns.sku'),
            ReportColumn::text('product', 'reports.columns.product'),
            ReportColumn::enum('type', 'reports.columns.movement_type', 'reports.movementType.'),
            ReportColumn::number('quantity', 'reports.columns.quantity'),
            ReportColumn::text('reference', 'reports.columns.reference'),
            ReportColumn::text('performed_by', 'reports.columns.performed_by'),
            ReportColumn::text('notes', 'reports.columns.notes'),
        ];
    }

    public function defaultSort(): ?array
    {
        return ['occurred_at', 'desc'];
    }

    public function query(Employee $employee, array $filters): Builder
    {
        $period = $this->resolvePeriod($filters);

        return InventoryMovement::query()
            ->join('warehouses', 'warehouses.id', '=', 'inventory_movements.warehouse_id')
            ->join('product_variants', 'product_variants.id', '=', 'inventory_movements.product_variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->leftJoin('employees', 'employees.id', '=', 'inventory_movements.created_by')
            ->where('inventory_movements.created_at', '>=', $period['from'])
            ->where('inventory_movements.created_at', '<', $period['to'])
            ->when($this->listOf($filters, 'warehouse_id'), fn ($q, array $v) => $q->whereIn('inventory_movements.warehouse_id', $v))
            ->when($this->listOf($filters, 'movement_type'), fn ($q, array $v) => $q->whereIn('inventory_movements.type', $v))
            ->when($this->listOf($filters, 'employee_id'), fn ($q, array $v) => $q->whereIn('inventory_movements.created_by', $v))
            ->when($this->listOf($filters, 'variant_id'), fn ($q, array $v) => $q->whereIn('inventory_movements.product_variant_id', $v))
            ->when($this->listOf($filters, 'product_id'), fn ($q, array $v) => $q->whereIn('products.id', $v))
            ->when($this->listOf($filters, 'category_id'), fn ($q, array $v) => $q->whereExists(fn ($sub) => $sub
                ->selectRaw('1')
                ->from('product_categories')
                ->whereColumn('product_categories.product_id', 'products.id')
                ->whereIn('product_categories.category_id', $v)))
            ->selectRaw('inventory_movements.created_at as occurred_at')
            ->selectRaw('warehouses.name as warehouse')
            ->selectRaw('product_variants.sku as sku')
            ->selectRaw($this->translatedName('products.name').' as product')
            ->selectRaw('inventory_movements.type as type')
            ->selectRaw('inventory_movements.quantity as quantity')
            ->selectRaw('inventory_movements.reference_type as reference_type')
            ->selectRaw('inventory_movements.reference_id as reference_id')
            ->selectRaw('employees.full_name as performed_by')
            ->selectRaw('inventory_movements.notes as notes')
            ->orderByDesc('inventory_movements.created_at');
    }

    /**
     * @return array<string, mixed>
     */
    public function map(object $row): array
    {
        $reference = $row->reference_type === null
            ? null
            : class_basename((string) $row->reference_type).' #'.$row->reference_id;

        return [
            'occurred_at' => $row->occurred_at,
            'warehouse' => $row->warehouse,
            'sku' => $row->sku,
            'product' => $row->product,
            'type' => $row->type instanceof \BackedEnum ? $row->type->value : $row->type,
            'quantity' => (int) $row->quantity,
            'reference' => $reference,
            // A null actor is a system-triggered movement (an order
            // reserving or deducting), not missing attribution.
            'performed_by' => $row->performed_by ?? __('reports.system'),
            'notes' => $row->notes,
        ];
    }
}
