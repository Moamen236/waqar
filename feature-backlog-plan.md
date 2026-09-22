# WAQAR — Feature Backlog Plan

## Context

20 change requests across storefront, checking, accounting, treasury, delivery and returns, mapped
against the current code before planning. The headline finding: **six were already built, or one line
away from it.** Several more turned out smaller than they sounded because the table already exists and
only the admin screen is missing. Two are genuinely new subsystems: the replacement cycle, and
returns going through Checking.

All questions are now answered. Decisions on the record:

| Decision | Choice |
|---|---|
| Shipping-by-address | Per-city price already works; the courier fee **is** that same number |
| Treasury | Courier keeps the shipping at the door — treasury receives goods only |
| Refused delivery | Courier still takes the shipping fee — nothing owed to them out of pocket |
| Replacement pricing | Customer pays shipping, plus the price difference if they trade up |
| Accounting bulk | Group by delivery man, settle together |
| Handover step | Added, but **not** mandatory before Delivered |
| Checking | Stays read-only — it cannot edit order items |
| Default date filter | History lists only; work queues stay unfiltered |
| Reports (#13) | **Removed from scope** |
| Reviews (#17) | **Deferred** |

---

## Already built — verify before building anything

| # | Request | Status |
|---|---|---|
| 5 | Remove icon per product | **Exists on the cart page** ([Cart/Index.tsx:185](resources/js/storefront/Pages/Cart/Index.tsx:185)) and in the mini-cart. Missing only from the **checkout** summary. |
| 6 | Order invoice | **Exists** — printable A4, [Orders/Invoice.tsx](resources/js/admin/Pages/Orders/Invoice.tsx), `window.print()`. The shipping **label** does not. |
| 1 | Shipping price per city | **Exists** — `shipping_rates` keyed by governorate/city/district/area, resolved most-specific-first by [ShippingRateResolver.php:22](app/Services/Shipping/ShippingRateResolver.php:22). |
| 7 | Cities and areas | **Tables, models and `GeoTree` all exist.** Only the admin screens are missing. |
| 3 | Size guide | The modal exists; it lists every size at once. You want the selected size's range shown inline → **A5**. |

---

## Phase A — Quick wins ✅ SHIPPED

**A1 · Show unit price per line in admin order create** — request #16
The server already sends `price` for every variant ([OrderController.php:326](app/Http/Controllers/Admin/OrderController.php:326)); the React mapper throws it away at [Create.tsx:104](resources/js/admin/Pages/Orders/Create.tsx:104), keeping only `{value,label}`. Keep `price`, add Unit Price and Line Total columns to the items table ([Create.tsx:398](resources/js/admin/Pages/Orders/Create.tsx:398)). Display only — the summary card stays server-priced, never a submitted field.

**A2 · Remove-line control on checkout** — request #5
Reuse the control from [Cart/Index.tsx:185](resources/js/storefront/Pages/Cart/Index.tsx:185) in the checkout summary ([Checkout/Index.tsx:300](resources/js/storefront/Pages/Checkout/Index.tsx:300)). Same `cart.destroy` route, `preserveScroll`. No backend change.

**A3 · Phone validation, 11 digits** — request #14
Currently `['required','string','max:30']` **duplicated across 9 controllers** with no format check. The lazy fix is the root-cause fix: one `app/Rules/PhoneNumber.php`, applied at all 9 sites, plus `inputMode="numeric"` on the 5 front-end inputs. Message goes in `lang/{en,ar}/validation.php` — the `attributes.phone` label already exists.

> Sites: Store `RegisterController:42`, `CheckoutController:76`, `AddressController:75`, `SettingsController:43`; Admin `OrderController:434`, `CustomerController:79,147`, `EmployeeController:146`, `RepresentativeController:131`, `ShippingCompanyController:76`.

**A4 · Shipping label** — request #6
A label designed for **this** system: the reference image was a US parcel-service label and half its blocks have no counterpart in an Egyptian COD business.

**Layout** — A6, 105×148mm portrait (standard courier pouch; four fit on an A4 sheet):

```
┌─────────────────────────────────────────┐
│  [LOGO]        WAQAR — <warehouse name> │
│                <warehouse address>      │
│                <warehouse phone>        │
├─────────────────────────────────────────┤
│   COD  1,250.00 EGP        (or PAID)    │  ← full-width banner
├──────────────────────┬──────────────────┤
│ RECIPIENT            │ ORDER            │
│ name                 │ #WQ-10234        │
│ phone                │ date             │
│ address line         │ 3 pieces         │
│ area, district       │ contents         │
│ city, governorate    │ courier / rep    │
├──────────────────────┴──────────────────┤
│  ║║│║││║║│║││║║│║││║║│║  <barcode>      │
│           WQ-10234                      │
├─────────────────────────────────────────┤
│ Notes: <orders.notes>        [FRAGILE]  │
└─────────────────────────────────────────┘
```

**The banner is the decision worth keeping.** The reference puts the *service class* there ("PRIORITY MAIL"); in a COD business the number the courier must not get wrong is **how much cash to collect**. So it shows `COD <total>`, flipping to `PAID — DO NOT COLLECT` when payment is already collected. That one line prevents double-collection, which is the expensive mistake a label can actually stop.

Every field comes from existing data: logo and warehouse as sender (`warehouses.name/address/phone`), `order->total` + `payment_status` for the banner, the shipping snapshot and four geo relations `OrderController::invoice():225` **already eager-loads**, `Order::itemsCount` for pieces ([Order.php:191](app/Models/Order.php:191)), item names for contents, `orders.notes` for instructions.

**Dropped deliberately** — nothing feeds them, and adding the columns would mean inventing the data-entry step too: *Weight* and *Dimensions* (no parcel-weight column; the only `weight` in the schema is `size_guide_weight_min/max`, the customer's **body** weight for sizing) · *Insurance* and *Signature required* · *Declared value* (the COD banner is the value) · *Email* on both parties (a courier does not use it) · *State/Zip/Country* (Egypt uses governorate → city → district → area, already modelled properly).

**Build:** `OrderController::label()`, a near-copy of `invoice():225` — same eager loads, same `orders.view` permission and `visibleTo()` guard. New route `admin.orders.label`, new page `Orders/Label.tsx` outside `AdminLayout` with its own `@page { size: A6 portrait }` and `window.print()`.

**One package:** `jsbarcode` (npm), CODE128 into an inline `<svg>` — ~30KB, no GD/Imagick in the container, no server round-trip, stays vector so it prints sharp enough to scan. A PHP package (picqer) emits a raster PNG and needs an image extension.

Label text renders in the admin's current locale; recipient address lines print exactly as stored, so an Arabic address stays Arabic whatever the UI language.

**A5 · Size guide follows the selected size** — request #3
The modal already exists and lists every size's weight range at once ([Show.tsx:455](resources/js/storefront/Pages/Product/Show.tsx:455), button at `:216`). You want the range for the size the customer just picked, inline.

Show it next to the size pills the moment `size` state is set — *"M — 70 to 80 kg"* — reading `size_guide_weight_min/max` off the matched variant, which [`ProductPresenter::variant():105`](app/Support/ProductPresenter.php:105) **already sends to the page**. No controller change, no query, no migration: the `variant` `useMemo` at `Show.tsx:60` already resolves it. Keep the full-table modal for customers who want to compare before choosing.

Falls back to `t('common.notSpecified')` when a variant has no range, exactly as the modal does today.

---

## Phase B — Geography ✅ SHIPPED

**B1 · CRUD for governorates / cities / districts / areas** — request #7
Five tables exist (`countries`, `governorates`, `cities`, `districts`, `areas`), all with JSON-translatable `name`, plus [app/Support/GeoTree.php](app/Support/GeoTree.php). Missing: controllers, routes, pages.

Build one nested section under `/admin/geo`, modelled on the existing `Delivery/ShippingRates` controller+form pair. New permission `geo.manage`.
*Watch:* `districts` uses a `status` boolean while the other four use `is_active` — normalise in the UI, not with a migration.
*Watch:* deleting a city that shipping rates or addresses point at — soft-delete and block, don't cascade.

**B2 · Courier fee per city** — request #1 — **collapsed to nothing**
The shipping charged to the customer and the fee owed to the courier are **always the same number**, and the courier takes it at the door. So:

- The per-city price already exists and works — `shipping_rates`.
- It is already snapshotted per order at checkout — `orders.shipping_amount`.
- Therefore **the courier's fee for an order *is* `orders.shipping_amount`.** Nothing to resolve, store or snapshot twice.

**Dropped:** the `shipping_company_rates` table, a second resolver method, `courier_delivery_fee` / `courier_return_fee` columns, the assign-time snapshot. All of it solved a problem that only exists if the two numbers can diverge.

`shipping_companies.delivery_fee` and `.return_fee` become dead — leave the columns, stop reading them (see Phase C).

---

## Phase C — Treasury: the courier keeps the shipping — request #2 ✅ SHIPPED

**The rule.** An order of 100 goods + 50 shipping = 150 at the door. The customer hands the courier 150; the courier **keeps the 50** and hands 100 to the company. Treasury receives **100**. Shipping never enters treasury, and because the fee equals the charge, the courier is paid in full the moment they take the cash — nothing is ever outstanding to them.

### The bug this fixes — the most important line in the phase

[`ConfirmDeliveryResultAction.php:46`](app/Actions/Orders/ConfirmDeliveryResultAction.php:46) defaults to the **gross** total and treats anything less as a short payment:

```php
$collectedAmount ??= (float) $order->total;               // 150
$paymentStatus = $collectedAmount < (float) $payment->amount
    ? PaymentStatus::PartiallyCollected                   // typing 100 lands here
    : PaymentStatus::Collected;
```

An accountant entering 100 today marks the order **Partially Collected**, drops it into the "Awaiting Balance" queue, and chases a 50 EGP debt that does not exist. Every correctly-settled order would look unpaid.

### The change — one rule, one place

The amount due to treasury is `total − shipping_amount`. Put it in **one** accessor on `Order` and read everything through it, the way `scopeVisibleTo()` is the single source of the visibility rule:

```php
/** What the courier actually hands over: goods only — they keep the shipping. */
public function netDueToTreasury(): float
{
    return round((float) $this->total - (float) $this->shipping_amount, 2);
}
```

Then, all in `ConfirmDeliveryResultAction`:

| Line | Change |
|---|---|
| `:46` | `$collectedAmount ??= $order->netDueToTreasury();` |
| `:62` | compare against `netDueToTreasury()`, **not** `payment->amount` — otherwise every order is Partially Collected |
| `:191-205` `dueForKeptItems()` | **stop adding `shipping_amount`** at `:204`. On a partial return the courier already kept their fee at the door; only the kept goods are due |
| `:224-231` `collectBalance()` | outstanding measured against the net figure |

Also wrap each `recordTransaction` call in `if ($amount > 0)` — a zero-goods order (a same-price replacement, or a 100%-coupon order, **which has this bug today**) currently takes a treasury lock and writes a zero row. Root-cause fix, three sites.

The treasury row is unchanged in shape — one Income row, just the correct smaller number.

**Frontend:** [Accounting/Show.tsx](resources/js/admin/Pages/Accounting/Show.tsx) must show the accountant the **net** figure as the amount to collect, with the gross and the courier's share alongside so the number explains itself at the desk. This is where the mistake would otherwise be made.

### Knock-on 1: refused deliveries need no change

On a refused delivery the customer still pays the shipping fee and the courier keeps it. Nothing is owed to the courier out of pocket, and nothing reaches treasury — which is exactly what `confirmReturnedAtDelivery():94` already does. The existing [`AcceptReturnShippingFeeAction`](app/Actions/Returns/AcceptReturnShippingFeeAction.php) is the matching customer-consent step and stays as-is.

### Knock-on 2: reconciliation would double-charge the courier

[`ReconciliationService::buildDraft():32`](app/Services/Treasury/ReconciliationService.php:32) computes `net_amount_expected = expected_customer_collection − delivery_fees_owed − return_fees_owed`. The courier has already paid themselves at the door on **both** delivered and refused orders, so `expected_customer_collection` is already net. Continuing to subtract either fee charges them twice.

**Both `delivery_fees_owed` and `return_fees_owed` become 0.** Reconciliation's remaining job is genuine and unchanged: agreeing the goods cash the courier owes the company.

### Knock-on 3: "we owe them" is silently marked settled — request via Q9, now in scope

[`ReconciliationService::recordTransfer():78`](app/Services/Treasury/ReconciliationService.php:78) sets `status = 'settled'` whenever `outstanding <= 0`. A negative outstanding means *you owe the courier*, and it is currently auto-closed with zero transfers — so nobody ever pays them. Fix:

```php
abs($outstanding) < 0.01 ? 'settled' : 'open'
```

plus a statement UI that can say "we owe them X" rather than showing a negative as paid. With fees now zeroed this is rarer, but it stays a real hole while any adjustment can push a statement negative.

### Knock-on 4: the dashboard revenue tile overstates

[`DashboardController:87`](app/Http/Controllers/Admin/DashboardController.php:87) computes `revenue_this_month` as `sum('total')` over Delivered orders — which includes shipping the couriers kept, money the company never sees. Must become `sum(total) − sum(shipping_amount)`, or it reports revenue you cannot find in the treasury.

### `Payment.amount` stays gross — deliberately

Do **not** change `Payment.amount` away from `order->total`. It records what the customer owed and paid (150), which is true, and is what the invoice, the label's COD banner and the customer's order history must show. Only the share reaching treasury changes.

Keeping it gross has a payoff: `payment->amount − collected_amount` is then exactly the courier's earnings on that order, so courier payout reporting comes free with no extra column, if you ever want it.

---

## Phase D — Accounting & delivery flow

**D1 · Two-step confirm: handover, then delivered** — request #9 ✅ SHIPPED
`OrderStatus::OutForDelivery` **exists in the enum and is never written anywhere** — read by eight filters and guarded for, but no code assigns it. It is the exact home for the handover step.

New `app/Actions/Orders/ConfirmHandoverAction.php` (~25 lines, shaped like `ConfirmOrderAction`): guard `status === Assigned`, write `OutForDelivery` + `customer_status`, append a status-history row. **No new table** — the history row already carries timestamp and employee.

Free win: the customer notification *"out for delivery, have cash ready"* is **already written** at `OrderStatusUpdatedNotification.php:50` and lights up the moment `customer_status` is set. Same for [`OrderTimeline`](app/Support/OrderTimeline.php:25).

`AccountingController::index():42` splits into two queues — `Assigned` (awaiting handover) and `OutForDelivery` (awaiting settlement) — using the named-page-param pattern already in that file. `Accounting/Show.tsx:92` branches: handover button when `Assigned`, the three outcome forms when `Out for Delivery`.

**Not mandatory.** `ConfirmDeliveryResultAction::lockAssignedOrder():262` **already accepts both statuses**, so Accounting can still go straight from `Assigned` to `Delivered` when a courier skips the desk. **No guard change at all** — the feature is purely additive, which also means no migration of orders currently sitting at `Assigned`. Reuses the `orders.confirm_delivery` permission.

Stock is untouched by handover; deduction stays exclusively at Delivered.

**D2 · Bulk settle by courier** — request #8 ✅ SHIPPED
Accounting has **zero** multi-select today. The one working example in the admin is [Delivery/Index.tsx:28-136](resources/js/admin/Pages/Delivery/Index.tsx:28) — checkboxes plus a floating action bar. Clone it, don't reinvent.

Add a representative / shipping-company filter to the Accounting queue, row checkboxes, a running **combined cash total** for the selection (net figures, per Phase C), and one bulk POST settling them against one treasury. Server side: one transaction per order, skip-and-report orders that moved — exactly how `assignBulk():146` already handles it.

**D3 · Reassign the delivery man** — request #12 ✅ SHIPPED
Not possible today for *either* orders or returns. [`AssignDeliveryAction.php:37`](app/Actions/Orders/AssignDeliveryAction.php:37) hard-requires `status === Confirmed`, the form 404s once assigned, and there is no unassign route.

- **Orders:** allow reassign from `Assigned` and `OutForDelivery`; append a second `delivery_assignments` row (the table already supports it) rather than overwriting, so the history survives.
- **Returns:** returns have **no assignee column at all** — [`ReceiveReturnAction`](app/Actions/Returns/ReceiveReturnAction.php) always uses `Warehouse::main()` and nobody is assigned to collect. Add `returns.delivery_representative_id` + `returns.shipping_company_id`, an assign screen, and a reassign action.

---

## Phase E — Returns & replacement

**E1 · Returns go through Checking** — request #10 ✅ SHIPPED
Today: `requested → approved → (received folded into) inspected → refunded`, acted on only by Customer Service, Warehouse Manager and Accounting. No Checking involvement.

**What Checking does on a return:** phones the customer, confirms the **reason** for the return, and — when it is a replacement — confirms the swap with them exactly as it would a new order. So the return checking stage takes the *same three verbs Checking already has on orders*:

| Verb | Meaning on a return | Reuses |
|---|---|---|
| Confirm | Reason verified, customer reached — proceed to collection | `ReturnStatus::Approved` |
| Reschedule | Customer not reachable or wants a later pickup | `ReturnStatus::Requested` + a history note, mirroring `PostponeOrderAction` |
| Cancel | Customer changed their mind, or the reason doesn't hold | **`ReturnStatus::Rejected`** — currently a dead enum value, never written |

That last row is the payoff: `rejected` already exists in `app/Enums/ReturnStatus.php` and has never had a writer. Three of the seven return statuses are dead (`rejected`, `received`, `completed`); this phase and E2 between them put two back to work without adding any.

Build: a Checking queue for returns alongside the order queue, `ReturnController` actions mirroring `CheckingController`'s confirm/postpone/cancel, and `returns.check` permission. The call outcome and reason go in the existing return notes.

**E2 · Replacement cycle** — request #11 ✅ SHIPPED
Zero occurrences of "replacement" or "exchange" in the repo. Shape: **a new Order linked by `replaces_order_id`** — not a flag on the original, not an extension of `OrderReturn`.

The original order is Delivered: stock deducted, payment collected, treasury booked. Forcing its total to zero would destroy a real sale. But the *incoming* item genuinely is a return — so the **existing return chain handles what comes back, and a new order handles what goes out.**

One migration column: `orders.replaces_order_id` (nullable FK). That column **is** the flag; no second boolean.

### Pricing — the customer pays shipping, plus any difference

Not a zero-total order. The returned item's value becomes a **credit**, applied as `discount_amount`:

| Case | subtotal | discount | shipping | total | → treasury | → courier |
|---|---|---|---|---|---|---|
| Same price (100 → 100) | 100 | 100 | 50 | **50** | 0 | 50 |
| Trade up (100 → 200) | 200 | 100 | 50 | **150** | 100 | 50 |
| Trade down (100 → 80) | 80 | 80 | 50 | **50** | 0 | 50 |

`total = subtotal − discount + shipping` holds in every row, so **nothing downstream needs a special case.** This is why the credit goes in `discount_amount` and line items keep their real `unit_price`: zeroing `unit_price` instead would make `subtotal` 0, short-circuit the discount-share guard in `dueForKeptItems():191`, and the system would ask the customer for money on a partial return of a replacement — besides printing worthless goods on the pick list and label.

The credit is **capped at the new item's subtotal**, so a trade-down is never a negative total. The customer is not refunded the difference on a cheaper swap — flagged as an assumption; say so if it should be refunded instead.

New `CreateReplacementOrderAction` calls the existing `CreateOrderAction` untouched, then applies the credit in the same transaction (`CreateOrderAction::execute` already opens one; Laravel nests safely). It also writes `ReturnStatus::Completed` on the return — the second dead status put back to work — so the return leaves the queue without `RefundReturnAction` ever running, which is correct: no money is refunded, and the original order's `payment_status` must **not** become `Refunded`.

Replacements always originate from a return; a goodwill send-out with nothing coming back is not in scope.

### What needs no change at all — the payoff of this shape

Stock out via the normal reserve/deduct path · stock in via the existing return restock · lands in the Checking queue automatically (`status = New`) · lands in the Accounting queue after Confirmed → Assigned · courier paid at the door from the shipping the customer pays, same as any order.

The only guard needed is Phase C's `if ($amount > 0)`, which a same-price replacement (0 goods due) requires — and which a 100%-coupon order needs today regardless.

**UI:** a **"Replacement for #NNNN"** badge with the amount due on Checking Show, Accounting Show, the invoice and the label's COD banner. It is the only thing stopping an employee collecting the wrong amount. Entry point: a "Create replacement" action on [Returns/Show.tsx](resources/js/admin/Pages/Returns/Show.tsx) with a variant picker for the outgoing item, reusing the `returns.refund` permission.

---

## Phase F — Order creation & checking

**F1 · Pick product by colour and size, like the customer does** — request #15 ✅ SHIPPED
Admin order create is a **flat `react-select` of every active variant in the database, eagerly loaded** ([OrderController.php:312](app/Http/Controllers/Admin/OrderController.php:312) ships them all), labelled `"<product> — <sku>"`.

Replace with product-first selection, then colour swatches and size pills — reusing the presenter logic already powering the storefront ([`ProductPresenter::detail/variant/option`](app/Support/ProductPresenter.php:67)). `app/Actions/Search/SearchProductsAction.php` **already exists and is unused by this screen** — wire it to a new `admin.products.search` route instead of shipping the whole catalogue. Combine with A1 so the price appears as soon as a variant resolves.

**F2 · Stock check gates the Confirm button in Checking** — request #20 ✅ SHIPPED
Checking **stays read-only** — it cannot add, remove or re-price order items, and no route to do so will be built.

So this is only the gate. `CheckingController::stockCheck():84` already computes `{required, available, tracked, can_resume}` per line against `Warehouse::main()` — but it runs **only for `Backorder` orders** ([`:72`](app/Http/Controllers/Admin/Checking/CheckingController.php:72)) and gates *Resume*, not *Confirm*. Run it for every order in the queue, render the same per-line panel already built at [Checking/Show.tsx:252](resources/js/admin/Pages/Checking/Show.tsx:252), and disable Confirm when any tracked line is short.

**Correction found while building this:** `quantity - reserved_quantity` is the wrong number to gate Confirm on. Stock is reserved at order creation, so that figure subtracts the order's *own* hold straight back out — a healthy order with exactly enough stock reads as `available: 0` and every order in the queue would have had Confirm locked. `stockCheck()` now also returns `reserved` (this order's hold, taken from the movement ledger and capped by what the inventory row really holds back) and only Confirm adds it in; `can_resume` still reads the bare `available`, because `ResumeBackorderAction` reserves every line from scratch and can't take a hold it already owns twice.

An Advertisement line does **not** block Confirm — selling one with no stock behind it is Question 14's design, and Backorder is where it gets caught. What the gate actually catches: a line that was converted Advertisement → Real while the order sat in the queue, so it now reads tracked with nothing ever reserved for it.

---

## Phase G — Storefront: colour changes the image — request #4 ✅ SHIPPED

The gap: **product images are product-level only.** `Product implements HasMedia`; `ProductVariant` does not, and nothing associates an image with a colour — so picking a swatch at [Show.tsx:184](resources/js/storefront/Pages/Product/Show.tsx:184) leaves the gallery untouched.

No migration needed: Spatie MediaLibrary already stores `custom_properties` JSON on every media row. Tag each product image with the `attribute_value_id` of its colour in the admin product form, then filter the gallery when a swatch is clicked, falling back to all images for untagged colours. Touches [`ProductPresenter::card():46`](app/Support/ProductPresenter.php:46), the gallery at `Show.tsx:100`, and the admin product form.

**Built as:** the tag goes on the media row and the *grouping* is done server-side — `detail()` gained `images_by_color`, a map of colour name → that colour's photos. Keying it on the translated name means the client compares one string against the swatch it already renders; keying the stored tag on `attribute_value_id` means renaming or translating a colour doesn't orphan the photos. `optionValues()` now carries the value's `id` too, and `ProductPresenter::colours()` is public, so the admin form offers exactly the colours the storefront swatches show and neither side decides separately what "its colours" means.

Tagging is its own `PATCH admin/products/{product}/images/{media}`, not part of the product save — the save posts new *files*, and a tag belongs to an image that already exists. New images upload untagged, which is the documented fallback, so there is no per-file control in the dropzone.

> **Sequencing note, resolved:** the uncommitted `ProductController.php` / `Products/Form.tsx` work this phase was waiting on is no longer in the tree.

---

## Phase H — Filtering & export

**H1 · Default date filter = today, on history lists only** — request #18 ✅ SHIPPED
Every admin index is `latest('id')` + `paginate(20)` with **no date limit ever**. Only Orders has a date-range filter at all ([Order.php:216](app/Models/Order.php:216)).

Apply a `date_from = today` default to **Orders, Returns, Activity Log, Treasury**, each with a visible "all dates" reset. **Leave Checking, Accounting and Delivery unfiltered** — they are work queues holding unfinished business from previous days, and defaulting them to today strands orders.

**Built as** one `app/Support/DateRangeFilter.php` rather than four copies of the same defaulting rule. The trick that makes a default *and* a reset fit in one query string, with no second parameter: **absence and emptiness mean different things.** No `date_from` at all means "hasn't chosen" and gets today; `?date_from=` is an explicit choice of all dates. The reset control is simply that empty parameter, so a wide-open list stays bookmarkable.

The one thing that had to be found rather than written: `Orders/Index.tsx`'s `queryParams()` **strips empty values before navigating**, which would have made "all dates" unreachable and silently re-defaulted the list to today on every filter change. Same trap on the Returns status select, the Activity Log search and the Treasury account switch — each now carries the window rather than dropping it. The Returns export carries it too, so a download can't be wider than the table.

Treasury's balance card is deliberately **not** filtered: it is the account's real balance, not the sum of what is on screen.

**H2 · Select orders → export or print** — request #19 ✅ SHIPPED
Exports exist (`maatwebsite/excel`, five export classes, one shared [ExportButton.tsx](resources/js/admin/Components/ExportButton.tsx)) but they export the **filter**, not a selection.

Add row checkboxes to Orders (clone Delivery/Index.tsx again), accept an `ids[]` param in `OrderController::orderFilters():129` alongside the existing filters, and reuse `OrdersExport` unchanged. Print = a batch version of the invoice page, and the same selection drives batch label printing.

**Built as** one more filter, not a second code path: `ids` joins `scopeFiltered()` alongside `status` and the date window, so a selection can only ever *narrow* a query that `visibleTo()` already scoped. There is no per-row authorisation to forget — an id from outside the employee's book simply isn't in the result, which is what the two scope tests assert.

Two print pages (`Orders/Invoices`, `Orders/Labels`) and their singles now share one sheet each — `Components/InvoiceSheet` and `Components/LabelSheet` — rather than a copy that drifts the first time a field is added. The bundle shows it: one `InvoiceSheet` chunk behind both invoice pages.

**One trap found:** `index()` echoes its filters back to the page, so an `ids` left in them would pin the table to the selection *and* keep it pinned through the next filter change. It is stripped there, and only there — the export and both print pages read the same `orderFilters()` untouched.

---

## Out of scope

- **Reports** (request #13) — removed at your request. `DashboardController`'s seven stat tiles stay as they are.
- **Reviews** (request #17) — deferred. Worth knowing when you come back to it: `reviews.moderate` is a seeded permission with **no admin screen and no route**, so nothing can approve a pending review except direct database access — every review is stuck at `pending` and invisible on the storefront. Separately, the admin product page averages *all* reviews including pending, while every storefront page averages approved only, so the same product shows two different ratings.

---

## Suggested order

```
A (quick wins)  ──┬─> C (net collection) ──> E2 (replacement)
                  ├─> B1 (geography CRUD)        ↑
                  ├─> E1 (returns checking) ─────┘
                  └─> D1 (handover) ──> D2, D3
F, G, H are independent — schedule anywhere.
```

**Start with C.** It is the bug that makes correctly-settled orders look unpaid, and its `> 0` treasury
guard is what a same-price replacement needs in E2. E1 before E2 because Checking is where a
replacement gets confirmed with the customer. Nothing else gates anything.

---

## Verification

Everything runs in Docker — no host PHP or Node.

```bash
docker compose run --rm app ./vendor/bin/pest
```

Per phase:

- **A3 (phone):** an 11-digit number passes and 10/12 digits fail, at register, checkout and admin order create.
- **C (net collection):** the 100 + 50 case end to end — the treasury row is **100**, the order lands `Collected` and **not** `PartiallyCollected`, and `dueForKeptItems()` on a partial return excludes shipping. Assert `sum(treasury_transactions.amount) == treasury.current_balance` still holds, and that `buildDraft()` no longer subtracts either fee.
- **C (we-owe fix):** a statement with a negative outstanding stays `open`, not `settled`.
- **D1:** `Assigned → Delivered` still works without handover (it is optional), and `customer_status` is written on handover.
- **E1:** a cancelled return lands in `Rejected` — the first time that status is ever written.
- **E2:** all three pricing rows in the table above produce the stated total; a same-price replacement writes **zero** treasury rows; stock deducts on the outgoing item and restocks on the incoming one; `dueForKeptItems()` returns 0 on a partial return of a replacement.
- **F2:** Confirm is blocked when any tracked line's available quantity is short.

**Manual end-to-end** after C–E, at `:26991`: create an order → Checking confirms → Delivery assigns a courier → Accounting hands over → Accounting confirms delivered, checking the collect field shows the **net** figure → raise a return → Checking calls and confirms it → issue a replacement → confirm the customer owes shipping only, and that no treasury row was written.
