# Phase 8 Handover — Infrastructure & Launch

**Status: Complete.** Verified with 130 Pest tests (792 assertions — the full Phase 1–7 suite re-run
alongside this phase's 7 new ones), Pint (272 files), Larastan (level 5, 150 files), tsc, ESLint,
Prettier, `tools/check-translations.py` (0 missing across four catalogs), a production build, a
**from-scratch `docker compose down -v && up -d --build`**, `waqar:preflight` against that fresh stack,
and a 46/46 authenticated admin render check.

Reference: `WAQAR-DELIVERY-ROADMAP.html` Phase 8 / `PROJECT-SYSTEM-DOCUMENTATION.html` Sections 04 and 23.

Operational detail lives in [`DEPLOYMENT.md`](DEPLOYMENT.md) — this document is what changed and why.

## The roadmap's own completion bar

> Done when — `docker compose up -d --build` serves the storefront at `http://localhost:26991` and
> phpMyAdmin at `http://localhost:32231`, and a real order placed reaches Delivered with stock, payment,
> and treasury all correctly reflected.

Both halves were walked for real. The stack was torn down **with its volumes** (`down -v`) and rebuilt
from nothing; the order path is `Phase8CutoverTest.php`'s first test, which starts at the *storefront* —
Section 04's flow begins at "Customer — Website", whereas Phase 4's lifecycle test starts at
`/admin/orders/create` and therefore never exercised cart, COD checkout or guest identity.

It asserts the three things the bar names, at the point each is supposed to change:

| After | Stock | Payment | Treasury |
|---|---|---|---|
| Checkout | reserved 2, on-hand **10** | — | — |
| Checking confirms | reserved 2, on-hand **10** | — | — |
| Accounting confirms Delivered | reserved 0, on-hand **8** | Collected | +540.00 |

The middle row is the one worth having a test for: confirmation is the step people assume deducts stock,
and deliberately does not (Section 07).

## Queue workers — the thing Phase 5 wrote an IOU against

`OrderPlacedNotification` has carried this comment since Phase 5:

> Deliberately *not* ShouldQueue yet: the compose stack has no Supervisor-managed queue worker until
> Phase 8 … Adding `implements ShouldQueue` to this class and its siblings is the one-line change to
> make at that point.

That is now paid. `docker/supervisor/supervisord.conf` runs **php-fpm, two `queue:work redis` workers and
`schedule:work`** in the `app` container, and all three notifications are queued.

Two things about it that were not one-line changes:

**The container stays unprivileged.** The obvious supervisord config runs as root and drops each program
to `www-data`. Doing that would have quietly broken the documented dev workflow — `USER www-data` is why
`docker compose run --rm app ...` produces host-owned files, and `CLAUDE.md`'s whole file-ownership note
exists because the container is uid 82. supervisord runs fine unprivileged, so it does, and no program
declares a `user=`.

**The locale is captured at construction, not read when the job runs.** Queueing changes *when* code
executes: `app()->getLocale()` inside `toArray()` now resolves in a worker that has no request to inherit
a locale from. Every route lives under `/{locale}/…` (Q20), so without this a customer shopping in
English would be mailed in Arabic by whichever worker picked the job up. Each notification sets
`$this->locale` in its constructor and Laravel wraps delivery in `withLocale()`. Verified by dispatching
under `en` on an Arabic-default stack and reading an English subject with an `/en/` URL out of the mail
catcher.

## The scheduler, and one thing deliberately not scheduled

`routes/console.php` now carries the schedule, run by supervisord's `schedule:work` rather than a host
crontab — so it is in the repo, reviewable and testable.

- **`activitylog:clean`, daily.** Phase 7 configured a 365-day retention in `config/activitylog.php` and
  nothing enforced it; it was a number in a config file until there was a scheduler.
- **`queue:prune-failed --hours=168`, daily.** A week to inspect a failure, then cleared.

**`queue:retry all` is deliberately absent**, and there is a test asserting it stays absent. On a
schedule it would retry a permanently-failing job every hour forever — and for a notification that
partially delivered, that means mailing the customer again on each pass. Retrying is a decision someone
makes after reading the failure.

## SMTP that is actually exercised before launch

The roadmap asks for "SMTP mail transport (business's own credentials, no third-party transactional
API)". Configuring `MAIL_MAILER=smtp` in `.env.example` satisfies that on paper while leaving the SMTP
path **never once executed** in development, because local runs used `MAIL_MAILER=log`.

So the compose stack gained a `mailpit` service (dev only, web UI on **:32232**). Local mail now travels
over real SMTP to a catcher. Production changes the `MAIL_*` values and nothing else. Verified end to
end: a queued notification → worker → SMTP → a message in the catcher with the Arabic subject
`استلمنا طلبك رقم 2001`.

## `waqar:preflight` — the cutover checklist as a command

The roadmap asks for "a cutover checklist walked against the Final Recommended System Flow end to end".
A markdown checklist gets walked once, by hand, on launch night. This is the same list as
`php artisan waqar:preflight`, exit code 1 on failure, so it can run after every deploy and in CI.

It deliberately targets what fails *silently*:

- **At least one active shipping rate.** With none, `ShippingRateResolver` matches nothing and checkout
  refuses **every** order while the system looks entirely healthy — the exact state Phase 5 shipped in.
