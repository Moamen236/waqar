# Phase 7 Handover — QA & Hardening

**Status: Complete.** Verified with 123 Pest tests (758 assertions — the full Phase 1–6 suite re-run
alongside this phase's 37 new ones), Pint (269 files), Larastan (level 5, 148 files), tsc, ESLint,
Prettier, `tools/check-translations.py` (0 missing across four catalogs), a production build, a
laramint architecture scan, a headless-Chromium pass over both bundles, and a CDP-driven render check
of **all 47 authenticated admin pages**.

Reference: `WAQAR-DELIVERY-ROADMAP.html` Phase 7 / `PROJECT-SYSTEM-DOCUMENTATION.html` Section 23.

## The roadmap's own completion bar

> Done when — a deleted product, a manual stock adjustment, and a role change each leave a readable,
> attributed log entry.

`Phase7AuditAndHardeningTest.php` opens with exactly those three, one test each, named after the bar.
All three were then walked by hand through the running app as well, because the interesting half of
"attributed" is a real session rather than `actingAs()`.

## The one thing that had to be built before anything could be audited

**A manual stock adjustment had no code path at all.** `InventoryMovementType` has carried
`adjustment`, `damaged` and `lost` since Phase 3, and nothing in the system could write one — every
`InventoryService` method belongs to the order flow. The roadmap's completion bar names a manual
adjustment directly, so Phase 7 had to build the thing before it could log it:

- `InventoryService::adjust()` — the only entry point that changes physical stock with no order
  behind it. It carries the two guards the order flow gets for free from its own shape: **a reason is
  mandatory**, and an adjustment **may never push physical quantity below `reserved_quantity`**. That
  second one matters more than it looks — reserved stock is spoken for by confirmed orders, and
  quietly removing it converts into an unfulfillable order weeks later instead of an error now.
- `AdjustStockAction` — thin by design (the guards stay in the service so no future caller can route
  around them). What it adds is the *attribution*: the acting employee is threaded to the movement's
  `created_by`, which is what lets the audit entry answer "who".
- `/admin/inventory` — stock on hand with the adjustment form and a recent-adjustments table.
  Deliberately shows **reserved and available alongside on-hand**; a screen showing only `quantity` is
  how someone adjusts away units that are already sold.

`inventory.view` and `inventory.adjust` were already in the permission catalog from Phase 4 and are
gated separately, with a test proving `.view` alone cannot move stock.

## Activity log — what is wired, and the two things that would have made it useless

Ten models opt into a shared `RecordsActivity` trait (`app/Models/Concerns/`) declaring an audit
domain and an explicit attribute list: Product, ProductVariant, Category (`catalog`), Order,
OrderReturn (`orders`), InventoryMovement (`inventory`), Treasury, TreasuryTransaction (`treasury`),
Customer (`customers`), Employee (`access`).

Attribute lists are per-model and explicit rather than `['*']` on purpose: a log that records every
column records `updated_at` on every write and buries the changes that matter.

### 1. Every admin action would have logged a null causer

`auth.defaults.guard` is **`customer`** (the storefront is the larger surface), and Spatie's stock
causer resolver asks the *default* guard. Every audited admin action would therefore have been
recorded with no causer at all — precisely the half of "a readable, **attributed** log entry" that the
phase exists to deliver, and it fails silently rather than erroring.

`AppServiceProvider::resolveActivityCauser()` overrides the resolver: employee guard first, customer
second. An employee session is the only thing that can reach the admin area, and a console command or
queued job has neither — which correctly logs as a system action rather than being falsely attributed
to whoever happens to be signed in. There is a regression test asserting the default guard is still
`customer`, so the day someone flips it, the reason this override exists is still on record.

### 2. Role and permission grants fire no model event

`syncRoles()` / `syncPermissions()` are pivot writes. No Eloquent event fires, so `RecordsActivity`
cannot see them at all. Spatie ships its own four events for this (`RoleAttachedEvent` and friends),
disabled by default — `config/permission.php` now sets `events_enabled => true` and
`App\Listeners\LogAccessChange` translates them into audit entries.

Listening to the events rather than logging inside `RoleController`/`EmployeeController` catches
**every** path — the seeders, tinker, and any Action added later — not only the two screens that exist
today. A role change reads as a **pair** (detach the outgoing role, attach the incoming one), because
either half alone says "changed to Accounting" without saying from what.

The handlers are named `on*`, not `handle*`. Laravel 11+ auto-discovers any `handle*` method in
`app/Listeners` that type-hints an event, which registered them a second time on top of the explicit
bindings and **logged every grant twice**. That was caught by eye during a smoke test, not by a gate.

## Three decisions that make an entry survive its own subject

An audit entry is consulted precisely when the thing it describes is gone, so:

- **The description stays the raw event name** (`created` / `role_attached` / …) and the UI translates
  it — the same key-lookup Phase 6 used for order statuses. Admin is Arabic (Q2); a sentence baked in
  at write time can never be translated afterward.
- **A label snapshot rides along in every entry's properties.** An entry that can no longer say *which*
  product was deleted fails the only bar this feature has.
- **`subject_returns_soft_deleted_models` is now `true`.** Most of what this project soft-deletes is
  soft-deleted so the audit trail can still reach it; left false, the one entry anyone actually goes
  looking for — "who deleted this product" — resolves its own subject to null.

`/admin/activity-log` exists because "readable" has an audience: without a screen, the log is a table
only someone with database access can consult. It is **read-only by construction** — there is no
write or delete route, not merely no permission for one, and a test asserts that every route matching
`activity-log` is GET-only. The new `activity.view` permission has no `activity.delete` counterpart
for the same reason: an audit log an operator can edit is not an audit log.

## A label that changed depending on who was looking

Worth recording because the first implementation passed every gate while being wrong.

`$product->name` on a translatable model returns the **current locale's** string. The app default is
Arabic, so a product deleted by an Arabic-speaking operator and edited by an English-speaking one
produced two entries that read as two different products. An audit trail has to be comparable across
its own rows, so `activitySubjectLabel()` now resolves translatable names in one **fixed** locale
(`app.fallback_locale`), falling back to whatever translation exists for a record written in only one
language.

The lookup reads the stored JSON column directly rather than calling `getTranslation()`, because only
half the models using the trait are translatable at all — a dynamic call there is an undefined method
on the other half, which is what Larastan correctly flagged.

## Soft deletes — confirmed, not assumed

All seven models the roadmap names (products, categories, orders, customers, employees, returns,
treasuries) carry `SoftDeletes`, and all seven tables carry `deleted_at` — checked against the live
schema, not just the migration source. One test deletes all seven and asserts three things per model:
the instance reports `trashed()`, the row is invisible to a normal query but present under
`withTrashed()`, and **the underlying database row still physically exists**.

The `->delete()` calls elsewhere in the codebase were audited and are all on models that deliberately
*don't* soft-delete — cart items, wishlist items, addresses, shipping rates, attribute values,
promotion items. Those are transient or configuration rows. `ProductController::removeDroppedVariants`
already handled the one genuine hazard in Phase 4's addendum (deactivate a variant with order history
rather than delete it).

