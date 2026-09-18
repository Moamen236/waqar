# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

WAQAR — a single-store fashion e-commerce platform (Laravel · Inertia.js · React · TypeScript) with a
customer storefront and an internal operations layer (checking, delivery, accounting, treasury) on the
same orders/products/inventory. Full spec: [`PROJECT-SYSTEM-DOCUMENTATION.html`](PROJECT-SYSTEM-DOCUMENTATION.html)
(Section 24 = database schema, Section 25 = the 9-phase build sequence, also mirrored in
[`WAQAR-DELIVERY-ROADMAP.html`](WAQAR-DELIVERY-ROADMAP.html)). Current build status and phase handover notes:
[`README.md`](README.md) and `PHASE-<n>-HANDOVER.md`.

**Two raw source documents** (`E-Commerce documentation.md`, `Internal Operations documentation.md`) fed
into that spec but contained conflicts and gaps. `PROJECT-SYSTEM-DOCUMENTATION.html` is the resolved,
authoritative version — if it disagrees with either raw doc, the spec wins.

## Everything runs in Docker — there is no local PHP or Node

This host has no PHP interpreter and no Linux-side Node install. Every `artisan`, `composer`, `npm`,
`pest`, `pint`, `phpstan`, or `eslint` command must run inside the `app` container, not directly:

```bash
docker compose up -d --build          # start/rebuild the full stack
docker compose run --rm app php artisan migrate
docker compose run --rm app php artisan tinker
docker compose run --rm app npm run build
docker compose run --rm app npm run dev
```

Services: `app` (PHP 8.3-FPM + Node, no host port), `nginx` (**:26991** — storefront `/`, admin `/admin`),
`mysql` (internal only — use `phpmyadmin` at **:32231** or `docker compose exec mysql mysql -u root -p`),
`redis` (internal only).

**Installing a package or publishing a vendor file** needs to write into the repo, which the container's
`www-data` user (uid 82) can't always do against files owned by the host user. Run those specific
commands as root, then chown back:

```bash
docker compose run --rm --user root app composer require <package>
docker compose run --rm --user root app npm install <package>
docker run --rm -v "$(pwd)":/var/www/html --user root waqar-app:latest chown -R 1000:1000 /var/www/html
```

`storage/` and `bootstrap/cache/` must stay `chmod -R 777` (already set) so `www-data` can write logs/cache
despite the uid mismatch with the host.

MySQL's first boot after a fresh volume takes ~90s to finish initializing — a "Connection refused" from
`migrate` run immediately after `docker compose up -d` usually just means it isn't ready yet, not that
something's broken.

## Commands

```bash
# Backend tests (Pest, not PHPUnit — see tests/Pest.php)
docker compose run --rm app ./vendor/bin/pest
docker compose run --rm app ./vendor/bin/pest --filter=<name>   # single test
docker compose run --rm app ./vendor/bin/pest tests/Feature/OrderTest.php   # single file

# Style / static analysis
docker compose run --rm app ./vendor/bin/pint            # auto-fix
docker compose run --rm app ./vendor/bin/pint --test     # check only
docker compose run --rm app ./vendor/bin/phpstan analyse # Larastan, phpstan.neon (level 5)

# Frontend
docker compose run --rm app npm run lint         # ESLint (flat config, eslint.config.js)
docker compose run --rm app npm run format       # Prettier — write
docker compose run --rm app npm run format:check
docker compose run --rm app ./node_modules/.bin/tsc --noEmit
```

## Architecture: two frontends, one backend, never mixed

