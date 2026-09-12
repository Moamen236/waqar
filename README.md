# WAQAR — Fashion E-Commerce Platform

A single-store fashion e-commerce platform (Laravel · Inertia.js · React · TypeScript · Tailwind CSS) with a full customer storefront and a complete internal operations layer underneath — order checking, delivery management, accounting, treasury, and employee management — on the same orders/products/inventory, without changing the customer's shopping experience.

## Documentation

Everything about *what* this system is and *how* it's built is recorded in two documents — update those first, this file only tracks *progress*:

- [`PROJECT-SYSTEM-DOCUMENTATION.html`](PROJECT-SYSTEM-DOCUMENTATION.html) — the full specification: unified customer + internal flow, product/inventory/order/shipping/returns model, roles & permissions, i18n, database schema (Section 24), and the 21 decisions made along the way (Section 22).
- [`WAQAR-DELIVERY-ROADMAP.html`](WAQAR-DELIVERY-ROADMAP.html) — the 9-phase build sequence (also mirrored as Section 25 of the spec above).

## Stack

| Layer | Technology |
|---|---|
| Backend | Laravel 12, PHP 8.3+ |
| Database | MySQL 8+ |
| Frontend runtime | React 19 + TypeScript via Inertia.js |
| Styling | Tailwind CSS v4 (storefront) · Bootstrap 5.3.3 + react-bootstrap (admin) — never both on one page |
| Auth / Authorization | Laravel Auth + spatie/laravel-permission (9 base roles) |
| Media | spatie/laravel-medialibrary |
| Audit log | spatie/laravel-activitylog |
| Cache / Queue / Session | Redis (phpredis extension, no predis package) |
| Translations | spatie/laravel-translatable (Arabic + English), URL-routed via `/ar/…` / `/en/…` (Phase 6) |
| Payment | Cash on Delivery only |
| Local environment | Docker Compose — `app` (PHP-FPM + Node), `nginx` (`:26991`), `mysql`, `redis`, `phpmyadmin` (`:32231`) |

## Getting started

```bash
docker compose up -d --build
```

Storefront: `http://localhost:26991` · Admin: `http://localhost:26991/admin` · phpMyAdmin: `http://localhost:32231`

`.env` already exists (git-ignored) and points at the Docker services; a fresh clone can regenerate it from `.env.example` the same way. One-off `artisan`/`composer`/`npm` commands run inside the `app` container:

```bash
docker compose run --rm app php artisan migrate
docker compose run --rm app npm run build
docker compose run --rm app ./vendor/bin/pest
```

The Pest suite runs against its own database (`waqar_testing`), created on the first boot of a fresh
`mysql` volume — it is **not** the development database, because `RefreshDatabase` drops every table it
touches. See `tests/TestCase.php` for why that override lives in PHP rather than in `phpunit.xml`.

If a command needs to write a *new* file into the repo (installing a package, publishing a vendor file, generating a migration), see the file-ownership note in the latest `PHASE-<n>-HANDOVER.md` — those were run as `--user root` and need a `chown` pass back to the host user afterward.

## Status

Sequencing follows the roadmap's 9 phases (`WAQAR-DELIVERY-ROADMAP.html`, or Section 25 of the spec). A `PHASE-<n>-HANDOVER.md` file is added as each phase completes, summarizing what landed and what the next phase needs to know.

| Phase | Status |
|---|---|
| 0 — Environment & Tooling | ✅ Complete — see [`PHASE-0-HANDOVER.md`](PHASE-0-HANDOVER.md) |
| 1 — Core Schema Foundation | ✅ Complete — see [`PHASE-1-HANDOVER.md`](PHASE-1-HANDOVER.md) |
| 2 — Schema Extensions | ✅ Complete — see [`PHASE-2-HANDOVER.md`](PHASE-2-HANDOVER.md) |
| 3 — Business Logic Layer | ✅ Complete — see [`PHASE-3-HANDOVER.md`](PHASE-3-HANDOVER.md) |
| 4 — Admin / Internal Operations Surface | ✅ Complete — see [`PHASE-4-HANDOVER.md`](PHASE-4-HANDOVER.md) |
| 5 — Storefront Rebuild | ✅ Complete — see [`PHASE-5-HANDOVER.md`](PHASE-5-HANDOVER.md) |
| 6 — i18n & RTL | Not started |
| 7 — QA & Hardening | Not started |
| 8 — Infrastructure & Launch | Not started |

### Log

