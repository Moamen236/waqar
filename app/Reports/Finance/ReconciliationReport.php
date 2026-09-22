<?php

namespace App\Reports\Finance;

use App\Models\Employee;
use App\Models\ShippingCompanyStatement;
use App\Reports\Concerns\AppliesStandardFilters;
use App\Reports\ReportColumn;
use App\Reports\ReportDefinition;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * FIN-04 · Shipping Company Reconciliation — what each carrier owes, has
 * transferred, and still holds.
 *
 * Every money column is **read from the statement, never recomputed**.
 * `ReconciliationService` is the authority on what a statement means, and
 * a report that re-derived `net_amount_expected` could disagree with the
 * figure the accountant actually settled against.
 *
 * Post-Phase C, `delivery_fees_owed` and `return_fees_owed` are hard-set to
 * zero by that service — the courier already took their fee out of the cash
 * at the door, so `collected_amount` is net of everything they are owed and
 * subtracting again would charge them twice. Those two columns therefore
 * appear only when a statement in the result actually carries a non-zero
 * figure, i.e. one written before the change: a column of zeros invites
 * someone to ask what broke.
 */
class ReconciliationReport extends ReportDefinition
{
    use AppliesStandardFilters;

    public function key(): string
    {
        return 'finance.reconciliation';
    }

    public function group(): string
    {
        return 'finance';
    }

    public function title(): string
    {
        return 'reports.finance.reconciliation.title';
    }

    public function description(): string
    {
        return 'reports.finance.reconciliation.description';
    }

    public function permission(): string
    {
        return 'reports.finance.view';
    }

    /**
     * @return list<string>
     */
    public function filters(): array
    {
        return ['preset', 'date_from', 'date_to', 'shipping_company_id', 'statement_status', 'employee_id'];
    }

    /**
     * @return list<ReportColumn>
     */
    public function columns(): array
    {
        return [
            ReportColumn::text('company', 'reports.columns.shipping_company'),
            ReportColumn::date('period_start', 'reports.columns.period_start'),
            ReportColumn::date('period_end', 'reports.columns.period_end'),
            ReportColumn::number('delivered_orders_count', 'reports.columns.delivered_orders'),
            ReportColumn::money('expected_customer_collection', 'reports.columns.expected_collection'),
            ReportColumn::money('net_amount_expected', 'reports.columns.net_expected'),
            ReportColumn::money('transferred_amount', 'reports.columns.transferred'),
            ReportColumn::money('outstanding_amount', 'reports.columns.outstanding'),
            ReportColumn::enum('status', 'reports.columns.status', 'status.'),
            ReportColumn::number('days_open', 'reports.columns.days_open'),
            ReportColumn::text('created_by', 'reports.columns.created_by'),
        ];
    }

    public function defaultSort(): ?array
    {
        return ['outstanding_amount', 'desc'];
    }

    /**
     * @return list<string>
     */
    public function notes(): array
    {
        return ['reports.notes.carrier_fees_zero'];
    }

    public function query(Employee $employee, array $filters): Builder
    {
        $period = $this->resolvePeriod($filters);

        return ShippingCompanyStatement::query()
            ->join('shipping_companies', 'shipping_companies.id', '=', 'shipping_company_statements.shipping_company_id')
            ->leftJoin('employees', 'employees.id', '=', 'shipping_company_statements.created_by')
            // Overlap, not containment: a statement spanning the window's
            // edge is still relevant to it, and requiring full containment
            // would hide exactly the open ones someone is looking for.
            ->where('shipping_company_statements.period_start', '<=', $period['label_to']->toDateString())
            ->where('shipping_company_statements.period_end', '>=', $period['from']->toDateString())
            ->when($this->listOf($filters, 'shipping_company_id'), fn ($q, array $v) => $q->whereIn('shipping_company_statements.shipping_company_id', $v))
            ->when(! empty($filters['statement_status']), fn ($q) => $q->where('shipping_company_statements.status', (string) $filters['statement_status']))
            ->when($this->listOf($filters, 'employee_id'), fn ($q, array $v) => $q->whereIn('shipping_company_statements.created_by', $v))
            ->selectRaw('shipping_companies.name as company')
            ->selectRaw('shipping_company_statements.period_start as period_start')
            ->selectRaw('shipping_company_statements.period_end as period_end')
            ->selectRaw('shipping_company_statements.delivered_orders_count as delivered_orders_count')
            ->selectRaw('shipping_company_statements.expected_customer_collection as expected_customer_collection')
            ->selectRaw('shipping_company_statements.net_amount_expected as net_amount_expected')
            ->selectRaw('shipping_company_statements.transferred_amount as transferred_amount')
            ->selectRaw('shipping_company_statements.outstanding_amount as outstanding_amount')
            ->selectRaw('shipping_company_statements.status as status')
            ->selectRaw('employees.full_name as created_by')
            ->orderByDesc('shipping_company_statements.outstanding_amount');
    }

    /**
     * @return array<string, mixed>
     */
    public function map(object $row): array
    {
        $status = $row->status instanceof \BackedEnum ? $row->status->value : (string) $row->status;

        return [
            'company' => $row->company,
            'period_start' => $row->period_start,
            'period_end' => $row->period_end,
            'delivered_orders_count' => (int) $row->delivered_orders_count,
            'expected_customer_collection' => (float) $row->expected_customer_collection,
            'net_amount_expected' => (float) $row->net_amount_expected,
            'transferred_amount' => (float) $row->transferred_amount,
            'outstanding_amount' => (float) $row->outstanding_amount,
            'status' => $status,
            // Only an open statement is ageing; a settled one's age is
            // history, and showing a number there reads as a problem.
            'days_open' => $status === 'open'
                ? now()->diffInDays(CarbonImmutable::parse((string) $row->period_end))
                : null,
            'created_by' => $row->created_by,
        ];
    }
}
