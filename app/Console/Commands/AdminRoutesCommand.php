<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * `php artisan waqar:admin-routes` — every admin GET route, with its
 * parameters filled from real rows, one URL per line.
 *
 * Feeds tools/render-check.js. Generated rather than hand-listed on
 * purpose: a hand-maintained list silently stops covering whatever was
 * added last, which is exactly the screen most likely to be broken.
 */
class AdminRoutesCommand extends Command
{
    protected $signature = 'waqar:admin-routes {--locale=ar : Locale segment to emit}';

    protected $description = 'List every admin GET route as a concrete URL, for the render check';

    /**
     * Route parameter name → the table its ids come from. A parameter not
     * listed here falls back to 1, which is fine for the handful that are
     * plain integers rather than model bindings.
     *
     * @var array<string, string>
     */
    private const SOURCES = [
        'order' => 'orders',
        'product' => 'products',
        'category' => 'categories',
        'collection' => 'collections',
        'customer' => 'customers',
        'employee' => 'employees',
        'promotion' => 'promotions',
        'return' => 'returns',
        'representative' => 'delivery_representatives',
        'shippingCompany' => 'shipping_companies',
        'shippingRate' => 'shipping_rates',
        'attribute' => 'attributes',
        'role' => 'roles',
        'statement' => 'shipping_company_statements',
    ];

    public function handle(): int
    {
        $locale = (string) $this->option('locale');
        $urls = [];

        // ->getRoutes() on the collection, not on the facade: the
        // facade returns RouteCollectionInterface, which PHPStan is right
        // to say is not declared iterable even though it happens to be.
        /** @var RoutingRoute $route */
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $uri = $route->uri();

            if (! str_starts_with($uri, '{locale}/admin') || str_contains($uri, 'login')) {
                continue;
            }

            $url = preg_replace_callback('/\{(\w+)\??\}/', function (array $match) use ($locale) {
                if ($match[1] === 'locale') {
                    return $locale;
                }

                $table = self::SOURCES[$match[1]] ?? null;

                return (string) ($table !== null ? ($this->firstId($table) ?? 1) : 1);
            }, $uri);

            $urls['/'.$url] = true;
        }

        $urls = array_keys($urls);
        sort($urls);

        foreach ($urls as $url) {
            $this->line($url);
        }

        return self::SUCCESS;
    }

    private function firstId(string $table): ?int
    {
        try {
            /** @var int|null $id */
            $id = DB::table($table)->min('id');

            return $id;
        } catch (\Throwable) {
            return null;
        }
    }
}
