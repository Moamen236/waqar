<?php

namespace App\Reports\Sales;

use App\Models\Employee;
use App\Models\Order;
use App\Reports\Concerns\AppliesStandardFilters;
use App\Reports\Concerns\MeasuresSales;
use App\Reports\ReportColumn;
use App\Reports\ReportDefinition;
use Illuminate\Database\Eloquent\Builder;

/**
 * SAL-01 · Sales Summary — the revenue line, by period.
 *
 * Where ORD-01 counts orders on the day they were placed and values them
 * at `orders.total`, this counts them on the day they were **delivered**
 * and values them at what actually reached a treasury. Same table, two
 * genuinely different questions — which is why both exist.
 */
class SalesSummaryReport extends ReportDefinition
{
    use AppliesStandardFilters, MeasuresSales;

    public function key(): string
    {
        return 'sales.summary';
    }

    public function group(): string
    {
        return 'sales';
    }

    public function title(): string
    {
        return 'reports.sales.summary.title';
    }

    public function description(): string
    {
        return 'reports.sales.summary.description';
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
        return ['delivered', 'collected', 'placed'];
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
            'order_source', 'employee_id',
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
            ReportColumn::number('orders_count', 'reports.columns.delivered_orders'),
            ReportColumn::number('units', 'reports.columns.units'),
            ReportColumn::money('gross', 'reports.columns.gross_value'),
            ReportColumn::money('discount', 'reports.columns.discount'),
            ReportColumn::money('courier_fees', 'reports.columns.courier_fees'),
            ReportColumn::money('recognised', 'reports.columns.recognised'),
            ReportColumn::money('refunds', 'reports.columns.refunds'),
            ReportColumn::money('net_revenue', 'reports.columns.net_revenue'),
            ReportColumn::money('aov', 'reports.columns.aov'),
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
        return ['reports.notes.revenue_on_delivery', 'reports.notes.net_of_shipping'];
    }

    public function query(Employee $employee, array $filters): Builder
    {
        $basis = $this->dateBasis($filters, $this->defaultDateBasis());
        $bucket = $this->bucketExpression($this->granularity($filters), $this->bucketColumn($basis));

        return $this->base($employee, $filters)
            ->selectRaw("{$bucket} as bucket")
            ->selectRaw($this->aggregates())
            ->groupByRaw($bucket)
            ->orderByRaw('bucket asc');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function totals(Employee $employee, array $filters): array
    {
        $row = $this->base($employee, $filters)->selectRaw($this->aggregates())->first();

        return $row === null ? [] : $this->figures($row);
    }

    /**
     * Which date column the bucket is cut on.
     *
     * The period *filter* is applied by `applyOrderDateBasis()` through a
     * whereHas; the bucket needs the same date as a plain column, and for
     * the delivered basis that means reaching into the history table.
     */
    private function bucketColumn(string $basis): string
    {
        return match ($basis) {
            'delivered' => "(SELECT MAX(h.created_at) FROM order_status_history h
                             WHERE h.order_id = orders.id AND h.to_status = 'Delivered')",
            'collected' => '(SELECT MAX(p.collected_at) FROM payments p WHERE p.order_id = orders.id)',
            default => 'orders.created_at',
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Order>
     */
    private function base(Employee $employee, array $filters): Builder
    {
        $period = $this->resolvePeriod($filters);
        $basis = $this->dateBasis($filters, $this->defaultDateBasis());

        $query = Order::query()
            ->visibleTo($employee)
            // Revenue is recognised on delivery (CLAUDE.md: stock deducts on
            // Accounting-confirmed Delivered only). An order merely confirmed
            // is not revenue, whatever its total says.
            ->whereIn('orders.status', $this->soldStatuses());

        $query = $this->applyOrderDateBasis($query, $basis, $period);

        return $this->applyOrderFilters($query, $filters);
    }

    private function aggregates(): string
    {
        $collected = $this->collectedSql();
        $refunded = $this->refundedSql();
        $units = $this->unitsSql();

        return <<<SQL
            COUNT(*) as orders_count,
            COALESCE(SUM({$units}), 0) as units,
            COALESCE(SUM(orders.total), 0) as gross,
            COALESCE(SUM(orders.discount_amount), 0) as discount,
            COALESCE(SUM(orders.shipping_amount), 0) as courier_fees,
            COALESCE(SUM({$collected}), 0) as recognised,
            COALESCE(SUM({$refunded}), 0) as refunds
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
        $net = round((float) $row->recognised - (float) $row->refunds, 2);

        return [
            'orders_count' => $orders,
            'units' => (int) $row->units,
            'gross' => (float) $row->gross,
            'discount' => (float) $row->discount,
            'courier_fees' => (float) $row->courier_fees,
            'recognised' => (float) $row->recognised,
            'refunds' => (float) $row->refunds,
            'net_revenue' => $net,
            'aov' => $orders === 0 ? null : round($net / $orders, 2),
        ];
    }
}
