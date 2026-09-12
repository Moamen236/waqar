# Deployment & Cutover — WAQAR

How to stand this system up, and the checklist to walk before it takes a real customer's money.

Most of this checklist is executable: **`php artisan waqar:preflight`** runs the machine-checkable half
and exits non-zero if the deployment could not take an order. Run it after every deploy, not just at
launch. The items it cannot check — a real test purchase, DNS, backups — are listed at the end.

---

## 1. The stack

Five services plus a mail sink, all defined in `docker-compose.yml`:

| Service | Image / build | Host port | Purpose |
|---|---|---|---|
| `app` | `docker/Dockerfile` (php:8.3-fpm-alpine) | — | php-fpm, **2 queue workers, scheduler** (supervisord) |
| `nginx` | nginx:1.27-alpine | **26991** | Storefront `/` and admin `/admin` |
| `mysql` | mysql:8.0 | — | Internal only |
| `redis` | redis:7-alpine | — | Cache, queue, session |
| `phpmyadmin` | phpmyadmin/phpmyadmin | **32231** | Database access |
| `mailpit` | axllent/mailpit | **32232** | **Development only** — see §4 |

```bash
docker compose up -d --build
docker compose exec app php artisan key:generate      # first boot only
docker compose exec app php artisan migrate --force
docker compose exec app php artisan db:seed --force   # roles, permissions, geography
docker compose exec app php artisan storage:link
docker compose exec app npm ci && docker compose exec app npm run build
docker compose exec app php artisan waqar:preflight
```

`mysql` and `redis` carry healthchecks and `app` waits on them, so the first-boot race that used to make
`migrate` fail with "Connection refused" for ~90 seconds is gone — `up -d` now blocks until the database
is genuinely ready.

---

## 2. Queue workers and the scheduler

`docker/supervisor/supervisord.conf` runs three programs inside the `app` container: php-fpm, **two**
`queue:work redis` workers, and `schedule:work`.

This is load-bearing, not decoration. **Customer notifications are `ShouldQueue` as of Phase 8**, on both
the mail *and database* channels — so with no worker draining Redis, order confirmations are never sent
*and* the customer's account Notifications tab stays empty, with nothing logged as an error. Preflight
round-trips a real job through the queue rather than checking a process list, precisely because that
failure is silent.

```bash
docker compose exec app supervisorctl status     # what is running
docker compose logs -f app                       # worker output
docker compose exec app php artisan queue:failed # anything that gave up
docker compose exec app php artisan queue:retry <id>
```

Scheduled tasks live in `routes/console.php`, not a host crontab:

| Task | When | Why |
|---|---|---|
| `activitylog:clean` | daily 03:10 | Enforces the 365-day audit retention Phase 7 configured |
| `queue:prune-failed --hours=168` | daily 03:40 | Keeps a week of failures for inspection, then clears |

**After deploying new code, restart the workers** — a long-lived worker holds the old code in memory:

```bash
docker compose exec app php artisan queue:restart
```

---

## 3. Environment

Copy `.env.example` to `.env` and change at minimum:

```ini
APP_ENV=production
APP_DEBUG=false          # preflight FAILS if this is true in production
APP_URL=https://your-real-domain            # media URLs and mailed links are absolute against this
APP_KEY=                 # php artisan key:generate

DB_PASSWORD=             # not the compose default
DB_ROOT_PASSWORD=
```

`APP_DEBUG=true` in production renders stack traces — including database credentials — to whoever
triggers an error. Preflight treats it as a hard failure for that reason.

For production also run the framework caches (and re-run them on every deploy):

```bash
docker compose exec app php artisan config:cache route:cache view:cache
```

---

## 4. Mail

SMTP against the business's own mail server — no third-party transactional API, per Section 23's
explicitly-excluded list. Set:

```ini
MAIL_MAILER=smtp
MAIL_HOST=mail.yourdomain.com
MAIL_PORT=587
MAIL_SCHEME=tls                  # or `smtps` on 465
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS=orders@yourdomain.com     # preflight warns while this is the scaffold default
MAIL_SUPPORT_ADDRESS=support@yourdomain.com
```

**Remove the `mailpit` service from `docker-compose.yml` for production**, or simply leave it unreferenced
— it exists so that development exercises the real SMTP transport instead of `MAIL_MAILER=log`, which is
how an SMTP misconfiguration otherwise stays hidden until launch night. Its web UI is on **:32232**.

---

## 5. Storage

Media goes to local disk via Spatie Media Library (`MEDIA_DISK=public`), served through the
`public/storage` symlink. No S3, no CDN.

- `php artisan storage:link` must have been run — preflight checks it.
- `storage/` and `bootstrap/cache/` must stay writable by the container's `www-data` (uid 82).
- **Back up `storage/app/public` alongside the database.** Product images live only there; a database
  restore without it leaves every product imageless.
