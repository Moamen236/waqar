# Phase 4 Handover — Admin / Internal Operations Surface

**Status: Complete**, including the same-day addendum that closed both gaps flagged in this handover's
first pass (Products/Categories admin CRUD, a dedicated Returns/Refunds screen — see the addendum section
below). Verified with 35 Pest tests (137 assertions, the full Phase 1-3 suite re-run alongside all of this
phase's work) against the dockerized MySQL/SQLite-in-memory test stack, Pint (203 files) and Larastan
(103 files, level 5) both clean, a full `migrate:fresh --seed --force` against the real dockerized MySQL,
and real end-to-end login → authenticated-session → permission-gated-route curl walkthroughs against the
running app (not just the test suite).

Reference: `WAQAR-DELIVERY-ROADMAP.html` Phase 4 / `PROJECT-SYSTEM-DOCUMENTATION.html` Sections 14/15/20.
Also followed `.claude/skills/ecommerce-workflow/SKILL.md` — this phase's content (auth, roles &
permissions, admin routes, checkout/treasury-adjacent controllers) maps to `/security-review` and
`/code-review` per the mapping table; see the status note below for why neither could run as the actual
tool, and what the manual pass covered instead.

## The roadmap's own completion bar

> Done when — Checking, Delivery Manager, Accounting and Customer Service can each complete their slice
> of one order's lifecycle from the admin UI alone.

`Phase4AdminOperationsTest.php`'s `it walks one order through its full lifecycle from the admin UI alone`
test is exactly this: Customer Service creates an order → Checking confirms it → Delivery Manager assigns
it to a representative → Accounting confirms Delivered (deducting stock and recording the treasury
transaction) — all through the actual HTTP routes/controllers, not by calling Actions directly. This is
the phase's real acceptance criterion, and it's green.

## What landed

**Employee auth** (prerequisite, not its own line item in the roadmap's task table, but nothing else in
this phase means anything without it): session login/logout on the `employee` guard
(`Admin\Auth\LoginController`), a deactivated-account check, `redirectGuestsTo()` wired in
`bootstrap/app.php` so an unauthenticated `/admin/*` hit lands on `/admin/login` rather than the
not-yet-built customer one.

