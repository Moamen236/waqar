# Phase 3 Handover — Business Logic Layer

**Status: Complete.** Verified with 20 Pest tests (67 assertions, including the full existing Phase 1/2
suite re-run alongside the new work) against the dockerized MySQL/SQLite-in-memory test stack, Pint (173
files) and Larastan (79 files, level 5) both clean, and a full `migrate:fresh --seed --force` against the
real dockerized MySQL dev database.

Reference: `WAQAR-DELIVERY-ROADMAP.html` Phase 3 / `PROJECT-SYSTEM-DOCUMENTATION.html` Section 24-25.
Also followed `.claude/skills/ecommerce-workflow/SKILL.md` — see the `/security-review` status below.

## What landed

**15 new Enums** (`app/Enums/`) as real backed PHP enums, matching Section 24's schema values exactly:
`OrderStatus` (+ `customerStatus()` mapping method to `CustomerOrderStatus`, Section 03's status table),
`CustomerOrderStatus`, `OrderSource`, `PaymentStatus`, `CollectionType`, `CollectedMethod`,
`DeliveryAssignmentType`, `InventoryMovementType`, `ReturnStage`, `ReturnStatus`, `RefundMethod`,
`RefundStatus`, `TreasuryTransactionType`, `TreasuryType`, `StockTransferStatus`, `ProductType`.

**Services** (`app/Services/<Domain>/`, shared logic multiple Actions lean on):
- `Shipping\ShippingRateResolver` — resolves a shipping price server-side by checking Area → District →
  City → Governorate, most specific first (Section 11). The client never supplies this.
- `Inventory\InventoryService` — **the single place physical/reserved stock ever changes** (Section 07):
  `reserve()` / `release()` / `deduct()` / `restock()`, every one wrapped in `lockForUpdate()` inside a
  `DB::transaction()` so concurrent requests queue on the row lock instead of racing past each other's
  read. `deduct()` is the only method that touches `quantity` (physical stock) — everything else only
  moves `reserved_quantity`, enforcing `CLAUDE.md`'s central rule (stock deducts on Accounting-confirmed
  Delivered only). `warehouseForReservation()` derives which warehouse an order's stock was reserved from
  by reading the `inventory_movements` ledger, since `order_items` doesn't carry its own `warehouse_id`.
- `Treasury\TreasuryService` — the single place a treasury's `current_balance` ever changes:
  `recordTransaction()` (signed-delta convention — callers decide the sign from the business event) and
  `transfer()` (one transfer_out/transfer_in pair, atomically).

**Actions** (`app/Actions/<Domain>/`, one class per business operation):
- `Checkout\CreateOrderAction` — shared by storefront checkout and Customer Service's
  `/admin/orders/create` (Section 03's Flow 1/2 converge here). Every price (unit price, subtotal,
  shipping, coupon discount, total) is computed server-side from stored records — nothing about pricing is
  ever trusted from the caller. Reserves stock per line via `InventoryService::reserve()`, **except**
  Advertisement products (`Product.inventory_tracking_enabled === false`), which bypass the check entirely
  per Section 05. Always creates a COD `Payment` (`pending`). Coupon validation covers active/date-range/
  minimum-order/usage-limit/per-customer-limit. Promotion auto-application is explicitly deferred (not a
  Phase 3 item).
- `Orders\ConfirmOrderAction` — Checking confirms New/Checking → Confirmed.
- `Orders\CancelOrderAction` — cancellable from New/Checking/Confirmed/Postponed/Backorder → Cancelled,
  releasing any reservation via the movement ledger (never a deduction, since one never happened).
- `Orders\AssignDeliveryAction` — Delivery Manager assigns a Confirmed order to a representative or
  shipping company → Assigned.
- `Orders\ConfirmDeliveryResultAction` — Accounting's three delivery outcomes, three methods (matches how
  `/admin/accounting/{order}` will actually present this — three separate confirmations, not one
  dropdown): `confirmDelivered()` (deducts stock, records the COD collection as a treasury Income
  transaction, → Delivered), `confirmReturnedAtDelivery()` (releases the reservation only, stock never
  left the warehouse, → Returned), `confirmPartiallyReturned()` (deducts the kept quantity per line,
  releases the rest, records the partial collection, → Partially Returned).
- `Returns\RequestReturnAction` — customer-initiated, post-delivery only (Delivered orders); an
  at-delivery refusal is Accounting's `confirmReturnedAtDelivery()` above, not this path.
- `Returns\AcceptReturnShippingFeeAction` — the customer's Question 6 consent step (must accept the
  return-shipping-fee deduction before a post-delivery return can be approved).
- `Returns\ApproveReturnAction` — Requested → Approved; enforces that a post-delivery return has recorded
  consent first (at-delivery returns never carry this fee, so they skip the check).
- `Returns\ReceiveReturnAction` — Approved → Inspected, restocking sellable items via
  `InventoryService::restock()`. Simplified from the spec's Received → Inspected two-step into one Action
  for Phase 3 — flagged here as worth splitting later if a real "received but failed inspection" path
  turns out to be needed.