- **A worker is genuinely draining the queue** — round-tripped as a real job, not inferred from a process
  list, because queued notifications fail silently in both directions (no mail, and no database row for
  the account's Notifications tab).
- `APP_DEBUG` on in production, an unset `APP_KEY`, a missing storage symlink, pending migrations, an
  unseeded permission table, no Super Admin, mail still on the `log` driver.

Three tests cover it, including two that assert it *fails* — no shipping rate, and no Super Admin.

## Two real infrastructure faults found by testing the stack rather than the code

Both were pre-existing, and neither is visible from the application side.

**1. Any app redeploy took the site down until nginx was restarted.** nginx resolves a literal upstream
hostname *once, at startup*. Recreating the `app` container gives it a new IP, and nginx kept sending
FastCGI to the old one — every page 502 until someone restarted nginx. I hit this mid-phase and initially
read it as a broken build. `docker/nginx/default.conf` now resolves per-request through Docker's embedded
DNS (`resolver 127.0.0.11` + a `set` variable). Verified by force-recreating `app` and confirming the
storefront stayed at 200 with nginx untouched.

This is the same family as the stale-bind-mount symptom `PHASE-3-HANDOVER.md` records — a stack-level
fault that presents as an application bug.

**2. The first-boot database race is gone.** `mysql` and `redis` now carry healthchecks and `app` waits
on `service_healthy`. MySQL's first boot on a fresh volume takes ~90 seconds, and `migrate` run in that
window failed with "Connection refused" — documented in `CLAUDE.md` as something to just know. The
from-scratch rebuild now blocks for ~2m35s and then works, instead of failing fast and looking broken.

## CI — including the gate Phase 7 asked for by name

`.github/workflows/ci.yml`, three jobs:

| Job | Gates |
|---|---|
| Static analysis & style | Pint, Larastan, tsc, ESLint, Prettier, `check-translations.py` |
| Pest | full suite against real MySQL + Redis services |
| **Admin render check** | boots the real compose stack, runs preflight, renders **every** admin page |

The third exists because **twice** a systemically broken admin UI has passed every other gate this
project has: Phase 6 rendered Arabic as tofu boxes, Phase 7 rendered twelve screens blank. Neither is
visible to a test suite, a typechecker or a linter.

`tools/render-check.js` is now in the repo rather than a scratch directory. It drives Chromium over CDP,
**signs in through the real login form**, walks every URL emitted by `php artisan waqar:admin-routes`, and
fails a page that renders nothing, throws, or shows an untranslated key. No npm dependencies — Node 22+
ships a global `WebSocket`, which is all CDP needs beyond `fetch`.

Three things learned building it, all now in its header comment:

- **`--cdp` must address Chromium by IP or `localhost`.** Chromium refuses CDP requests whose Host header
  is a hostname, and Node's `fetch` forbids overriding that header, so `--cdp http://chromium:9222` cannot
  work. CI publishes the port and uses `127.0.0.1`.
- **Poll for painted content; never sleep a guessed interval.** My first version waited a fixed 1.2s after
  load and reported a different set of "blank" pages on each run. A flaky gate is worse than no gate —
  nobody trusts one that cries wolf. It now polls until the body has text.
- **The untranslated-key heuristic has to match exact catalog prefixes.** A loose `word.word` pattern
  flagged `/admin/roles` on every run, because the permission matrix legitimately *displays* dotted
  permission names (`treasury.manage`, `orders.view`).

## What the render check caught on its first real run

Ten admin page titles still hard-coded in English — `New Product`, `Edit Employee`, `Permissions — …`,
`Reconciliation — …` and others. The same class of leak Phase 7 closed, in the one place neither
`check-translations.py` (they were string literals, not missing keys) nor a human skimming the UI in
Arabic would notice, because a browser tab title is easy to not look at. All localised; admin catalogs
377 → **432 keys**, still 0 missing.

That is the gate paying for itself on day one.

## An operational papercut worth knowing

**`migrate:fresh` wipes the database but not the media on disk.** Media IDs restart at 1 and the seeder
writes into directories the previous run left behind. On a development box where those directories were
created under a different uid — via one of the `--user root` + `chown` cycles `CLAUDE.md` prescribes —
the write fails with a bare ``Disk named `public` cannot be accessed``, which reads like a misconfigured
filesystem rather than a permissions collision. It cost real time to diagnose mid-phase.

`DEPLOYMENT.md` §5 has the one-line fix. A genuinely fresh deployment starts with an empty directory and
never sees it.

## Gate results

| Gate | Result |
|---|---|
| Pest | 130 passed, 792 assertions (123 → 130; +7 this phase) |
| Pint | 272 files, clean |
| Larastan (level 5) | 150 files, no errors |
| tsc `--noEmit` | clean |
| ESLint | 0 errors (3 pre-existing warnings in Phase 4 forms) |
| Prettier | clean |
| `tools/check-translations.py` | 0 missing across four catalogs (admin 432 keys) |
| `npm run build` | clean |
| `docker compose down -v && up -d --build` | healthy in ~2m35s, storefront 200 |
| `waqar:preflight` (fresh stack) | all checks pass, 4 expected local warnings |
| Admin render check | **46/46 pages**, 0 blank, 0 exceptions — twice, deterministic |

## What is left

The roadmap's nine phases are complete. What remains is genuinely outside it:

- **Deferred by the spec itself.** Dashboard widgets are Question 18's explicit follow-up, and reporting
  screens are named in the permission catalog (`reports.view`) without a UI behind them.
- ~~**Turn English on for staff** when the business wants it.~~ **Done (2026-09-13)** — an admin locale
  switcher now sits in the topbar and on the login page. This reverses Question 2's "Arabic-only for
  v1" decision; `PROJECT-SYSTEM-DOCUMENTATION.html` still records the original and should be updated to
  match, per the roadmap's own "specification document is the source of truth" rule.
- **Before real traffic:** walk `DEPLOYMENT.md` §6's manual list on the production host. Nothing in this
  repository can verify DNS, TLS, a tested backup restore, or that someone other than the deployer holds
  the Super Admin credentials.
