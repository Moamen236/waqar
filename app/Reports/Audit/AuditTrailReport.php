<?php

namespace App\Reports\Audit;

use App\Models\Employee;
use App\Reports\Concerns\AppliesStandardFilters;
use App\Reports\ReportColumn;
use App\Reports\ReportDefinition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * AUD-01 · Audit Trail — Employee → Action → Entity → Previous → New →
 * Timestamp.
 *
 * **One row per changed field, not per log entry.** That is the difference
 * between a trail and a JSON dump: an entry that changed three fields
 * becomes three rows, each answering "what was it, what is it now". It is
 * also what makes the export usable — a spreadsheet column of nested JSON
 * is not something anyone can filter.
 *
 * MySQL has no lateral join, so the fan-out uses a small numbers table
 * generated inline and `JSON_KEYS` to index into the properties payload.
 *
 * The entity label is read from `properties->label`, the snapshot
 * `RecordsActivity` writes at log time — so a deleted product still says
 * which product it was, which is the only bar this feature has to clear.
 */
class AuditTrailReport extends ReportDefinition
{
    use AppliesStandardFilters;

    /** Fields that carry no information worth a row of their own. */
    private const IGNORED_FIELDS = ['label', 'updated_at', 'created_at'];

    public function key(): string
    {
        return 'audit.trail';
    }

    public function group(): string
    {
        return 'audit';
    }

    public function title(): string
    {
        return 'reports.audit.trail.title';
    }

    public function description(): string
    {
        return 'reports.audit.trail.description';
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
        return ['preset', 'date_from', 'date_to', 'log_name', 'event', 'employee_id', 'subject_type', 'search'];
    }

    /**
     * @return list<ReportColumn>
     */
    public function columns(): array
    {
        return [
            ReportColumn::datetime('logged_at', 'reports.columns.datetime'),
            ReportColumn::text('employee', 'reports.columns.employee'),
            ReportColumn::enum('event', 'reports.columns.action', 'activity.event.'),
            ReportColumn::text('entity_type', 'reports.columns.entity_type'),
            ReportColumn::text('entity', 'reports.columns.entity'),
            ReportColumn::text('field', 'reports.columns.field'),
            ReportColumn::text('old_value', 'reports.columns.previous_value'),
            ReportColumn::text('new_value', 'reports.columns.new_value'),
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

        $entries = DB::table('activity_log')
            ->leftJoin('employees', function ($join) {
                $join->on('employees.id', '=', 'activity_log.causer_id')
                    ->where('activity_log.causer_type', '=', (new Employee)->getMorphClass());
            })
            ->where('activity_log.created_at', '>=', $period['from'])
            ->where('activity_log.created_at', '<', $period['to'])
            ->when($this->listOf($filters, 'log_name'), fn ($q, array $v) => $q->whereIn('activity_log.log_name', $v))
            ->when($this->listOf($filters, 'event'), fn ($q, array $v) => $q->whereIn('activity_log.event', $v))
            ->when($this->listOf($filters, 'employee_id'), fn ($q, array $v) => $q->whereIn('activity_log.causer_id', $v))
            ->when(! empty($filters['subject_type']), fn ($q) => $q->where('activity_log.subject_type', 'like', '%'.$filters['subject_type']))
            ->when($this->term($filters, 'search'), fn ($q, string $v) => $q->where('activity_log.properties->label', 'like', "%{$v}%"))
            ->selectRaw('activity_log.id as activity_id')
            ->selectRaw('activity_log.created_at as logged_at')
            ->selectRaw('activity_log.event as event')
            ->selectRaw('activity_log.log_name as log_name')
            ->selectRaw('activity_log.subject_type as subject_type')
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(activity_log.properties, '$.label')) as entity")
            ->selectRaw('activity_log.properties as properties')
            ->selectRaw('employees.full_name as employee');

        // 0..63 — an audited model logs a bounded, explicit attribute list
        // (RecordsActivity), so sixty-four covers every case with room to
        // spare. Generated inline rather than as a table: one fewer thing
        // to migrate and keep in step.
        $indexes = DB::query()->selectRaw('0 AS n');

        for ($n = 1; $n < 64; $n++) {
            $indexes->unionAll(DB::query()->selectRaw("{$n} AS n"));
        }

        $ignored = "'".implode("','", self::IGNORED_FIELDS)."'";

        return Activity::query()
            ->fromSub($entries, 'a')
            ->crossJoinSub($indexes, 'idx')
            ->selectRaw('a.logged_at as logged_at')
            ->selectRaw('a.employee as employee')
            ->selectRaw('a.event as event')
            ->selectRaw('a.subject_type as subject_type')
            ->selectRaw('a.entity as entity')
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(JSON_KEYS(a.properties, '$.attributes'), CONCAT('$[', idx.n, ']'))) as field")
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(a.properties, CONCAT('$.old.',
                JSON_UNQUOTE(JSON_EXTRACT(JSON_KEYS(a.properties, '$.attributes'), CONCAT('$[', idx.n, ']')))))) as old_value")
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(a.properties, CONCAT('$.attributes.',
                JSON_UNQUOTE(JSON_EXTRACT(JSON_KEYS(a.properties, '$.attributes'), CONCAT('$[', idx.n, ']')))))) as new_value")
            // Only indexes that actually point at a key, and never the
            // bookkeeping fields.
            ->whereRaw("idx.n < JSON_LENGTH(JSON_KEYS(a.properties, '$.attributes'))")
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(JSON_KEYS(a.properties, '$.attributes'), CONCAT('$[', idx.n, ']'))) NOT IN ({$ignored})")
            ->orderByDesc('a.logged_at')
            ->orderBy('a.activity_id')
            ->orderBy('idx.n');
    }

    /**
     * @return array<string, mixed>
     */
    public function map(object $row): array
    {
        return [
            'logged_at' => $row->logged_at,
            // A null causer is a system action, not lost attribution.
            'employee' => $row->employee ?? __('reports.system'),
            'event' => $row->event,
            'entity_type' => $row->subject_type === null ? null : class_basename((string) $row->subject_type),
            'entity' => $row->entity,
            'field' => $row->field,
            'old_value' => $this->scalar($row->old_value),
            'new_value' => $this->scalar($row->new_value),
        ];
    }

    /**
     * Translatable columns are JSON and enum casts serialise as objects, so
     * a raw value can arrive as `{"ar":"...","en":"..."}`. Render something
     * a person can read rather than the storage format.
     */
    private function scalar(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $decoded = json_decode((string) $value, true);

        if (is_array($decoded)) {
            $locale = app()->getLocale();

            return (string) ($decoded[$locale]
                ?? $decoded[config('app.fallback_locale', 'en')]
                ?? collect($decoded)->filter(fn ($item) => is_scalar($item))->first()
                ?? (string) $value);
        }

        return (string) $value;
    }
}
