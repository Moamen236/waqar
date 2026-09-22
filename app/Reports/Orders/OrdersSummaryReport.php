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
 * ORD-01 · Orders Summary — one row per period bucket: how many orders
 * came in, what happened to them, what they were worth.
 *
 * Counts orders, not money reaching the bank. That distinction is the
 * whole reason this report and SAL-01 both exist and are not duplicates:
 * here an order counts on the day it was *placed* and is valued at
 * `orders.total`; in SAL-01 it counts on the day it was *delivered* and is
 * valued at what actually reached a treasury.
 */
class OrdersSummaryReport extends ReportDefinition
{
    use AppliesStandardFilters;

    public function key(): string
    {
        return 'orders.summary';
    }

    public function group(): string
    {
        return 'orders';
    }

    public function title(): string
    {
        return 'reports.orders.summary.title';
    }

    public function description(): string
    {
        return 'reports.orders.summary.description';
    }

    public function permission(): string
    {
        return 'reports.orders.view';
    }

    public function availableDateBases(): array
    {
        return ['placed', 'delivered'];
    }

    public function isPaginated(): bool
    {
        return false;
    }

    public function supportsComparison(): bool
    {
        return true;
    }

    /**
     * @return list<string>
     */
    public function filters(): array
    {
        return [
            'preset', 'date_from', 'date_to', 'date_basis', 'granularity', 'compare_to',
            'order_source', 'status', 'payment_status', 'employee_id',
            'governorate_id', 'city_id', 'district_id', 'area_id',
            'representative_id', 'shipping_company_id',
            'category_id', 'product_id', 'variant_id', 'warehouse_id',
            'customer_id', 'customer', 'coupon_id',
        ];
    }

    /**
     * @return list<ReportColumn>
     */
    public function columns(): array
    {
        return [
            ReportColumn::date('bucket', 'reports.columns.period'),
            ReportColumn::number('orders_count', 'reports.columns.orders'),
            ReportColumn::number('units', 'reports.columns.units'),
            ReportColumn::money('gross', 'reports.columns.gross_value'),
            ReportColumn::money('discount', 'reports.columns.discount'),
            ReportColumn::money('shipping', 'reports.columns.shipping'),
            ReportColumn::money('net_value', 'reports.columns.net_value'),
            ReportColumn::number('delivered', 'reports.columns.delivered'),
            ReportColumn::number('cancelled', 'reports.columns.cancelled'),
            ReportColumn::number('returned', 'reports.columns.returned'),
            ReportColumn::percent('cancellation_rate', 'reports.columns.cancellation_rate'),
            ReportColumn::percent('delivery_rate', 'reports.columns.delivery_rate'),
        ];
    }

    public function defaultSort(): ?array
    {
        return ['bucket', 'asc'];
    }

    /**
     * @return list<string>
     */
    public function notes(): array
    {
        return ['reports.notes.net_of_shipping'];
    }

    public function query(Employee $employee, array $filters): Builder
    {
        $bucket = $this->bucketExpression($this->granularity($filters), 'orders.created_at');

        return $this->base($employee, $filters)
            ->selectRaw("{$bucket} as bucket")
            ->selectRaw($this->aggregates())
            ->groupByRaw($bucket)
            ->orderByRaw('bucket asc');
    }

    /**
     * Totals come from their own un-grouped aggregate rather than summing
     * the rows on screen: the rate columns are ratios, and a sum of
     * percentages is not a percentage.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function totals(Employee $employee, array $filters): array
    {
        $row = $this->base($employee, $filters)->selectRaw($this->aggregates())->first();

        return $row === null ? [] : $this->figures($row);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Order>
     */
    private function base(Employee $employee, array $filters): Builder
    {
        $period = $this->resolvePeriod($filters);
        $basis = $this->dateBasis($filters, $this->defaultDateBasis());

        $query = Order::query()->visibleTo($employee);
        $query = $this->applyOrderDateBasis($query, $basis, $period);

        return $this->applyOrderFilters($query, $filters);
    }

    /**
     * Units is a correlated subquery rather than a join to `order_items`:
     * joining would multiply each order by its line count and quietly
     * inflate every other figure in the same row. `Order::scopeFiltered()`
     * already uses this shape for its quantity filters.
     */
    private function aggregates(): string
    {
        $delivered = OrderStatus::Delivered->value;
        $cancelled = OrderStatus::Cancelled->value;
        $returned = OrderStatus::Returned->value;
        $partial = OrderStatus::PartiallyReturned->value;

        return <<<SQL
            COUNT(*) as orders_count,
            COALESCE(SUM((SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi WHERE oi.order_id = orders.id)), 0) as units,
            COALESCE(SUM(orders.total), 0) as gross,
            COALESCE(SUM(orders.discount_amount), 0) as discount,
            COALESCE(SUM(orders.shipping_amount), 0) as shipping,
            COALESCE(SUM(orders.total - orders.shipping_amount), 0) as net_value,
            SUM(CASE WHEN orders.status IN ('{$delivered}', '{$partial}') THEN 1 ELSE 0 END) as delivered,
            SUM(CASE WHEN orders.status = '{$cancelled}' THEN 1 ELSE 0 END) as cancelled,
            SUM(CASE WHEN orders.status = '{$returned}' THEN 1 ELSE 0 END) as returned
        SQL;
    }

    /**
     * @return array<string, mixed>
     */
    public function map(object $row): array
    {
        return ['bucket' => $row->bucket] + $this->figures($row);
    }

    /**
     * @return array<string, mixed>
     */
    private function figures(object $row): array
    {
        $orders = (int) $row->orders_count;

        return [
            'orders_count' => $orders,
            'units' => (int) $row->units,
            'gross' => (float) $row->gross,
            'discount' => (float) $row->discount,
            'shipping' => (float) $row->shipping,
            'net_value' => (float) $row->net_value,
            'delivered' => (int) $row->delivered,
            'cancelled' => (int) $row->cancelled,
            'returned' => (int) $row->returned,
            'cancellation_rate' => $this->ratio((float) $row->cancelled, (float) $orders),
            'delivery_rate' => $this->ratio((float) $row->delivered, (float) $orders),
        ];
    }
}
