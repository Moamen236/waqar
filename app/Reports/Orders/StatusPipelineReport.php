<?php

namespace App\Reports\Orders;

use App\Models\Employee;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Reports\Concerns\AppliesStandardFilters;
use App\Reports\ReportColumn;
use App\Reports\ReportDefinition;
use Illuminate\Database\Eloquent\Builder;

/**
 * ORD-02 · Status Pipeline & Ageing — what is stuck, where, and for how
 * long.
 *
 * A snapshot of *now*, not of a period, so it takes no date range at all:
 * asking "how many orders are sitting in Checking" and then filtering that
 * to last month answers a question nobody has. Age is measured from the
 * order's most recent status transition, which is what an operator means
 * by "this has been sitting there for three days".
 */
class StatusPipelineReport extends ReportDefinition
{
    use AppliesStandardFilters;

    public function key(): string
    {
        return 'orders.pipeline';
    }

    public function group(): string
    {
        return 'orders';
    }

    public function title(): string
    {
        return 'reports.orders.pipeline.title';
    }

    public function description(): string
    {
        return 'reports.orders.pipeline.description';
    }

    public function permission(): string
    {
        return 'reports.orders.view';
    }

    public function isPaginated(): bool
    {
        return false;
    }

    /**
     * No date filters: this is a live queue, not a period report.
     *
     * @return list<string>
     */
    public function filters(): array
    {
        return [
            'status', 'order_source', 'employee_id',
            'governorate_id', 'city_id', 'district_id', 'area_id',
            'representative_id', 'shipping_company_id', 'warehouse_id',
        ];
    }

    /**
     * @return list<ReportColumn>
     */
    public function columns(): array
    {
        return [
            ReportColumn::enum('status', 'reports.columns.status', 'status.'),
            ReportColumn::number('orders_count', 'reports.columns.orders'),
            ReportColumn::money('value', 'reports.columns.gross_value'),
            ReportColumn::number('avg_age_days', 'reports.columns.avg_age_days'),
            ReportColumn::number('oldest_age_days', 'reports.columns.oldest_age_days'),
            ReportColumn::number('bucket_0_1', 'reports.columns.age_0_1'),
            ReportColumn::number('bucket_2_3', 'reports.columns.age_2_3'),
            ReportColumn::number('bucket_4_7', 'reports.columns.age_4_7'),
            ReportColumn::number('bucket_8_plus', 'reports.columns.age_8_plus'),
        ];
    }

    public function defaultSort(): ?array
    {
        return ['oldest_age_days', 'desc'];
    }

    public function query(Employee $employee, array $filters): Builder
    {
        // Age is measured from the LAST transition, so the subquery takes
        // MAX(created_at) per order rather than the order's own created_at
        // — an order placed three weeks ago but confirmed this morning is
        // one day old in this queue, not twenty-one.
        $history = (new OrderStatusHistory)->getTable();
        $lastMove = "COALESCE((SELECT MAX(h.created_at) FROM {$history} h WHERE h.order_id = orders.id), orders.created_at)";
        $age = "TIMESTAMPDIFF(DAY, {$lastMove}, NOW())";

        return $this->base($employee, $filters)
            ->selectRaw('orders.status as status')
            ->selectRaw('COUNT(*) as orders_count')
            ->selectRaw('COALESCE(SUM(orders.total), 0) as value')
            ->selectRaw("ROUND(AVG({$age}), 1) as avg_age_days")
            ->selectRaw("MAX({$age}) as oldest_age_days")
            ->selectRaw("SUM(CASE WHEN {$age} <= 1 THEN 1 ELSE 0 END) as bucket_0_1")
            ->selectRaw("SUM(CASE WHEN {$age} BETWEEN 2 AND 3 THEN 1 ELSE 0 END) as bucket_2_3")
            ->selectRaw("SUM(CASE WHEN {$age} BETWEEN 4 AND 7 THEN 1 ELSE 0 END) as bucket_4_7")
            ->selectRaw("SUM(CASE WHEN {$age} >= 8 THEN 1 ELSE 0 END) as bucket_8_plus")
            ->groupBy('orders.status')
            ->orderByDesc('oldest_age_days');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Order>
     */
    private function base(Employee $employee, array $filters): Builder
    {
        return $this->applyOrderFilters(Order::query()->visibleTo($employee), $filters);
    }

    /**
     * @return array<string, mixed>
     */
    public function map(object $row): array
    {
        return [
            'status' => $row->status instanceof \BackedEnum ? $row->status->value : $row->status,
            'orders_count' => (int) $row->orders_count,
            'value' => (float) $row->value,
            'avg_age_days' => (float) $row->avg_age_days,
            'oldest_age_days' => (int) $row->oldest_age_days,
            'bucket_0_1' => (int) $row->bucket_0_1,
            'bucket_2_3' => (int) $row->bucket_2_3,
            'bucket_4_7' => (int) $row->bucket_4_7,
            'bucket_8_plus' => (int) $row->bucket_8_plus,
        ];
    }
}
