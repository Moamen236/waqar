<?php

namespace App\Reports\Finance;

use App\Enums\TreasuryTransactionType;
use App\Models\Employee;
use App\Models\Treasury;
use App\Reports\Concerns\AppliesStandardFilters;
use App\Reports\ReportColumn;
use App\Reports\ReportDefinition;
use Illuminate\Database\Eloquent\Builder;

/**
 * FIN-01 · Treasury Balances & Movement — opening to closing, per account.
 *
 * The **variance column is the reason this report exists.** Closing is
 * derived by replaying every transaction in the period on top of the
 * opening balance; `treasuries.current_balance` is the running figure the
 * application maintains. They must agree. A non-zero variance means a
 * balance was written without a matching transaction, and that is the one
 * thing in the finance module nobody would otherwise notice.
 */
class TreasuryBalancesReport extends ReportDefinition
{
    use AppliesStandardFilters;

    public function key(): string
    {
        return 'finance.treasury';
    }

    public function group(): string
    {
        return 'finance';
    }

    public function title(): string
    {
        return 'reports.finance.treasury.title';
    }

    public function description(): string
    {
        return 'reports.finance.treasury.description';
    }

    public function permission(): string
    {
        return 'reports.finance.view';
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
        return ['preset', 'date_from', 'date_to', 'treasury_id', 'employee_id'];
    }

    /**
     * @return list<ReportColumn>
     */
    public function columns(): array
    {
        return [
            ReportColumn::text('treasury', 'reports.columns.treasury'),
            ReportColumn::enum('type', 'reports.columns.treasury_type', 'reports.treasuryType.'),
            ReportColumn::money('opening', 'reports.columns.opening'),
            ReportColumn::money('income', 'reports.columns.income'),
            ReportColumn::money('expense', 'reports.columns.expense'),
            ReportColumn::money('transfer_in', 'reports.columns.transfer_in'),
            ReportColumn::money('transfer_out', 'reports.columns.transfer_out'),
            ReportColumn::money('adjustment', 'reports.columns.adjustment'),
            ReportColumn::money('closing', 'reports.columns.closing'),
            ReportColumn::money('stored_balance', 'reports.columns.stored_balance'),
            ReportColumn::money('variance', 'reports.columns.variance'),
        ];
    }

    public function defaultSort(): ?array
    {
        return ['closing', 'desc'];
    }

    /**
     * @return list<string>
     */
    public function notes(): array
    {
        return ['reports.notes.treasury_variance'];
    }

    public function query(Employee $employee, array $filters): Builder
    {
        $period = $this->resolvePeriod($filters);
        $from = $period['from']->toDateTimeString();
        $to = $period['to']->toDateTimeString();

        $income = TreasuryTransactionType::Income->value;
        $expense = TreasuryTransactionType::Expense->value;
        $transferIn = TreasuryTransactionType::TransferIn->value;
        $transferOut = TreasuryTransactionType::TransferOut->value;
        $adjustment = TreasuryTransactionType::Adjustment->value;

        $actorFilter = $this->listOf($filters, 'employee_id');
        $actorSql = $actorFilter === null
            ? ''
            : ' AND tt.created_by IN ('.implode(',', array_map('intval', $actorFilter)).')';

        // Everything before the window opened. Transfer-out and expense
        // amounts are stored negative (TreasuryService), so the opening
        // balance is a plain signed sum rather than a set of cases.
        $opening = "(SELECT COALESCE(SUM(tt.amount), 0) FROM treasury_transactions tt
                     WHERE tt.treasury_id = treasuries.id AND tt.created_at < '{$from}'{$actorSql})";

        $inWindow = fn (string $type) => "(SELECT COALESCE(SUM(tt.amount), 0) FROM treasury_transactions tt
              WHERE tt.treasury_id = treasuries.id AND tt.type = '{$type}'
                AND tt.created_at >= '{$from}' AND tt.created_at < '{$to}'{$actorSql})";

        return Treasury::query()
            ->when($this->listOf($filters, 'treasury_id'), fn ($q, array $v) => $q->whereIn('treasuries.id', $v))
            ->selectRaw('treasuries.name as treasury')
            ->selectRaw('treasuries.type as type')
            ->selectRaw('treasuries.current_balance as stored_balance')
            ->selectRaw("{$opening} as opening")
            ->selectRaw($inWindow($income).' as income')
            ->selectRaw($inWindow($expense).' as expense')
            ->selectRaw($inWindow($transferIn).' as transfer_in')
            ->selectRaw($inWindow($transferOut).' as transfer_out')
            ->selectRaw($inWindow($adjustment).' as adjustment')
            ->orderByDesc('treasuries.current_balance');
    }

    /**
     * @return array<string, mixed>
     */
    public function map(object $row): array
    {
        $opening = (float) $row->opening;
        $movement = (float) $row->income + (float) $row->expense
            + (float) $row->transfer_in + (float) $row->transfer_out
            + (float) $row->adjustment;

        $closing = round($opening + $movement, 2);
        $stored = (float) $row->stored_balance;

        return [
            'treasury' => $row->treasury,
            'type' => $row->type instanceof \BackedEnum ? $row->type->value : $row->type,
            'opening' => $opening,
            'income' => (float) $row->income,
            'expense' => (float) $row->expense,
            'transfer_in' => (float) $row->transfer_in,
            'transfer_out' => (float) $row->transfer_out,
            'adjustment' => (float) $row->adjustment,
            'closing' => $closing,
            'stored_balance' => $stored,
            // Only meaningful when the window ends now; a historical period
            // legitimately differs from today's balance, which is why the
            // note on the report says so rather than flagging every row.
            'variance' => round($closing - $stored, 2),
        ];
    }
}