## The three security findings Phase 6 left open, closed

All three ended in **same-origin script execution**, which is why the third one is a CSP rather than a
third point fix.

**1. Unvalidated image upload** (`app/Support/ImageUpload.php`). The `image` field had *no rule at
all* on Category and Collection. Two things the obvious fix misses: Laravel's `image` rule **permits
SVG** — an XML document that may carry `<script>`, served back from this application's own origin — so
ProductController's existing `['image', 'max:5120']` was also too loose; and `mimes:` validates
**content**, not the filename, so `payload.html` renamed to `photo.png` fails. Both are tested, the
second with a real temp file rather than `UploadedFile::fake()`, because a faked upload reports its
MIME from the extension and literally cannot express that attack.

**2. Stored XSS via product description** (`app/Services/Content/RichTextSanitizer.php`, on
`symfony/html-sanitizer`). Sanitised on **write**, at the single `validated()` choke point both
`store()` and `update()` pass through — not at render. The stored value already has more than one
consumer (storefront page, order confirmation mail) and will have more (Section 23's mobile API), and
a sanitiser attached to one renderer protects only that renderer. The allowlist is Quill's own
toolbar, so anything the editor can legitimately produce survives a round trip.

One real bug in the first cut: `blockElement()` removes the tag but **keeps its text**, so a blocked
`<script>` left its source behind as visible prose in the description. `dropElement()` removes the
element and its contents. Harmless but wrong, and only a test looking for the payload string rather
than for `<script` would have caught it.

**3. No CSP anywhere** (`app/Http/Middleware/SecurityHeaders.php`). A strict `script-src` is only
affordable because Phases 5 and 6 vendored everything this app loads — Phosphor, icomoon, Cairo, both
themes — so there is no CDN to allowlist. The two inline scripts that do exist (Ziggy's route table,
Vite's tags) carry a **per-request nonce** via `@routes(nonce: …)` and `Vite::useCspNonce()` rather
than being waved through with `'unsafe-inline'`. `style-src` does keep `'unsafe-inline'`: React writes
inline `style` attributes, nonces don't apply to attributes, and a style attribute cannot execute
script. `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy` and `Permissions-Policy` ship
alongside.

`Vite::isRunningHot()` adds the dev server to `script-src`/`connect-src` — without it `npm run dev`
serves a blank page while production is perfectly fine, which is an unpleasant way to find out.

## `/security-review` finally ran — five phases after it was first blocked

Phases 1–5 each recorded it as blocked for want of a git repository. There is one now, so the review
ran against the real diff. **No HIGH or MEDIUM findings** above the confidence threshold. What was
checked and cleared: authorization on all three new routes (verified against the live router, not the
source), SQL injection through the log/event/search filters (all bound, the one JSON path is a
literal), upload validation ordering (validation precedes `storeImage()` in all three controllers, so
a rejected file is never written), nonce randomness and per-request freshness, and credential/PII
exclusion from the trail.

Three notes it raised that sit below the reporting bar but are real:

- **The activity log is not team-scoped.** Orders are team-scoped elsewhere via
  `Order::scopeVisibleTo` (Q16); the log is not. Harmless as shipped — `activity.view` defaults to
  Chairman alone, who already sees every order — but granting it to a Team Leader role would show
  order activity outside their team. Worth a deliberate decision rather than a later discovery.
- **Pre-fix stored uploads are not retroactively validated.** Irrelevant here (the 28 files in
  `storage/app/public` are all Phase 5's *generated* demo artwork, written by `ProductSeeder`, which
  is trusted code) but it matters if this is ever deployed onto a database carrying real operator
  uploads from before the fix.
- **A deliberate asymmetry:** the seeder writes SVGs that `/admin/products` now refuses to accept.
  That is correct — generated content is trusted, uploaded content is not — but it will look like a
  bug to whoever hits it first.

## The laramint architecture graph, and how to actually run it

The sign-off gate passes: **656 nodes, 1390 edges, 156 routes**, with routes → controllers → Actions →
models tracing cleanly. Four isolated nodes, each explainable by design:

| Isolated node | Why |
|---|---|
| `inspire` command | Laravel's stock scaffold command |
| `ShippingRate` model | No FK by design — it uses a polymorphic `geo_type`/`geo_id` pointer (Section 11) |
| `GET /` | Phase 6's bare-URL locale redirect; it redirects, it never reaches a controller |
| `GET /{path}` | The locale-less catch-all redirect, same reason |

**`brain:scan` cannot run in the `app` container.** `GLOB_BRACE` is a glibc constant; the image is
Alpine/musl, which neither defines it nor supports the flag, so the scan dies partway through
"Tracing full lifecycle". Defining the constant by hand does not help — musl's `glob()` rejects the
flag outright. Run it in a glibc image against the same mounted repo instead:

```bash
docker run --rm -v "$(pwd)":/app -w /app --network waqar_waqar php:8.3-cli php artisan brain:scan --no-interaction
```

The output lands in `storage/app/laravel-brain/` (gitignored) as one graph per route.

## Gate results

| Gate | Result |
|---|---|
| Pest | 123 passed, 758 assertions (86 → 123; +37 this phase) |
| Pint | 269 files, clean |
| Larastan (level 5) | 148 files, no errors |
| tsc `--noEmit` | clean |
| ESLint | 0 errors (3 pre-existing warnings in Phase 4 forms — `react-hooks/incompatible-library`) |
| Prettier | clean |
| `tools/check-translations.py` | 0 missing across four catalogs (admin 327 → 414 keys) |
| `npm run build` | clean |
| laramint `brain:scan` | 656 nodes / 1390 edges / 156 routes, 4 explained isolated nodes |
| Admin render check (CDP) | 47/47 pages render; 0 blank, 0 console exceptions |

## Operational notes (in addition to Phase 0–6's)

- **`symfony/html-sanitizer` was added** (`composer require`, root + chown per `CLAUDE.md`). It is the
  only new runtime dependency this phase.
- **Enabling `permission.events_enabled` changes `syncRoles()` behaviour**, not just its
  observability: with events on it routes through `removeRole()` for the outgoing roles rather than a
  bulk detach, which costs one extra query. That is the documented trade and the reason grants are
  auditable at all.
- **The visual pass earned its keep again**, as Phase 6 predicted it would. A CSP fails in exactly the
  way no test sees — a blocked script renders a blank page with a 200 status. Screenshots of both
  bundles under the live CSP confirmed React hydrates, RTL holds, and Cairo still resolves.
- **Activity entries are retained for a year** (`delete_records_older_than_days => 365`) and
  `activitylog:clean` is the command that enforces it. Nothing schedules it yet — see below.


## Addendum — the admin was rendering blank pages, and no gate could see it

Reported after the phase was first signed off, and fixed here. Worth reading in full, because the
failure mode is the one this project keeps producing: **every backend gate green while the product is
unusable.**

### What was actually wrong

`spatie/laravel-translatable` overrides `getAttributeValue()`, so `$product->name` correctly returns
one string. It does **not** override `attributesToArray()`. Everything that *serializes* a model —
`toArray()`, `paginate()`, `->get()`, every Inertia prop — therefore emitted the raw
`{"ar": "…", "en": "…"}` map. React cannot render an object as a child, so it threw (error #31) and
unmounted the tree, leaving a **blank page with a 200 status**.

Invisible to the entire gate suite: the response was correct HTTP, the props were correct data, the
types were correct TypeScript. Only a browser executing the bundle could see it.

The storefront was unaffected only by luck — Phase 5's controllers map their props by hand.

### The blast radius was wider than the report

An audit of every admin GET route with an authenticated session found **12 routes** shipping raw maps,
not the 5 first observed — the extra ones being Attributes, Collections index, Categories create/edit
and Products create/edit. The same sweep turned up **two hard 500s** nobody had reported.

### The fix, and why it is at the serialization boundary

`Models\Concerns\SerializesTranslations` overrides `attributesToArray()` to resolve translatable
columns to the current locale. Applied to all 13 translatable models.

The nuance that makes this more than a one-liner: **authoring screens need the opposite.** Product,
Category and Collection edit forms populate an English *and* an Arabic input, so those three
controllers now ask for `getTranslations()` explicitly. Coercing globally without that would have
silently broken every translation-editing screen in the admin — trading a visible crash for invisible
data loss.

That direction of bug was in fact already present: `Collections/Form` typed `name` as a string and
posted `ar: ''` back, **wiping the Arabic name on every collection edit**. There is now a test that
edits a collection and asserts the other language survives.

### Four more real bugs the sweep surfaced

1. **`$variant->product` is null for a soft-deleted product**, so `/admin/orders/create` and
   `/admin/promotions/create` threw `Call to a member function getTranslation() on null` — a 500, not a
   blank page. Both pickers now `whereHas('product')`: a deleted product cannot be sold, so it should
   not be offerable. Phase 7's own smoke-test delete is what put the database in that state, but
   `/admin/products` soft-deletes in production identically.
2. **Order detail screens reached through `item.product_variant.product.name`** — the same null one
   delete away. They now render `product_name_snapshot`, which `order_items` has carried since Phase 3
   for exactly this reason, and which is also *more correct*: the name as sold, not the name as it
   stands today.
3. **`/admin/returns/create` never eager-loaded the customer it renders.** A different crash
   (`Cannot read properties of undefined`) hiding behind the same blank page — and one the payload
   audit could not see, because the prop was absent rather than misshapen. Only the render check caught
   it.
4. **Every notification built a URL that cannot work off a web request.** `route('order-tracking.index')`
   relies on the `{locale}` default `SetLocale` registers *during a request*, and
   `route('account.orders.show', $number)` additionally fills `{locale}` **positionally** — the exact
   trap this document records for Phase 6. All three notifications now pass named parameters and an
   explicit locale. **Phase 8 adds queue workers, which is precisely when this would have started
   firing** on every queued notification.

### i18n leaks closed

Raw server enum values were rendering untranslated in the Arabic admin: treasury account types
(`cash`), treasury transaction types, order/return/payment statuses (`active`, `pending`, …) and
product types (`real`). All now key off the server's own value through the catalog, the same
convention Phase 6 set. Four English `<Head title>` strings were localised alongside them. Admin
catalogs went 377 → **414 keys**, still 0 missing.

### How this was verified, and what to reuse

A CDP-driven Chromium harness sets the admin session cookie, visits each page and reports
`document.body.innerText` length, table row counts, uncaught exceptions and any literal
`[object Object]`. **47/47 admin pages render**, 0 exceptions.

The script lives outside the repo (it needs `ws`, which is not a project dependency) — it is in the
session scratchpad, and is ~80 lines worth rewriting if this is ever needed again. **A payload audit is
not a substitute**: it cleared `/admin/returns/create`, which was still crashing on a missing prop.

If any single practice from this phase is worth keeping, it is that admin screens need a render check
in CI, not just a green test suite. That is now twice — Phase 6's tofu, and this — that a systemically
broken UI shipped past every gate the project has.

12 new regression tests (`Phase7TranslatableRenderingTest.php`) cover the serialization contract in
both directions, the nested-relation case that blanked the products index, the soft-deleted-product
pickers, the snapshot rendering and the notification URLs.

## What Phase 8 needs to know

Next up: **Infrastructure & Launch** (`WAQAR-DELIVERY-ROADMAP.html` Phase 8). Phase 7 leaves three
things pointed directly at it:

- **`activitylog:clean` needs a schedule.** The 365-day retention is configured but nothing runs it,
  and Phase 8 is where the supervisor-managed queue/scheduler process lands anyway. One line in
  `routes/console.php` once there is a scheduler to run it.
- **Run `tools/check-translations.py` and the test suite in CI.** Both are cheap, both exit non-zero
  on failure, and the translation guard is the only thing standing between a new screen and shipping
  raw key names.
- **`brain:scan` needs the glibc invocation above** if the architecture graph is to be part of a CI
  sign-off; the `app` container cannot run it.
- **The notification URL fix is a Phase 8 prerequisite, not a nicety.** Queued notifications run
  without a request, and every one of them built a URL that depended on having one — see the addendum.
- **Add an admin render check to CI.** Two phases running, a systemically broken admin UI has passed
  every existing gate; only a browser executing the bundle catches it.

Nothing from this phase is left open. The two confirmed-High findings that Phase 6 handed forward are
both closed, and the CSP closes the shared same-origin leg they both relied on.
