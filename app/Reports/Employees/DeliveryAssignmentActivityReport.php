<?php

namespace App\Reports\Employees;

use App\Enums\DeliveryAssignmentType;
use App\Models\DeliveryAssignment;
use App\Models\Employee;
use App\Reports\Concerns\AppliesStandardFilters;
use App\Reports\ReportColumn;
use App\Reports\ReportDefinition;
use Illuminate\Database\Eloquent\Builder;

/**
 * EMP-04 · Delivery Assignment Activity — who assigns what, to whom, how
 * fast.
 *
 * "Reassignments" counts orders this employee assigned that already had an
 * earlier assignment. It is not a reprimand — reassignment is often the
 * right call when a carrier fails — but a rising count is worth knowing
 * about, and nothing else in the system surfaces it.
 */
class DeliveryAssignmentActivityReport extends ReportDefinition
{
    use AppliesStandardFilters;

    public function key(): string
    {
        return 'employees.delivery';
    }

    public function group(): string
    {
        return 'employees';
    }

    public function title(): string
    {
        return 'reports.employees.delivery.title';
    }

    public function description(): string
    {
        return 'reports.employees.delivery.description';
    }

    public function permission(): string
    {
        return 'reports.employees.view';
    }

    public function isPaginated(): bool
    {
        return false;
    }

    /**
     * @return list<string>
     */
    public function filters(): array
    {
        return ['preset', 'date_from', 'date_to', 'employee_id', 'assignment_type', 'representative_id', 'shipping_company_id'];
    }

    /**
     * @return list<ReportColumn>
     */
    public function columns(): array
    {
        return [
            ReportColumn::text('employee', 'reports.columns.employee'),
            ReportColumn::number('assignments', 'reports.columns.assignments'),
            ReportColumn::number('to_representatives', 'reports.columns.to_representatives'),
            ReportColumn::number('to_companies', 'reports.columns.to_companies'),
            ReportColumn::number('distinct_carriers', 'reports.columns.distinct_carriers'),
            ReportColumn::number('avg_hours_to_assign', 'reports.columns.avg_hours_to_assign'),
            ReportColumn::number('reassignments', 'reports.columns.reassignments'),
        ];
    }

    public function defaultSort(): ?array
    {
        return ['assignments', 'desc'];
    }

    public function query(Employee $employee, array $filters): Builder
    {
        $period = $this->resolvePeriod($filters);
        $representative = DeliveryAssignmentType::Representative->value;
        $company = DeliveryAssignmentType::ShippingCompany->value;

        // Hours from the order being confirmed to it being assigned — the
        // window this role actually owns.
        $confirmedAt = "(SELECT MAX(h.created_at) FROM order_status_history h
                         WHERE h.order_id = delivery_assignments.order_id AND h.to_status = 'Confirmed')";

        // An assignment that already had an earlier one for the same order.
        $isReassignment = '(SELECT COUNT(*) FROM delivery_assignments d2
                            WHERE d2.order_id = delivery_assignments.order_id
                              AND d2.assigned_at < delivery_assignments.assigned_at)';

        return DeliveryAssignment::query()
            ->join('employees', 'employees.id', '=', 'delivery_assignments.assigned_by')
            ->whereIn('delivery_assignments.order_id', $this->visibleOrders($employee))
            ->where('delivery_assignments.assigned_at', '>=', $period['from'])
            ->where('delivery_assignments.assigned_at', '<', $period['to'])
            ->when($this->listOf($filters, 'employee_id'), fn ($q, array $v) => $q->whereIn('delivery_assignments.assigned_by', $v))
            ->when(! empty($filters['assignment_type']), fn ($q) => $q->where('delivery_assignments.assignment_type', (string) $filters['assignment_type']))
            ->when($this->listOf($filters, 'representative_id'), fn ($q, array $v) => $q->whereIn('delivery_assignments.delivery_representative_id', $v))
            ->when($this->listOf($filters, 'shipping_company_id'), fn ($q, array $v) => $q->whereIn('delivery_assignments.shipping_company_id', $v))
            ->selectRaw('employees.full_name as employee')
            ->selectRaw('COUNT(*) as assignments')
            ->selectRaw("SUM(CASE WHEN delivery_assignments.assignment_type = '{$representative}' THEN 1 ELSE 0 END) as to_representatives")
            ->selectRaw("SUM(CASE WHEN delivery_assignments.assignment_type = '{$company}' THEN 1 ELSE 0 END) as to_companies")
            ->selectRaw('COUNT(DISTINCT COALESCE(delivery_assignments.delivery_representative_id, 0), COALESCE(delivery_assignments.shipping_company_id, 0)) as distinct_carriers')
            ->selectRaw("ROUND(AVG(TIMESTAMPDIFF(MINUTE, {$confirmedAt}, delivery_assignments.assigned_at)) / 60, 1) as avg_hours_to_assign")
            ->selectRaw("SUM(CASE WHEN {$isReassignment} > 0 THEN 1 ELSE 0 END) as reassignments")
            ->groupBy('employees.id', 'employee')
            ->orderByDesc('assignments');
    }

    /**
     * @return array<string, mixed>
     */
    public function map(object $row): array
    {
        return [
            'employee' => $row->employee,
            'assignments' => (int) $row->assignments,
            'to_representatives' => (int) $row->to_representatives,
            'to_companies' => (int) $row->to_companies,
            'distinct_carriers' => (int) $row->distinct_carriers,
            'avg_hours_to_assign' => $row->avg_hours_to_assign === null ? null : (float) $row->avg_hours_to_assign,
            'reassignments' => (int) $row->reassignments,
        ];
    }
}
