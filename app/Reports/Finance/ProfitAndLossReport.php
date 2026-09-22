<?php

namespace App\Reports\Finance;

use App\Models\Employee;
use App\Models\Expense;
use App\Models\Order;
use App\Reports\Concerns\AppliesStandardFilters;
use App\Reports\Concerns\MeasuresSales;
use App\Reports\ReportColumn;
use App\Reports\ReportDefinition;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * FIN-05 · Operational Profit & Loss.
 *
 * One row per P&L line, in statement order, with a comparison column. The
 * most-scrutinised number in the module, so three things are stated rather
 * than assumed:
 *
 * - **There is no delivery-cost line.** Post-Phase C the courier is paid by
 *   the customer at the door and the money never reaches a treasury, so the
 *   shipping is neither revenue nor expense. It appears as a memo line
 *   below the total. Subtracting `shipping_company_statements.delivery_fees_owed`
 *   would double-count against a column that is now always zero.
 * - **COGS is at today's cost price.** `order_items` carries no cost
 *   snapshot; the note says so, and AUD-03 shows every cost change.
 * - **Revenue is recognised on delivery**, matching where stock actually
 *   deducts.
 *
 * Built as a UNION of small aggregates rather than one wide query: the
 * lines come from genuinely different tables, and forcing them into one
 * join would produce a cartesian product between expenses and orders.
 */
class ProfitAndLossReport extends ReportDefinition
{
    use AppliesStandardFilters, MeasuresSales;

    public function key(): string
    {
        return 'finance.profit-loss';
    }

    public function group(): string
    {
        return 'finance';
    }

    public function title(): string
    {
        return 'reports.finance.pnl.title';
    }

    public function description(): string
    {
        return 'reports.finance.pnl.description';
    }

    public function permission(): string
    {
        return 'reports.finance.view';
    }

    public function defaultDateBasis(): string
    {
        return 'delivered';
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
        return ['preset', 'date_from', 'date_to', 'compare_to', 'order_source', 'governorate_id'];
    }

    /**
     * Cost figures carry the gate; the line labels do not, so a viewer
     * without `reports.cost.view` sees an empty report rather than a
     * confusing half-one — which is why the whole report is also listed
     * under Accounting and Chairman only in the permission matrix.
     *
     * @return list<ReportColumn>
     */
    public function columns(): array
    {
        return [
            ReportColumn::enum('line', 'reports.columns.line', ''),
            ReportColumn::money('current', 'reports.columns.current_period', false, 'reports.cost.view'),
            ReportColumn::money('comparison', 'reports.columns.comparison_period', false, 'reports.cost.view'),
            ReportColumn::money('delta', 'reports.columns.delta', false, 'reports.cost.view'),
            ReportColumn::percent('delta_percent', 'reports.columns.delta_percent', false, 'reports.cost.view'),
        ];
    }

    /**
     * @return list<string>
     */
    public function notes(): array
    {
        return [
            'reports.notes.revenue_on_delivery',
            'reports.notes.current_cost_basis',
            'reports.notes.shipping_not_pnl',
        ];
    }

