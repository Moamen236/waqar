<?php

namespace App\Reports\Sales;

use App\Models\Employee;
use App\Reports\Concerns\QueriesOrderLines;
use App\Reports\ReportColumn;
use App\Reports\ReportDefinition;
use Illuminate\Database\Eloquent\Builder;

/**
 * SAL-02 · Sales by Product & Variant — what actually sells, at SKU level.
 *
 * Display uses `order_items.product_name_snapshot` / `variant_sku_snapshot`
 * so renaming a product does not rewrite last quarter's report; the join to
 * `product_variants` exists only so the report can be *filtered* and
 * *grouped* by today's catalogue.
 */
class SalesByProductReport extends ReportDefinition
{
    use QueriesOrderLines;

    public function key(): string
    {
        return 'sales.by-product';
    }

    public function group(): string
    {
        return 'sales';
    }

    public function title(): string
    {
        return 'reports.sales.byProduct.title';
    }

    public function description(): string
    {
        return 'reports.sales.byProduct.description';
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

    /**
     * @return list<string>
     */
    public function filters(): array
    {
        return [
            'preset', 'date_from', 'date_to', 'date_basis',
            'order_source', 'category_id', 'product_id', 'variant_id', 'warehouse_id',
            'governorate_id', 'city_id', 'district_id', 'area_id',
        ];
    }

    /**
     * @return list<ReportColumn>
     */
    public function columns(): array
    {
        return [
            ReportColumn::text('sku', 'reports.columns.sku'),
            ReportColumn::text('product', 'reports.columns.product'),
            ReportColumn::number('units', 'reports.columns.units_sold'),
            ReportColumn::number('units_returned', 'reports.columns.units_returned'),
            ReportColumn::number('net_units', 'reports.columns.net_units'),
            ReportColumn::number('orders_count', 'reports.columns.orders'),
            ReportColumn::money('revenue', 'reports.columns.revenue'),
            ReportColumn::money('avg_price', 'reports.columns.avg_price'),
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
        return ['reports.notes.line_value_basis'];
    }

    public function query(Employee $employee, array $filters): Builder
    {
        $returned = $this->returnedUnitsSql();

        return $this->lineBase($employee, $filters)
            ->selectRaw('order_items.variant_sku_snapshot as sku')
            ->selectRaw('MAX(order_items.product_name_snapshot) as product')
            ->selectRaw('COALESCE(SUM(order_items.quantity), 0) as units')
            ->selectRaw("COALESCE(SUM({$returned}), 0) as units_returned")
            ->selectRaw('COUNT(DISTINCT order_items.order_id) as orders_count')
            ->selectRaw('COALESCE(SUM(order_items.subtotal), 0) as revenue')
            ->groupBy('order_items.variant_sku_snapshot')
            ->orderByDesc('revenue');
    }

    /**
     * @return array<string, mixed>
     */
    public function map(object $row): array
    {
        $units = (int) $row->units;
        $returned = (int) $row->units_returned;
        $revenue = (float) $row->revenue;

        return [
            'sku' => $row->sku,
            'product' => $row->product,
            'units' => $units,
            'units_returned' => $returned,
            'net_units' => $units - $returned,
            'orders_count' => (int) $row->orders_count,
            'revenue' => $revenue,
            'avg_price' => $units === 0 ? null : round($revenue / $units, 2),
            'return_rate' => $this->ratio((float) $returned, (float) $units),
        ];
    }
}
