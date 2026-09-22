<?php

namespace App\Http\Controllers\Admin\Reports;

use App\Exports\Reports\ReportExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReportFilterRequest;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\DeliveryRepresentative;
use App\Models\Employee;
use App\Models\ShippingCompany;
use App\Models\Warehouse;
use App\Reports\ReportColumn;
use App\Reports\ReportDefinition;
use App\Reports\ReportRegistry;
use App\Support\GeoTree;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * /admin/reports — four routes for every report in the module.
 *
 * Read-only by construction, like `ActivityLogController`: there is no
 * write route here at all, not merely no permission for one.
 *
 * Authorisation happens in three layers, and only the first is this
 * class's own work:
 *
 * 1. **Report level** — `authorize()` below, against the definition's own
 *    `permission()`. Resolved per report rather than declared in
 *    `middleware()`, because the report is a route *parameter*.
 * 2. **Column level** — `visibleColumns()` strips gated columns
 *    (`reports.cost.view`) from the payload entirely rather than zeroing
 *    them, so a viewer who cannot see cost never receives it.
 * 3. **Row level** — inside each definition's `query()`, via
 *    `Order::visibleTo()`. A Customer Service Team Leader's export of a
 *    sales report cannot contain another team's orders.
 *
 * Exports carry the extra `reports.export` grant on top of the report's
 * own view permission — the project's existing principle that seeing a
 * list and walking out with the whole table as a file are different
 * rights (see `PermissionSeeder`'s note on `orders.export`).
 */
class ReportController extends Controller implements HasMiddleware
{
    public function __construct(private readonly ReportRegistry $registry) {}

    public static function middleware(): array
    {
        return [
            new Middleware('permission:reports.view'),
            new Middleware('permission:reports.export', only: ['export']),
        ];
    }

    /**
     * The catalogue. Groups with no report this employee may open are
     * absent, not empty.
     */
    public function index(Request $request): Response
    {
        $employee = $request->user('employee');

        return Inertia::render('Reports/Index', [
            'groups' => $this->registry->visibleTo($employee)
                ->map(fn ($reports) => $reports->map(fn (ReportDefinition $report) => [
                    'key' => $report->key(),
                    'title' => $report->title(),
                    'description' => $report->description(),
                ])->values())
                ->all(),
            'group' => $request->string('group')->toString(),
        ]);
    }

    public function show(ReportFilterRequest $request, string $report): Response
    {
        $employee = $request->user('employee');
        $definition = $this->resolve($employee, $report);
        $filters = $request->filters($definition->defaultDateBasis());

        $query = $definition->query($employee, $filters);
        $columns = $definition->visibleColumns($employee);

        $rows = $definition->isPaginated()
            ? $query->paginate((int) ($filters['per_page'] ?? 50))->withQueryString()
            : $query->get();

        return Inertia::render('Reports/Show', [
            'report' => $this->describe($definition, $employee),
            'options' => $this->filterOptions($definition),
            'columns' => array_map(fn (ReportColumn $column) => $column->toArray(), $columns),
            'rows' => $definition->isPaginated()
                ? $rows->through(fn ($row) => $definition->map($row))
                : $rows->map(fn ($row) => $definition->map($row))->values(),
            'totals' => $definition->totals($employee, $filters),
            'filters' => $filters,
            'paginated' => $definition->isPaginated(),
            'canExport' => $employee->can('reports.export'),
        ]);
    }

    /**
     * The print view: identical data, no chrome, `@media print` styling.
     *
     * A separate Inertia page rather than a server-rendered PDF, matching
     * how `/admin/orders/{order}/invoice` already works. The browser
     * already renders this app's Arabic correctly; a PDF library would
     * need font and RTL shaping work to reach the same place, so it stays
     * out until someone actually needs an emailed PDF.
     */
    public function print(ReportFilterRequest $request, string $report): Response
    {
        $employee = $request->user('employee');
        $definition = $this->resolve($employee, $report);
        $filters = $request->filters($definition->defaultDateBasis());

        // Print takes the whole filtered set, not page one — a printout
        // that silently stops at row 50 is worse than no printout.
        $rows = $definition->query($employee, $filters)->get();

        return Inertia::render('Reports/Print', [
            'report' => $this->describe($definition, $employee),
            'columns' => array_map(
                fn (ReportColumn $column) => $column->toArray(),
                $definition->visibleColumns($employee),
            ),
            'rows' => $rows->map(fn ($row) => $definition->map($row))->values(),
            'totals' => $definition->totals($employee, $filters),
            'filters' => $filters,
            'generatedBy' => $employee->full_name,
            'generatedAt' => now()->toDateTimeString(),
        ]);
    }

    public function export(ReportFilterRequest $request, string $report): BinaryFileResponse
    {
        $employee = $request->user('employee');
        $definition = $this->resolve($employee, $report);
        $filters = $request->filters($definition->defaultDateBasis());

        $csv = $request->string('format')->toString() === 'csv';

        $name = sprintf(
            '%s_%s.%s',
            $definition->filename(),
            now()->format('Ymd_His'),
            $csv ? 'csv' : 'xlsx',
        );

        return Excel::download(
            new ReportExport($definition, $employee, $filters),
            $name,
            $csv ? ExcelFormat::CSV : ExcelFormat::XLSX,
        );
    }

    /**
     * Option lists for the relational filters — and only for the ones this
     * report actually declares.
     *
     * A report that filters by warehouse should not also ship every
     * category, every coupon and the whole geo tree just because some other
     * report needs them. The filter bar renders what it is given, so the
     * payload stays proportional to the screen.
     *
     * @return array<string, mixed>
     */
    private function filterOptions(ReportDefinition $definition): array
    {
        $wanted = $definition->filters();
        $options = [];

        // One shared tree for all four geo levels — GeoTree::tree() is the
        // same cascading source the order-create and shipping-rate screens
        // already use, and Section 11's geography is small enough to send
        // whole rather than paginating levels over AJAX.
        if (array_intersect($wanted, ['governorate_id', 'city_id', 'district_id', 'area_id'])) {
            $options['geoTree'] = GeoTree::tree();
        }

        if (in_array('warehouse_id', $wanted, true)) {
            $options['warehouses'] = Warehouse::query()
                ->where('is_active', true)->orderBy('name')->get(['id', 'name']);
        }

        if (in_array('employee_id', $wanted, true)) {
            $options['employees'] = Employee::query()
                ->orderBy('full_name')->get(['id', 'full_name']);
        }

        if (in_array('category_id', $wanted, true)) {
            $options['categories'] = Category::query()
                ->where('status', true)->get(['id', 'name'])
                ->sortBy(fn (Category $category) => (string) $category->name)->values();
        }

        if (in_array('representative_id', $wanted, true)) {
            $options['representatives'] = DeliveryRepresentative::query()
                ->orderBy('name')->get(['id', 'name']);
        }

        if (in_array('shipping_company_id', $wanted, true)) {
            $options['shippingCompanies'] = ShippingCompany::query()
                ->orderBy('name')->get(['id', 'name']);
        }

        if (in_array('coupon_id', $wanted, true)) {
            $options['coupons'] = Coupon::query()->orderBy('code')->get(['id', 'code']);
        }

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(ReportDefinition $definition, Employee $employee): array
    {
        return [
            'key' => $definition->key(),
            'group' => $definition->group(),
            'title' => $definition->title(),
            'description' => $definition->description(),
            'filters' => $definition->filters(),
            'date_bases' => $definition->availableDateBases(),
            'supports_comparison' => $definition->supportsComparison(),
            'notes' => $definition->notes(),
            'default_sort' => $definition->defaultSort(),
        ];
    }

    private function resolve(Employee $employee, string $key): ReportDefinition
    {
        $definition = $this->registry->find($key);

        abort_if($definition === null, 404);
        abort_unless($employee->can($definition->permission()), 403);

        return $definition;
    }
}