- `Returns\RefundReturnAction` — Accounting records a manual bank/wallet refund (Question 5 — no store
  credit, no card reversal), net of the accepted return-shipping fee, and posts it as a treasury Expense.
- `Search\SearchProductsAction` — plain MySQL `LIKE` search across `sku` and the translatable
  `name`/`description` JSON columns via MySQL JSON-path extraction — **no Laravel Scout**, per the spec's
  own decision (Section 23).

**Policy:** `app/Policies/OrderPolicy.php` — `view()` (a customer only ever sees their own order —
this is literally the "cross-customer order access" critical test) and `viewAsEmployee()` (permission gate
+ reuses the Team Leader team-scoping logic; not yet wired to Laravel's `Gate` since that needs Phase 4's
permission seeding to mean anything).

**Models:** every model touched by the above got the enum casts, `$attributes` DB-default mirrors (same
pattern Phase 2 established), and the `@property`/`@return <Relation><Generics>` PHPDoc Larastan needs to
infer enum-cast and relation types correctly under Laravel 11+'s `casts(): array` method style (see
Larastan note below). `Order::scopeVisibleTo()` is the reusable Eloquent scope implementing the Customer
Service Team Leader team-scoping rule (Question 16/18) — used by the critical test directly and available
to any order-listing screen Phase 4 builds.

## What the tests actually verify

