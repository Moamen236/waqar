<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\ShippingRate;
use App\Models\Treasury;
use App\Models\Warehouse;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Throwable;

/**
 * `php artisan waqar:preflight` — the cutover checklist, executable.
 *
 * The roadmap's Phase 8 asks for "a cutover checklist walked against the
 * Final Recommended System Flow end to end". A markdown checklist would
 * be walked once, by hand, on launch night; this is the same list as a
 * command, so it can be re-run after every deploy and by CI.
 *
 * It deliberately checks the things that are *silently* wrong rather than
 * loudly broken — an unseeded shipping rate that makes checkout refuse
 * every order (the gap Phase 5 shipped with), a queue with no worker
 * draining it, `APP_DEBUG=true` leaking stack traces to customers. A
 * broken database announces itself; these do not.
 *
 * Exit code 1 if any check FAILs, so it can gate a deploy. Warnings do
 * not fail the run — they are judgement calls the operator may have made
 * deliberately.
 */
class PreflightCommand extends Command
{
    protected $signature = 'waqar:preflight {--skip-queue : Do not round-trip a job through the queue}';

    protected $description = 'Verify this deployment is ready to take real orders';

    /** @var list<array{status: string, group: string, label: string, detail: string}> */
    private array $results = [];

    public function handle(): int
    {
        $this->components->info('WAQAR preflight — checking this deployment against the Section 04 flow');

        $this->checkEnvironment();
        $this->checkConnectivity();
        $this->checkSchema();
        $this->checkAccessControl();
        $this->checkOperationalData();
        $this->checkMail();

        if (! $this->option('skip-queue')) {
            $this->checkQueue();
        }

        return $this->report();
    }

    private function checkEnvironment(): void
    {
        $group = 'Environment';

        $this->assert($group, 'APP_KEY is set', config('app.key') !== null && config('app.key') !== '',
            'Run `php artisan key:generate` — sessions and encrypted cookies depend on it.');

        $production = app()->environment('production');

        $this->assert($group, 'APP_DEBUG is off', ! config('app.debug') || ! $production,
            'Debug mode renders stack traces — including database credentials — to whoever triggers the error.');

        $this->warnIf($group, 'APP_ENV is production', ! $production,
            'Currently ['.app()->environment().']. Fine for a staging walk-through, not for launch.');

        $url = (string) config('app.url');
        $this->warnIf($group, 'APP_URL is not localhost', str_contains($url, 'localhost') || str_contains($url, '127.0.0.1'),
            "Currently [{$url}]. Media URLs and mailed links are absolute against this value.");

        $this->assert($group, 'Storage symlink exists', is_link(public_path('storage')) || is_dir(public_path('storage')),
            'Run `php artisan storage:link` — product images resolve through it.');
    }

    private function checkConnectivity(): void
    {
        $group = 'Connectivity';

        $this->attempt($group, 'Database reachable', function () {
            DB::connection()->getPdo();

            return DB::connection()->getDatabaseName();
        });

        $this->attempt($group, 'Redis reachable', function () {
            Redis::connection()->ping();

            return config('database.redis.default.host');
        });

        $this->attempt($group, 'Media disk writable', function () {
            $disk = config('media-library.disk_name');
            $probe = '.preflight-'.uniqid();
            Storage::disk($disk)->put($probe, 'ok');
            Storage::disk($disk)->delete($probe);

            return "disk [{$disk}]";
        });
    }

    private function checkSchema(): void
    {
        $group = 'Schema';

        $pending = [];

        try {
            $ran = DB::table('migrations')->pluck('migration')->all();
            foreach (glob(database_path('migrations/*.php')) ?: [] as $file) {
                $name = basename($file, '.php');
                if (! in_array($name, $ran, true)) {
                    $pending[] = $name;
                }
            }
        } catch (Throwable $e) {
            $this->recordFailure($group, 'Migrations table readable', $e->getMessage());

            return;
        }

        $this->assert($group, 'No pending migrations', $pending === [],
            count($pending).' pending — run `php artisan migrate --force`.');

        // The audit trail is the one table whose absence is invisible:
        // nothing errors, entries simply never appear.
        $this->assert($group, 'Activity log table present', Schema::hasTable(config('activitylog.table_name')),
            'Phase 7 wired the audit trail to ten models; without this table nothing is recorded.');
    }

    private function checkAccessControl(): void
    {
        $group = 'Access control';

        $this->assert($group, 'Roles seeded', Role::where('guard_name', 'employee')->count() >= 8,
            'Expected the 9 base roles (Section 15) plus Store Orders — run `php artisan db:seed --class=RoleSeeder`.');

        $this->assert($group, 'Permissions seeded', Permission::where('guard_name', 'employee')->count() >= 30,
            'Run `php artisan db:seed --class=PermissionSeeder` — every admin route is permission-gated.');

        $superAdmins = Employee::role('Super Admin')->where('is_active', true)->count();
        $this->assert($group, 'An active Super Admin exists', $superAdmins > 0,
            'Nobody can administer the system, including granting anyone else access.');
    }