- **`migrate:fresh` does not clear media from disk.** The database is wiped, so media IDs restart at 1
  and the seeder writes into directories left behind by the previous run. On a development box where
  those directories were created by a different uid (the host user, via one of the `--user root` +
  `chown` cycles `CLAUDE.md` describes) the write fails with a bare
  `Disk named \`public\` cannot be accessed`, which reads like a misconfigured filesystem rather than a
  permissions collision. Clear it explicitly when re-seeding from scratch:

  ```bash
  docker run --rm -v "$(pwd)":/var/www/html --user root waqar-app:latest \
    sh -c 'rm -rf /var/www/html/storage/app/public/[0-9]*'
  docker compose exec app php artisan migrate:fresh --seed --force
  ```

  A genuinely fresh deployment starts with an empty directory and is unaffected.

---

## 6. Cutover checklist

### Automated — `php artisan waqar:preflight`

Covers: `APP_KEY` set · `APP_DEBUG` off · `APP_URL` not localhost · storage symlink · database, Redis and
media disk reachable · no pending migrations · activity-log table present · roles and permissions seeded ·
an active Super Admin exists · **at least one active shipping rate** · at least one active warehouse · a
treasury exists · mail transport is not `log` · from-address changed · **a worker is actually draining the
queue** · no failed jobs.

The shipping-rate check earns its place: with no rate configured, `ShippingRateResolver` finds nothing and
checkout refuses **every** order, while the whole system otherwise looks perfectly healthy. Phase 5
shipped in exactly that state.

### Manual — walk Section 04's flow once, on the real deployment

Automated coverage of this exact path lives in `tests/Feature/Phase8CutoverTest.php`; this is the
human confirmation against real infrastructure.

- [ ] **Browse** the storefront, in both `/ar` and `/en`. Arabic renders as Arabic, not empty boxes.
- [ ] **Add to cart** and confirm the shipping cost appears once a Governorate → City → Area is chosen.
- [ ] **Check out** as a guest, COD. No card fields anywhere.
- [ ] Order confirmation email **arrives** (check the real inbox, not the log).
- [ ] Stock is **reserved, not deducted** — `/admin/inventory` shows reserved go up, on-hand unchanged.
- [ ] **Checking** confirms the order.
- [ ] **Delivery Manager** assigns it to a representative or shipping company.
- [ ] **Accounting** confirms Delivered, recording cash into a treasury.
- [ ] On-hand stock has now **dropped**, the reservation is released, payment reads Collected, and the
      treasury balance has increased by the order total.
- [ ] The customer's account shows the order and a notification.
- [ ] `/admin/activity-log` shows the above, **attributed to the employees who did it**.
- [ ] Post-delivery: request a return, approve, receive (stock restocked), refund.

### Not checked by anything above

- [ ] DNS and TLS terminate correctly on the real domain.
- [ ] Database backups are scheduled **and a restore has been tested**.
- [ ] `storage/app/public` is included in those backups.
- [ ] Someone other than the deployer has the Super Admin credentials.
- [ ] Log rotation is configured for `storage/logs`.

---

## 7. Continuous integration

`.github/workflows/ci.yml` runs three jobs: static analysis and style (Pint, Larastan, tsc, ESLint,
Prettier, translation catalogs), the Pest suite against real MySQL and Redis, and an **admin render
check**.

That last job exists because twice this project has shipped a systemically broken admin UI past every
other gate — Phase 6 rendered Arabic as tofu boxes, Phase 7 rendered twelve screens blank — and neither
is visible to a test suite, a typechecker or a linter. `tools/render-check.js` drives a headless Chromium,
signs in, visits every admin route from `php artisan waqar:admin-routes`, and fails on a page that renders
nothing, throws, or shows an untranslated key.

To run it by hand:

```bash
docker run -d --name chromium --network waqar_waqar -p 9222:9222 \
  zenika/alpine-chrome:latest --headless --no-sandbox --disable-gpu \
  --remote-debugging-port=9222 --remote-debugging-address=0.0.0.0 \
  --remote-allow-origins='*' --host-resolver-rules="MAP localhost:26991 nginx:80"

docker compose exec app php artisan waqar:admin-routes > routes.txt
node tools/render-check.js --cdp http://127.0.0.1:9222 \
  --email admin@waqar.test --password <password> --routes routes.txt
```

`--cdp` must address Chromium by **IP or localhost**, never by container name — Chromium refuses CDP
requests whose Host header is a hostname, and Node's `fetch` will not let that header be overridden.

---

## 8. Rollback

```bash
git checkout <previous-tag>
docker compose up -d --build
docker compose exec app php artisan migrate --force      # only if the new version added migrations
docker compose exec app php artisan queue:restart
docker compose exec app php artisan waqar:preflight
```

Migrations are **not** automatically reversed; check `php artisan migrate:status` and roll back
deliberately if the failed deploy added a destructive change. The seven soft-deleted models (products,
categories, orders, customers, employees, returns, treasuries) keep their rows, so a bad delete is
recoverable — `withTrashed()->restore()` — rather than needing a database restore.
