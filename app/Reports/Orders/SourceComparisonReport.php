<?php

namespace App\Reports\Orders;

use App\Enums\OrderStatus;
use App\Models\Employee;
use App\Models\Order;
use App\Reports\Concerns\AppliesStandardFilters;
use App\Reports\ReportColumn;
use App\Reports\ReportDefinition;
use Illuminate\Database\Eloquent\Builder;

/**
 * ORD-06 · Website vs Customer Service — the one report whose whole purpose
 * is to contrast the two order sources.
 *
 * `order_source` is the axis here, not a filter, which is what stops this
 * being ORD-01 with a filter applied: both channels are always present, so
 * the difference is readable at a glance instead of requiring two runs.
 *
 * One row per channel, metrics across the columns — rather than the
 * metrics-down-the-side pivot the spec sketched. A pivot would need its own
 * handling in the table, the export and the print view for the sake of a
 * single report, and a transposed table exports worse: each column here is
 * one measure, so Excel can still sort and chart it.
 */
class SourceComparisonReport extends ReportDefinition
{
    use AppliesStandardFilters;

    public function key(): string
    {
        return 'orders.source-comparison';
    }

    public function group(): string
    {
        return 'orders';
    }

    public function title(): string
    {
        return 'reports.orders.source.title';
    }

    public function description(): string
    {
        return 'reports.orders.source.description';
    }

    public function permission(): string
    {
        return 'reports.orders.view';
    }

    public function isPaginated(): bool
    {
        return false;
    }

    public function availableDateBases(): array
    {
        return ['placed', 'delivered'];
    }

    /**
     * `order_source` is deliberately absent — it is the axis, and offering
     * it as a filter would collapse the report to one row.
     *
     * @return list<string>
     */
    public function filters(): array
    {
        return [
            'preset', 'date_from', 'date_to', 'date_basis',
            'governorate_id', 'city_id', 'district_id', 'area_id',
            'category_id', 'product_id', 'variant_id', 'warehouse_id',
        ];
    }

    /**
     * @return list<ReportColumn>
     */
    public function columns(): array
    {
        return [
            ReportColumn::enum('order_source', 'reports.columns.source', 'reports.source.'),
            ReportColumn::number('orders_count', 'reports.columns.orders'),
            ReportColumn::number('units', 'reports.columns.units'),
            ReportColumn::money('gross', 'reports.columns.gross_value'),
            ReportColumn::money('discount', 'reports.columns.discount'),
            ReportColumn::money('aov', 'reports.columns.aov'),
            ReportColumn::number('delivered', 'reports.columns.delivered'),
            ReportColumn::number('cancelled', 'reports.columns.cancelled'),
            ReportColumn::percent('delivery_rate', 'reports.columns.delivery_rate'),
            ReportColumn::percent('cancellation_rate', 'reports.columns.cancellation_rate'),
        ];
    }

    public function defaultSort(): ?array
    {
        return ['gross', 'desc'];
    }

    public function query(Employee $employee, array $filters): Builder
    {
        $delivered = OrderStatus::Delivered->value;
        $partial = OrderStatus::PartiallyReturned->value;
        $cancelled = OrderStatus::Cancelled->value;

        $period = $this->resolvePeriod($filters);
        $query = Order::query()->visibleTo($employee);
        $query = $this->applyOrderDateBasis($query, $this->dateBasis($filters, 'placed'), $period);

        return $this->applyOrderFilters($query, $filters)
            ->selectRaw('orders.order_source as order_source')
            ->selectRaw('COUNT(*) as orders_count')
            ->selectRaw('COALESCE(SUM((SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi WHERE oi.order_id = orders.id)), 0) as units')
            ->selectRaw('COALESCE(SUM(orders.total), 0) as gross')
            ->selectRaw('COALESCE(SUM(orders.discount_amount), 0) as discount')
            ->selectRaw("SUM(CASE WHEN orders.status IN ('{$delivered}', '{$partial}') THEN 1 ELSE 0 END) as delivered")
            ->selectRaw("SUM(CASE WHEN orders.status = '{$cancelled}' THEN 1 ELSE 0 END) as cancelled")
            ->groupBy('orders.order_source')
            ->orderByDesc('gross');
    }

    /**
     * @return array<string, mixed>
     */
    public function map(object $row): array
    {
        $orders = (int) $row->orders_count;
        $gross = (float) $row->gross;

        return [
            'order_source' => $row->order_source instanceof \BackedEnum
                ? $row->order_source->value
                : $row->order_source,
            'orders_count' => $orders,
            'units' => (int) $row->units,
            'gross' => $gross,
            'discount' => (float) $row->discount,
            'aov' => $orders === 0 ? null : round($gross / $orders, 2),
            'delivered' => (int) $row->delivered,
            'cancelled' => (int) $row->cancelled,
            'delivery_rate' => $this->ratio((float) $row->delivered, (float) $orders),
            'cancellation_rate' => $this->ratio((float) $row->cancelled, (float) $orders),
        ];
    }
}
