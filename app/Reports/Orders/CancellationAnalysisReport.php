<?php

namespace App\Reports\Orders;

use App\Enums\OrderStatus;
use App\Models\Employee;
use App\Models\OrderStatusHistory;
use App\Reports\Concerns\AppliesStandardFilters;
use App\Reports\ReportColumn;
use App\Reports\ReportDefinition;
use Illuminate\Database\Eloquent\Builder;

/**
 * ORD-04 · Cancellation, Postponement & Backorder Analysis — why orders
 * fall out of the funnel, and who recorded it.
 *
 * Rooted in `order_status_history`, not `orders`: an order cancelled today
 * and re-opened tomorrow still *had* a cancellation, and the history is
 * the only place that fact survives. It also means an order postponed
 * three times contributes three rows, which is the honest count for a
 * report about how often this happens.
 *
 * Grouping is primarily on `to_status`, a real enum. `reason` is free text
 * on the history table, so it is offered as a secondary breakdown rather
 * than treated as a taxonomy it is not.
 */
class CancellationAnalysisReport extends ReportDefinition
{
    use AppliesStandardFilters;

    public function key(): string
    {
        return 'orders.cancellations';
    }

    public function group(): string
    {
        return 'orders';
    }

    public function title(): string
    {
        return 'reports.orders.cancellations.title';
    }

    public function description(): string
    {
        return 'reports.orders.cancellations.description';
    }

    public function permission(): string
    {
        return 'reports.orders.view';
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
            'preset', 'date_from', 'date_to', 'compare_to',
            'order_source', 'employee_id',
            'governorate_id', 'city_id', 'district_id', 'area_id',
        ];
    }

    /**
     * @return list<ReportColumn>
     */
    public function columns(): array
    {
        return [
            ReportColumn::enum('to_status', 'reports.columns.outcome', 'status.'),
            ReportColumn::text('reason', 'reports.columns.reason'),
            ReportColumn::number('events', 'reports.columns.events'),
            ReportColumn::number('orders_count', 'reports.columns.orders'),
            ReportColumn::money('value', 'reports.columns.gross_value'),
            ReportColumn::text('recorded_by', 'reports.columns.recorded_by'),
        ];
    }

    public function defaultSort(): ?array
    {
        return ['events', 'desc'];
    }

    /**
     * @return list<string>
     */
    public function notes(): array
    {
        return ['reports.notes.free_text_reason'];
    }

    public function query(Employee $employee, array $filters): Builder
    {
        return $this->base($employee, $filters)
            ->selectRaw('order_status_history.to_status as to_status')
            ->selectRaw("COALESCE(NULLIF(order_status_history.reason, ''), '—') as reason")
            ->selectRaw('COUNT(*) as events')
            ->selectRaw('COUNT(DISTINCT order_status_history.order_id) as orders_count')
            ->selectRaw('COALESCE(SUM(orders.total), 0) as value')
            // The actor is shown only when a single employee is behind the
            // whole group; otherwise naming one of several would be a lie.
            ->selectRaw('CASE WHEN COUNT(DISTINCT order_status_history.changed_by) = 1 THEN MAX(employees.full_name) ELSE NULL END as recorded_by')
            ->groupBy('order_status_history.to_status', 'reason')
            ->orderByDesc('events');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<OrderStatusHistory>
     */
    private function base(Employee $employee, array $filters): Builder
    {
        $period = $this->resolvePeriod($filters);

        return OrderStatusHistory::query()
            ->join('orders', 'orders.id', '=', 'order_status_history.order_id')
            ->leftJoin('employees', 'employees.id', '=', 'order_status_history.changed_by')
            // Row visibility rides on the joined order, so a Customer
            // Service agent sees only their own orders' transitions —
            // whoever recorded them.
            ->whereIn('orders.id', $this->visibleOrders($employee))
            ->whereIn('order_status_history.to_status', [
                OrderStatus::Cancelled->value,
                OrderStatus::Postponed->value,
                OrderStatus::Backorder->value,
            ])
            ->where('order_status_history.created_at', '>=', $period['from'])
            ->where('order_status_history.created_at', '<', $period['to'])
            ->when(! empty($filters['order_source']), fn ($q) => $q->where('orders.order_source', (string) $filters['order_source']))
            ->when($this->listOf($filters, 'employee_id'), fn ($q, array $v) => $q->whereIn('order_status_history.changed_by', $v))
            ->when(! empty($filters['governorate_id']), fn ($q) => $q->where('orders.shipping_governorate_id', (int) $filters['governorate_id']))
            ->when(! empty($filters['city_id']), fn ($q) => $q->where('orders.shipping_city_id', (int) $filters['city_id']))
            ->when(! empty($filters['district_id']), fn ($q) => $q->where('orders.shipping_district_id', (int) $filters['district_id']))
            ->when(! empty($filters['area_id']), fn ($q) => $q->where('orders.shipping_area_id', (int) $filters['area_id']));
    }

    /**
     * @return array<string, mixed>
     */
    public function map(object $row): array
    {
        return [
            'to_status' => $row->to_status,
            'reason' => $row->reason,
            'events' => (int) $row->events,
            'orders_count' => (int) $row->orders_count,
            'value' => (float) $row->value,
            'recorded_by' => $row->recorded_by,
        ];
    }
}
