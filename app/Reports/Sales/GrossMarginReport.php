<?php

namespace App\Reports\Sales;

use App\Models\Employee;
use App\Reports\Concerns\QueriesOrderLines;
use App\Reports\ReportColumn;
use App\Reports\ReportDefinition;
use Illuminate\Database\Eloquent\Builder;

/**
 * SAL-06 · Gross Margin — profitability per SKU, not just revenue.
 *
 * Every column here is gated behind `reports.cost.view` on top of the
 * report's own permission: `products.cost_price` is documented in the
 * schema as internal-only, and the same reasoning applies to an operations
 * employee who may legitimately see what sold but not what it cost.
 *
 * **Costed at today's cost price.** `order_items` carries no cost snapshot,
 * so editing a cost price moves historical margin. Two things make that
 * workable rather than misleading: the note printed on the report says so,
 * and `cost_price` changes are audit-logged on both `Product` and
 * `ProductVariant`, so a reader can always check whether a restatement is
 * real. Rows with no cost recorded at all are surfaced as a separate count
 * rather than silently costed at zero, which would invent margin.
 */
class GrossMarginReport extends ReportDefinition
{
    use QueriesOrderLines;

    public function key(): string
    {
        return 'sales.margin';
    }

    public function group(): string
    {
        return 'sales';
    }

    public function title(): string
    {
        return 'reports.sales.margin.title';
    }

    public function description(): string
    {
        return 'reports.sales.margin.description';
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
     * Every money column carries the cost gate — a viewer without it sees
     * the report's units but none of its economics, rather than a table of
     * zeros that reads as "we make no margin".
     *
     * @return list<ReportColumn>
     */
    public function columns(): array
    {
        return [
            ReportColumn::text('sku', 'reports.columns.sku'),
            ReportColumn::text('product', 'reports.columns.product'),
            ReportColumn::number('units', 'reports.columns.units_sold'),
            ReportColumn::money('revenue', 'reports.columns.revenue', true, 'reports.cost.view'),
            ReportColumn::money('unit_cost', 'reports.columns.unit_cost', true, 'reports.cost.view'),
            ReportColumn::money('cogs', 'reports.columns.cogs', true, 'reports.cost.view'),
            ReportColumn::money('gross_profit', 'reports.columns.gross_profit', true, 'reports.cost.view'),
            ReportColumn::percent('margin', 'reports.columns.margin', true, 'reports.cost.view'),
            ReportColumn::number('uncosted_units', 'reports.columns.uncosted_units'),
        ];
    }

    public function defaultSort(): ?array
    {
        return ['gross_profit', 'desc'];
    }

    /**
     * @return list<string>
     */
    public function notes(): array
    {
        return ['reports.notes.current_cost_basis', 'reports.notes.line_value_basis'];
    }

    public function query(Employee $employee, array $filters): Builder
    {
        $cost = $this->unitCostSql();

        return $this->lineBase($employee, $filters)
            ->selectRaw('order_items.variant_sku_snapshot as sku')
            ->selectRaw('MAX(order_items.product_name_snapshot) as product')
            ->selectRaw('COALESCE(SUM(order_items.quantity), 0) as units')
            ->selectRaw('COALESCE(SUM(order_items.subtotal), 0) as revenue')
            ->selectRaw("COALESCE(SUM(order_items.quantity * {$cost}), 0) as cogs")
            // Units whose variant *and* product both lack a cost price.
            // Counted, not costed at zero: pretending an unknown cost is
            // nil manufactures margin that does not exist.
            ->selectRaw('COALESCE(SUM(CASE WHEN product_variants.cost_price IS NULL AND products.cost_price IS NULL THEN order_items.quantity ELSE 0 END), 0) as uncosted_units')
            ->groupBy('order_items.variant_sku_snapshot')
            ->orderByDesc('revenue');
    }

    /**
     * @return array<string, mixed>
     */
    public function map(object $row): array
    {
        $units = (int) $row->units;
        $revenue = (float) $row->revenue;
        $cogs = (float) $row->cogs;
        $profit = round($revenue - $cogs, 2);

        return [
            'sku' => $row->sku,
            'product' => $row->product,
            'units' => $units,
            'revenue' => $revenue,
            'unit_cost' => $units === 0 ? null : round($cogs / $units, 2),
            'cogs' => $cogs,
            'gross_profit' => $profit,
            'margin' => $this->ratio($profit, $revenue),
            'uncosted_units' => (int) $row->uncosted_units,
        ];
    }
}
