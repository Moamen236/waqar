<?php

use App\Models\DeliveryRepresentative;
use App\Models\Employee;
use App\Models\ShippingCompany;
use App\Reports\ReportDefinition;
use App\Reports\ReportRegistry;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

// Every admin list page, against the full demo dataset, with lazy loading
// turned into an error — an N+1 on any of them fails here rather than in
// production once the tables have grown. Also caps the query count per
// page, so a per-row query can't sneak in through a relation that is
// eager-loaded but a count or sum that isn't.

beforeEach(function () {
    // The catalogue seeder attaches product photos.
    Storage::fake('public');
    $this->seed(DatabaseSeeder::class);
    Model::preventLazyLoading();
});

afterEach(function () {
    Model::preventLazyLoading(false);
});

/**
 * Every GET admin page that takes no route parameter, minus the ones that
 * aren't list screens (downloads, prints, JSON endpoints, auth).
 *
 * @return list<string>
 */
function adminListRoutes(): array
{
    $skip = ['export', 'print', 'invoices', 'labels', 'login', 'logout', 'quote', 'product-search', 'customer-search', 'create'];

    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array('GET', $route->methods(), true))
        ->filter(fn ($route) => str_starts_with((string) $route->getName(), 'admin.'))
        ->filter(fn ($route) => array_diff($route->parameterNames(), ['locale']) === [])
        ->reject(fn ($route) => collect($skip)->contains(fn ($word) => str_contains((string) $route->getName(), $word)))
        ->map(fn ($route) => route((string) $route->getName()))
        ->values()
        ->all();
}

/**
 * List screens that sit behind a route parameter: each geography level,
 * a courier's order history, a shipping company's statements, and every
 * report that pages its rows.
 *
 * @return list<string>
 */
function adminParameterisedListUrls(): array
{
    return [
        ...array_map(fn ($level) => route('admin.geo.index', $level), ['governorates', 'cities', 'districts', 'areas']),
        route('admin.delivery.representatives.show', DeliveryRepresentative::query()->firstOrFail()),
        route('admin.accounting.reconciliation.show', ShippingCompany::query()->firstOrFail()),
        ...collect(app(ReportRegistry::class)->all())
            ->filter(fn (ReportDefinition $report) => $report->isPaginated())
            ->map(fn (ReportDefinition $report) => route('admin.reports.show', $report->key()))
            ->values()
            ->all(),
    ];
}

it('renders every admin list page without lazy loading, in a bounded number of queries', function () {
    $admin = Employee::query()->role('Super Admin')->firstOrFail();
    $report = [];

    foreach ([...adminListRoutes(), ...adminParameterisedListUrls()] as $url) {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->actingAs($admin, 'employee')->get($url);

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $report[$url] = [
            'status' => $response->status(),
            'queries' => $queries,
            'error' => $response->status() >= 500 ? ($response->exception?->getMessage() ?? 'server error') : null,
        ];
    }

    $failures = array_filter($report, fn ($row) => $row['error'] !== null);
    $heavy = array_filter($report, fn ($row) => $row['queries'] > 40);

    expect($failures)->toBe([])
        ->and($heavy)->toBe([]);
})->group('performance');
