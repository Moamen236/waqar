<?php

namespace App\Reports\Returns;

use App\Enums\OrderStatus;
use App\Models\Employee;
use App\Models\OrderItem;
use App\Reports\Concerns\AppliesStandardFilters;
use App\Reports\ReportColumn;
use App\Reports\ReportDefinition;
use Illuminate\Database\Eloquent\Builder;

/**
 * RET-03 · Product Return Rate — which SKUs come back disproportionately.
 *
 * Rooted in what was *sold* rather than what was returned, because a
 * return count on its own is meaningless: ten returns on a thousand units
 * is noise, ten on twelve is a product problem. The denominator is the
 * point of this report, and it is what makes it distinct from RET-02.
 *
 * A minimum-units filter guards the top of the ranking — without it a SKU
 * that sold one unit and had it returned sits at 100% above everything
 * that matters.
 */
class ProductReturnRateReport extends ReportDefinition
{
    use AppliesStandardFilters;

    public function key(): string
    {
        return 'returns.by-product';
    }

    public function group(): string
    {
        return 'returns';
    }

    public function title(): string
    {
        return 'reports.returns.byProduct.title';
    }

    public function description(): string
    {
        return 'reports.returns.byProduct.description';
    }

    public function permission(): string
    {
        return 'reports.returns.view';
    }

    /**
     * @return list<string>
     */
    public function filters(): array
    {
        return [
            'preset', 'date_from', 'date_to',
            'category_id', 'product_id', 'variant_id', 'min_units',
            'order_source', 'governorate_id', 'city_id', 'area_id',
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
            ReportColumn::number('units_sold', 'reports.columns.units_sold'),
            ReportColumn::number('units_returned', 'reports.columns.units_returned'),
            ReportColumn::percent('return_rate', 'reports.columns.return_rate'),
            ReportColumn::money('value_returned', 'reports.columns.value_returned'),
        ];
    }

    public function defaultSort(): ?array
    {
        return ['return_rate', 'desc'];
    }

    /**
     * @return list<string>
     */
    public function notes(): array
    {
        return ['reports.notes.min_units_guard'];
    }

    public function query(Employee $employee, array $filters): Builder
    {
        $period = $this->resolvePeriod($filters);
        $sold = OrderStatus::Delivered->value;
        $partial = OrderStatus::PartiallyReturned->value;
        $minUnits = max(1, (int) ($filters['min_units'] ?? 1));

        $returned = '(SELECT COALESCE(SUM(ri.quantity), 0) FROM return_items ri WHERE ri.order_item_id = order_items.id)';

        return OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('product_variants', 'product_variants.id', '=', 'order_items.product_variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->whereIn('orders.id', $this->visibleOrders($employee))
            ->whereIn('orders.status', [$sold, $partial])
            ->where('orders.created_at', '>=', $period['from'])
            ->where('orders.created_at', '<', $period['to'])
            ->when(! empty($filters['order_source']), fn ($q) => $q->where('orders.order_source', (string) $filters['order_source']))
            ->when(! empty($filters['governorate_id']), fn ($q) => $q->where('orders.shipping_governorate_id', (int) $filters['governorate_id']))
            ->when(! empty($filters['city_id']), fn ($q) => $q->where('orders.shipping_city_id', (int) $filters['city_id']))
            ->when(! empty($filters['area_id']), fn ($q) => $q->where('orders.shipping_area_id', (int) $filters['area_id']))
            ->when($this->listOf($filters, 'variant_id'), fn ($q, array $v) => $q->whereIn('order_items.product_variant_id', $v))
            ->when($this->listOf($filters, 'product_id'), fn ($q, array $v) => $q->whereIn('products.id', $v))
            ->when($this->listOf($filters, 'category_id'), fn ($q, array $v) => $q->whereExists(fn ($sub) => $sub
                ->selectRaw('1')
                ->from('product_categories')
                ->whereColumn('product_categories.product_id', 'products.id')
                ->whereIn('product_categories.category_id', $v)))
            ->selectRaw('order_items.variant_sku_snapshot as sku')
            ->selectRaw('MAX(order_items.product_name_snapshot) as product')
            ->selectRaw('COALESCE(SUM(order_items.quantity), 0) as units_sold')
            ->selectRaw("COALESCE(SUM({$returned}), 0) as units_returned")
            ->selectRaw("COALESCE(SUM({$returned} * order_items.unit_price), 0) as value_returned")
            ->groupBy('order_items.variant_sku_snapshot')
            ->havingRaw('units_sold >= ?', [$minUnits])
            ->havingRaw('units_returned > 0')
            ->orderByRaw('(units_returned / units_sold) DESC');
    }

    /**
     * @return array<string, mixed>
     */
    public function map(object $row): array
    {
        $sold = (int) $row->units_sold;
        $returned = (int) $row->units_returned;

        return [
            'sku' => $row->sku,
            'product' => $row->product,
            'units_sold' => $sold,
            'units_returned' => $returned,
            'return_rate' => $this->ratio((float) $returned, (float) $sold),
            'value_returned' => (float) $row->value_returned,
        ];
    }
}
