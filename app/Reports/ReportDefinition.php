<?php

namespace App\Reports;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;

/**
 * One report, defined once.
 *
 * The screen, the .xlsx, the .csv and the print view all read this same
 * object — they never build a query of their own. That is the project's
 * existing rule, lifted from `OrdersExport`, which calls the same
 * `Order::filtered()` scope `/admin/orders` uses "so the download can
 * never drift from the table". Thirty-nine reports multiply the cost of
 * getting that wrong by thirty-nine, so here it is a base class rather
 * than a convention.
 *
 * Two shapes of report inherit from this:
 *
 * - **Detail** — one row per record, paginated (`isPaginated()` true).
 *   The order register, the movement ledger, the audit trail.
 * - **Summary** — one row per bucket, already aggregated in SQL and
 *   bounded by the period, so pagination would be noise. Sales by month,
 *   status pipeline, reasons breakdown.
 *
 * Row-level scoping is *not* the subclass's business to remember: every
 * report whose rows are orders runs `Order::visibleTo($employee)` inside
 * its own `query()`, and the controller passes the employee in for
 * exactly that reason. A Customer Service Team Leader exporting a sales
 * report physically cannot receive another team's orders.
 */
abstract class ReportDefinition
{
    /** Stable identifier used in the URL: `orders.summary`, `sales.by-product`. */
    abstract public function key(): string;

    /** Report group, matching the navigation: orders|sales|inventory|returns|finance|employees|audit|executive. */
    abstract public function group(): string;

    /** Translation key for the report's own name. */
    abstract public function title(): string;

    /** Translation key for the one-line description on the catalogue card. */
    abstract public function description(): string;

    /** The permission that gates this report entirely. */
    abstract public function permission(): string;

    /**
     * Which of the standard filter keys (spec A.3) this report accepts.
     * The filter bar renders only these, so a report never shows a control
     * that does nothing.
     *
     * @return list<string>
     */
    abstract public function filters(): array;

    /**
     * @return list<ReportColumn>
     */
    abstract public function columns(): array;

    /**
     * @param  array<string, mixed>  $filters
     */
    abstract public function query(Employee $employee, array $filters): Builder;

    /**
     * One result row as a flat, column-keyed array. Used verbatim by the
     * table, the export and the print view.
     *
     * @return array<string, mixed>
     */
    abstract public function map(object $row): array;

    /**
     * Which date column the period filter applies to: `placed`,
     * `delivered` or `collected` (spec A.3).
     *
     * "Orders in September" and "revenue in September" are different
     * questions against different columns, so every report states its
     * answer rather than leaving the reader to assume one.
     */
    public function defaultDateBasis(): string
    {
        return 'placed';
    }

    /**
     * Whether the user may switch the date basis, or it is fixed by what
     * the report means. A stock snapshot has no date basis at all.
     *
     * @return list<string>
     */
    public function availableDateBases(): array
    {
        return [$this->defaultDateBasis()];
    }

    /** Summary reports return bounded buckets; detail reports paginate. */
    public function isPaginated(): bool
    {
        return true;
    }

    /** Whether `compare_to` (previous period / same period last year) is offered. */
    public function supportsComparison(): bool
    {
        return false;
    }

    /**
     * Extra permissions beyond `permission()`, each unlocking a subset of
     * columns rather than the whole report — `reports.cost.view` is the
     * one that exists today. Columns naming a gate the viewer lacks are
     * stripped from the payload entirely rather than zeroed, matching how
     * `DashboardController` omits tiles a viewer cannot see.
     *
     * @return list<string>
     */
    public function columnGates(): array
    {
        return array_values(array_unique(array_filter(
            array_map(fn (ReportColumn $column) => $column->gate, $this->columns())
        )));
    }

    /**
     * The columns this employee may actually see.
     *
     * @return list<ReportColumn>
     */
    public function visibleColumns(Employee $employee): array
    {
        return array_values(array_filter(
            $this->columns(),
            fn (ReportColumn $column) => $column->gate === null || $employee->can($column->gate),
        ));
    }

    /**
     * Footer totals, as a column-keyed array. Computed by its own
     * aggregate query, never by summing the paginated page — a page-two
     * total that disagrees with page one is worse than no total.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function totals(Employee $employee, array $filters): array
    {
        return [];
    }

    /**
     * Default sort as [column key, direction]. Null leaves the ordering
     * the `query()` itself applied.
     *
     * @return array{0: string, 1: string}|null
     */
    public function defaultSort(): ?array
    {
        return null;
    }

    /**
     * Notes printed on the report header and carried into the export's
     * first rows — the basis a figure is computed on, a retention window,
     * a known approximation. Translation keys.
     *
     * A report that quietly uses current cost prices for historical margin,
     * or quietly stops at the audit retention boundary, is a report that
     * misleads precisely the people who trust it most. These are not
     * disclaimers to bury.
     *
     * @return list<string>
     */
    public function notes(): array
    {
        return [];
    }

    /**
     * Filename stem for downloads. ASCII only and never localized —
     * filesystem safety across Windows, macOS and Linux matters more than
     * a translated filename, and the headings inside are translated anyway.
     */
    public function filename(): string
    {
        return str_replace('.', '-', $this->key());
    }
}
