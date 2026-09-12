# Phase 2 Handover — Schema Extensions

**Status: Complete.** Verified via a full `migrate:fresh --seed` against the dockerized MySQL (64
migrations, clean) and 11 Pest tests (35 assertions) covering the new relationships end to end. Pint
(140 files) and Larastan (47 files, level 5) both clean.

Reference: `WAQAR-DELIVERY-ROADMAP.html` Phase 2 / `PROJECT-SYSTEM-DOCUMENTATION.html` Section 24. Also
followed `.claude/skills/ecommerce-workflow/SKILL.md` — see the scope note and the `/security-review`
status below.

## Scope was expanded beyond the roadmap's original Phase 2 list — here's why

The roadmap's Phase 2 table names ~10 "extension" items (`product_type`, `districts`, `order_source`,
the delivery module, `refunds`, etc.) on the assumption that a *base* schema — `orders`, `payments`,
`returns`, warehouses/inventory, carts/coupons — already existed to extend. That assumption came from
the two source documents describing an existing system; it doesn't hold here, since Phase 1 only built
Identity/Catalog/Geography from scratch. Extending a table that doesn't exist isn't possible, so this
phase built the full remaining schema in one pass — everything in Section 24 except what Phase 1 already
covered — so Phase 3's Actions (`CreateOrderAction`, inventory movements, returns/refunds workflow,
treasury/reconciliation) have a complete schema to write against. **34 new tables**, all field-level
matching Section 24 exactly.

**Deliberately still deferred** (don't block Phase 3's listed Actions, lower priority): `wishlists`/
`wishlist_items`, `reviews`, the Laravel `notifications` package table, `settings`. These are Phase 4/5
territory (account pages, admin notification feed) — flag if Phase 3 turns out to need one earlier.

## What landed

**Catalog extensions:** `products.product_type`/`inventory_tracking_enabled` (Section 05's Advertisement/
Real mechanism), `product_categories` pivot, `collections` + `collection_product`.

**Geography follow-through from Phase 1:** `districts` table, plus the `district_id` nullable columns on
`areas` and `addresses` that Phase 1's migrations deliberately deferred (Q12 — District is optional, so
`city_id` stays as the fallback on both).