`tests/Feature/Phase3CriticalBusinessRulesTest.php` — the "Critical Pest suite" the spec names explicitly
(Section 23's tech-stack table), plus the Team Leader scoping test added in an earlier session:

1. **Overselling prevention** — reserving more than available stock throws `InsufficientStockException`.
2. **Concurrent-purchase race condition** — two requests racing to reserve the last unit of stock: the
   second, exhausting request fails; stock never goes negative.
3. **Cancelled-order stock release** — cancelling an order releases its reservation without ever touching
   physical `quantity`.
4. **COD-collected treasury transaction** — a treasury transaction (and balance change) is recorded only
   when Accounting confirms Delivered, and physical stock deducts only at that same point, never earlier.
5. **Shipping price not client-manipulable** — always resolved server-side from `ShippingRate`, regardless
   of what the caller passes.
6. **Cross-customer order access** — a customer can never view another customer's order
   (`OrderPolicy::view()`).
7. **Customer Service Team Leader team-scoping** — `Order::scopeVisibleTo()` limits a Team Leader to only
   their own team's CS-sourced orders.
8. **Full return → refund workflow** — request → accept shipping fee → approve → receive (restocks) →
   refund (deducts the accepted shipping fee, records a treasury outflow), end to end.
9. **Plain-MySQL search** — no Scout, `sku`/translatable-JSON matching.

## Bugs this phase's own tooling caught and fixed

- **Larastan false-positive cascade (40 → 0 errors)** — not a runtime bug, but worth documenting since it
  ate most of this phase's static-analysis time. Larastan 3.12.0 doesn't fully infer types through
  Laravel 11+'s new-style `protected function casts(): array` model method (vs. the older `protected
  $casts` property), so every enum-cast column appeared as plain `string` to the analyzer. That cascaded:
  `in_array($order->status, [OrderStatus::New, ...], true)` was reported as "always evaluates to false,"
  which flipped negated guard clauses (`if (! in_array(...))`) into "always throws," making the code after
  them "unreachable," which then made `execute(): Order` methods appear to "return null." Verified via
  manual trace through `ConfirmOrderAction` that runtime behavior is correct — `casts()` does return the
  right enum mapping and Laravel applies it — only the analyzer's inference was confused. A second,
  related gap: relation methods without explicit generics (`@return HasMany<OrderItem, $this>`) typed
  eager-loaded results as the generic base `Model` class, producing "undefined property" errors on chained
  access like `$item->productVariant->product`. **Fixed** by adding `@property <EnumType> $column`
  class-level annotations and `@return <Relation><X, $this>` method-level annotations across every model
  Phase 3's Actions actually traverse (`Order`, `OrderItem`, `OrderReturn`, `ReturnItem`,
  `InventoryMovement`, `ProductVariant`, `Payment`, `Refund`, `TreasuryTransaction`, `DeliveryAssignment`,
  `StockTransfer`, `Treasury`) — both are Larastan's documented, standard fixes for this exact gap, not
  workarounds. The remaining 3 errors after that were real (a missing generic on one more relation, and an
  `array{...}` shape that didn't match how the code actually used it) and are fixed the same way.
- **Two Phase 2 test assertions broke from Phase 3's own enum casts** — `Phase2SchemaExtensionsTest.php`
  asserted `$payment->status` and `$return->stage` against plain strings (`'pending'`, `'post_delivery'`),
  which were correct before Phase 3 added enum casting to those columns but became `toBe()` strict-identity
  failures once `$payment->status` started returning a `PaymentStatus` enum instance instead of a string.
  Not a Phase 3 code bug — fixed by updating those two assertions to compare against the enum
  (`PaymentStatus::Pending`, `ReturnStage::PostDelivery`) instead of its raw string value.
- **Stale WSL2 bind mount on the long-running `app` service container** — not a code bug, an environment
  one, but worth recording since it produced a scary false alarm during this phase's final verification:
  after a long session, `docker compose exec app ls /var/www/html` returned an empty directory (root-owned,
  freshly created by Docker itself as a mount-point placeholder) even though every `docker compose run
  --rm app ...` one-off command throughout the entire phase saw the real files correctly — only the
  persistent `waqar_app` *service* container's bind mount had gone stale, which showed up as both `/` and
  `/admin` suddenly 404ing (`could not open input file: artisan`) despite nothing in the app code changing.
  Fixed with `docker compose restart app` — a fresh container re-establishes the bind mount from scratch.
  Worth knowing for future long sessions: if a running dev-server 404s for no code reason, check
  `docker compose exec app ls /var/www/html` before assuming a routing/config regression.

## `/security-review` status

**Still no git repository** — third phase in a row this has blocked the formal tool. I did a careful
manual pass instead, focused on what this phase actually introduced (checkout, stock reservation,
treasury, COD, refunds — exactly what the project skill calls out as needing it most):

- **Pricing is never trusted from the client** — `CreateOrderAction` computes unit price (via
  `ProductVariant::effectivePrice()`'s sale→regular fallback chain), subtotal, shipping (via
  `ShippingRateResolver`, server-side lookup only), coupon discount, and total entirely from stored
  records; nothing about money is accepted as a parameter from a request body. Verified by test #5 above.
- **Stock races are closed with row-level locking**, not optimistic checks — every `InventoryService`
  method takes `lockForUpdate()` inside a `DB::transaction()` before reading `quantity`/
  `reserved_quantity`, so two concurrent reservations against the same variant serialize on the DB row
  rather than both reading a stale "available" figure. Verified by test #2 above.
- **Cross-customer and cross-team data access are enforced**, not merely documented — `OrderPolicy::view()`
  and `Order::scopeVisibleTo()` are the actual code paths, covered by tests #6 and #7, not just comments.
- **No new PII/financial-field exposure** — `cost_price` stays off anything Actions return to a customer
  context; refund/treasury records only ever reach Accounting-role code paths in this phase (no
  controllers/routes exist yet to expose them — that's Phase 4).
- **COD-only, no card data anywhere** — consistent with Section 22's decision; `RefundMethod` is
  bank_transfer/wallet only, never a reversal.

Nothing new to flag beyond the ongoing recommendation: `git init` before Phase 4 starts adding
controllers/routes, where request validation and the customer/employee auth boundary become the
higher-value target for both `/code-review` and `/security-review` to actually run against.

## Operational notes (in addition to Phase 0/1/2's)

- Phase 3 added **zero new migrations** — it's purely Enums/Services/Actions/Policy on top of Phase 2's
  already-complete schema. `migrate:fresh --seed --force` ran clean against the real MySQL in one pass.
- Pint needed `--user root` to actually write its fixes this phase (`docker compose run --rm --no-deps
  --user root app ./vendor/bin/pint`) — the plain non-root run correctly *detected* the 6 style issues but
  couldn't write them back, per the usual host/container uid mismatch. Followed by the standard `chown`
  pass.
- The full Pest suite (20 tests including the Phase 1/2 carryover) now takes ~55-65s end to end in this
  environment, dominated by one Phase 1 test (`it creates a product with a variant and translatable
  attributes`, 50-63s) that isn't itself slow logic — consistent with the per-migration overhead noted in
  the Phase 2 handover; SQLite-in-memory still runs every migration file once per test run.

## What Phase 4 needs to know

Next up: **Admin / Internal Operations Surface** (`WAQAR-DELIVERY-ROADMAP.html` Phase 4). Every Action
this phase built is ready to be called from a controller: `CreateOrderAction` for
`/admin/orders/create` (Customer Service), `ConfirmOrderAction`/`CancelOrderAction` for Checking's
screens, `AssignDeliveryAction` for the Delivery Manager, `ConfirmDeliveryResultAction`'s three methods for
`/admin/accounting/{order}`, the Returns Actions for Accounting's return-processing screens,
`SearchProductsAction` for both storefront and admin search. `OrderPolicy` needs wiring to Laravel's `Gate`
plus the actual permission seeding (`spatie/laravel-permission` is installed but Phase 4 is where the 9
roles get their real ability sets assigned, not just their names — `RoleSeeder` currently only creates the
roles). Controllers stay thin per `CLAUDE.md`'s convention — call an Action, return an Inertia response,
no business logic in the controller. Split into `Http/Controllers/Store/` and `Http/Controllers/Admin/`
per that same convention, and `routes/store.php`/`routes/admin.php` rather than growing `routes/web.php`
further.