    private function checkOperationalData(): void
    {
        $group = 'Operational data';

        // The one that silently breaks the whole storefront: with no rate
        // configured, ShippingRateResolver finds nothing and checkout
        // refuses every order. Phase 5 shipped in exactly this state.
        $this->assert($group, 'At least one shipping rate configured', ShippingRate::where('is_active', true)->exists(),
            'Checkout refuses EVERY order without one — see /admin/delivery/shipping-rates.');

        $this->assert($group, 'At least one active warehouse', Warehouse::where('is_active', true)->exists(),
            'Stock is held per warehouse; orders cannot reserve against none.');

        $this->warnIf($group, 'At least one active treasury', ! Treasury::where('is_active', true)->exists(),
            'Accounting cannot record COD collection until a treasury exists (Section 09).');
    }

    private function checkMail(): void
    {
        $group = 'Mail';

        $mailer = (string) config('mail.default');

        $this->assert($group, 'Mail transport is not the log driver', $mailer !== 'log' || ! app()->environment('production'),
            'Order confirmations would be written to the log instead of sent.');

        $from = (string) config('mail.from.address');
        $this->warnIf($group, 'From-address is not the scaffold default',
            $from === 'hello@example.com' || $from === '',
            "Currently [{$from}] — set MAIL_FROM_ADDRESS to the business's own address.");
    }

    private function checkQueue(): void
    {
        $group = 'Queue';

        // Notifications became ShouldQueue in Phase 8, so a stack with no
        // worker draining Redis does not error — it just silently stops
        // sending order confirmations and stops writing the database-channel
        // rows the customer's Notifications tab reads. Round-trip a real
        // job rather than guessing from a process list.
        $this->attempt($group, 'A worker is draining the queue', function () {
            $key = 'waqar:preflight:'.uniqid();
            dispatch(function () use ($key) {
                cache()->put($key, 'drained', 120);
            });

            foreach (range(1, 30) as $ignored) {
                if (cache()->get($key) === 'drained') {
                    cache()->forget($key);

                    return 'job round-tripped';
                }
                usleep(500_000);
            }

            throw new \RuntimeException('no worker picked the job up within 15s — check supervisord');
        });

        $this->warnIf($group, 'No failed jobs queued up',
            Schema::hasTable('failed_jobs') && DB::table('failed_jobs')->count() > 0,
            Schema::hasTable('failed_jobs')
                ? DB::table('failed_jobs')->count().' failed job(s) — inspect with `php artisan queue:failed`.'
                : '');
    }

    private function assert(string $group, string $label, bool $passed, string $detail = ''): void
    {
        $this->results[] = ['status' => $passed ? 'pass' : 'fail', 'group' => $group, 'label' => $label, 'detail' => $passed ? '' : $detail];
    }

    private function warnIf(string $group, string $label, bool $problem, string $detail = ''): void
    {
        $this->results[] = ['status' => $problem ? 'warn' : 'pass', 'group' => $group, 'label' => $label, 'detail' => $problem ? $detail : ''];
    }

    private function recordFailure(string $group, string $label, string $detail): void
    {
        $this->results[] = ['status' => 'fail', 'group' => $group, 'label' => $label, 'detail' => $detail];
    }

    private function attempt(string $group, string $label, callable $probe): void
    {
        try {
            $detail = (string) $probe();
            $this->results[] = ['status' => 'pass', 'group' => $group, 'label' => $label, 'detail' => $detail];
        } catch (Throwable $e) {
            $this->recordFailure($group, $label, $e->getMessage());
        }
    }

    private function report(): int
    {
        $current = null;

        foreach ($this->results as $result) {
            if ($result['group'] !== $current) {
                $current = $result['group'];
                $this->newLine();
                $this->line("<fg=gray>{$current}</>");
            }

            $icon = match ($result['status']) {
                'pass' => '<fg=green>  ✓</>',
                'warn' => '<fg=yellow>  !</>',
                default => '<fg=red>  ✗</>',
            };

            $this->line("{$icon} {$result['label']}".($result['detail'] !== '' ? " <fg=gray>— {$result['detail']}</>" : ''));
        }

        $failed = count(array_filter($this->results, fn ($r) => $r['status'] === 'fail'));
        $warned = count(array_filter($this->results, fn ($r) => $r['status'] === 'warn'));

        $this->newLine();

        if ($failed > 0) {
            $this->components->error("{$failed} check(s) failed — this deployment is not ready to take orders.");

            return self::FAILURE;
        }

        $warned > 0
            ? $this->components->warn("All checks passed, with {$warned} warning(s) to review before launch.")
            : $this->components->success('All checks passed — ready to take orders.');

        return self::SUCCESS;
    }
}
