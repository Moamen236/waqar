<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled tasks
|--------------------------------------------------------------------------
|
| Run by the `scheduler` program in docker/supervisor/supervisord.conf
| (`artisan schedule:work`), not by a host crontab — keeping the schedule
| here means it is in the repo, reviewable, and testable, rather than
| split between the code and a crontab nobody reads.
|
*/

// Phase 7 configured a 365-day retention for the audit trail
// (config/activitylog.php) but nothing enforced it — the retention was a
// number in a config file until there was a scheduler to act on it.
Schedule::command('activitylog:clean')
    ->daily()
    ->at('03:10')
    ->onOneServer()
    ->description('Trim activity-log entries past their retention window');

// Failed jobs are kept for a week so someone can actually look at why,
// then pruned. Deliberately *not* `queue:retry all` on a schedule: a job
// that failed for a permanent reason would retry every hour forever, and
// for a partially-delivered notification that means mailing the customer
// again on each pass. Retrying is a decision a human makes after reading
// the failure — `php artisan queue:retry <id>`.
Schedule::command('queue:prune-failed --hours=168')
    ->daily()
    ->at('03:40')
    ->onOneServer()
    ->description('Prune failed jobs older than a week');

// Staff notifications are work-queue signals, not records — Checking
// alone gets one per order placed, so the table grows with order volume
// forever if nothing trims it. Only *read* rows are removed: an unread
// notification is still someone's outstanding work no matter how old,
// and silently deleting it would be the one failure mode worse than an
// oversized table. The audit trail keeps the durable history (Phase 7).
Schedule::call(function () {
    DB::table('notifications')
        ->whereNotNull('read_at')
        ->where('read_at', '<', now()->subDays(30))
        ->delete();
})
    ->name('notifications:prune')
    ->daily()
    ->at('03:25')
    // name() must come first on a closure event — onOneServer needs
    // something to key the lock on, and a callback has no command
    // string to derive one from.
    ->onOneServer()
    ->description('Delete notifications read more than 30 days ago');
