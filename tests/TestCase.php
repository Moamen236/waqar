<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

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
}
