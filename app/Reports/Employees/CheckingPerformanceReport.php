<?php

namespace App\Reports\Employees;

use App\Enums\OrderStatus;
use App\Models\Employee;
use App\Models\OrderStatusHistory;
use App\Reports\Concerns\AppliesStandardFilters;
use App\Reports\ReportColumn;
use App\Reports\ReportDefinition;
use Illuminate\Database\Eloquent\Builder;

/**
 * EMP-03 · Checking Team Performance — throughput and decision mix.
 *
 * Counts *decisions*, not orders: an order confirmed after two
 * postponements took three decisions, and a report that counted orders
 * would credit a third of the work actually done.
 *
 * `order_status_history.changed_by` is the source — permanent, unlike the
 * activity log — so this report has no retention horizon.
 */
class CheckingPerformanceReport extends ReportDefinition
{
    use AppliesStandardFilters;

    public function key(): string
    {
        return 'employees.checking';
    }

    public function group(): string
    {
        return 'employees';
    }

    public function title(): string
    {
        return 'reports.employees.checking.title';
    }

    public function description(): string
    {
        return 'reports.employees.checking.description';
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
        return ['preset', 'date_from', 'date_to', 'compare_to', 'employee_id', 'order_source'];
    }

    /**
     * @return list<ReportColumn>
     */
    public function columns(): array
    {
        return [
            ReportColumn::text('employee', 'reports.columns.employee'),
            ReportColumn::number('decisions', 'reports.columns.decisions'),
            ReportColumn::number('confirmed', 'reports.columns.confirmed'),
            ReportColumn::number('postponed', 'reports.columns.postponed'),
            ReportColumn::number('cancelled', 'reports.columns.cancelled'),
            ReportColumn::number('backorder', 'reports.columns.backorder'),
            ReportColumn::percent('confirm_rate', 'reports.columns.confirm_rate'),
            ReportColumn::percent('cancel_rate', 'reports.columns.cancellation_rate'),
            ReportColumn::number('orders_touched', 'reports.columns.orders_touched'),
        ];
    }

    public function defaultSort(): ?array
    {
        return ['decisions', 'desc'];
    }

    public function query(Employee $employee, array $filters): Builder
    {
        $period = $this->resolvePeriod($filters);

        $confirmed = OrderStatus::Confirmed->value;
        $postponed = OrderStatus::Postponed->value;
        $cancelled = OrderStatus::Cancelled->value;
        $backorder = OrderStatus::Backorder->value;

        return OrderStatusHistory::query()
            ->join('orders', 'orders.id', '=', 'order_status_history.order_id')
            ->join('employees', 'employees.id', '=', 'order_status_history.changed_by')
            ->whereIn('orders.id', $this->visibleOrders($employee))
            ->whereIn('order_status_history.to_status', [$confirmed, $postponed, $cancelled, $backorder])
            ->where('order_status_history.created_at', '>=', $period['from'])
            ->where('order_status_history.created_at', '<', $period['to'])
            ->when($this->listOf($filters, 'employee_id'), fn ($q, array $v) => $q->whereIn('order_status_history.changed_by', $v))
            ->when(! empty($filters['order_source']), fn ($q) => $q->where('orders.order_source', (string) $filters['order_source']))
            ->selectRaw('employees.full_name as employee')
            ->selectRaw('COUNT(*) as decisions')
            ->selectRaw('COUNT(DISTINCT order_status_history.order_id) as orders_touched')
            ->selectRaw("SUM(CASE WHEN order_status_history.to_status = '{$confirmed}' THEN 1 ELSE 0 END) as confirmed")
            ->selectRaw("SUM(CASE WHEN order_status_history.to_status = '{$postponed}' THEN 1 ELSE 0 END) as postponed")
            ->selectRaw("SUM(CASE WHEN order_status_history.to_status = '{$cancelled}' THEN 1 ELSE 0 END) as cancelled")
            ->selectRaw("SUM(CASE WHEN order_status_history.to_status = '{$backorder}' THEN 1 ELSE 0 END) as backorder")
            ->groupBy('employees.id', 'employee')
            ->orderByDesc('decisions');
    }

    /**
     * @return array<string, mixed>
     */
    public function map(object $row): array
    {
        $decisions = (int) $row->decisions;

        return [
            'employee' => $row->employee,
            'decisions' => $decisions,
            'confirmed' => (int) $row->confirmed,
            'postponed' => (int) $row->postponed,
            'cancelled' => (int) $row->cancelled,
            'backorder' => (int) $row->backorder,
            'confirm_rate' => $this->ratio((float) $row->confirmed, (float) $decisions),
            'cancel_rate' => $this->ratio((float) $row->cancelled, (float) $decisions),
            'orders_touched' => (int) $row->orders_touched,
        ];
    }
}
