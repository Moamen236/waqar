<?php

namespace App\Reports\Finance;

use App\Models\Employee;
use App\Models\Expense;
use App\Reports\Concerns\AppliesStandardFilters;
use App\Reports\ReportColumn;
use App\Reports\ReportDefinition;
use Illuminate\Database\Eloquent\Builder;

/**
 * FIN-03 · Expenses — operating spend by category, account and period.
 *
 * Dated on `expense_date`, the business date the spend belongs to, rather
 * than `created_at`, the moment someone typed it in. An invoice entered a
 * week late belongs in the week it was incurred, and using the wrong column
 * quietly moves spend between months.
 */
class ExpensesReport extends ReportDefinition
{
    use AppliesStandardFilters;

    public function key(): string
    {
        return 'finance.expenses';
    }

    public function group(): string
    {
        return 'finance';
    }

    public function title(): string
    {
        return 'reports.finance.expenses.title';
    }

    public function description(): string
    {
        return 'reports.finance.expenses.description';
    }

    public function permission(): string
    {
        return 'reports.finance.view';
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
        return ['preset', 'date_from', 'date_to', 'granularity', 'compare_to', 'expense_category_id', 'treasury_id', 'employee_id'];
    }

    /**
     * @return list<ReportColumn>
     */
    public function columns(): array
    {
        return [
            ReportColumn::text('category', 'reports.columns.expense_category'),
            ReportColumn::text('treasury', 'reports.columns.treasury'),
            ReportColumn::number('entries', 'reports.columns.entries'),
            ReportColumn::money('amount', 'reports.columns.amount'),
            ReportColumn::percent('share', 'reports.columns.share'),
        ];
    }

    public function defaultSort(): ?array
    {
        return ['amount', 'desc'];
    }

    public function query(Employee $employee, array $filters): Builder
    {
        return $this->base($filters)
            ->join('expense_categories', 'expense_categories.id', '=', 'expenses.expense_category_id')
            ->join('treasuries', 'treasuries.id', '=', 'expenses.treasury_id')
            ->selectRaw($this->translatedName('expense_categories.name').' as category')
            ->selectRaw('treasuries.name as treasury')
            ->selectRaw('COUNT(*) as entries')
            ->selectRaw('COALESCE(SUM(expenses.amount), 0) as amount')
            // Each group's share of the filtered total. A window function
            // rather than a second query, so the percentages always add up
            // to the same total the footer shows.
            ->selectRaw('COALESCE(SUM(expenses.amount) / NULLIF(SUM(SUM(expenses.amount)) OVER (), 0) * 100, 0) as share')
            ->groupBy('expense_categories.id', 'category', 'treasuries.id', 'treasury')
            ->orderByDesc('amount');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function totals(Employee $employee, array $filters): array
    {
        // toBase(): an aggregate row is not an Expense, and hydrating one
        // means every alias looks like an undefined model property.
        $row = $this->base($filters)
            ->toBase()
            ->selectRaw('COUNT(*) as entries')
            ->selectRaw('COALESCE(SUM(expenses.amount), 0) as amount')
            ->first();

        return $row === null ? [] : [
            'entries' => (int) $row->entries,
            'amount' => (float) $row->amount,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Expense>
     */
    private function base(array $filters): Builder
    {
        $period = $this->resolvePeriod($filters);

        return Expense::query()
            ->whereDate('expenses.expense_date', '>=', $period['from']->toDateString())
            ->whereDate('expenses.expense_date', '<=', $period['label_to']->toDateString())
            ->when($this->listOf($filters, 'expense_category_id'), fn ($q, array $v) => $q->whereIn('expenses.expense_category_id', $v))
            ->when($this->listOf($filters, 'treasury_id'), fn ($q, array $v) => $q->whereIn('expenses.treasury_id', $v))
            ->when($this->listOf($filters, 'employee_id'), fn ($q, array $v) => $q->whereIn('expenses.created_by', $v));
    }

    /**
     * @return array<string, mixed>
     */
    public function map(object $row): array
    {
        return [
            'category' => $row->category,
            'treasury' => $row->treasury,
            'entries' => (int) $row->entries,
            'amount' => (float) $row->amount,
            'share' => round((float) $row->share, 2),
        ];
    }
}
