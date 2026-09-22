<?php

namespace App\Reports\Employees;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Models\Employee;
use App\Models\Order;
use App\Reports\Concerns\AppliesStandardFilters;
use App\Reports\Concerns\MeasuresSales;
use App\Reports\ReportColumn;
use App\Reports\ReportDefinition;
use Illuminate\Database\Eloquent\Builder;

/**
 * EMP-02 · Customer Service Scorecard — agent productivity on phone orders.
 *
 * Built on `orders.created_by_employee_id`, a first-class column, rather
 * than on the activity log: attribution for who placed an order is
 * permanent and never pruned.
 *
 * A Team Leader running this sees their own team and nobody else's, with no
 * code here to make that happen — `Order::scopeVisibleTo()` narrows the
 * rows, exactly as it narrows their order queue.
 */
class CustomerServiceScorecardReport extends ReportDefinition
{
    use AppliesStandardFilters, MeasuresSales;

    public function key(): string
    {
        return 'employees.customer-service';
    }

    public function group(): string
    {
        return 'employees';
    }

    public function title(): string
    {
        return 'reports.employees.cs.title';
    }

    public function description(): string
    {
        return 'reports.employees.cs.description';
    }

    public function permission(): string
    {
        return 'reports.employees.view';
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
        return ['preset', 'date_from', 'date_to', 'compare_to', 'employee_id', 'governorate_id', 'city_id', 'area_id'];
    }

    /**
     * @return list<ReportColumn>
     */
    public function columns(): array
    {
        return [
            ReportColumn::text('agent', 'reports.columns.agent'),
            ReportColumn::text('team_leader', 'reports.columns.team_leader'),
            ReportColumn::number('orders_count', 'reports.columns.orders'),
            ReportColumn::number('units', 'reports.columns.units'),
            ReportColumn::money('gross', 'reports.columns.gross_value'),
            ReportColumn::number('delivered', 'reports.columns.delivered'),
            ReportColumn::money('recognised', 'reports.columns.net_revenue'),
            ReportColumn::money('aov', 'reports.columns.aov'),
            ReportColumn::number('cancelled', 'reports.columns.cancelled'),
            ReportColumn::percent('cancellation_rate', 'reports.columns.cancellation_rate'),
        ];
    }

    public function defaultSort(): ?array
    {
        return ['recognised', 'desc'];
    }

    public function query(Employee $employee, array $filters): Builder
    {
        $period = $this->resolvePeriod($filters);
        $delivered = OrderStatus::Delivered->value;
        $partial = OrderStatus::PartiallyReturned->value;
        $cancelled = OrderStatus::Cancelled->value;

        $collected = $this->collectedSql();
        $units = $this->unitsSql();

        $query = Order::query()
            ->visibleTo($employee)
            // Phone orders only: a website order has no agent behind it, and
            // including them would credit the whole storefront to whoever
            // happens to be null.
            ->where('orders.order_source', OrderSource::CustomerService->value)
            ->whereNotNull('orders.created_by_employee_id');

        $query = $this->applyOrderDateBasis($query, 'placed', $period);
        $query = $this->applyOrderFilters($query, $filters);

        return $query
            ->join('employees', 'employees.id', '=', 'orders.created_by_employee_id')
            ->leftJoin('employees as leaders', 'leaders.id', '=', 'employees.team_leader_id')
            ->selectRaw('employees.full_name as agent')
            ->selectRaw('leaders.full_name as team_leader')
            ->selectRaw('COUNT(*) as orders_count')
            ->selectRaw("COALESCE(SUM({$units}), 0) as units")
            ->selectRaw('COALESCE(SUM(orders.total), 0) as gross')
            ->selectRaw("SUM(CASE WHEN orders.status IN ('{$delivered}', '{$partial}') THEN 1 ELSE 0 END) as delivered")
            ->selectRaw("COALESCE(SUM(CASE WHEN orders.status IN ('{$delivered}', '{$partial}') THEN {$collected} ELSE 0 END), 0) as recognised")
            ->selectRaw("SUM(CASE WHEN orders.status = '{$cancelled}' THEN 1 ELSE 0 END) as cancelled")
            ->groupBy('employees.id', 'agent', 'team_leader')
            ->orderByDesc('recognised');
    }

    /**
     * @return array<string, mixed>
     */
    public function map(object $row): array
    {
        $orders = (int) $row->orders_count;
        $delivered = (int) $row->delivered;
        $recognised = (float) $row->recognised;

        return [
            'agent' => $row->agent,
            'team_leader' => $row->team_leader,
            'orders_count' => $orders,
            'units' => (int) $row->units,
            'gross' => (float) $row->gross,
            'delivered' => $delivered,
            'recognised' => $recognised,
            // Against delivered orders, not all orders: an average built on
            // a denominator that includes cancellations is not an order value.
            'aov' => $delivered === 0 ? null : round($recognised / $delivered, 2),
            'cancelled' => (int) $row->cancelled,
            'cancellation_rate' => $this->ratio((float) $row->cancelled, (float) $orders),
        ];
    }
}