    /**
     * The P&L is computed, not selected — `query()` returns a builder over
     * an inline VALUES-like derived table so the module's one-query-per-
     * report contract still holds for the export and the print view.
     */
    public function query(Employee $employee, array $filters): Builder
    {
        $period = $this->resolvePeriod($filters);
        $comparison = $this->comparisonPeriod($period, (string) ($filters['compare_to'] ?? 'none'));

        $current = $this->figuresFor($employee, $filters, $period);
        $previous = $comparison === null ? null : $this->figuresFor($employee, $filters, $comparison);

        $lines = [
            'net_revenue' => 'reports.pnl.net_revenue',
            'cogs' => 'reports.pnl.cogs',
            'gross_profit' => 'reports.pnl.gross_profit',
            'operating_expense' => 'reports.pnl.operating_expense',
            'operating_profit' => 'reports.pnl.operating_profit',
            'memo_courier_fees' => 'reports.pnl.memo_courier_fees',
            'memo_gross_at_door' => 'reports.pnl.memo_gross_at_door',
        ];

        $rows = [];
        $order = 0;

        foreach ($lines as $key => $label) {
            $a = $current[$key];
            $b = $previous[$key] ?? null;

            $rows[] = DB::query()->selectRaw(
                'CAST(? AS CHAR) as line, CAST(? AS DECIMAL(14,2)) as current_value,
                 CAST(? AS DECIMAL(14,2)) as comparison_value, ? as sort_order',
                [$label, $a, $b, $order++]
            );
        }

        $union = array_shift($rows);

        foreach ($rows as $row) {
            $union->unionAll($row);
        }

        return Order::query()
            ->withoutGlobalScopes()
            ->fromSub($union, 'pnl')
            ->selectRaw('pnl.line, pnl.current_value, pnl.comparison_value')
            ->orderBy('pnl.sort_order');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array{from: CarbonImmutable, to: CarbonImmutable}  $period
     * @return array<string, float|null>
     */
    private function figuresFor(Employee $employee, array $filters, array $period): array
    {
        $collected = $this->collectedSql();
        $refunded = $this->refundedSql();
        $cost = $this->unitCostSql();

        $orders = Order::query()
            ->visibleTo($employee)
            ->whereIn('orders.status', $this->soldStatuses());

        $orders = $this->applyOrderDateBasis($orders, 'delivered', $period);
        $orders = $this->applyOrderFilters($orders, $filters);

        // toBase(): aggregate rows are not Orders. Scopes (visibleTo, soft
        // deletes) are already applied by the time toBase() hands back the
        // underlying query, so nothing is lost by dropping the model.
        $revenue = (clone $orders)
            ->toBase()
            ->selectRaw("COALESCE(SUM({$collected}), 0) as recognised")
            ->selectRaw("COALESCE(SUM({$refunded}), 0) as refunds")
            ->selectRaw('COALESCE(SUM(orders.shipping_amount), 0) as courier_fees')
            ->first();

        // COGS over the same order set, at line level.
        $cogs = (clone $orders)
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->join('product_variants', 'product_variants.id', '=', 'order_items.product_variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->selectRaw("COALESCE(SUM(order_items.quantity * {$cost}), 0) as cogs")
            ->value('cogs');

        $expenses = Expense::query()
            ->whereDate('expenses.expense_date', '>=', $period['from']->toDateString())
            ->whereDate('expenses.expense_date', '<', $period['to']->toDateString())
            ->sum('amount');

        $netRevenue = round((float) $revenue->recognised - (float) $revenue->refunds, 2);
        $cogsValue = round((float) $cogs, 2);
        $grossProfit = round($netRevenue - $cogsValue, 2);
        $expenseValue = round((float) $expenses, 2);
        $courierFees = round((float) $revenue->courier_fees, 2);

        return [
            'net_revenue' => $netRevenue,
            // Shown negative so the column reads like a statement rather
            // than requiring the reader to know which lines to subtract.
            'cogs' => -$cogsValue,
            'gross_profit' => $grossProfit,
            'operating_expense' => -$expenseValue,
            'operating_profit' => round($grossProfit - $expenseValue, 2),
            'memo_courier_fees' => $courierFees,
            'memo_gross_at_door' => round($netRevenue + $courierFees, 2),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function map(object $row): array
    {
        $current = $row->current_value === null ? null : (float) $row->current_value;
        $comparison = $row->comparison_value === null ? null : (float) $row->comparison_value;

        return [
            'line' => $row->line,
            'current' => $current,
            'comparison' => $comparison,
            'delta' => $current === null || $comparison === null ? null : round($current - $comparison, 2),
            // Guarded: a prior period of zero makes percentage change
            // undefined, not infinite.
            'delta_percent' => $current === null || $comparison === null || $comparison == 0.0
                ? null
                : round(($current - $comparison) / abs($comparison) * 100, 2),
        ];
    }
}
