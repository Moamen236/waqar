<?php

namespace App\Exports\Reports;

use App\Models\Employee;
use App\Reports\ReportColumn;
use App\Reports\ReportDefinition;
use App\Reports\ReportTranslator;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * One export class for every report.
 *
 * It wraps a `ReportDefinition` and reads its query, its columns and its
 * row mapper — the same three things the screen reads. That is the rule
 * `OrdersExport` already establishes ("so the download can never drift
 * from the table"), applied once instead of copied thirty-nine times.
 *
 * Column gating travels with it: `visibleColumns()` strips anything the
 * downloading employee lacks the permission for, so a spreadsheet can
 * never contain a cost column its owner could not see on screen. Row
 * scoping is inside the definition's own `query()`, through
 * `Order::visibleTo()`.
 *
 * Headings are translated server-side against the request locale, so an
 * Arabic operator's download has Arabic headers. Values are not: money
 * and counts go out as raw numerics, because a currency-formatted cell is
 * text to Excel and a totals column nobody can sum defeats the point of
 * exporting at all.
 */
class ReportExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping
{
    use Exportable;

    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        private readonly ReportDefinition $report,
        private readonly Employee $employee,
        private readonly array $filters,
    ) {}

    public function query(): Builder
    {
        return $this->report->query($this->employee, $this->filters);
    }

    /**
     * The filter summary rides above the column headings rather than in a
     * separate sheet: a downloaded file that cannot say which query
     * produced it is a file nobody can defend in a meeting three weeks
     * later.
     *
     * @return list<list<string>>
     */
    public function headings(): array
    {
        $t = app(ReportTranslator::class);

        $columns = array_map(
            fn (ReportColumn $column) => $t->get($column->label),
            $this->report->visibleColumns($this->employee),
        );

        $meta = [
            [$t->get($this->report->title())],
            [$t->get('reports.meta.generated_at'), now()->toDateTimeString()],
            [$t->get('reports.meta.generated_by'), $this->employee->full_name],
        ];

        // The basis notes ride into the file too: a spreadsheet that says
        // "costed at current cost price" is one nobody misreads three
        // months later.
        foreach ($this->report->notes() as $note) {
            $meta[] = [$t->get($note)];
        }

        // Blank spacer, then the real header row.
        $meta[] = [];
        $meta[] = $columns;

        return $meta;
    }

    /**
     * @return list<mixed>
     */
    public function map($row): array
    {
        $mapped = $this->report->map($row);

        return array_map(
            fn (ReportColumn $column) => $mapped[$column->key] ?? null,
            $this->report->visibleColumns($this->employee),
        );
    }
}
