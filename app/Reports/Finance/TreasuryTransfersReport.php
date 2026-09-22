<?php

namespace App\Reports\Finance;

use App\Models\Employee;
use App\Models\TreasuryTransfer;
use App\Reports\Concerns\AppliesStandardFilters;
use App\Reports\ReportColumn;
use App\Reports\ReportDefinition;
use Illuminate\Database\Eloquent\Builder;

/**
 * FIN-07 · Treasury Transfers — money moved between the company's own
 * accounts, and who moved it.
 *
 * Deliberately separate from FIN-01, which nets transfers into a balance.
 * A transfer changes no total and so disappears from a balance report, but
 * it is exactly the kind of movement someone may need to account for
 * later — cash swept to a bank account, a wallet topped up.
 */
class TreasuryTransfersReport extends ReportDefinition
{
    use AppliesStandardFilters;

    public function key(): string
    {
        return 'finance.transfers';
    }

    public function group(): string
    {
        return 'finance';
    }

    public function title(): string
    {
        return 'reports.finance.transfers.title';
    }

    public function description(): string
    {
        return 'reports.finance.transfers.description';
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
        return ['preset', 'date_from', 'date_to', 'treasury_id', 'employee_id'];
    }

    /**
     * @return list<ReportColumn>
     */
    public function columns(): array
    {
        return [
            ReportColumn::datetime('created_at', 'reports.columns.datetime'),
            ReportColumn::text('from_treasury', 'reports.columns.from_treasury'),
            ReportColumn::text('to_treasury', 'reports.columns.to_treasury'),
            ReportColumn::money('amount', 'reports.columns.amount'),
            ReportColumn::text('created_by', 'reports.columns.created_by'),
            ReportColumn::text('notes', 'reports.columns.notes'),
        ];
    }

    public function defaultSort(): ?array
    {
        return ['created_at', 'desc'];
    }

    public function query(Employee $employee, array $filters): Builder
    {
        $period = $this->resolvePeriod($filters);
        $treasuries = $this->listOf($filters, 'treasury_id');

        return TreasuryTransfer::query()
            ->join('treasuries as src', 'src.id', '=', 'treasury_transfers.from_treasury_id')
            ->join('treasuries as dst', 'dst.id', '=', 'treasury_transfers.to_treasury_id')
            ->join('employees', 'employees.id', '=', 'treasury_transfers.created_by')
            ->where('treasury_transfers.created_at', '>=', $period['from'])
            ->where('treasury_transfers.created_at', '<', $period['to'])
            // A treasury filter matches either end: someone asking about an
            // account wants the money that left it and the money that
            // arrived, not one direction.
            ->when($treasuries !== null, fn ($q) => $q->where(fn ($inner) => $inner
                ->whereIn('treasury_transfers.from_treasury_id', $treasuries)
                ->orWhereIn('treasury_transfers.to_treasury_id', $treasuries)))
            ->when($this->listOf($filters, 'employee_id'), fn ($q, array $v) => $q->whereIn('treasury_transfers.created_by', $v))
            ->selectRaw('treasury_transfers.created_at as created_at')
            ->selectRaw('src.name as from_treasury')
            ->selectRaw('dst.name as to_treasury')
            ->selectRaw('treasury_transfers.amount as amount')
            ->selectRaw('employees.full_name as created_by')
            ->selectRaw('treasury_transfers.notes as notes')
            ->orderByDesc('treasury_transfers.created_at');
    }

    /**
     * @return array<string, mixed>
     */
    public function map(object $row): array
    {
        return [
            'created_at' => $row->created_at,
            'from_treasury' => $row->from_treasury,
            'to_treasury' => $row->to_treasury,
            'amount' => (float) $row->amount,
            'created_by' => $row->created_by,
            'notes' => $row->notes,
        ];
    }
}