- **2026-09-11** — Docker environment scaffolded (`docker-compose.yml`, `docker/Dockerfile`, `docker/nginx/default.conf`); this README added.
- **2026-09-11** — **Phase 0 complete.** Laravel 12 + Inertia scaffolded with a dual storefront (Tailwind)/admin (Bootstrap 5.3.3) frontend, each building to its own bundle; Redis service added to the compose stack; Spatie permission/translatable/medialibrary/activitylog + Sanctum installed and migrated; Pest/Pint/Larastan/ESLint/Prettier/laramint all wired and verified clean on the fresh scaffold. Full 5-container stack boots and serves both areas through nginx (`/` → storefront placeholder, `/admin` → admin placeholder). Details, version-pin notes, and ownership gotchas: [`PHASE-0-HANDOVER.md`](PHASE-0-HANDOVER.md).
- **2026-09-11** — `CLAUDE.md` added (per `.claude/skills/ecommerce-workflow/SKILL.md`'s Phase 1 guidance).
- **2026-09-11** — **Phase 1 complete.** 14 tables migrated (geography, customers, employees, addresses, categories, products/variants, attributes) with 12 matching Eloquent models; two auth guards (`customer`/`employee`, no shared `users` table, Q19); `employees.team_leader_id` hierarchy (Q16) wired end to end; 9 base roles + sample Egypt geo data seeded. Verified with a permanent Pest suite (product+variant+translatable-attributes creation, address resolving through all 4 geo levels, role seeding, team hierarchy) — 6/6 tests, Pint and Larastan both clean. Details, what got deliberately deferred to Phase 2, and why `/security-review` couldn't run: [`PHASE-1-HANDOVER.md`](PHASE-1-HANDOVER.md).
- **2026-09-11** — **Phase 2 complete.** 34 more tables migrated — the roadmap's original "extensions" list plus the base Orders/Payments/Returns/Inventory/Commerce/Treasury tables those extensions assumed already existed (they didn't; see the handover for why scope expanded). Full Promotions system (Q17), `order_number` sequencing from 1001, `collection_type`/`collected_method` on payments from the start, `districts` + the `district_id` follow-up on areas/addresses deferred from Phase 1. 11 Pest tests (35 assertions), Pint (140 files) and Larastan (47 files) both clean against the real dockerized MySQL. Two real bugs this phase's own tests caught and fixed (missing `deleted_at` on `orders`/`returns`; DB-default columns not reflected on a freshly created in-memory model) — details, and the now-twice-blocked `/security-review`: [`PHASE-2-HANDOVER.md`](PHASE-2-HANDOVER.md).
- **2026-09-11** — **Phase 3 complete.** The Actions/Services/Enums business-logic layer `CLAUDE.md` documents the convention for: `CreateOrderAction` (server-side pricing only, Advertisement-product stock-check bypass), the order status lifecycle Actions (confirm/cancel/assign/three delivery-result outcomes), `InventoryService` (the one place stock ever changes — reserve/release/deduct/restock, `lockForUpdate()` race-safe, deducts physical stock only on Accounting-confirmed Delivered), the full returns→refund workflow, `TreasuryService`, and a plain-MySQL `SearchProductsAction` (no Scout). 15 new status Enums. 20 Pest tests (67 assertions) including the spec's explicitly-named "Critical Pest suite" (overselling prevention, concurrent-purchase race, cancelled-order stock release, COD-collected treasury transaction, non-manipulable shipping price, cross-customer order access, Team Leader team-scoping, full return→refund, search) — all passing against the real dockerized MySQL, Pint (173 files) and Larastan (79 files) both clean. Root-caused and fixed a 40-error Larastan false-positive cascade (Laravel 11+'s `casts(): array` method isn't fully inferred by Larastan 3.12 without `@property`/relation-generic PHPDoc) rather than suppressing it. Details, the now three-times-blocked `/security-review`, and a stale-WSL2-bind-mount gotcha worth knowing about: [`PHASE-3-HANDOVER.md`](PHASE-3-HANDOVER.md).
- **2026-09-11** — **Phase 4 complete.** Employee login/logout on the `employee` guard; a 30-permission RBAC catalog (`PermissionSeeder`) with a Super-Admin `Gate::before` bypass and a react-hook-form permission-matrix editor; all 10 of Section 14's admin routes (Checking's work queue, Delivery's assignment board + representatives/shipping-companies CRUD, Accounting's delivery confirmation + shipping-company reconciliation) plus `/admin/orders/create` reusing `CreateOrderAction` directly, a real Customer create/edit form, and from-scratch Collections/Employees/Promotions/Treasury-ledger screens — none of which exist anywhere in the Larkon admin template. 6 new UI libraries wired (react-select, react-quill-new, react-dropzone, flatpickr, sweetalert2, react-apexcharts, simplebar-react) plus Ziggy for a real `route()` helper in TypeScript. Filled two real gaps in Phase 3's Actions found while building this (`PostponeOrderAction`, `MarkOrderBackorderAction`/`ResumeBackorderAction`). 29 Pest tests (103 assertions) — including a full order-lifecycle walkthrough through the actual HTTP routes matching the roadmap's own "Done when" bar — Pint (198 files) and Larastan (99 files) both clean, verified against the real dockerized MySQL and a live curl login session. The manual `/security-review` pass (still no git repo — see the handover) caught and fixed a real privilege-escalation gap: any role granted `employees.manage` could have self-assigned Super Admin. Details: [`PHASE-4-HANDOVER.md`](PHASE-4-HANDOVER.md).
- **2026-09-11** — **Phase 4 addendum: Products/Categories admin CRUD + a dedicated Returns/Refunds screen**, closing both gaps flagged in the Phase 4 handover, done the same day on request rather than deferred. `/admin/products` (translatable copy, variant table with attribute pickers, category/collection multi-select, `spatie/laravel-medialibrary` image upload) — `inventory_tracking_enabled` is derived server-side from `product_type`, never client-supplied, per Section 05. `/admin/categories` (self-nesting) and `/admin/attributes` (Color/Size + values, the variant picker's prerequisite data). `/admin/returns` covers Section 12's post-delivery workflow end to end: file on a customer's behalf → record shipping-fee consent → approve → receive (restocks) → refund — split across a new `returns.create`/`returns.manage` permission pair after a Customer-Service-only test caught the first cut gating the whole thing behind Warehouse Manager/Accounting's permission. Manual review (still no git repo) caught and fixed a real data-integrity gap before it shipped: removing an already-ordered variant from the product form would have hard-deleted a row `order_items`/`inventory_movements` still reference (no cascade/null-on-delete on those FKs) — now deactivated instead, with a test proving the order survives intact. Also fixed two latent copies of the same "`?:` on a maybe-absent validated array key throws `ErrorException`" bug in `CategoryController` and `CollectionController` (the latter shipped undetected in the original Phase 4 pass — no test happened to omit `slug`). 6 more Pest tests (34 assertions, 35/137 total for the phase now), Larastan (103 files) and Pint (203 files) still clean, verified against real MySQL and a live authenticated curl session. Full details folded into [`PHASE-4-HANDOVER.md`](PHASE-4-HANDOVER.md)'s addendum section.
- **2026-09-12** — **Phase 5 complete.** The storefront rebuilt page-by-page from the confirmed Anvogue bases (Section 17): homepage, listing (shop/category/collection), product detail, cart, COD checkout, customer account, wishlist, search, order tracking, auth, static pages and 404 — with the template's own compiled theme CSS vendored in and layered between Tailwind's `base` and `utilities`, so a utility still beats the theme class it's combined with, exactly as in the template. Section 17's required changes applied rather than carried over: every card/PayPal/Apple-Pay field and the brand field gone, Country/State/City/Zip replaced by the real Governorate → City → District → Area cascade, the Billing tab dropped, and the Rating/Availability filters plus the Reviews/Notifications/Recently-Viewed account tabs added (§20 #14–16). Customer auth on the `customer` guard, a live-priced `CartService` with guest-cart merge on login, a `CouponService` extracted from `CreateOrderAction` so the cart previews the same discount the order gets, and 3 new tables (`reviews`, `wishlists`/`wishlist_items`, `notifications`). Checkout reuses `CreateOrderAction` unchanged — stock is reserved, never deducted. 25 new Pest tests (60/356 total), Pint (238 files) and Larastan both clean, verified against real MySQL and a live curl walkthrough of the full guest journey. Found and fixed a serious pre-existing problem along the way: **the test suite had been dropping the development database on every run** (container env beats `phpunit.xml`), which is also the real cause of Phase 4's "concurrent test runs corrupt each other". Details, and the one gap that blocks real-world use (`/admin/shipping-rates`): [`PHASE-5-HANDOVER.md`](PHASE-5-HANDOVER.md).
- **2026-09-12** — **Phase 5 gap closure.** Every item the Phase 5 handover flagged, resolved rather than deferred: `/admin/shipping-rates` built behind a new `delivery.rates.manage` permission (with a test that walks a checkout from *refused* → Delivery Manager adds a rate → *succeeds at that rate*); real order/return notifications on the database + mail channels, hung off model observers and dispatched via `DB::afterCommit()` so a rolled-back order emails nobody; guest-checkout email collisions fixed with a `customers.is_guest` flag — a registered account is refused, a guest record is reused, and registering **claims** the guest row so earlier orders aren't orphaned; Country became a real select driven by the `countries` table; the contact form now actually sends to the support inbox, with a honeypot and rate limit; all imagery generated and labelled "DEMO ARTWORK" — the Anvogue download ships **no photography at all** (every collection image, banner, hero and about-us tile is the same grey placeholder; verified by md5), so product images now seed through medialibrary and the hero/banners/404/favicon are generated SVGs, taking `public/storefront/images` from 3.5 MB to 44 KB; and the build dropped from **~12 MB to ~3 MB** by trimming Phosphor to woff2 and code-splitting every Inertia page (admin entry 1.65 MB → ~4 KB, closing Phase 4's deferred item too). Template fidelity verified against headless-Chromium renders, not just structurally. 14 new tests (74/447 total), Pint (246 files) and Larastan clean. Details: [`PHASE-5-HANDOVER.md`](PHASE-5-HANDOVER.md)'s addendum.
