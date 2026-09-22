<?php

namespace App\Reports\Returns;

use App\Models\Employee;
use App\Models\OrderReturn;
use App\Reports\Concerns\AppliesStandardFilters;
use App\Reports\ReportColumn;
use App\Reports\ReportDefinition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * RET-05 · Return Processing Cycle Time — how long a return sits at each
 * stage.
 *
 * **Derived from `activity_log`, and says so.** `returns` has no
 * per-transition columns and no history table, so the only record of when
 * a return moved from approved to received is the audit entry
 * `OrderReturn` writes — which works precisely because `status` is in its
 * `activityLogAttributes()`, and carries the causer and timestamp with it.
 *
 * The cost of that is a horizon: `config/activitylog.php` prunes after a
 * year, so transitions older than the retention window are gone. The
 * report prints its window rather than quietly reporting on a truncated
 * set — a cycle-time chart that silently loses its oldest half is worse
 * than no chart.
 */
class ReturnCycleTimeReport extends ReportDefinition
{
    use AppliesStandardFilters;

    public function key(): string
    {
        return 'returns.cycle-time';
    }

    public function group(): string
    {
        return 'returns';
    }

    public function title(): string
    {
        return 'reports.returns.cycleTime.title';
    }

    public function description(): string
    {
        return 'reports.returns.cycleTime.description';
    }

    public function permission(): string
    {
        return 'reports.returns.view';
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
        return ['preset', 'date_from', 'date_to', 'return_stage', 'employee_id'];
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
            ReportColumn::number('max_hours', 'reports.columns.max_hours'),
        ];
    }

    public function defaultSort(): ?array
    {
        return ['avg_hours', 'desc'];
    }

    /**
     * @return list<string>
     */
    public function notes(): array
    {
        return ['reports.notes.activity_retention'];
    }

    public function query(Employee $employee, array $filters): Builder
    {
        $period = $this->resolvePeriod($filters);
        $subjectType = (new OrderReturn)->getMorphClass();

        // Status transitions as recorded by RecordsActivity: the new value
        // lives in properties->attributes->status, the old in
        // properties->old->status.
        $steps = DB::table('activity_log')
            ->selectRaw('subject_id as return_id')
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(properties, '$.old.status')) as from_status")
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(properties, '$.attributes.status')) as to_status")
            ->selectRaw('created_at')
            ->selectRaw('LAG(created_at) OVER (PARTITION BY subject_id ORDER BY created_at, id) as from_at')
            ->where('subject_type', $subjectType)
            ->where('event', 'updated')
            ->whereRaw("JSON_EXTRACT(properties, '$.attributes.status') IS NOT NULL")
            ->when($this->listOf($filters, 'employee_id'), fn ($q, array $v) => $q->whereIn('causer_id', $v));

        // withoutGlobalScopes: OrderReturn soft-deletes, and the model's
        // scope would append `returns.deleted_at IS NULL` against a FROM
        // that is an activity_log sub-query with no such column. The model
        // is only here to give the report an Eloquent builder to return.
        return OrderReturn::query()
            ->withoutGlobalScopes()
            ->fromSub($steps, 's')
            ->selectRaw("CONCAT(COALESCE(s.from_status, '—'), ' → ', s.to_status) as transition")
            ->selectRaw('COUNT(*) as transitions')
            ->selectRaw('ROUND(AVG(TIMESTAMPDIFF(MINUTE, s.from_at, s.created_at)) / 60, 1) as avg_hours')
            ->selectRaw('ROUND(MAX(TIMESTAMPDIFF(MINUTE, s.from_at, s.created_at)) / 60, 1) as max_hours')
            ->whereNotNull('s.from_at')
            ->whereNotNull('s.to_status')
            ->where('s.created_at', '>=', $period['from'])
            ->where('s.created_at', '<', $period['to'])
            ->groupBy('s.from_status', 's.to_status')
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
            'max_hours' => (float) $row->max_hours,
        ];
    }
}