**RBAC** — `PermissionSeeder` (new) seeds a 30-permission catalog (`orders.view`, `orders.assign`,
`treasury.manage`, etc. — resource.action, per Section 15's own rule: "gated by a granular permission,
never by role name directly") with sensible per-role defaults matching each of the 9 base roles'
Section 15 responsibilities. **Super Admin holds none of these explicitly** — it bypasses every check via
a `Gate::before` hook in `AppServiceProvider` (Spatie's own recommended super-user pattern), verified not
to crash on a *customer*-guard authorization check either (the closure checks `instanceof Employee` before
calling `hasRole()`, since `Gate::before` runs for every guard's checks). `/admin/roles` +
`/admin/roles/{role}/edit` is the permission-matrix editor (react-hook-form, as the roadmap named) a Super
Admin uses to change any of this afterward.

**10 admin routes** (Section 14's list, verbatim) plus the ones the roadmap's other bullets implied:
`/admin/checking` (+ `{order}` confirm/postpone/cancel/backorder/resume), `/admin/delivery` (+
representatives CRUD + coverage-areas sub-resource + shipping-companies CRUD), `/admin/accounting` (+
`{order}` confirm Delivered/Returned/Partially-Returned + reconciliation statements/transfers),
`/admin/orders/create` (Customer Service, reusing Phase 3's `CreateOrderAction` directly — same
server-side pricing, same stock reservation, same COD payment record no matter who's placing the order).
64 routes total, all but the login pair behind `auth:employee`, and every group beyond that behind its
specific `permission:` middleware.

**Real Customer create/edit form** (`/admin/customers`) — Section 17 flagged the template's own
`customer-add/edit.html` as a mislabeled Seller-list copy, unusable as reference; built from scratch.

**Collections, Employees, Promotions, Treasury ledger UI** — none of these exist anywhere in the Larkon
admin template (Section 20 #23), all built from scratch: Collections (translatable name/description, image
upload via react-dropzone), Employees (role assignment + the `team_leader_id` hierarchy from Phase 1),
Promotions (Bundle vs. Buy-X-Get-Y, `items`/`rewards` dynamic rows targeting a variant/category/collection,
react-hook-form's `useFieldArray`), Treasury (per-account ledger, manual transaction entry, inter-treasury
transfers, a react-apexcharts bar chart of recent activity).

**Shipping-company reconciliation** — a new `ReconciliationService` (`app/Services/Treasury/`) computes a
draft statement from delivered/returned orders in a period (delivered count, expected collection, fees
owed, net expected) that Accounting reviews before saving, then records partial/full transfers against it
via the same `TreasuryService::recordTransaction()` every other money movement in the system goes through.

**Template UI libraries wired** (Section 23's list): `react-select` (customer/variant search on the
order-create form, target pickers on the promotion form), `react-quill-new` (rich-text collection
descriptions — see the substitution note below), `react-dropzone` (collection image upload),
`flatpickr`/`react-flatpickr` (reconciliation period picker), `sweetalert2` (a shared `confirmAction()`
helper used before every status-changing action across Checking/Delivery/Accounting), `react-apexcharts`
(treasury activity chart), `simplebar-react` (the admin sidebar's scroll area), plus `bootstrap-icons`
(not roadmap-named, added because the sidebar needed *some* icon set and Larkon's own `iconify-icon` +
theme CSS isn't part of this build — see the layout note below) and `tightenco/ziggy` + `ziggy-js` (not
roadmap-named either, but the standard way an Inertia+Laravel app gets a `route()` helper in
TypeScript/React rather than hand-written URL strings — used in every page written this phase).

**Two real gaps in Phase 3's Actions, filled here** (found while wiring the Checking screen, which needs
both): `PostponeOrderAction` (New/Checking/Confirmed → Postponed — Section 03's status table lists this
explicitly but Phase 3 never built it) and `MarkOrderBackorderAction` /
`ResumeBackorderAction` (Confirmed ↔ Backorder, Question 14 — `ResumeBackorderAction` actually reserves
stock for the now-real product, since an Advertisement product's order line skipped reservation entirely
at creation). `ConfirmOrderAction` was also widened to accept `Postponed` as a valid starting status, since
without that a postponed order had no way back to Confirmed.

## Deliberate scope decisions — worth flagging to you directly

- **No Larkon theme CSS was ported.** `resources/css/admin.css` only ever imported plain Bootstrap
  (Section 23's decision from Phase 0); Larkon's own sidebar/nav markup (`nav-icon`, `menu-arrow`,
  `sub-navbar-nav`, the `iconify-icon` web component) depends entirely on the template's un-imported theme
  CSS, so porting that markup as-is would render unstyled. The admin shell built this phase
  (`AdminLayout.tsx`) is a plain-Bootstrap sidebar/topbar instead — functional, permission-gated nav,
  consistent styling, but not a pixel-match to the Larkon mockups. This matches Section 17's own framing of
  the template pages as "reusable as component reference only," but is a bigger visual gap for the
  sidebar/topbar shell specifically than for the individual CRUD screens, since there was no template page
  to reference for *that* at all.
- **`react-quill-new` instead of `react-quill`.** The roadmap names `react-quill`, but that package is
  unmaintained (no React 18/19 support) and would need a legacy-peer-deps workaround with real risk of
  runtime breakage under React 19's stricter behavior. `react-quill-new` is a maintained fork with the same
  API, same role (rich-text editor), used identically to what was asked for.
- **No Products/Categories/Attributes admin CRUD was built** in this phase's original pass — genuinely
  absent from both the roadmap's Phase 4 task table and its "Done when" bar. **Closed as a same-day
  follow-up, on request** — see "Products/Categories admin CRUD + Returns/Refunds screen" below.
- **`AssignDeliveryAction`'s single-warehouse-per-order assumption from Phase 3 carries forward unchanged**
  — the assignment board doesn't add a new constraint here, just surfaces what Phase 3 already built.
- **Dashboard content is still the Phase 0 placeholder.** Question 18's role-scoped widgets are explicitly
  deferred by the spec itself ("screen layout/components... designed in a separate follow-up," not this
  phase's responsibility) — this phase only moved it behind real auth.
- **Returns/refunds had no dedicated admin screen** in this phase's original pass. Phase 3 built the full
  Actions (`RequestReturnAction` → ... → `RefundReturnAction`); neither the roadmap's 10-route list nor its
  task table asked for a `/admin/returns` UI. **Closed as a same-day follow-up, on request** — see below.
- **The production JS bundle is large** (~1.6MB uncompressed / ~480KB gzipped for the admin entry) —
  expected, given six new UI libraries loading eagerly rather than per-route, and not a blocker for this
  phase, but worth a code-splitting pass (`React.lazy` per admin page) before Phase 7's hardening pass if
  admin load time becomes a real concern.

## Bugs this phase's own verification caught and fixed

- **A 40-error Larastan cascade, again** — same root cause Phase 3 documented (Larastan 3.12 not fully
  inferring Laravel 11+'s `casts(): array` method / relation generics without explicit PHPDoc), triggered
  fresh by the new controllers traversing relations Phase 3 never touched (`Order::customer()`,
  `Governorate::cities()`, `City::districts()`/`areas()`). Fixed the same documented way: `@return
  BelongsTo<Customer, $this>` / `@return HasMany<X, $this>` annotations, not suppression. A `GeoTree`
  helper's deeply-nested `->map()` chain also hit a *different* Larastan gap — structurally-identical but
  independently-inferred array shapes failing `Collection`'s non-covariant generic check — fixed by
  refactoring into one static method per geo level with its own explicit `@return array{...}` docblock
  instead of nested closures.
- **`PermissionSeeder` read its own stale permission cache mid-seed** — `Role::syncPermissions()` inside
  the same `run()` method that had just created those permissions threw `PermissionDoesNotExist`, because
  Spatie's permission list is cached in memory on first read and `forgetCachedPermissions()` was only
  called once, before any permissions existed. Fixed by calling it again after the creation loop, before
  the sync loop — caught immediately by the very first Pest run of the new test file, not by manual
  inspection.
- **`WarehouseSeeder` was missing `phone`**, a required column with no DB default — invisible against
  SQLite (which didn't enforce it during the Pest run) but a hard failure the moment `migrate:fresh --seed`
  ran against the real MySQL. This is exactly the SQLite-vs-MySQL strictness gap called out in earlier
  phases' handovers as a reason the real-MySQL pass matters independently of the test suite passing.
- **A real privilege-escalation gap, found during the manual security pass** (see below), not by a tool:
  the Employee create/edit form's role dropdown included every role unconditionally, so granting
  `employees.manage` to *any* role — even one that should never touch it — would let that role's holder
  assign themselves (or anyone) Super Admin. Fixed two ways: the role list served to the form excludes
  Super Admin unless the acting employee already holds it, and `EmployeeController` independently rejects
  (`403`) any request that tries to set `role: 'Super Admin'` from a non-Super-Admin actor, regardless of
  what the client actually sent — the second check is the real boundary, the first is just not showing a
  dead-end option in the UI. Covered by a dedicated test that grants `employees.manage` to the Checking
  role specifically (the worst case: a role that should never see this at all) and confirms the attempt is
  rejected and no employee record is created.

## `/security-review` status

**Still no git repository** — fourth phase in a row this has blocked the formal tool, and the first phase
where it would have mattered most (auth, roles & permissions, and every admin route this phase adds are
exactly what the project skill names for `/security-review`). Did a careful manual pass instead, and it
surfaced a real finding (the Super-Admin-assignment gap above) — worth taking as a data point that a
formal review pass on this phase specifically would likely be valuable once a git repo exists, not just a
process formality. Beyond that fix:

- **CSRF, session, and the `permission:`/`role:` middleware are all real enforcement**, not just UI
  affordances — verified by test (a Checking employee's direct POST to `/admin/treasury` returns 403, not
  merely a hidden nav link) and by a real curl session against the running app (login → authenticated
  session → a Super-Admin-only route), not only through the test client.
- **`Gate::before`'s Super Admin bypass is guard-safe** — explicitly typed to check `instanceof Employee`
  before calling `hasRole()`, so a customer-guard authorization check (e.g. `OrderPolicy::view`) can't
  TypeError against it. This was reasoned through and fixed proactively, not found as a bug — noted here
  since it's the kind of thing that's easy to get wrong silently until a storefront Policy check actually
  runs against a customer in Phase 5.
- **Every route mutating state sits behind the specific permission Section 15 assigns that responsibility**
  — cross-checked the full `routes/admin.php` group-by-group against the PermissionSeeder defaults rather
  than spot-checking; no route was found gated by a broader or narrower permission than its department's
  actual responsibility.
- **PII stays hidden** — `Employee.national_id_number` stays off the edit form's props (can't be prefilled,
  by design — re-entering it is optional on update, required on create) and off any JSON response
  (`$hidden` from Phase 1, unchanged).
- **No new card/payment-data surface** — nothing this phase touches payment collection beyond selecting
  `collected_method` (cash/bank_transfer/wallet/other, matching Phase 3's `CollectedMethod` enum) and a
  `treasury_id` — consistent with COD-only, no card data anywhere in the system.

## Operational notes (in addition to Phase 0-3's)

- **`vite build` needs `--user root`**, same reasoning as `npm install`/Pint — it writes a new temp config
  file into `node_modules/.vite-temp/`, which the non-root `www-data` container user can't do against files
  owned by the host uid. Not previously hit because this is the first phase to actually run a production
  build. Followed by the standard `chown` pass.
- **The long-running `app` service container's bind mount can go stale mid-session** (a WSL2/Docker
  Desktop quirk, first hit and documented in `PHASE-3-HANDOVER.md`) — hit again this phase after the `vite
  build`. Same fix: `docker compose restart app`. Every one-off `docker compose run --rm` command is
  unaffected since it mounts fresh each time; only the persistent service container can go stale.
- `php artisan storage:link` needed `--user root` too (same symlink-into-the-repo category as
  migrations/seeders) — done once this phase for Collections' image uploads.

## Addendum — Products/Categories admin CRUD + Returns/Refunds screen

Requested the same day, closing the two gaps flagged above rather than deferring them. Verified with 6
more Pest tests (34 assertions — 35 total for the phase now, 137 assertions), Larastan and Pint both still
clean, a real MySQL `migrate:fresh --seed --force`, and an authenticated curl walkthrough of all four new
routes.

**Products** (`/admin/products`, Vice Chairman + Chairman) — translatable name/description/short
description (`react-quill-new` for the long description), SKU/slug, price/sale/cost price, the
Advertisement↔Real `product_type` toggle, category and collection multi-select (`react-select`), and a
variant table: each row its own SKU with a price override and a multi-select of attribute values
(Color: Red, Size: M, …) via `react-dropzone`-uploaded images through
`spatie/laravel-medialibrary`'s existing `product_images` collection.

**Categories** (`/admin/categories`) — self-nesting via `parent_id` (unlimited depth, Section 05),
translatable name/description, image upload.

**Attributes** (`/admin/attributes`) — one page: create an attribute (Color, Size, …) and its values
inline, including an optional color-swatch hex for Color-type attributes. Prerequisite data for the
Products form's variant picker — without this screen there'd be no way to add a new attribute value
without `tinker`.

**Returns/Refunds** (`/admin/returns`, split across two new permissions — see below) — the post-delivery
customer-initiated workflow (Section 12): file a return on a customer's behalf (order number lookup →
select items/quantities/reason), record the customer's accepted return-shipping fee (Question 6's consent
step), approve, receive (warehouse restocks), refund (treasury/method/reference number). An at-delivery
refusal stays on the existing Accounting screen (`confirmReturnedAtDelivery()`, Phase 4's original pass) —
this screen covers only the other half of Section 12's unified `stage` model.

**`inventory_tracking_enabled` is never client-supplied** — Section 05 is explicit that this flag is
"never edited independently by a user," only ever derived from `product_type`. It isn't even a validated
request field; `ProductController::productFields()` computes it server-side from the validated
`product_type` every time, covered by a test that posts a request with no `inventory_tracking_enabled` key
at all and confirms the stored value still matches the product type.

**New permission split: `returns.create` vs. `returns.manage`.** The first attempt gated the entire
`/admin/returns` group behind `returns.manage` (Warehouse Manager + Accounting only) — but the controller's
own design intent was for Customer Service to file a return on a customer's behalf, the same convention
`/admin/orders/create` already established, which a Customer-Service-only test caught immediately as a 403.
Split into `returns.create` (view queue, file a return, record shipping-fee consent — Customer Service +
Customer Service Team Leader + Warehouse Manager + Accounting) and `returns.manage` (approve/receive/refund
— Warehouse Manager + Accounting only, unchanged).

**A real data-integrity bug, found in review, not by a tool or a test:** removing a variant from the
product form originally hard-deleted its `product_variants` row unconditionally. `order_items.
product_variant_id` and `inventory_movements.product_variant_id` are both plain `constrained()` — no
`cascadeOnDelete()`/`nullOnDelete()` — so MySQL's default RESTRICT behavior means deleting a variant with
any order or stock-movement history would throw an unhandled `QueryException` (a raw 500) the first time
someone tried it, not silently corrupt anything, but still a real robustness gap this session's own manual
tracing caught before it shipped. Fixed by checking for that history first: a variant with none is still
hard-deleted (the common case — a typo'd row added and removed before saving), one with history is
deactivated (`status = false`) instead, leaving the row and everything referencing it intact. Covered by a
dedicated test: order a variant, drop it from the form, confirm the row survives, is inactive, and the
order's `product_variant_id` still resolves.

**Two more `?:`-on-a-possibly-absent-array-key bugs, same shape as the one above, found while fixing it** —
`CategoryController` and `CollectionController` (the latter dating to this phase's original pass) both
computed their slug as `$data['slug'] ?: Str::slug(...)`, which throws `ErrorException: Undefined array
key` the moment a request omits `slug` entirely (a `nullable` validation rule doesn't add an absent key to
Laravel's `validate()` return array — only `??` short-circuits that safely, `?:` still touches the key
first). `ProductController` had the identical pattern from this same addendum. All three fixed to
`($data['slug'] ?? null) ?: ...`. `CollectionController`'s instance means this exact bug shipped
undetected in the phase's original pass — no Collections test happened to omit `slug` — a reminder that a
passing suite isn't proof of an absent-key path being exercised.

**Caught during this addendum's own verification, unrelated to the new code:** running two
`docker compose run --rm` Pest invocations concurrently against the real MySQL corrupted both runs with
connection-collision errors (`Table already exists`, `Table doesn't exist`) that looked like a config
problem but weren't — `phpunit.xml`'s `DB_CONNECTION=sqlite`/`:memory:` overrides were never in question.
Worth remembering for future sessions: this environment's `docker compose run` invocations are not safe to
run in parallel when they touch the database, even though each spins up its own container — re-run
serially, not concurrently, whenever a background test run is still in flight.

## What Phase 5 needs to know

Next up: **Storefront Rebuild** (`WAQAR-DELIVERY-ROADMAP.html` Phase 5) — porting the Anvogue template
page-by-page into Tailwind/React, culminating in a working COD checkout. `CreateOrderAction` is already
proven end-to-end from this phase's admin order-create flow — the storefront checkout controller should
call it exactly the same way, just with `OrderSource::Website` and no `createdByEmployee`. `ShippingRate`,
`GeoTree` (this phase's helper, in `app/Support/`), and the coupon-validation logic inside
`CreateOrderAction` are all reusable as-is for the storefront's own address form and cart. Customer
storefront auth (login/register/guest-checkout) still doesn't exist — `redirectGuestsTo()` in
`bootstrap/app.php` already routes a non-admin guest to `route('home')` as a placeholder, but a real
customer login screen and `guest:customer`/`auth:customer` route groups are Phase 5's first prerequisite,
mirroring this phase's `Admin\Auth\LoginController` pattern for the `employee` guard. Real catalog data can
now be entered through `/admin/products` (the addendum above) rather than only via `ProductSeeder`
fixtures, so the storefront has an actual admin-managed catalog to browse against once it lands.
