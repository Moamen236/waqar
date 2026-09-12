<?php

namespace Tests;

use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\URL;

abstract class TestCase extends BaseTestCase
{
    /**
     * Pin the suite to its own database, always.
     *
     * This matters more than it looks. The `app` container exports
     * DB_CONNECTION/DB_DATABASE (docker-compose.yml), and PHP surfaces
     * those in `$_SERVER` — which is the *first* source Laravel's Env
     * repository reads. phpunit.xml's `<env>` entries (even with
     * force="true") only reach `$_ENV` and putenv, so they lose, and
     * without this override the suite runs against the **development**
     * database and RefreshDatabase drops every table in it on each run.
     * That is also the real cause of the "two concurrent test runs
     * corrupt each other" symptom PHASE-4-HANDOVER.md documented: both
     * runs were migrating the same live database.
     *
     * Deliberately still MySQL rather than sqlite/:memory: — the suite
     * should keep catching MySQL-only strictness (a NOT NULL column with
     * no default, JSON path queries in the translatable-column scopes),
     * which is exactly what an earlier phase's WarehouseSeeder bug slipped
     * past under sqlite. It just gets its own database to do it in.
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $app['config']->set('database.default', 'mysql');
        $app['config']->set('database.connections.mysql.database', env('DB_TEST_DATABASE', 'waqar_testing'));

        return $app;
    }

    /**
     * Give route() a {locale} to fill in.
     *
     * Every route lives under /{locale}/… (Q20) and SetLocale registers the
     * segment as a default route parameter — but that only happens *during*
     * a request, and a test calls route() before making one. Without this,
     * every `route('login')` in the suite throws UrlGenerationException.
     *
     * English, not the app's Arabic default: the suite asserts on
     * English copy, and pinning it here keeps those assertions meaningful
     * rather than dependent on APP_LOCALE.
     */
    protected function setUp(): void
    {
        parent::setUp();

        URL::defaults(['locale' => 'en']);
    }

    /**
     * Render the same page under a given locale.
     *
     * @return $this
     */
    protected function withLocale(string $locale): static
    {
        abort_unless(in_array($locale, SetLocale::SUPPORTED, true), 500);

        app()->setLocale($locale);
        URL::defaults(['locale' => $locale]);

        return $this;
    }
}
