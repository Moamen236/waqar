<?php

namespace App\Reports\Audit;

use App\Models\Employee;
use App\Models\Order;
use App\Reports\Concerns\AppliesStandardFilters;
use App\Reports\ReportColumn;
use App\Reports\ReportDefinition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * AUD-02 · Entity History — one record's complete timeline.
 *
 * Opened from a detail screen with `subject_type` and `subject_id`, so it
 * is the "what happened to this order" view rather than a period report.
 *
 * For an order it **merges `order_status_history` with `activity_log`**,
 * because the two hold different halves of the story: the status table is
 * permanent and carries the reason an operator typed, while the activity
 * log carries field-level edits but is pruned after a year. Showing only
 * the log would lose the reason a year-old order was cancelled; showing
 * only the status table would lose that someone changed its total.
 */
class EntityHistoryReport extends ReportDefinition
{
    use AppliesStandardFilters;

    public function key(): string
    {
        return 'audit.entity-history';
    }

    public function group(): string
    {
        return 'audit';
    }

    public function title(): string
    {
        return 'reports.audit.entity.title';
    }

    public function description(): string
    {
        return 'reports.audit.entity.description';
    }

    public function permission(): string
    {
        return 'reports.audit.view';
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
        return ['subject_type', 'subject_id'];
    }

    /**
     * @return list<ReportColumn>
     */
    public function columns(): array
    {
        return [
            ReportColumn::datetime('logged_at', 'reports.columns.datetime'),
            ReportColumn::text('employee', 'reports.columns.employee'),
            ReportColumn::text('action', 'reports.columns.action'),
            ReportColumn::text('detail', 'reports.columns.detail'),
            ReportColumn::text('source', 'reports.columns.source_table'),
        ];
    }

    public function defaultSort(): ?array
    {
        return ['logged_at', 'asc'];
    }

    /**
     * @return list<string>
     */
    public function notes(): array
    {
        return ['reports.notes.entity_history_sources'];
    }

    public function query(Employee $employee, array $filters): Builder
    {
        $subjectType = (string) ($filters['subject_type'] ?? '');
        $subjectId = (int) ($filters['subject_id'] ?? 0);
        $employeeType = (new Employee)->getMorphClass();

        $log = DB::table('activity_log')
            ->leftJoin('employees', function ($join) use ($employeeType) {
                $join->on('employees.id', '=', 'activity_log.causer_id')
                    ->where('activity_log.causer_type', '=', $employeeType);
            })
            ->where('activity_log.subject_id', $subjectId)
            ->where('activity_log.subject_type', 'like', '%'.$subjectType)
            ->selectRaw('activity_log.created_at as logged_at')
            ->selectRaw('employees.full_name as employee')
            ->selectRaw('activity_log.event as action')
            ->selectRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(activity_log.properties, '$.label')) AS CHAR) as detail")
            ->selectRaw("'activity_log' as source");

        // Orders carry a second, permanent history worth merging in.
        if ($subjectType !== '' && str_contains(Order::class, $subjectType)) {
            $status = DB::table('order_status_history')
                ->leftJoin('employees', 'employees.id', '=', 'order_status_history.changed_by')
                ->where('order_status_history.order_id', $subjectId)
                ->selectRaw('order_status_history.created_at as logged_at')
                ->selectRaw('employees.full_name as employee')
                ->selectRaw("CONCAT(COALESCE(order_status_history.from_status, '—'), ' → ', order_status_history.to_status) as action")
                ->selectRaw('CAST(COALESCE(order_status_history.reason, order_status_history.notes) AS CHAR) as detail')
                ->selectRaw("'order_status_history' as source");

            $log->unionAll($status);
        }

        return Activity::query()
            ->fromSub($log, 'h')
            ->selectRaw('h.logged_at, h.employee, h.action, h.detail, h.source')
            ->orderBy('h.logged_at');
    }

    /**
     * @return array<string, mixed>
     */
    public function map(object $row): array
    {
        return [
            'logged_at' => $row->logged_at,
            'employee' => $row->employee ?? __('reports.system'),
            'action' => $row->action,
            'detail' => $row->detail,
            'source' => $row->source,
        ];
    }
}
