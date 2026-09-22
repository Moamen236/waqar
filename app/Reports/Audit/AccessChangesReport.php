<?php

namespace App\Reports\Audit;

use App\Models\Employee;
use App\Reports\Concerns\AppliesStandardFilters;
use App\Reports\ReportColumn;
use App\Reports\ReportDefinition;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;

/**
 * AUD-04 · Access & Permission Changes — who gained or lost access, and
 * who granted it.
 *
 * The `access` log domain, which `Employee` declares. Role and permission
 * names are snapshotted into `properties->names` at write time, so an entry
 * stays readable after a role is renamed — the same reasoning as the label
 * snapshot everywhere else in the audit trail.
 *
 * Deliberately a separate report rather than an AUD-01 filter: access
 * changes are the ones an auditor asks for by name, and they should not
 * require knowing which log domain to type.
 */
class AccessChangesReport extends ReportDefinition
{
    use AppliesStandardFilters;

    public function key(): string
    {
        return 'audit.access';
    }

    public function group(): string
    {
        return 'audit';
    }

    public function title(): string
    {
        return 'reports.audit.access.title';
    }

    public function description(): string
    {
        return 'reports.audit.access.description';
    }

    public function permission(): string
    {
        return 'reports.audit.view';
    }

    /**
     * @return list<string>
     */
    public function filters(): array
    {
        return ['preset', 'date_from', 'date_to', 'event', 'employee_id', 'search'];
    }

    /**
     * @return list<ReportColumn>
     */
    public function columns(): array
    {
        return [
            ReportColumn::datetime('logged_at', 'reports.columns.datetime'),
            ReportColumn::text('granted_by', 'reports.columns.granted_by'),
            ReportColumn::enum('event', 'reports.columns.action', 'activity.event.'),
            ReportColumn::text('target', 'reports.columns.target_employee'),
            ReportColumn::text('names', 'reports.columns.roles_permissions'),
        ];
    }

    public function defaultSort(): ?array
    {
        return ['logged_at', 'desc'];
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
        $employeeType = (new Employee)->getMorphClass();

        return Activity::query()
            ->leftJoin('employees as actor', function ($join) use ($employeeType) {
                $join->on('actor.id', '=', 'activity_log.causer_id')
                    ->where('activity_log.causer_type', '=', $employeeType);
            })
            ->where('activity_log.log_name', 'access')
            ->where('activity_log.created_at', '>=', $period['from'])
            ->where('activity_log.created_at', '<', $period['to'])
            ->when($this->listOf($filters, 'event'), fn ($q, array $v) => $q->whereIn('activity_log.event', $v))
            ->when($this->listOf($filters, 'employee_id'), fn ($q, array $v) => $q->whereIn('activity_log.causer_id', $v))
            ->when($this->term($filters, 'search'), fn ($q, string $v) => $q->where('activity_log.properties->label', 'like', "%{$v}%"))
            ->selectRaw('activity_log.created_at as logged_at')
            ->selectRaw('actor.full_name as granted_by')
            ->selectRaw('activity_log.event as event')
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(activity_log.properties, '$.label')) as target")
            ->selectRaw("JSON_EXTRACT(activity_log.properties, '$.names') as names")
            ->orderByDesc('activity_log.created_at');
    }

    /**
     * @return array<string, mixed>
     */
    public function map(object $row): array
    {
        $names = $row->names === null ? null : json_decode((string) $row->names, true);

        return [
            'logged_at' => $row->logged_at,
            'granted_by' => $row->granted_by ?? __('reports.system'),
            'event' => $row->event,
            'target' => $row->target,
            'names' => is_array($names) ? implode(', ', array_filter($names, 'is_scalar')) : null,
        ];
    }
}
