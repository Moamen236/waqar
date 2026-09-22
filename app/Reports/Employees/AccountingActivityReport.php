<?php

namespace App\Reports\Employees;

use App\Models\Employee;
use App\Reports\Concerns\AppliesStandardFilters;
use App\Reports\ReportColumn;
use App\Reports\ReportDefinition;
use Illuminate\Database\Eloquent\Builder;

/**
 * EMP-05 · Accounting Activity — reconciliation and cash-posting
 * throughput per accountant.
 *
 * One employee's work spreads across five tables, each with its own actor
 * column, so this report is built from correlated counts off `employees`
 * rather than a join: joining five one-to-many tables would multiply every
 * count by every other.
 */
class AccountingActivityReport extends ReportDefinition
{
    use AppliesStandardFilters;

    public function key(): string
    {
        return 'employees.accounting';
    }

    public function group(): string
    {
        return 'employees';
    }

    public function title(): string
    {
        return 'reports.employees.accounting.title';
    }

    public function description(): string
    {
        return 'reports.employees.accounting.description';
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
        return ['preset', 'date_from', 'date_to', 'employee_id', 'treasury_id'];
    }

    /**
     * @return list<ReportColumn>
     */
    public function columns(): array
    {
        return [
            ReportColumn::text('employee', 'reports.columns.employee'),
            ReportColumn::number('deliveries_confirmed', 'reports.columns.deliveries_confirmed'),
            ReportColumn::number('transactions_posted', 'reports.columns.transactions_posted'),
            ReportColumn::money('cash_posted', 'reports.columns.cash_posted'),
            ReportColumn::number('refunds_processed', 'reports.columns.refunds_processed'),
            ReportColumn::money('refund_value', 'reports.columns.refund_value'),
            ReportColumn::number('expenses_recorded', 'reports.columns.expenses_recorded'),
            ReportColumn::number('statements_created', 'reports.columns.statements_created'),
            ReportColumn::number('transfers_made', 'reports.columns.transfers_made'),
        ];
    }

    public function defaultSort(): ?array
    {
        return ['transactions_posted', 'desc'];
    }

    public function query(Employee $employee, array $filters): Builder
    {
        $period = $this->resolvePeriod($filters);
        $from = $period['from']->toDateTimeString();
        $to = $period['to']->toDateTimeString();

        $window = fn (string $table, string $column = 'created_at') => "{$table}.{$column} >= '{$from}' AND {$table}.{$column} < '{$to}'";

        $treasuries = $this->listOf($filters, 'treasury_id');
        $treasuryFilter = $treasuries === null
            ? ''
            : ' AND tt.treasury_id IN ('.implode(',', array_map('intval', $treasuries)).')';

        return Employee::query()
            ->selectRaw('employees.full_name as employee')
            ->selectRaw("(SELECT COUNT(*) FROM order_status_history h
                          WHERE h.changed_by = employees.id
                            AND h.to_status IN ('Delivered', 'Returned', 'Partially Returned')
                            AND {$window('h')}) as deliveries_confirmed")
            ->selectRaw("(SELECT COUNT(*) FROM treasury_transactions tt
                          WHERE tt.created_by = employees.id AND {$window('tt')}{$treasuryFilter}) as transactions_posted")
            // Income only: netting expenses into "cash posted" would make a
            // busy accountant who also books refunds look idle.
            ->selectRaw("(SELECT COALESCE(SUM(tt.amount), 0) FROM treasury_transactions tt
                          WHERE tt.created_by = employees.id AND tt.type = 'income'
                            AND {$window('tt')}{$treasuryFilter}) as cash_posted")
            ->selectRaw("(SELECT COUNT(*) FROM refunds rf
                          WHERE rf.processed_by = employees.id AND rf.processed_at IS NOT NULL
                            AND rf.processed_at >= '{$from}' AND rf.processed_at < '{$to}') as refunds_processed")
            ->selectRaw("(SELECT COALESCE(SUM(rf.net_amount), 0) FROM refunds rf
                          WHERE rf.processed_by = employees.id AND rf.processed_at IS NOT NULL
                            AND rf.processed_at >= '{$from}' AND rf.processed_at < '{$to}') as refund_value")
            ->selectRaw("(SELECT COUNT(*) FROM expenses ex
                          WHERE ex.created_by = employees.id AND {$window('ex')}) as expenses_recorded")
            ->selectRaw("(SELECT COUNT(*) FROM shipping_company_statements st
                          WHERE st.created_by = employees.id AND {$window('st')}) as statements_created")
            ->selectRaw("(SELECT COUNT(*) FROM treasury_transfers tr
                          WHERE tr.created_by = employees.id AND {$window('tr')}) as transfers_made")
            ->when($this->listOf($filters, 'employee_id'), fn ($q, array $v) => $q->whereIn('employees.id', $v))
            // Employees with no accounting activity at all are omitted:
            // this is a workload report, not a staff list.
            ->havingRaw('deliveries_confirmed + transactions_posted + refunds_processed
                         + expenses_recorded + statements_created + transfers_made > 0')
            ->orderByDesc('transactions_posted');
    }

    /**
     * @return array<string, mixed>
     */
    public function map(object $row): array
    {
        return [
            'employee' => $row->employee,
            'deliveries_confirmed' => (int) $row->deliveries_confirmed,
            'transactions_posted' => (int) $row->transactions_posted,
            'cash_posted' => (float) $row->cash_posted,
            'refunds_processed' => (int) $row->refunds_processed,
            'refund_value' => (float) $row->refund_value,
            'expenses_recorded' => (int) $row->expenses_recorded,
            'statements_created' => (int) $row->statements_created,
            'transfers_made' => (int) $row->transfers_made,
        ];
    }
}
