<?php

namespace App\Reports\Sales;

use App\Models\Employee;
use App\Reports\Concerns\QueriesOrderLines;
use App\Reports\ReportColumn;
use App\Reports\ReportDefinition;
use Illuminate\Database\Eloquent\Builder;

/**
 * SAL-03 · Sales by Category — the mix, and what each category contributes.
 *
 * A roll-up of the same lines SAL-02 measures, along a different axis, so
 * the two are not duplicates: one ranks SKUs, this one answers "which part
 * of the catalogue is carrying the quarter".
 *
 * **Rows do not sum to the period total.** `product_categories` is a
 * many-to-many, so a product filed under both "Dresses" and "New In"
 * contributes its revenue to each. That is the correct answer to "how much
 * did Dresses sell" and the wrong answer to "what did we sell in total" —
 * the note on the report says so rather than leaving a reader to discover
 * it by adding the column up.
 */
class SalesByCategoryReport extends ReportDefinition
{
    use QueriesOrderLines;

    public function key(): string
    {
        return 'sales.by-category';
    }

    public function group(): string
    {
        return 'sales';
    }

    public function title(): string
    {
        return 'reports.sales.byCategory.title';
    }

    public function description(): string
    {
        return 'reports.sales.byCategory.description';
    }

    public function permission(): string
    {
        return 'reports.sales.view';
    }

    public function defaultDateBasis(): string
    {
        return 'delivered';
    }

    public function availableDateBases(): array
    {
        return ['delivered', 'placed'];
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
            'preset', 'date_from', 'date_to', 'date_basis',
            'order_source', 'category_id', 'warehouse_id',
            'governorate_id', 'city_id', 'district_id', 'area_id',
        ];
    }

    /**
     * @return list<ReportColumn>
     */
    public function columns(): array
    {
        return [
            ReportColumn::text('category', 'reports.columns.category'),
            ReportColumn::text('parent', 'reports.columns.parent_category'),
            ReportColumn::number('units', 'reports.columns.units_sold'),
            ReportColumn::number('units_returned', 'reports.columns.units_returned'),
            ReportColumn::number('orders_count', 'reports.columns.orders'),
            ReportColumn::money('revenue', 'reports.columns.revenue'),
            ReportColumn::percent('return_rate', 'reports.columns.return_rate'),
        ];
    }

    public function defaultSort(): ?array
    {
        return ['revenue', 'desc'];
    }

    /**
     * @return list<string>
     */
    public function notes(): array
    {
        return ['reports.notes.category_overlap', 'reports.notes.line_value_basis'];
    }

    public function query(Employee $employee, array $filters): Builder
    {
        $returned = $this->returnedUnitsSql();
        $name = $this->translatedName('categories.name');
        $parentName = $this->translatedName('parents.name');

        return $this->lineBase($employee, $filters)
            ->join('product_categories', 'product_categories.product_id', '=', 'products.id')
            ->join('categories', 'categories.id', '=', 'product_categories.category_id')
            ->leftJoin('categories as parents', 'parents.id', '=', 'categories.parent_id')
            ->whereNull('categories.deleted_at')
            ->selectRaw("{$name} as category")
            ->selectRaw("{$parentName} as parent")
            ->selectRaw('COALESCE(SUM(order_items.quantity), 0) as units')
            ->selectRaw("COALESCE(SUM({$returned}), 0) as units_returned")
            ->selectRaw('COUNT(DISTINCT order_items.order_id) as orders_count')
            ->selectRaw('COALESCE(SUM(order_items.subtotal), 0) as revenue')
            ->groupBy('categories.id', 'category', 'parent')
            ->orderByDesc('revenue');
    }

    /**
     * @return array<string, mixed>
     */
    public function map(object $row): array
    {
        $units = (int) $row->units;
        $returned = (int) $row->units_returned;

        return [
            'category' => $row->category,
            'parent' => $row->parent,
            'units' => $units,
            'units_returned' => $returned,
            'orders_count' => (int) $row->orders_count,
            'revenue' => (float) $row->revenue,
            'return_rate' => $this->ratio((float) $returned, (float) $units),
        ];
    }
}
