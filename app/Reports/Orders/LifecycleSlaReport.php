<?php

namespace App\Reports\Orders;

use App\Models\Employee;
use App\Models\OrderStatusHistory;
use App\Reports\Concerns\AppliesStandardFilters;
use App\Reports\ReportColumn;
use App\Reports\ReportDefinition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * ORD-03 · Order Lifecycle & SLA — how long each hand-off actually takes.
 *
 * Every transition is paired with the one before it on the same order via
 * `LAG()`, so "Confirmed → Assigned" measures the gap between those two
 * rows rather than from the order's creation. That is what makes the
 * numbers actionable: a slow Assigned step is the Delivery Manager's
 * queue, not Checking's.
 *
 * `orders` has no `confirmed_at`/`delivered_at` columns and needs none —
 * `order_status_history` is that record, and unlike `activity_log` it is
 * never pruned, so this report has no retention horizon.
 *
 * **Median and p90 are real, not approximations.** MySQL 8 has no
 * `PERCENTILE_CONT`, and the usual `GROUP_CONCAT` trick silently truncates
 * at `group_concat_max_len` — which fails exactly when there is enough
 * data to care. Instead each duration is row-numbered within its
 * transition and the median/p90 rows are picked by position. An average
 * alone would hide the case this report exists to find: a step that is
 * fine most days and catastrophic occasionally.
 */
class LifecycleSlaReport extends ReportDefinition
{
    use AppliesStandardFilters;

    public function key(): string
    {
        return 'orders.lifecycle';
    }

    public function group(): string
    {
        return 'orders';
    }

    public function title(): string
    {
        return 'reports.orders.lifecycle.title';
    }

    public function description(): string
    {
        return 'reports.orders.lifecycle.description';
    }

    public function permission(): string
    {
        return 'reports.orders.view';
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
        return [
            'preset', 'date_from', 'date_to',
            'order_source', 'employee_id',
            'governorate_id', 'city_id', 'district_id', 'area_id',
            'representative_id', 'shipping_company_id',
        ];
    }

    /**
     * @return list<ReportColumn>
     */
    public function columns(): array
    {
        return [
            ReportColumn::text('transition', 'reports.columns.transition'),
            ReportColumn::number('transitions', 'reports.columns.events'),
            ReportColumn::number('avg_hours', 'reports.columns.avg_hours'),
            ReportColumn::number('median_hours', 'reports.columns.median_hours'),
            ReportColumn::number('p90_hours', 'reports.columns.p90_hours'),
            ReportColumn::number('max_hours', 'reports.columns.max_hours'),
        ];
    }

    public function defaultSort(): ?array
    {
        return ['avg_hours', 'desc'];
    }

    public function query(Employee $employee, array $filters): Builder
    {
        $period = $this->resolvePeriod($filters);

        // 1. Pair each transition with its predecessor on the same order.
        //    Ordering by created_at then id breaks ties deterministically —
        //    two transitions can share a timestamp to the second, and an
        //    unstable pairing would produce negative durations.
        $steps = OrderStatusHistory::query()
            ->selectRaw('order_id, to_status, created_at')
            ->selectRaw('LAG(to_status) OVER (PARTITION BY order_id ORDER BY created_at, id) as from_status')
            ->selectRaw('LAG(created_at) OVER (PARTITION BY order_id ORDER BY created_at, id) as from_at')
            ->whereIn('order_id', $this->visibleOrders($employee))
            ->when($this->listOf($filters, 'employee_id'), fn ($q, array $v) => $q->whereIn('changed_by', $v))
            ->toBase();

        // 2. Keep the paired rows inside the period and measure each gap.
        $durations = DB::query()
            ->fromSub($steps, 's')
            ->selectRaw("CONCAT(s.from_status, ' → ', s.to_status) as transition")
            ->selectRaw('TIMESTAMPDIFF(MINUTE, s.from_at, s.created_at) as mins')
            ->whereNotNull('s.from_at')
            ->where('s.created_at', '>=', $period['from'])
            ->where('s.created_at', '<', $period['to']);

        // 3. Rank each duration within its transition so the percentile
        //    rows can be picked by position.
        $ranked = DB::query()
            ->fromSub($durations, 'd')
            ->selectRaw('d.transition, d.mins')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY d.transition ORDER BY d.mins) as rn')
            ->selectRaw('GREATEST(CEIL(COUNT(*) OVER (PARTITION BY d.transition) * 0.5), 1) as median_rn')
            ->selectRaw('GREATEST(CEIL(COUNT(*) OVER (PARTITION BY d.transition) * 0.9), 1) as p90_rn');

        return OrderStatusHistory::query()
            ->fromSub($ranked, 'r')
            ->selectRaw('r.transition as transition')
            ->selectRaw('COUNT(*) as transitions')
            ->selectRaw('ROUND(AVG(r.mins) / 60, 1) as avg_hours')
            ->selectRaw('ROUND(MAX(r.mins) / 60, 1) as max_hours')
            ->selectRaw('ROUND(MAX(CASE WHEN r.rn = r.median_rn THEN r.mins END) / 60, 1) as median_hours')
            ->selectRaw('ROUND(MAX(CASE WHEN r.rn = r.p90_rn THEN r.mins END) / 60, 1) as p90_hours')
            ->groupBy('r.transition')
            ->orderByDesc('avg_hours');
    }

    /**
     * @return array<string, mixed>
     */
    public function map(object $row): array
    {
        return [
            'transition' => $row->transition,
            'transitions' => (int) $row->transitions,
            'avg_hours' => (float) $row->avg_hours,
            'median_hours' => (float) $row->median_hours,
            'p90_hours' => (float) $row->p90_hours,
            'max_hours' => (float) $row->max_hours,
        ];
    }
}
