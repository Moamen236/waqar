# Phase 0 Handover — Environment & Tooling

**Status: Complete.** Verified end-to-end: the full Docker stack boots, both the storefront and admin Inertia bundles render through nginx with their own CSS framework, migrations run against the dockerized MySQL, Redis-backed cache works, and every tooling command (Pint, Larastan, Pest, ESLint, Prettier, tsc) runs clean on the fresh scaffold.

Reference: `WAQAR-DELIVERY-ROADMAP.html` Phase 0 / `PROJECT-SYSTEM-DOCUMENTATION.html` Section 25.

## What's running

```bash
docker compose up -d --build
```

| Service | Image | Verified |
|---|---|---|
| `app` | `docker/Dockerfile` — PHP 8.3.33-FPM (Alpine) + Node 24 + npm 11 + Composer 2.10 | `php artisan --version` → Laravel 12.69.2 |
| `nginx` | `nginx:1.27-alpine`, port **26991** | `GET /` and `GET /admin` both → HTTP 200 |
| `mysql` | `mysql:8.0` | `php artisan migrate` ran clean (9 migrations) |
| `redis` | `redis:7-alpine` | `Cache::put/get` round-trip verified (phpredis, ext-redis compiled into the image — no `predis/predis` package needed) |
| `phpmyadmin` | port **32231** | HTTP 200 |

## What was built

**Laravel 12** scaffolded via `composer create-project` (into a temp dir inside the `app` container, then moved into the repo root — the target directory wasn't empty, since the docs/templates already live there).

**Dual Inertia frontend** — one Laravel backend, two completely separate bundles, per Section 23's "two CSS frameworks, one per area, never on the same page" decision:

```
resources/
  css/
    storefront.css   → @import "tailwindcss";
    admin.css        → @import "bootstrap/dist/css/bootstrap.min.css";
  js/
    storefront/app.tsx + Pages/Welcome.tsx     (placeholder)
    admin/app.tsx     + Pages/Dashboard.tsx    (placeholder, uses react-bootstrap)
  views/
    storefront.blade.php   (loads storefront.css + storefront/app.tsx)
    admin.blade.php        (loads admin.css + admin/app.tsx)
```

`app/Http/Middleware/HandleInertiaRequests.php` picks the root view per-request (`admin` if the URL has an `admin` segment, else `storefront`) — written so it keeps working once Phase 6 adds the `{locale}` prefix in front (`/ar/admin/…` still matches).

`vite.config.ts` builds both entry pairs from one config; verified they land in **separate** compiled bundles:

```
public/build/assets/storefront-*.css   (Tailwind, ~62KB)
public/build/assets/admin-*.css        (Bootstrap, ~232KB)
```

`routes/web.php` has exactly two placeholder routes (`/` and `/admin`) to prove this — **not real pages**. Real routing (the `{locale}` prefix, the full page set, the admin route list) is Phase 4–6 work.

**Backend packages installed & configured:** `inertiajs/inertia-laravel`, `spatie/laravel-permission`, `spatie/laravel-translatable`, `spatie/laravel-medialibrary`, `spatie/laravel-activitylog`, `laravel/sanctum`, `doctrine/dbal`. Configs and migrations published (`config/permission.php`, `config/media-library.php`, `config/activitylog.php`, `config/sanctum.php`) and migrated successfully.

**Confirmed *not* installed**, per the spec's own decisions: `laravel/scout` (Section 23 — no Scout, search is plain MySQL query scopes, Phase 3) and no SCSS build tooling (full Tailwind port, no Anvogue SCSS carried over).

**Dev tooling, all verified passing on the fresh scaffold:**

| Tool | Command | Result |
|---|---|---|
| Pest (+ `pest-plugin-laravel`) | `./vendor/bin/pest` | 2/2 example tests pass |
| Pint | `./vendor/bin/pint --test` | clean (auto-fixed 6 import-order nits from the vendor publishes) |
| Larastan | `./vendor/bin/phpstan analyse` | 0 errors at level 5 (`phpstan.neon` — raise the level as the Actions/Services layer fills in) |
| ESLint 9 (flat config) + typescript-eslint + `eslint-plugin-react(-hooks)` | `npm run lint` | clean |
| Prettier | `npm run format:check` | clean |
| TypeScript | `tsc --noEmit` | clean |
| `npm run build` | — | builds both bundles, ~15s |

`laramint/laravel-brain` is installed (dev-only) for the architecture graph Phase 7 uses as a sign-off gate.

## Version pins worth knowing about

A few packages had just released breaking majors ahead of what the rest of the ecosystem supports as of this build — pinned down rather than silently left on latest:

- **TypeScript** → `^5.7` (not the new `7.x` major — `typescript-eslint@8.70` doesn't support it yet)
- **ESLint** → `^9` (not `10.x` — `eslint-plugin-react@7.37` doesn't support it yet)
- **`@vitejs/plugin-react`** → `^4` (not `6.x`, which needs Vite 8; we're on Vite 7 per `laravel-vite-plugin@2`)

Worth revisiting each once the rest of the toolchain catches up.

## Operational notes for whoever continues from here

- **File ownership:** one-off `composer`/`npm`/`artisan` commands that need to write into the bind-mounted repo (installing a package, publishing a vendor file, generating a migration) were run as `--user root` inside the container to avoid permission errors, e.g.:
  ```bash
  docker compose run --rm --user root app composer require <package>
  ```
  This leaves new/changed files **root-owned on the host**. Re-run after each such batch:
  ```bash
  docker run --rm -v "$(pwd)":/var/www/html --user root waqar-app:latest chown -R 1000:1000 /var/www/html
  ```
  (`1000` is this host's `moon` user — adjust if a different machine picks this up.) `storage/` and `bootstrap/cache/` need to stay `chmod -R 777` for the container's `www-data` (uid `82`, different from the host user) to write logs/cache — already set, and the Dockerfile does this at build time too.
- **MySQL's first boot is slow** — its own first-time data directory initialization took ~90s in this environment before it would accept connections. If migrations fail with "Connection refused" right after `docker compose up -d`, that's why; wait and retry rather than assuming something's broken.
- `.env` is real (git-ignored) and already points at the Docker services; `.env.example` mirrors it (no secrets) so a fresh clone's `cp .env.example .env && php artisan key:generate` works immediately against this same compose stack.

## What Phase 1 needs to know

Next up is **Phase 1 — Core Schema Foundation** (`WAQAR-DELIVERY-ROADMAP.html`): `customers`, `employees`, `roles`/`permissions` (Spatie already installed and migrated — just needs the 9 base roles seeded, Section 15), `addresses`, `categories`, `products`, `product_variants`, `attributes`/`attribute_values`, `media` (package already installed), and the four base geo tables (`countries`/`governorates`/`cities`/`areas`). Full field-level definitions: `PROJECT-SYSTEM-DOCUMENTATION.html` Section 24.

Nothing in Phase 0 touched application tables — `php artisan migrate` so far has only run the framework's own defaults plus the three Spatie packages' tables.