**Inventory:** `warehouses`, `warehouse_inventory` (singular table name per spec — `WarehouseInventory::
$table` overrides Eloquent's default pluralization, same pattern as `order_status_history`),
`inventory_movements`, `stock_transfers` + `stock_transfer_items`.

**Commerce:** `carts`/`cart_items`, `coupons` + its 4 targeting pivots, and the full Promotions system
from Question 17 — `promotions`/`promotion_items`/`promotion_rewards` + `order_items.promotion_id`.

**Orders & Fulfillment:** `orders` (all of Section 10's fields — `order_source`, `customer_status`,
`delivery_assignment_type`, the snapshot shipping-address fields including `shipping_district_id`),
`order_items`, `order_status_history`, `delivery_representatives` + `delivery_representative_areas`,
`shipping_companies`, `delivery_assignments`, `shipping_company_statements`.

**Payments & Finance:** `payments` (with `collection_type`/`collected_method` built in from the start,
not a later "extend" migration), `treasuries`, `treasury_transactions`, `treasury_transfers`,
`expense_categories`, `expenses`.

**Returns:** `return_reasons` (seeded with the 6 reasons from Section 12) — note the `returns` table is
`OrderReturn` in PHP, since `Return` is a reserved keyword — plus `return_items`, `refunds`.

**`order_number` sequencing:** implemented as an `Order::booted()` `creating` hook (`max(order_number) +
1`, starting at 1001 per Section 10), not a database auto-increment, so it stays under application
control regardless of which path creates the order. Verified two orders in sequence get 1001, 1002.

## What the tests actually verify

`tests/Feature/Phase2SchemaExtensionsTest.php` — not exhaustive per-table coverage (that would be ~35
trivial tests), but every genuinely new *capability*: an area resolving through an optional district; a
full order with items, a promotion, and a coupon; stock reservation/deduction via
`inventory_movements` plus a delivery assignment and a shipping-company statement; a return unified under
`stage` with its linked refund; a treasury transaction and cart contents.

## Bugs this phase's own tests caught and fixed

- **`orders` and `returns` were missing `deleted_at`** even though their models use `SoftDeletes` — both
  are in Section 23's 7-model soft-delete list, and I'd added the trait without the column. Caught
  immediately by a `QueryException` the moment `Order::max('order_number')` ran a soft-delete-aware
  query against a column that didn't exist. Fixed in both migrations.
- **DB-level column defaults don't appear on a freshly created in-memory model** — `Payment::create()`
  without an explicit `method` leaves `$payment->method` `null` in memory even though the DB row correctly
  gets `'cod'`, until the model is refreshed. Fixed by mirroring the business-critical defaults
  (`Payment.method/status`, `Order.status/payment_status`, `Product.product_type/
  inventory_tracking_enabled` — the last one specifically because Section 05 says `CreateOrderAction`'s
  stock-check bypass reads it directly) onto each model's `$attributes` property. **Not exhaustive** —
  several other defaulted columns (`coupons.is_active`, `promotions.*`, `treasuries.is_active`,
  `stock_transfers.status`, etc.) still rely on the DB default alone. Phase 3's Actions should set these
  explicitly when creating real records rather than depending on an implicit default either way, so this
  is unlikely to bite, but worth knowing if something reads `null` unexpectedly right after a `::create()`.

## `/security-review` status

Same situation as Phase 1 — **still no git repository**, so the formal tool can't run. I did another
manual pass against what this phase actually touched: no new user input paths yet (still schema only),
`cost_price` stays excluded from `Product`'s translatable/visible surface, PII stays limited to what
Phase 1 already hid. Nothing new to flag. This is now the second phase in a row this blocked — worth
deciding on `git init` before Phase 3 starts writing actual business logic, where `/code-review` and
`/security-review` (checkout, stock reservation, treasury, COD, refunds — all things the project skill
explicitly calls out) become a lot more valuable to actually run.

## Operational notes (in addition to Phase 0/1's)

- **This environment's per-migration overhead scales with schema size.** Phase 1's 22 migrations took
  the first Pest run ~210s; Phase 2's 64 migrations took it ~840–1020s (SQLite in-memory, but Laravel
  still runs every migration file once). Against the real dockerized MySQL, the full `migrate:fresh` took
  roughly 15 minutes. Neither is a code problem — it's WSL2/Docker Desktop I/O overhead, consistent with
  everything else observed so far. Budget for it rather than assuming a hang; a single migration
  occasionally takes 30–90s on its own (the `orders` table took 1m27s in one run) with no pattern to which
  one.
- Migration timestamps needed manual renumbering more than once this phase — `php artisan make:model X -m`
  assigns timestamps by wall-clock second, which doesn't know about FK dependency order between tables
  generated in the same batch. Generate in dependency order where possible, or check `ls database/
  migrations | sort` before running `migrate` and renumber (`mv`) anything that would create a table
  before something it references.
- Same file-ownership pattern as before — every `make:model`/`make:migration`/`make:seeder` call still
  needs `--user root`, and Pint/npm scripts that write to existing files need it too, followed by the
  `chown` pass.

## What Phase 3 needs to know

Next up: **Business Logic Layer** (`WAQAR-DELIVERY-ROADMAP.html` Phase 3) — the Actions/Services layer
`CLAUDE.md` already documents the convention for (`app/Actions/<Domain>/`, `app/Services/<Domain>/`,
`app/Enums/`). Every table the listed Actions need now exists: `CreateOrderAction` (`orders`,
`order_items`, `payments`, stock-check bypass via `Product.inventory_tracking_enabled`), the inventory
movement service (`inventory_movements`, `warehouse_inventory`), the returns/refunds workflow (`returns`,
`return_items`, `refunds`, `return_reasons`), the treasury/reconciliation service (`treasuries`,
`treasury_transactions`, `shipping_company_statements`), and the plain-MySQL search Action (no Scout,
per the spec's own decision). Status enums (`OrderStatus`, `PaymentStatus`, etc.) should be introduced
now as real PHP enums per `CLAUDE.md`'s convention — the migrations currently store these as plain
strings with the allowed values only in comments.