The storefront and admin are **separate Inertia apps** sharing one Laravel backend — not one app with
an admin section. This was a deliberate decision (spec Section 23: "two CSS frameworks, one per area,
never on the same page") because the storefront is Tailwind (Anvogue template) and admin is Bootstrap
5.3.3 + react-bootstrap (Larkon template), and loading both frameworks' CSS on one page causes
reset/specificity conflicts.

```
resources/
  css/storefront.css          Tailwind entry — @import "tailwindcss";
  css/admin.css                Bootstrap entry — @import "bootstrap/dist/css/bootstrap.min.css";
  js/storefront/app.tsx        Inertia root for the storefront, Pages/ resolved relative to it
  js/storefront/Pages/*.tsx
  js/admin/app.tsx             Inertia root for admin, Pages/ resolved relative to it
  js/admin/Pages/*.tsx
  views/storefront.blade.php   loads storefront.css + storefront/app.tsx
  views/admin.blade.php        loads admin.css + admin/app.tsx
```

`app/Http/Middleware/HandleInertiaRequests::rootView()` picks which of the two root views to render
based on whether the URL has an `admin` segment — **not** a hardcoded property. When adding a new
route, it lands in the right bundle automatically as long as admin routes contain `/admin/` somewhere
in the path (which they all do — see Section 14's route list). `vite.config.ts` builds both entry pairs
from one config but Vite/Rollup keeps them as separate output chunks — confirm with
`npm run build` that a change to one area's code doesn't bleed into the other bundle's output hash.

A `{locale}` route prefix (`/ar/…`, `/en/…`, `/ar/admin/…`, `/en/admin/…`) is planned but not yet
built — that's Phase 6 (spec Question 20). The root views already read `app()->getLocale()` for
`<html lang dir>` so that phase only needs to add the middleware that sets it per-request, not touch
the views again.

## Backend structure convention (Actions/Services/Enums)

Not yet built out beyond `app/Models`, `app/Http/Controllers`, `app/Http/Middleware` — Phase 1+ work.
The convention to follow as it's built, from the E-Commerce source doc's recommended structure:

- **`app/Actions/<Domain>/`** — one class per business operation (`CreateOrderAction`,
  `ReserveStockAction`), the actual unit callers invoke. Domains: Cart, Checkout, Inventory, Orders,
  Payments, Returns, Shipping, Treasury, Warehouses.
- **`app/Services/<Domain>/`** — shared logic multiple Actions lean on (e.g. shipping-rate resolution
  used by both checkout and admin order-create).
- **`app/Enums/`** — status enums as real PHP enums, not magic strings: `OrderStatus`,
  `PaymentStatus`, `InventoryMovementType`, `ReturnStatus`, `TreasuryTransactionType`,
  `StockTransferStatus`. Match the values already fixed in spec Section 24's schema tables exactly.
- Controllers stay thin — they call an Action and return an Inertia response, no business logic in
  the controller itself. Split `Http/Controllers` into `Store/`, `Admin/`, `Api/` subdirectories to
  match the storefront/admin/future-mobile-API split above.
- Routes: the plan is `routes/store.php` (customer-facing) and `routes/admin.php` (internal ops),
  both loaded from `routes/web.php`, rather than one growing file. Not yet split — currently
  `routes/web.php` has two placeholder routes only (`/` and `/admin`, Phase 0).

**Two identity models, not one `users` table** (spec Section 15, Q19): `customers` (storefront login) and
`employees` (staff — Checking, Delivery Manager, Accounting, etc., the spec's 9 roles plus `Store Orders`,
via `spatie/laravel-permission`, already installed and migrated). Only `employees` get the `HasRoles` trait.

**Three roles are data-scoped, not just permission-gated** — `Order::scopeVisibleTo()` is the single
source of the rule and every order listing, guard, dashboard widget and export reads through it:
`Customer Service` sees only orders it created, `Customer Service Team Leader` its team's, `Store Orders`
only website-sourced orders (read-only). `OrderPolicy::viewAsEmployee()` repeats it per row,
`Employee::scopeVisibleTo()` mirrors it for staff records, and `OrderReturn::scopeVisibleTo()` inherits it
through the order. The same `orders.view` permission therefore means a different row set per role — this
supersedes spec Q16's "agents are unscoped" wording; see Section 15's build-amendment callout.

**Stock deducts on Accounting-confirmed "Delivered" only** — not at order creation, not at Checking
confirmation, not at shipment. Order creation *reserves* stock (Section 07); this is the single most
important business rule to not get wrong when writing the order/inventory Actions in Phase 3.

## Database

MySQL 8, via the `mysql` service (`DB_HOST=mysql` in `.env`, already configured). Full field-level
schema — every table, column, type, nullability, and FK — is spec Section 24, not re-derived here.
Money columns are `decimal(10,2)`; PKs are `bigint unsigned auto_increment`; translatable columns
(marked `*` in the spec, e.g. `products.name*`) are `spatie/laravel-translatable` JSON columns, not a
separate `translations` table.

`spatie/laravel-permission`, `spatie/laravel-translatable`, `spatie/laravel-medialibrary`,
`spatie/laravel-activitylog`, and `laravel/sanctum` are installed and migrated (Phase 0). No
`laravel/scout` (spec decision — search is plain MySQL query scopes, not a search-index package).

## graphify

This project has a knowledge graph at graphify-out/ with god nodes, community structure, and cross-file relationships.

Rules:
- For codebase questions, first run `graphify query "<question>"` when graphify-out/graph.json exists. Use `graphify path "<A>" "<B>"` for relationships and `graphify explain "<concept>"` for focused concepts. These return a scoped subgraph, usually much smaller than GRAPH_REPORT.md or raw grep output.
- If graphify-out/wiki/index.md exists, use it for broad navigation instead of raw source browsing.
- Read graphify-out/GRAPH_REPORT.md only for broad architecture review or when query/path/explain do not surface enough context.
- After modifying code, run `graphify update .` to keep the graph current (AST-only, no API cost).
