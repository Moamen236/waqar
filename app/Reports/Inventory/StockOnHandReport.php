<?php

namespace App\Reports\Inventory;

use App\Models\Employee;
use App\Models\WarehouseInventory;
use App\Reports\Concerns\AppliesStandardFilters;
use App\Reports\Concerns\MeasuresSales;
use App\Reports\ReportColumn;
use App\Reports\ReportDefinition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * INV-01 · Stock on Hand & Valuation — what is in each warehouse now, and
 * what it is worth.
 *
 * A snapshot, so it takes no date range: `warehouse_inventory` holds
 * current state only and has no history to filter. (Stock value *over
 * time* would need a daily rollup, which is deliberately not built —
 * nothing in this module needs it yet.)
 *
 * `available` is computed at read time, never stored — the existing rule
 * from spec Section 07, which `WarehouseInventory::getAvailableAttribute`
 * already implements for single rows and this report reproduces in SQL so
 * it can sort and filter on it.
 */
class StockOnHandReport extends ReportDefinition
{
    use AppliesStandardFilters, MeasuresSales;

    public function key(): string
    {
        return 'inventory.stock-on-hand';
    }

    public function group(): string
    {
        return 'inventory';
    }

    public function title(): string
    {
        return 'reports.inventory.stock.title';
    }

    public function description(): string
    {
        return 'reports.inventory.stock.description';
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
        return ['warehouse_id', 'category_id', 'product_id', 'variant_id'];
    }

    /**
     * @return list<ReportColumn>
     */
    public function columns(): array
    {
        return [
            ReportColumn::text('warehouse', 'reports.columns.warehouse'),
            ReportColumn::text('sku', 'reports.columns.sku'),
            ReportColumn::text('product', 'reports.columns.product'),
            ReportColumn::number('quantity', 'reports.columns.on_hand'),
            ReportColumn::number('reserved', 'reports.columns.reserved'),
            ReportColumn::number('available', 'reports.columns.available'),
            ReportColumn::money('unit_cost', 'reports.columns.unit_cost', true, 'reports.cost.view'),
            ReportColumn::money('cost_value', 'reports.columns.cost_value', true, 'reports.cost.view'),
            ReportColumn::money('retail_value', 'reports.columns.retail_value'),
        ];
    }

    public function defaultSort(): ?array
    {
        return ['available', 'asc'];
    }

    public function query(Employee $employee, array $filters): Builder
    {
        $cost = $this->unitCostSql();
        $price = 'COALESCE(product_variants.sale_price, product_variants.price, products.sale_price, products.price, 0)';

        return WarehouseInventory::query()
            ->join('warehouses', 'warehouses.id', '=', 'warehouse_inventory.warehouse_id')
            ->join('product_variants', 'product_variants.id', '=', 'warehouse_inventory.product_variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->whereNull('products.deleted_at')
            ->when($this->listOf($filters, 'warehouse_id'), fn ($q, array $v) => $q->whereIn('warehouse_inventory.warehouse_id', $v))
            ->when($this->listOf($filters, 'variant_id'), fn ($q, array $v) => $q->whereIn('warehouse_inventory.product_variant_id', $v))
            ->when($this->listOf($filters, 'product_id'), fn ($q, array $v) => $q->whereIn('products.id', $v))
            ->when($this->listOf($filters, 'category_id'), fn ($q, array $v) => $q->whereExists(fn ($sub) => $sub
                ->selectRaw('1')
                ->from('product_categories')
                ->whereColumn('product_categories.product_id', 'products.id')
                ->whereIn('product_categories.category_id', $v)))
            ->selectRaw('warehouses.name as warehouse')
            ->selectRaw('product_variants.sku as sku')
            ->selectRaw($this->translatedName('products.name').' as product')
            ->selectRaw('warehouse_inventory.quantity as quantity')
            ->selectRaw('warehouse_inventory.reserved_quantity as reserved')
            ->selectRaw('(warehouse_inventory.quantity - warehouse_inventory.reserved_quantity) as available')
            ->selectRaw("{$cost} as unit_cost")
            ->selectRaw("(warehouse_inventory.quantity * {$cost}) as cost_value")
            ->selectRaw("(warehouse_inventory.quantity * {$price}) as retail_value")
            ->orderBy('available');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function totals(Employee $employee, array $filters): array
    {
        // Wraps the row query rather than re-deriving the arithmetic, so a
        // change to how cost_value is computed can never leave the footer
        // disagreeing with the column above it.
        $totals = DB::query()
            ->fromSub($this->query($employee, $filters)->reorder(), 'stock')
            ->selectRaw('COALESCE(SUM(stock.quantity), 0) as quantity')
            ->selectRaw('COALESCE(SUM(stock.reserved), 0) as reserved')
            ->selectRaw('COALESCE(SUM(stock.available), 0) as available')
            ->selectRaw('COALESCE(SUM(stock.cost_value), 0) as cost_value')
            ->selectRaw('COALESCE(SUM(stock.retail_value), 0) as retail_value')
            ->first();

        return $totals === null ? [] : [
            'quantity' => (int) $totals->quantity,
            'reserved' => (int) $totals->reserved,
            'available' => (int) $totals->available,
            'cost_value' => (float) $totals->cost_value,
            'retail_value' => (float) $totals->retail_value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function map(object $row): array
    {
        return [
            'warehouse' => $row->warehouse,
            'sku' => $row->sku,
            'product' => $row->product,
            'quantity' => (int) $row->quantity,
            'reserved' => (int) $row->reserved,
            'available' => (int) $row->available,
            'unit_cost' => (float) $row->unit_cost,
            'cost_value' => (float) $row->cost_value,
            'retail_value' => (float) $row->retail_value,
        ];
    }
}
