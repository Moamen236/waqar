# WAQAR — Reporting & Analytics Module Specification

Design-only document. Nothing here is implemented yet.

**Source of truth:** the migrations in `database/migrations/`, the enums in `app/Enums/`,
`database/seeders/PermissionSeeder.php`, `app/Services/Inventory/InventoryService.php`, and
`app/Models/Concerns/RecordsActivity.php`.

> **Zero new columns, zero new tables.** Every one of the 46 reports below is computed from data the
> system already stores. **Section C** proves this report by report, including the derivation for each
> figure that does not sit in a column of its own. The only migration this module needs is an
> **index-only** one (Section H.1) — indexes store no new data, they just stop the reports from
> table-scanning. Five reports carry a documented accuracy caveat rather than a blocker; each says so
> on its own header, and each is listed in **C.3**.

---

## A. Reports Module Structure

### A.1 Design rules

1. **One query definition per report, shared by screen + export.** This is already the project's
   idiom: `OrdersExport` calls `Order::filtered()`, the same scope `/admin/orders` uses, "so the
   download can never drift from the table". Reports follow it — a report class owns its query, and
   the Inertia page, the .xlsx, the .csv and the print view all read that one query.
2. **No business logic in report controllers.** Controllers resolve the report, validate filters,
   return Inertia (or hand off to Excel). Same thin-controller convention as the rest of `Admin/`.
3. **Row-level scoping is never re-implemented.** Any report whose rows are orders runs through
   `Order::scopeVisibleTo()`. A Customer Service Team Leader's sales report shows their team's orders
   and nothing else, for free.
4. **Aggregate in SQL.** No `->get()->groupBy()` in PHP on order-volume tables.
5. **Every label is a translation key.** Report names, column headings and enum values render through
   the existing key-lookup (`resources/js/admin/locales/{ar,en}.json`), the same way
   `ActivityLogController` renders event names.

### A.2 File layout

```
app/
  Reports/
    ReportDefinition.php            abstract: key(), permission(), filters(), query(), columns(), totals()
    Concerns/
      AppliesStandardFilters.php    the STD filter bundle → query constraints
      ComparesPeriods.php           previous-period / same-period-last-year second pass
    Orders/          OrdersSummaryReport.php, StatusPipelineReport.php, ...
    Sales/           SalesSummaryReport.php, SalesByProductReport.php, ...
    Inventory/       StockOnHandReport.php, MovementLedgerReport.php, ...
    Returns/         ReturnsSummaryReport.php, ReturnReasonsReport.php, ...
    Finance/         TreasuryBalancesReport.php, CashCollectionReport.php, ...
    Employees/       EmployeeActionLogReport.php, CustomerServiceScorecardReport.php, ...
    Audit/           AuditTrailReport.php, SensitiveChangesReport.php, ...
  Http/
    Controllers/Admin/Reports/
      ReportController.php          index (catalogue), show (render), export, print
    Requests/Admin/ReportFilterRequest.php
  Exports/Reports/
    ReportExport.php                one generic FromQuery+WithHeadings+WithMapping over a ReportDefinition
resources/js/admin/
  Pages/Reports/
    Index.tsx                       the report catalogue, permission-filtered
    Show.tsx                        filter bar + table + totals + chart slot
    Print.tsx                       print-CSS layout (same data, no chrome)
  Components/
    ReportFilterBar.tsx             the STD filter bundle as one component
    PeriodComparison.tsx            current vs comparison delta cells
routes/admin.php                    reports.* group
```

Four routes total, not one per report:

```php
Route::get('reports',                  [ReportController::class, 'index'])->name('reports.index');
Route::get('reports/{report}',         [ReportController::class, 'show'])->name('reports.show');
Route::get('reports/{report}/export',  [ReportController::class, 'export'])->name('reports.export');
Route::get('reports/{report}/print',   [ReportController::class, 'print'])->name('reports.print');
```

`{report}` is the report key (`orders.summary`, `sales.by-product`, …). `ReportController` resolves
it from a registry and aborts 403 unless the employee holds that report's own permission.

### A.3 The standard filter bundle (`STD`)

Referenced by every report below instead of being repeated 46 times. Every key is optional; blank
values are ignored — same convention as `Order::scopeFiltered()`.

| Filter | Values |
|---|---|
| `preset` | `today`, `yesterday`, `this_week`, `last_week`, `this_month`, `last_month`, `this_quarter`, `this_year`, `last_year`, `custom` |
| `date_from` / `date_to` | dates, inclusive; required when `preset=custom` |
| `date_basis` | `placed` (`orders.created_at`) · `delivered` (`order_status_history` → `to_status='Delivered'`) · `collected` (`payments.collected_at`) — **declared per report, see B** |
| `compare_to` | `none`, `previous_period`, `same_period_last_year` |
| `granularity` | `day`, `week`, `month`, `quarter`, `year` (drives the time bucket) |
| `order_source` | `website`, `customer_service` |
| `status[]` | `OrderStatus` cases |
| `payment_status[]` | `PaymentStatus` cases |
| `employee_id[]` | meaning is per-report (creator / actor / assigner) — stated in each spec |
| `customer_id` or `customer` | id, or name/phone/email search |
| `governorate_id`, `city_id`, `district_id`, `area_id` | order shipping destination snapshot |
| `warehouse_id[]` | via `inventory_movements.warehouse_id` on the order's `reservation` row — see C.1 §1 |
| `category_id[]`, `product_id[]`, `variant_id[]` | through `product_categories` → `products` → `product_variants` |
| `representative_id[]`, `shipping_company_id[]`, `assignment_type` | who carries it |
| `coupon_id`, `promotion_id` | discount attribution |

**`date_basis` is the single most important filter decision in this module.** "Orders in September"
and "revenue in September" are different questions against different columns. Every report below
declares its default basis and whether the user may change it.

### A.4 Revenue recognition rules (used by every money figure)

Fixed by two existing decisions — stock deducts on Accounting-confirmed *Delivered* only (CLAUDE.md),
and `DashboardController` already computes revenue as Delivered-only.

**Phase C has shipped** (`feature-backlog-plan.md` §C, verified in `ConfirmDeliveryResultAction` and
`Order::netDueToTreasury()`). The courier keeps the shipping at the door, so:

| Column | Means | Basis |
|---|---|---|
| `orders.total` | what the customer hands over | **gross** (goods + shipping) |
| `orders.shipping_amount` | the courier's fee, snapshotted at checkout | pass-through |
| `payments.amount` | what the customer paid — kept gross for the invoice | **gross** |
| `payments.collected_amount` | what actually reached a treasury | **net — goods only** |

```
Gross customer payment = SUM(payments.amount)                       -- invoice view
Courier fees (memo)    = SUM(orders.shipping_amount)                -- never enters treasury
Recognised revenue     = SUM(payments.collected_amount)             -- ALREADY net of shipping
                         WHERE payments.status IN ('collected','partially_collected')
Refunds                = SUM(refunds.net_amount) WHERE refunds.status = 'completed'
Net revenue            = Recognised revenue − Refunds
Net due on an order    = orders.total − orders.shipping_amount      -- Order::netDueToTreasury()
Discount given         = SUM(orders.discount_amount)
AOV                    = Net revenue ÷ COUNT(DISTINCT delivered orders)
```

⚠ **Never compare `collected_amount` against `payments.amount`.** One is net, the other gross — the
comparison marks every correctly-settled order short by exactly the shipping. This was the original
Phase C bug and it is just as easy to reintroduce in a report as it was in the Action. Any shortfall
or outstanding figure compares against `payments.amount − orders.shipping_amount`, mirroring
`Order::netOfShipping()`. `ConfirmDeliveryResultAction` carries the same warning in a comment.

⚠ **Shipping is not a P&L line.** The customer pays the courier through the company's order, and the
money never touches a treasury. It is neither revenue nor expense — it appears only as the memo line
above. Reports must not add it to revenue *or* subtract it as a delivery cost.

**Historical note.** Before Phase C, `collected_amount` held the gross total and reports would have
needed to subtract shipping. Orders settled before Phase C shipped still carry gross
`collected_amount` values. Any report crossing that boundary will show a step change in revenue that
is an artefact of the fix, not a business event — **SAL-01 and FIN-02 flag periods spanning the
Phase C deploy date** rather than silently averaging two conventions together.

### A.5 Localization

- Report keys, names, descriptions, column headings, filter labels → `resources/js/admin/locales/{ar,en}.json`.
- Enum values (`OrderStatus`, `PaymentStatus`, `InventoryMovementType`, `ReturnStatus`, activity events)
  render through the existing key-lookup, never as stored English.
- Export headings are translated **server-side** from `lang/{ar,en}` in `ReportExport::headings()`,
  using the request locale — an Arabic user's .xlsx has Arabic headers.
- Translatable entity names (`products.name`, `categories.name`, `expense_categories.name`,
  `return_reasons.name`) are JSON columns; select and sort on `name->$."{locale}"`, not on the raw JSON.
- Print views inherit `<html dir>` from the root view, which already reads `app()->getLocale()`.
- Numbers/currency/dates formatted per locale; **exports write raw numerics**, not formatted strings,
  so Excel can still sum the column.

---

## B. Report-by-Report Specification

Format per report: **Purpose → KPIs → Sources → Filters → Columns → Formulas → Group/Sort →
Export → Permission → Roles.** `STD` = the bundle in A.3. All reports export xlsx + csv + print
unless noted; "Roles" lists defaults a Super Admin can change in the existing
`/admin/roles/{role}` matrix editor.

---

### 1. Orders Reports

#### ORD-01 · Orders Summary
- **Purpose** — one line per period bucket: how many orders came in, what happened to them, what they were worth.
- **KPIs** — orders placed, delivered, cancelled, returned; gross order value; AOV; cancellation rate; delivery rate.
- **Sources** — `orders`, `order_items` (unit count).
- **Filters** — STD. `date_basis` = `placed` (changeable). `compare_to` supported.
- **Columns** — Period · Orders · Units · Gross value · Discount · Shipping · Net value · Delivered · Cancelled · Returned · Cancellation % · Delivery %.
- **Formulas** — `Cancellation % = Cancelled ÷ Orders`; `Delivery % = Delivered ÷ Orders`; `Units = SUM(order_items.quantity)`.
- **Group** — by `granularity`; secondary by `order_source`, governorate, status.
- **Sort** — period asc (default), any metric desc.
- **Permission** — `reports.orders.view` · **Roles** — Chairman, Vice Chairman, CS Team Leader, Checking, Delivery Manager, Accounting.

#### ORD-02 · Order Status Pipeline & Ageing
- **Purpose** — what is stuck, where, and for how long. The operational queue view.
- **KPIs** — open orders per status; oldest order age per status; count breaching an age bucket.
- **Sources** — `orders`, `order_status_history` (last transition per order).
- **Filters** — STD minus `date_basis` (this is a snapshot of *now*). `status[]`, `order_source`, geo, `employee_id[]` (= `created_by_employee_id`), carrier.
- **Columns** — Status · Orders · Value · Avg age (days) · Oldest · 0–1d · 2–3d · 4–7d · 8+d.
- **Formulas** — `age = NOW() − (latest order_status_history.created_at for that order)`.
- **Group** — status; optional second dimension: source, governorate, creating employee.
- **Sort** — oldest-first (default).
- **Permission** — `reports.orders.view` · **Roles** — Chairman, Checking, Delivery Manager, CS Team Leader, Store Orders (website rows only, via `visibleTo`).

#### ORD-03 · Order Lifecycle & SLA
- **Purpose** — how long each hand-off actually takes: New→Checking→Confirmed→Assigned→Out for Delivery→Delivered.
- **KPIs** — avg/median/p90 hours per transition; total lead time placed→delivered; % within target.
- **Sources** — `order_status_history` (self-join on consecutive rows per order), `orders`.
- **Filters** — STD, `date_basis` = `placed`. Plus `employee_id[]` = `order_status_history.changed_by`.
- **Columns** — Transition · Orders · Avg hrs · Median hrs · p90 hrs · Max hrs · Slowest order #.
- **Formulas** — `TIMESTAMPDIFF(HOUR, prev.created_at, next.created_at)` over the ordered history per order; medians via window function (MySQL 8).
- **Group** — transition; optional by carrier, governorate, creating employee.
- **Sort** — avg hrs desc.
- **Export** — xlsx/csv/print. **Permission** — `reports.orders.view` · **Roles** — Chairman, Checking, Delivery Manager, Accounting.
- **Note** — fully derivable today; needs index **P-03**. No `confirmed_at`/`delivered_at` columns exist and none are needed.

#### ORD-04 · Cancellation, Postponement & Backorder Analysis
- **Purpose** — why orders fall out of the funnel, and who is recording it.
- **KPIs** — cancelled / postponed / backordered counts and value; top reasons; rate by source, area, agent.
- **Sources** — `order_status_history` (`to_status IN ('Cancelled','Postponed','Backorder')`, `reason`, `notes`, `changed_by`), `orders`.
- **Filters** — STD, `date_basis` = the transition date. `employee_id[]` = `changed_by`.
- **Columns** — Reason · To status · Orders · Value · % of period orders · Top area · Recorded by.
- **Formulas** — `rate = transitions ÷ orders placed in period`.
- **Group** — reason, status, source, area, employee.
- **Sort** — count desc.
- **Permission** — `reports.orders.view` · **Roles** — Chairman, Checking, CS Team Leader, Delivery Manager.
- **Note** — `order_status_history.reason` is a free-text `string`. **Primary grouping is therefore `to_status`, which is a clean enum**; reason text is a secondary breakdown with a "top 20 + other" cut, and appears in full on the detail rows. Nothing is missing — the report just doesn't pretend free text is a taxonomy.

#### ORD-05 · Delivery Performance by Carrier
- **Purpose** — compare representatives against shipping companies, and each other.
- **KPIs** — assigned, delivered, returned, refused; delivery success rate; avg assign→deliver hours; COD collected; return rate.
- **Sources** — `delivery_assignments`, `orders`, `order_status_history`, `payments`.
- **Filters** — STD, `date_basis` = `delivered`. `assignment_type`, `representative_id[]`, `shipping_company_id[]`, geo.
- **Columns** — Carrier · Type · Assigned · Delivered · Returned · Success % · Avg hrs to deliver · COD collected · Uncollected · Areas served.
- **Formulas** — `Success % = Delivered ÷ Assigned`; hours from `delivery_assignments.assigned_at` → the `Delivered` history row.
- **Group** — carrier; optional by governorate/city/area.
- **Sort** — success % desc, volume desc.
- **Permission** — `reports.orders.view` · **Roles** — Chairman, Delivery Manager, Accounting.

#### ORD-06 · Order Source Comparison (Website vs Customer Service)
- **Purpose** — how the two channels differ in value, mix and outcome. The one report that exists purely to contrast `order_source`.
- **KPIs** — orders, units, AOV, discount rate, cancellation rate, delivery rate, return rate — each split website vs customer_service.
- **Sources** — `orders`, `order_items`, `returns`.
- **Filters** — STD, `date_basis` = `placed`, `compare_to` supported. `order_source` is the axis, not a filter.
- **Columns** — Metric · Website · Customer Service · Δ · Δ%.
- **Formulas** — as ORD-01, computed twice and pivoted.
- **Group** — metric rows; optional secondary split by period bucket or governorate.
- **Sort** — fixed metric order.
- **Permission** — `reports.orders.view` · **Roles** — Chairman, Vice Chairman, CS Team Leader, Store Orders.

---

### 2. Sales Reports

*Sales reports recognise revenue on Delivered (A.4). ORD reports count orders; SAL reports count money.*

#### SAL-01 · Sales Summary
- **Purpose** — the revenue line, by period, with comparison.
- **KPIs** — net revenue, merchandise revenue, units sold, orders delivered, AOV, avg units/order, discount rate, refund rate.
- **Sources** — `orders`, `order_items`, `payments`, `refunds`, `order_status_history` (delivery date).
- **Filters** — STD, `date_basis` = `delivered` (default) or `collected`. `compare_to` supported.
- **Columns** — Period · Delivered orders · Units · Gross · Discount · Shipping · Recognised · Refunds · **Net revenue** · AOV.
- **Formulas** — A.4 in full.
- **Group** — `granularity`; secondary by source, governorate, carrier.
- **Sort** — period asc.
- **Permission** — `reports.sales.view` · **Roles** — Chairman, Vice Chairman, Accounting.

#### SAL-02 · Sales by Product & Variant
- **Purpose** — what actually sells, at SKU granularity.
- **KPIs** — units sold, revenue, orders containing the item, avg selling price, discount depth, return rate.
- **Sources** — `order_items` → `product_variants` → `products`, `orders` (status/date filter), `return_items`.
- **Filters** — STD, `date_basis` = `delivered`. `category_id[]`, `product_id[]`, `variant_id[]`, `warehouse_id[]`.
- **Columns** — SKU · Product · Variant attributes · Units · Orders · Revenue · Avg price · List price · Discount % · Units returned · Return %.
- **Formulas** — `Revenue = SUM(order_items.subtotal)` on delivered orders; `Avg price = Revenue ÷ Units`; `Return % = returned units ÷ sold units`.
- **Group** — variant (default) or roll up to product.
- **Sort** — revenue desc, units desc, return % desc.
- **Permission** — `reports.sales.view` · **Roles** — Chairman, Vice Chairman, Warehouse Manager.
- **Note** — use `order_items.product_name_snapshot` / `variant_sku_snapshot` for display so renamed products don't rewrite history; join to `product_variants` only for grouping/filtering.

#### SAL-03 · Sales by Category
- **Purpose** — category mix and contribution. Roll-up of SAL-02 along a different axis, not a duplicate of it.
- **KPIs** — revenue, units, share of total, orders, category return rate.
- **Sources** — `order_items` → `product_variants` → `products` → `product_categories` → `categories`.
- **Filters** — STD, `date_basis` = `delivered`, `category_id[]` (subtree), `compare_to`.
- **Columns** — Category · Parent · Units · Orders · Revenue · Share % · Δ vs comparison · Return %.
- **Formulas** — `Share % = category revenue ÷ period revenue`. A product in N categories contributes to each — **totals are therefore not additive across rows**; the report footer shows the de-duplicated period total separately.
- **Group** — category; optional top-level rollup via `categories.parent_id`.
- **Sort** — revenue desc.
- **Permission** — `reports.sales.view` · **Roles** — Chairman, Vice Chairman.

#### SAL-04 · Sales & Orders by Geography
- **Purpose** — where demand and money are, at every level of the geo tree. Combines volume and value so there is no separate "orders by area" report.
- **KPIs** — orders, delivered orders, revenue, AOV, delivery success rate, avg shipping charged.
- **Sources** — `orders` (shipping geo snapshot FKs), `governorates`/`cities`/`districts`/`areas`, `payments`.
- **Filters** — STD, geo drill level (`governorate|city|district|area`), `date_basis` = `delivered`.
- **Columns** — Geography · Orders · Delivered · Revenue · AOV · Success % · Avg shipping · Return %.
- **Formulas** — A.4 grouped by the chosen geo column.
- **Group** — chosen level, drill-down to the next.
- **Sort** — revenue desc.
- **Permission** — `reports.sales.view` · **Roles** — Chairman, Vice Chairman, Delivery Manager, Accounting.

#### SAL-05 · Discounts, Coupons & Promotions
- **Purpose** — what the discounting is buying.
- **KPIs** — discounted order count and share; total discount given; discount as % of gross; per-coupon redemptions and revenue; per-promotion attached units.
- **Sources** — `orders` (`coupon_id`, `discount_amount`), `coupons`, `order_items.promotion_id`, `promotions`.
- **Filters** — STD, `coupon_id`, `promotion_id`, `date_basis` = `placed`.
- **Columns** — Coupon/Promotion · Type · Value · Orders · Units · Gross · Discount given · Net revenue · Discount % · Redemptions left.
- **Formulas** — `Discount % = discount ÷ gross`; `Redemptions left = coupons.usage_limit − coupons.times_used` (null = unlimited).
- **Group** — coupon, promotion, discount type, period.
- **Sort** — discount given desc.
- **Permission** — `reports.sales.view` · **Roles** — Chairman, Vice Chairman.

#### SAL-06 · Gross Margin
- **Purpose** — profitability per product/category, not just revenue.
- **KPIs** — revenue, COGS, gross profit, margin %.
- **Sources** — `order_items`, `product_variants.cost_price` → fallback `products.cost_price`.
- **Filters** — STD, `date_basis` = `delivered`, category/product/variant.
- **Columns** — SKU · Product · Units · Revenue · Unit cost · COGS · Gross profit · Margin %.
- **Formulas** — `COGS = SUM(quantity × COALESCE(variant.cost_price, product.cost_price))`; `Margin % = (Revenue − COGS) ÷ Revenue`.
- **Group** — variant, product, category, period.
- **Sort** — gross profit desc, margin % asc (to surface loss-makers).
- **Permission** — `reports.sales.view` **and** `reports.cost.view` (cost price is documented internal-only in the schema) · **Roles** — Chairman, Vice Chairman, Accounting.
- **⚠ Accuracy caveat, not a blocker.** `order_items` carries no cost snapshot, so COGS is computed at
  **today's** `cost_price`, not the cost in force when the order shipped. Editing a cost price
  retroactively moves historical margin. Two things make this workable now: cost prices in fashion
  retail change rarely, and **`cost_price` changes are already audit-logged** on both `Product` and
  `ProductVariant` (`activityLogAttributes()` includes it on each), so AUD-03 shows exactly when a
  cost moved and by how much — a reader can always tell whether a margin restatement is real. The
  report header states "costed at current cost price"; the footer links to the AUD-03 cost-change
  view for the same period. Rows whose SKU had a cost change inside the reporting window are flagged.

#### SAL-07 · Customer Sales & Retention
- **Purpose** — who buys, how often, how much.
- **KPIs** — active customers, new vs returning, orders/customer, revenue/customer, repeat rate, top customers, guest share.
- **Sources** — `customers` (`is_guest`), `orders`, `payments`, `returns`.
- **Filters** — STD, `customer_id`, `order_source`, geo, `date_basis` = `delivered`.
- **Columns** — Customer · Phone · Type (guest/registered) · First order · Last order · Orders · Units · Net revenue · AOV · Returns · Return %.
- **Formulas** — `New = customers whose first order falls in the period`; `Repeat rate = customers with ≥2 delivered orders ÷ customers with ≥1`.
- **Group** — customer (default), or summary mode: new/returning/guest cohorts by period.
- **Sort** — net revenue desc, orders desc.
- **Permission** — `reports.sales.view` + `customers.view` · **Roles** — Chairman, Vice Chairman, CS Team Leader (own team's orders only, via `visibleTo`).

---

### 3. Products & Inventory Reports

#### INV-01 · Stock on Hand & Valuation
- **Purpose** — what is in each warehouse right now and what it is worth.
- **KPIs** — total SKUs, total units, reserved units, available units, stock value at cost, stock value at retail.
- **Sources** — `warehouse_inventory`, `product_variants`, `products`, `warehouses`.
- **Filters** — `warehouse_id[]`, `category_id[]`, `product_id[]`, `variant_id[]`, product status, SKU search, `available` range. Snapshot — no date range.
- **Columns** — Warehouse · SKU · Product · On hand · Reserved · **Available** · Unit cost · Cost value · Unit price · Retail value.
- **Formulas** — `Available = quantity − reserved_quantity` (computed at read, never stored — existing rule); `Cost value = quantity × COALESCE(variant.cost_price, product.cost_price)`.
- **Group** — warehouse, category, product.
- **Sort** — available asc (default), cost value desc.
- **Permission** — `reports.inventory.view`; cost columns additionally require `reports.cost.view` · **Roles** — Chairman, Warehouse Manager, Accounting (cost), Vice Chairman.

#### INV-02 · Low & Out of Stock
- **Purpose** — the reorder worklist.
- **KPIs** — out-of-stock SKUs, SKUs below threshold, oversold/negative-available count, value at risk (30-day velocity × price).
- **Sources** — `warehouse_inventory`, `inventory_movements` (velocity), `order_items` (velocity).
- **Filters** — `warehouse_id[]`, category/product, threshold override, "include zero-velocity".
- **Columns** — Warehouse · SKU · Product · Available · Reserved · Units sold (30d) · Days of cover · Status (OK/Low/Out).
- **Formulas** — `Daily velocity = units sold in last 30d ÷ 30`; `Days of cover = Available ÷ velocity` (∞ when velocity 0).
- **Group** — warehouse, status band.
- **Sort** — days of cover asc.
- **Permission** — `reports.inventory.view` · **Roles** — Warehouse Manager, Chairman, Vice Chairman, Checking.
- **Threshold source — already exists.** `InventoryService::flagLowStock()` reads
  `config('inventory.low_stock_threshold')` to decide when to notify Warehouse Manager / Vice Chairman.
  **This report uses that same config value**, so the report and the alerts can never disagree about
  what "low" means. The filter bar allows a per-run override for what-if analysis; the config value is
  the default. Days-of-cover is offered as a second, velocity-aware band. No reorder-point column needed.

#### INV-03 · Inventory Movement Ledger
- **Purpose** — every stock change, auditable, with its cause and its actor. The inventory counterpart of the audit trail.
- **KPIs** — movements by type; units in; units out; net change; movements with no actor (system).
- **Sources** — `inventory_movements` (+ polymorphic `reference_type`/`reference_id` → `Order`, `OrderReturn`, `StockTransfer`), `employees`, `warehouses`, `product_variants`.
- **Filters** — STD dates on `created_at`, `warehouse_id[]`, `type[]` (`InventoryMovementType`), `variant_id[]`, `category_id[]`, `employee_id[]` (= `created_by`), reference type.
- **Columns** — Date/time · Warehouse · SKU · Product · Type · Qty (signed) · Balance after¹ · Reference (type + link) · Performed by · Notes.
- **Formulas** — `Units in = SUM(quantity) WHERE quantity > 0`; `Units out = ABS(SUM(...)) WHERE quantity < 0`; `Net = SUM(quantity)`.
- **Group** — type, warehouse, SKU, day, employee.
- **Sort** — date desc.
- **Permission** — `reports.inventory.view` · **Roles** — Warehouse Manager, Chairman, Accounting.
- ¹ Running balance is a window function over the filtered set; correct only when the filter is a single warehouse+variant. Shown only in that mode.

#### INV-04 · Stock Transfers
- **Purpose** — inter-warehouse movement, and transfers stuck in flight.
- **KPIs** — transfers by status, units moved, avg request→received days, open transfers, in-transit value.
- **Sources** — `stock_transfers`, `stock_transfer_items`, `warehouses`, `employees`.
- **Filters** — date range, `from_warehouse_id`, `to_warehouse_id`, `status[]` (`StockTransferStatus`), `employee_id[]` (= `requested_by` / `approved_by`), variant/category.
- **Columns** — Transfer # · From · To · Status · SKUs · Units · Value · Requested by · Requested at · Approved by · Age (days).
- **Formulas** — `Age = NOW() − created_at` for non-completed; `Units = SUM(stock_transfer_items.quantity)`; `Value` at cost price.
- **Group** — status, route (from→to), warehouse.
- **Sort** — age desc.
- **Permission** — `reports.inventory.view` · **Roles** — Warehouse Manager, Chairman.
- **⚠ Not a data gap — a workflow that isn't built yet.** `stock_transfers` / `stock_transfer_items`,
  the `StockTransferStatus` enum, the model, `StockTransferSeeder` and the `inventory.transfer`
  permission all exist, but **there is no route, controller or Action that creates or advances a stock
  transfer** — `InventoryController` has only `index`, `export` and `adjust`, and
  `InventoryMovementType::TransferIn`/`TransferOut` are never written by any code. The report is
  writable today and correct against the table, but in production it will show only seeded rows until
  the transfer feature itself is built. **Recommendation: defer INV-04 to whenever stock transfers
  ship.** Building a report for a screen nobody can reach is the one kind of waste worth refusing.

#### INV-05 · Stock Turnover, Ageing & Dead Stock
- **Purpose** — capital tied up in stock that isn't moving. Distinct from SAL-02: that ranks by revenue, this ranks by **velocity relative to stock held**.
- **KPIs** — turnover ratio, days of inventory, dead SKUs (zero sales in N days), ageing buckets, dead-stock value.
- **Sources** — `warehouse_inventory`, `inventory_movements` (first inbound date = age), `order_items`.
- **Filters** — `warehouse_id[]`, category/product, "no sales since" window (30/60/90/180d), min stock value.
- **Columns** — SKU · Product · On hand · Cost value · Units sold (period) · Turnover · Days of inventory · Last sold · Days since last sale · Age bucket.
- **Formulas** — `Turnover = units sold in period ÷ avg on hand`; `Days of inventory = period days ÷ turnover`; age from earliest `purchase`/`transfer_in` movement for that warehouse+variant.
- **Group** — warehouse, category, age bucket.
- **Sort** — days since last sale desc, cost value desc.
- **Permission** — `reports.inventory.view` + `reports.cost.view` for value columns · **Roles** — Chairman, Vice Chairman, Warehouse Manager.

#### INV-06 · Shrinkage & Adjustments
- **Purpose** — units lost to `damaged`, `lost` and manual `adjustment`, and who recorded them. A control report, deliberately separate from the full ledger.
- **KPIs** — units and value written off by type; adjustments per employee; top SKUs; shrinkage as % of units sold.
- **Sources** — `inventory_movements` where `type IN ('damaged','lost','adjustment')`, `employees`.
- **Filters** — STD dates, `warehouse_id[]`, `type[]`, `employee_id[]`, variant/category, min quantity.
- **Columns** — Date · Warehouse · SKU · Type · Qty · Cost value · Recorded by · Notes · Reference.
- **Formulas** — `Shrinkage % = ABS(shrinkage units) ÷ units sold in period`.
- **Group** — type, warehouse, employee, SKU.
- **Sort** — cost value desc.
- **Permission** — `reports.inventory.view` · **Roles** — Chairman, Warehouse Manager, Accounting.

#### INV-07 · Reservation Integrity
- **Purpose** — catch reservations that never got released or deducted. A data-health report, not a business report — it is the one that prevents phantom out-of-stock.
- **KPIs** — total reserved units; reserved units with no matching open order; orders in a terminal status still holding reservations; variance between `reserved_quantity` and summed reservation movements.
- **Sources** — `warehouse_inventory.reserved_quantity`, `inventory_movements` (`reservation` / `release` / `sale`), `orders`.
- **Filters** — `warehouse_id[]`, variant, "orphans only".
- **Columns** — Warehouse · SKU · Reserved (stored) · Reserved (from movements) · Variance · Orphan orders · Oldest orphan date.
- **Formulas** — `Reserved (from movements) = SUM(reservation) − SUM(release) − SUM(sale reservations consumed)`; orphan = reservation movement whose referenced order is `Cancelled`/`Delivered`/`Returned` with no matching release/sale.
- **Group** — warehouse, SKU.
- **Sort** — variance desc.
- **Permission** — `reports.inventory.view` · **Roles** — Warehouse Manager, Super Admin, Chairman.

---

### 4. Returns Reports

#### RET-01 · Returns Summary
- **Purpose** — return volume and cost by period.
- **KPIs** — returns raised, by stage (`at_delivery` vs `post_delivery`), by status; units returned; return rate; refund value; shipping fees recovered.
- **Sources** — `returns`, `return_items`, `orders`, `refunds`.
- **Filters** — STD, `stage`, `status[]` (`ReturnStatus`), `reason_id`, geo, `order_source`, `customer_id`, `compare_to`.
- **Columns** — Period · Returns · At-delivery · Post-delivery · Units · Order value returned · Refund net · Shipping fees charged · Return rate %.
- **Formulas** — `Return rate = returns raised ÷ delivered orders in period`; `Order value returned = SUM(return_items.quantity × order_items.unit_price)`.
- **Group** — `granularity`, stage, status, source.
- **Sort** — period asc.
- **Permission** — `reports.returns.view` · **Roles** — Chairman, Warehouse Manager, Accounting, CS Team Leader.

#### RET-02 · Return Reasons
- **Purpose** — why goods come back. Feeds catalogue and QC decisions.
- **KPIs** — returns and units per reason; reason share; reason mix by stage; top product per reason.
- **Sources** — `return_reasons`, `returns.reason_id`, `return_items.reason_id` (item-level reason can differ from header).
- **Filters** — STD, `stage`, `reason_id[]`, category/product/variant, geo, carrier.
- **Columns** — Reason (localized) · Returns · Units · Share % · Refund value · Top product · Top area · Top carrier.
- **Formulas** — `Share % = reason units ÷ total returned units`.
- **Group** — reason; secondary by product, category, carrier, area.
- **Sort** — units desc.
- **Permission** — `reports.returns.view` · **Roles** — Chairman, Vice Chairman, Warehouse Manager.

#### RET-03 · Product Return Rate
- **Purpose** — which SKUs come back disproportionately.
- **KPIs** — units sold, units returned, return rate, refund value, dominant reason.
- **Sources** — `return_items` → `product_variants`, `order_items`.
- **Filters** — STD, category/product/variant, `warehouse_id[]`, min units sold (to suppress noise), `stage`.
- **Columns** — SKU · Product · Units sold · Units returned · **Return %** · Refund value · Top reason · Trend vs comparison.
- **Formulas** — `Return % = returned units ÷ sold units` over the same period and filter set.
- **Group** — variant, product, category.
- **Sort** — return % desc (with min-volume guard).
- **Permission** — `reports.returns.view` · **Roles** — Chairman, Vice Chairman, Warehouse Manager.

#### RET-04 · Refunds Register & Liability
- **Purpose** — every refund, its method, its status, and what is still owed to customers.
- **KPIs** — refunds pending vs completed; pending liability; refunds by method; fees deducted; avg approve→refund days.
- **Sources** — `refunds`, `returns`, `orders`, `employees` (`processed_by`).
- **Filters** — STD dates on `created_at`/`processed_at`, `status` (`RefundStatus`), `method` (`RefundMethod`), `employee_id[]` (= `processed_by`), `customer_id`, amount range.
- **Columns** — Refund # · Return # · Order # · Customer · Amount · Return shipping fee · **Net amount** · Method · Status · Processed by · Processed at · Reference #.
- **Formulas** — `Pending liability = SUM(net_amount) WHERE status = 'pending'`; `net_amount = amount − return_shipping_fee` (already stored — the report reads it, does not recompute it).
- **Group** — status, method, processor, period.
- **Sort** — created desc; pending first.
- **Permission** — `reports.finance.view` or `reports.returns.view` · **Roles** — Accounting, Chairman, Warehouse Manager.

#### RET-05 · Return Processing Cycle Time
- **Purpose** — how long a return sits at each stage, and where it stalls.
- **KPIs** — avg days requested→approved, approved→received, received→inspected, inspected→refunded; open returns by stage; oldest open return.
- **Sources** — `returns`, `refunds.processed_at`, `activity_log` (subject = `OrderReturn`, `log_name = 'orders'`) for the intermediate status timestamps.
- **Filters** — STD, `stage`, `status[]`, `reason_id`, `employee_id[]` (= activity causer).
- **Columns** — Stage transition · Returns · Avg days · Median · Max · Open now · Oldest open.
- **Formulas** — consecutive status-change timestamps per return.
- **Group** — transition; secondary by reason, stage.
- **Sort** — avg days desc.
- **Permission** — `reports.returns.view` · **Roles** — Warehouse Manager, Accounting, Chairman.
- **Derivation — verified available.** `OrderReturn` uses `RecordsActivity` with
  `activityLogAttributes()` returning `['order_id','customer_id','stage','status','reason_id','return_shipping_fee']`.
  **`status` is logged**, so every transition writes an `activity_log` row carrying old status, new
  status, causer (the employee) and timestamp — precisely the four fields this report needs. The final
  step also has first-class columns (`refunds.processed_by`, `refunds.processed_at`). No new table
  required.
- **Caveat to print on the header** — `config/activitylog.php` retains entries for 1 year, so
  transitions older than that are not available. The report states its own retention window (see C.2).

---

### 5. Treasury / Financial Reports

#### FIN-01 · Treasury Balances & Movement
- **Purpose** — opening/closing balance per treasury account, and every movement in between.
- **KPIs** — opening balance, income, expense, transfers in/out, adjustments, closing balance, variance vs `treasuries.current_balance`.
- **Sources** — `treasuries`, `treasury_transactions`, `treasury_transfers`.
- **Filters** — STD dates, `treasury_id[]`, `type[]` (`TreasuryTransactionType`), `employee_id[]` (= `created_by`), amount range, reference type.
- **Columns** — Treasury · Type (cash/bank/wallet) · Opening · Income · Expense · Transfer in · Transfer out · Adjustments · **Closing** · Stored balance · Variance.
- **Formulas** — `Closing = Opening + income + transfer_in − expense − transfer_out ± adjustment`; `Variance = Closing − treasuries.current_balance` (non-zero = investigate; this is the reconciliation control).
- **Group** — treasury; drill to transaction list.
- **Sort** — closing desc.
- **Permission** — `reports.finance.view` + `treasury.view` · **Roles** — Chairman, Accounting, Super Admin.

#### FIN-02 · COD Cash Collection
- **Purpose** — cash actually collected against cash expected, by day, carrier and collector.
- **KPIs** — expected collection, collected, partially collected, not collected, collection rate, collection by method.
- **Sources** — `payments`, `orders`, `delivery_assignments`, `treasury_transactions` (`reference_type = Payment` → who posted it).
- **Filters** — STD, `date_basis` = `collected` (`payments.collected_at`), `payment_status[]`, `collected_method`, `collection_type`, carrier, geo, `employee_id[]` (= `treasury_transactions.created_by`).
- **Columns** — Date · Order # · Customer · Carrier · Customer paid (gross) · Courier fee · **Net due** · Collected · Shortfall · Method · Collection type · Treasury · Posted by.
- **Formulas** — `Net due = payments.amount − orders.shipping_amount` (mirrors `Order::netOfShipping()`); `Shortfall = MAX(0, Net due − COALESCE(collected_amount, 0))`; `Collection rate = SUM(collected_amount) ÷ SUM(Net due)`. **Never `amount − collected_amount`** — see the A.4 warning.
- **Group** — day, carrier, method, treasury, collector.
- **Sort** — date desc, shortfall desc.
- **Permission** — `reports.finance.view` · **Roles** — Accounting, Chairman.
- **"Posted by" derivation — verified reliable.** `payments` has no `collected_by` column, but
  `ConfirmDeliveryResultAction` calls `TreasuryService::recordTransaction($treasury, Income, $amount, $accountant, $payment, …)`
  on **every** collection, and `treasury_transactions` stores `created_by` plus a polymorphic
  `reference_type`/`reference_id` pointing at that `Payment`. So the collecting accountant is a
  one-join lookup (`reference_type = 'App\Models\Payment'`), and it is not an approximation — the
  transaction and the collection are written in the same `DB::transaction`, so one cannot exist
  without the other. (No morph map is registered, so `reference_type` holds the full class name.)

#### FIN-03 · Expenses
- **Purpose** — operating spend by category, treasury and period.
- **KPIs** — total expenses, by category, by treasury, expense as % of net revenue, avg per day, comparison delta.
- **Sources** — `expenses`, `expense_categories`, `treasuries`, `employees`.
- **Filters** — STD dates on `expense_date`, `expense_category_id[]`, `treasury_id[]`, `employee_id[]` (= `created_by`), amount range, `compare_to`.
- **Columns** — Date · Category (localized) · Description · Treasury · Amount · Recorded by · Share % · Δ vs comparison.
- **Formulas** — `Share % = category total ÷ period total`; `Expense ratio = expenses ÷ net revenue`.
- **Group** — category, treasury, period, employee.
- **Sort** — amount desc, date desc.
- **Permission** — `reports.finance.view` + `expenses.manage` for the detail rows · **Roles** — Accounting, Chairman.

#### FIN-04 · Shipping Company Reconciliation
- **Purpose** — what each shipping company owes, has transferred, and still holds.
- **KPIs** — open statements, net expected, transferred, **outstanding**, days outstanding, delivery/return fees owed.
- **Sources** — `shipping_company_statements`, `shipping_companies`, `treasury_transactions` (`reference_type = ShippingCompanyStatement`).
- **Filters** — date range on `period_start`/`period_end`, `shipping_company_id[]`, `status` (open/settled), outstanding > 0.
- **Columns** — Company · Period · Delivered orders · Expected collection · Delivery fees · Return fees · Net expected · Transferred · **Outstanding** · Status · Days open · Created by.
- **Formulas** — all four money columns are **stored** on the table (`net_amount_expected`, `outstanding_amount`) and read, not recomputed — the report must not disagree with `ReconciliationService`. `Days open = NOW() − period_end` for `status='open'`.
- **⚠ Post-Phase C: two columns are now always zero.** `ReconciliationService::buildDraft()` hard-sets
  `delivery_fees_owed = 0` and `return_fees_owed = 0`, because the courier already took their fee at
  the door — `collected_amount` is net of everything they are owed, and subtracting again would
  charge them twice. `shipping_companies.delivery_fee` / `.return_fee` are dead columns kept only so
  historical statements read back. **The report shows both columns only for statements created before
  Phase C**, and hides them otherwise rather than printing a column of zeros that invites someone to
  ask what broke.
- **Group** — company, status, period.
- **Sort** — outstanding desc, days open desc.
- **Permission** — `reports.finance.view` + `accounting.reconciliation.view` · **Roles** — Accounting, Chairman.

#### FIN-05 · Operational Profit & Loss
- **Purpose** — the one page that nets revenue against cost of goods, refunds and expenses.
- **KPIs** — net revenue, COGS, gross profit, margin %, expenses by category, operating profit, operating margin.
- **Sources** — everything above: `payments`, `refunds`, `order_items` + cost price, `expenses`, `shipping_company_statements` (fees).
- **Filters** — STD dates, `date_basis` = `delivered`, `compare_to`, `order_source`, geo.
- **Columns** — Line · Current period · Comparison period · Δ · Δ% · % of revenue.
- **Formulas** —
  ```
  Net revenue      = recognised collections − completed refunds       (A.4, already net of shipping)
  COGS             = Σ delivered units × unit cost                    (SAL-06)
  Gross profit     = Net revenue − COGS
  Operating expense= Σ expenses.amount                                 (by category)
  Operating profit = Gross profit − Operating expense

  Memo (not a P&L line):
  Courier fees     = Σ orders.shipping_amount on delivered orders
  Gross at door    = Net revenue + Courier fees
  ```
  **There is no delivery-cost line.** Post-Phase C the courier is paid by the customer at the door and
  the money never reaches a treasury, so it is neither revenue nor expense — subtracting
  `shipping_company_statements.delivery_fees_owed` would be double-counting against a column that is
  now always zero. Courier fees appear as a memo so the board can still see the gross at the door.
- **Group** — P&L line order (fixed); optional split by source or governorate.
- **Sort** — fixed.
- **Permission** — `reports.finance.view` + `reports.cost.view` · **Roles** — Chairman, Accounting. (Vice Chairman deliberately excluded — the seeded matrix gives Vice Chairman catalogue authority, not financial.)
- **⚠ Inherits one caveat** — SAL-06's current-cost COGS. Every input column exists. Sequenced into
  Phase 3 because it is the most-scrutinised number in the module and should sit on top of reports
  already checked against reality, not because data is missing.

#### FIN-06 · Outstanding Receivables & Uncollected
- **Purpose** — money delivered but not in a treasury. The leak report.
- **KPIs** — orders delivered with `payment_status` ≠ collected; total uncollected; ageing; uncollected by carrier; partially-collected shortfall.
- **Sources** — `orders`, `payments`, `delivery_assignments`, `shipping_company_statements`.
- **Filters** — `payment_status[]`, carrier, geo, date range on delivery, min amount.
- **Columns** — Order # · Delivered on · Customer · Carrier · Amount due · Collected · **Outstanding** · Payment status · Days outstanding · Statement (if any).
- **Formulas** — `Outstanding = MAX(0, (payments.amount − orders.shipping_amount) − COALESCE(collected_amount, 0))` for orders in `Delivered`/`Partially Returned` whose payment status is `pending`/`partially_collected`/`not_collected`. Comparing against gross `payments.amount` would report every settled order as owing exactly its shipping — the A.4 warning.
- **Group** — carrier, ageing bucket, payment status.
- **Sort** — days outstanding desc.
- **Permission** — `reports.finance.view` · **Roles** — Accounting, Chairman.

#### FIN-07 · Treasury Transfers
- **Purpose** — internal money movement between accounts, with its actor.
- **KPIs** — transfer count and value; net flow per treasury; transfers per employee.
- **Sources** — `treasury_transfers`, `treasuries`, `employees`.
- **Filters** — date range, `from_treasury_id`, `to_treasury_id`, `employee_id[]` (= `created_by`), amount range.
- **Columns** — Date · From · To · Amount · Notes · Created by.
- **Formulas** — `Net flow per treasury = Σ in − Σ out`.
- **Group** — route, treasury, employee, period.
- **Sort** — date desc, amount desc.
- **Permission** — `reports.finance.view` + `treasury.transfer` · **Roles** — Accounting, Chairman, Super Admin.

---

### 6. Employee Activity & Performance Reports

*Built strictly on recorded actions. Two kinds of evidence exist and both are used:*

| Evidence | Where | Covers |
|---|---|---|
| **Attribution columns** — first-class, permanent | `order_status_history.changed_by`, `delivery_assignments.assigned_by`, `inventory_movements.created_by`, `treasury_transactions.created_by`, `treasury_transfers.created_by`, `expenses.created_by`, `refunds.processed_by`, `stock_transfers.requested_by`/`approved_by`, `shipping_company_statements.created_by`, `orders.created_by_employee_id` | the operational workflow |
| **Activity log** — `causer_id` + old/new values, 1-year retention | `activity_log` for the 10 models using `RecordsActivity` | field-level edits |

Performance reports prefer the columns; the audit trail (Section 7) uses the log.

#### EMP-01 · Employee Action Log
- **Purpose** — every action one employee took, across all subsystems, on one screen. The "what did this person do last week" report.
- **KPIs** — actions by type; actions per day; entities touched; first/last action.
- **Sources** — a UNION over the attribution columns above, plus `activity_log` rows where `causer_type = Employee`.
- **Filters** — STD dates, `employee_id[]` (required), role, action domain (orders/inventory/treasury/returns/catalog/access), entity type.
- **Columns** — Timestamp · Employee · Role · Domain · Action · Entity type · Entity ref · Summary · Source (column | activity log).
- **Formulas** — counts only.
- **Group** — employee, domain, action, day.
- **Sort** — timestamp desc.
- **Permission** — `reports.employees.view` · **Roles** — Chairman, Super Admin, CS Team Leader (own team only — mirror `Employee::scopeVisibleTo()`).
- **Performance** — the UNION is expensive; run it per-employee with a bounded date range (validation enforces ≤ 92 days) and never unfiltered.

#### EMP-02 · Customer Service Scorecard
- **Purpose** — agent productivity on phone orders.
- **KPIs** — orders created, gross value, delivered value, AOV, cancellation rate, return rate, returns filed, customers created.
- **Sources** — `orders.created_by_employee_id`, `order_items`, `payments`, `returns`, `activity_log` (customer creates).
- **Filters** — STD, `employee_id[]`, team (via `employees.team_leader_id`), geo, `date_basis` = `placed`, `compare_to`.
- **Columns** — Agent · Team leader · Orders · Units · Gross value · Delivered orders · Net revenue · AOV · Cancelled · Cancel % · Returned · Return % · Customers created.
- **Formulas** — as ORD-01/SAL-01, grouped by `created_by_employee_id`.
- **Group** — agent; rollup by team leader.
- **Sort** — net revenue desc, cancel % desc.
- **Permission** — `reports.employees.view` · **Roles** — Chairman, CS Team Leader (scoped to own team by `Order::scopeVisibleTo()` — no extra code).

#### EMP-03 · Checking Team Performance
- **Purpose** — throughput and decision mix of the Checking role.
- **KPIs** — orders processed; confirmed / postponed / cancelled / backordered counts and shares; avg time from New→decision; orders per day.
- **Sources** — `order_status_history` (`changed_by`, `from_status`, `to_status`, `reason`).
- **Filters** — STD dates on the transition, `employee_id[]` (= `changed_by`), `to_status[]`, `order_source`, geo.
- **Columns** — Employee · Processed · Confirmed · Postponed · Cancelled · Backorder · Confirm % · Cancel % · Avg decision hrs · Busiest day.
- **Formulas** — `Confirm % = confirmed ÷ processed`; decision hrs = `Confirmed row.created_at − New row.created_at` for the same order.
- **Group** — employee, day, decision.
- **Sort** — processed desc.
- **Permission** — `reports.employees.view` · **Roles** — Chairman, Super Admin. (Checking employees see only ORD-02/ORD-04, not their own scorecard, unless granted.)

#### EMP-04 · Delivery Assignment Activity
- **Purpose** — who assigns what, to whom, how fast.
- **KPIs** — assignments made; split representative vs shipping company; avg confirm→assign hours; assignments per carrier; reassignment count.
- **Sources** — `delivery_assignments` (`assigned_by`, `assigned_at`), `order_status_history` (the `Confirmed` row).
- **Filters** — STD dates on `assigned_at`, `employee_id[]` (= `assigned_by`), `assignment_type`, carrier, geo.
- **Columns** — Employee · Assignments · To representatives · To companies · Avg hrs to assign · Distinct carriers used · Reassignments.
- **Formulas** — `Avg hrs to assign = assigned_at − Confirmed transition time`; `Reassignments = orders with >1 delivery_assignments row`.
- **Group** — employee, carrier, day.
- **Sort** — assignments desc.
- **Permission** — `reports.employees.view` · **Roles** — Chairman, Delivery Manager, Super Admin.

#### EMP-05 · Accounting Activity
- **Purpose** — reconciliation and cash-posting throughput per accountant.
- **KPIs** — delivery results confirmed; COD posted (count + value); refunds processed; expenses recorded; statements created/settled; treasury transactions posted.
- **Sources** — `order_status_history` (Delivered/Returned/Partially Returned rows), `treasury_transactions.created_by`, `refunds.processed_by`, `expenses.created_by`, `shipping_company_statements.created_by`, `treasury_transfers.created_by`.
- **Filters** — STD dates, `employee_id[]`, treasury, action type.
- **Columns** — Employee · Deliveries confirmed · COD posted (value) · Refunds processed (value) · Expenses recorded · Statements created · Transfers made · Total transactions.
- **Formulas** — counts and sums over each source, keyed by employee.
- **Group** — employee, action type, period.
- **Sort** — total transactions desc.
- **Permission** — `reports.employees.view` · **Roles** — Chairman, Super Admin.

#### EMP-06 · Warehouse Activity
- **Purpose** — inventory work per employee.
- **KPIs** — movements recorded; units in/out; adjustments; damaged/lost recorded; returns received; transfers requested/approved.
- **Sources** — `inventory_movements.created_by`, `stock_transfers.requested_by`/`approved_by`, `returns` via `activity_log`.
- **Filters** — STD dates, `employee_id[]`, `warehouse_id[]`, `type[]`.
- **Columns** — Employee · Warehouse · Movements · Units in · Units out · Adjustments · Damaged/Lost units · Transfers requested · Transfers approved · Returns received.
- **Formulas** — as INV-03/INV-06, grouped by `created_by`.
- **Group** — employee, warehouse, movement type.
- **Sort** — movements desc; adjustments desc (control view).
- **Permission** — `reports.employees.view` · **Roles** — Chairman, Warehouse Manager, Super Admin.

#### EMP-07 · Workforce Roster & Coverage
- **Purpose** — the people dimension itself: who exists, in what role, active or not, and who has produced nothing.
- **KPIs** — active/inactive employees per role; team sizes; employees with zero recorded actions in the period; new/deactivated in period.
- **Sources** — `employees` (+ `team_leader_id`), `model_has_roles`, and the attribution columns for the activity count.
- **Filters** — role, `is_active`, team leader, date range for "activity in period".
- **Columns** — Employee · Role(s) · Team leader · Active · Created · Last recorded action · Actions in period.
- **Formulas** — `Last recorded action = MAX()` across the attribution sources plus `activity_log`.
- **Group** — role, team.
- **Sort** — actions in period asc (surfaces dormant accounts).
- **Permission** — `reports.employees.view` + `employees.view` · **Roles** — Chairman, Super Admin, CS Team Leader (own team).
- **Metric definition, stated on the column header** — the system records no authentication events, so
  the column is **"Last recorded action"**, not "last login": the most recent row this employee wrote
  across the attribution columns and `activity_log`. That is arguably the more useful number for a
  workforce report anyway — it measures work done, not sessions opened — and it needs nothing new.
  A dormant-account sweep reads it exactly the same way.

---

### 7. Audit / Activity Reports

#### AUD-01 · Audit Trail
- **Purpose** — the required professional trail: **Employee → Action → Entity → Previous Value → New Value → Timestamp**.
- **KPIs** — entries per domain, per event, per employee; entries with no causer (system); entries on deleted subjects.
- **Sources** — `activity_log` (`log_name`, `event`, `subject_type`/`subject_id`, `causer_type`/`causer_id`, `properties->old`, `properties->attributes`, `properties->label`, `batch_uuid`, `created_at`).
- **Filters** — STD dates, `log_name[]` (`catalog`, `orders`, `inventory`, `treasury`, `customers`, `access`), `event[]` (`created`/`updated`/`deleted`/`restored`/`role_attached`…), `employee_id[]` (= causer), `subject_type`, `subject_id`, field name, label search, "changes only".
- **Columns** — **Timestamp · Employee (causer) · Role · Action (event) · Entity type · Entity (label snapshot) · Field · Previous value · New value** · Batch.
- **Formulas** — none; the existing `ActivityLogController::changes()` flattening is lifted into a shared service so screen, report and export produce the identical old→new pairs. One **row per changed field**, not per entry — that is what makes it a trail rather than a JSON dump, and it is what makes the export usable.
- **Group** — day, employee, entity type, domain, event.
- **Sort** — timestamp desc.
- **Export** — xlsx/csv/print. **Exports of this report are themselves worth logging** — consider a manual `activity()->log()` on export.
- **Permission** — `reports.audit.view` (distinct from the existing screen-level `activity.view`) · **Roles** — Chairman, Super Admin. Read-only by construction; there is deliberately no delete, matching the existing rule.
- **Localization** — `description` holds the raw event key and is translated at render, never stored as a sentence. Translatable subject values in old/new are JSON; render the active locale, fall back to the pinned fallback locale like `activitySubjectLabel()` does.

#### AUD-02 · Entity History
- **Purpose** — one record's complete timeline: every change, in order, with actor. Opened from any detail screen.
- **KPIs** — change count, distinct editors, first/last change, days since last change.
- **Sources** — `activity_log` filtered to one `subject_type` + `subject_id`; for orders, **merged with `order_status_history`**, which is the richer source for status and is not retention-limited.
- **Filters** — entity type + id (required), date range, field, employee.
- **Columns** — Timestamp · Employee · Action · Field · Previous · New · Reason/Notes (for order status rows).
- **Group** — chronological; optional by field.
- **Sort** — timestamp asc (the story) or desc.
- **Permission** — `reports.audit.view`, **or** the viewer's `*.view` permission for that entity type (an order's history is visible to whoever can view the order, scoped by `Order::scopeVisibleTo()`).
- **Roles** — all roles, narrowed to entities they can already see.

#### AUD-03 · Sensitive Changes
- **Purpose** — a standing watchlist. Not a duplicate of AUD-01: it is AUD-01 pre-filtered to the changes that carry financial or access risk, so nobody has to remember to look for them.
- **Watchlist — every item below is already captured today.** Each is annotated with its source so
  nothing on this list depends on logging that doesn't exist:

  | Watch item | Source (exists) |
  |---|---|
  | Product / variant `price`, `sale_price`, `cost_price` changed | `activity_log`, `log_name='catalog'` — all three are in `Product` and `ProductVariant` `activityLogAttributes()` |
  | Order deleted, or `total` / `payment_status` changed | `activity_log`, `log_name='orders'` |
  | Stock `adjustment` / `damaged` / `lost` | `activity_log` `log_name='inventory'` **and** `inventory_movements.created_by` directly |
  | Treasury balance or account changed | `activity_log`, `log_name='treasury'` (`current_balance` is logged) |
  | Treasury transaction posted | `activity_log` + `treasury_transactions.created_by` |
  | Employee created / deactivated (`is_active`) | `activity_log`, `log_name='access'` |
  | Role / permission granted or revoked | `activity_log`, `log_name='access'`, names snapshotted in `properties->names` |
  | Customer contact details edited | `activity_log`, `log_name='customers'` |
  | Refund processed, expense recorded, statement settled | actor columns: `refunds.processed_by`, `expenses.created_by`, `shipping_company_statements.created_by` |

  | Coupon / promotion value, dates or active flag changed | `activity_log`, `log_name='catalog'` — **added in this build** (see Section I decisions) |

  **`coupons.times_used` and `promotions.times_used` are deliberately not logged.** They increment on
  every redemption, so logging them would write an audit entry per order and bury the deliberate edits
  this report exists to surface. Redemption volume is already reported by SAL-05.
- **KPIs** — events per watch category; per employee; out-of-hours events; largest value delta.
- **Sources** — `activity_log`, plus `inventory_movements` and `treasury_transactions` directly (they carry an actor column even where a log entry might not exist).
- **Filters** — STD dates, category, `employee_id[]`, min value delta, "outside working hours".
- **Columns** — Timestamp · Category · Employee · Entity · Field · Previous · New · Delta · Flags.
- **Formulas** — `Delta = new − old` for numeric fields; `Delta % = delta ÷ old`.
- **Group** — category, employee, day.
- **Sort** — timestamp desc; delta desc.
- **Permission** — `reports.audit.view` · **Roles** — Chairman, Super Admin only.

#### AUD-04 · Access & Permission Changes
- **Purpose** — who gained or lost access, granted by whom.
- **KPIs** — role assignments/removals; permission grants/revocations; employees created/deactivated/restored; changes per grantor.
- **Sources** — `activity_log` where `log_name = 'access'` (the domain `Employee` already declares), with role/permission names snapshotted in `properties->names`.
- **Filters** — date range, subject employee, causer employee, event, role, permission.
- **Columns** — Timestamp · Granted/Revoked by · Target employee · Action · Role(s)/Permission(s) · Previous · New.
- **Group** — target employee, grantor, role.
- **Sort** — timestamp desc.
- **Permission** — `reports.audit.view` + `roles.view` · **Roles** — Super Admin, Chairman.

---

### 8. Management / Executive Dashboard

#### EXE-01 · Executive Dashboard
- **Purpose** — the single screen a Chairman opens. Tiles + trend charts, each linking into the report that explains it.
- **Sources** — the aggregates of ORD-01, SAL-01, INV-01/02, RET-01, FIN-01/06.
- **Filters** — period preset + custom, `compare_to` (defaults to `previous_period`), `order_source`, governorate. Warehouse for the stock tiles.
- **Behaviour** — every tile shows value, comparison delta and sparkline; every tile is a link, never a dead end.
- **Permission** — `reports.executive.view` · **Roles** — Chairman, Vice Chairman, Super Admin.
- **Tile set** — see Section D.
- **Note** — extends, does not replace, the existing `/admin` `DashboardController`, which stays the per-role operational landing page. Keeping them separate avoids the duplicate-report trap: `/admin` answers "what's in my queue", `/admin/reports/executive` answers "how is the business doing".

#### EXE-02 · Period Comparison
- **Purpose** — one table comparing any two periods across every headline metric, for board packs.
- **KPIs** — orders, units, net revenue, AOV, delivery rate, cancellation rate, return rate, gross margin, expenses, operating profit, new customers, repeat rate.
- **Sources** — the same report classes, executed twice with different date windows.
- **Filters** — period A, period B (or `compare_to` shorthand), `order_source`, geo, category.
- **Columns** — Metric · Period A · Period B · Δ · Δ% · Direction.
- **Formulas** — `Δ% = (A − B) ÷ B`; guard `B = 0`.
- **Group** — metric family (volume / revenue / quality / cost).
- **Sort** — fixed; optional Δ% desc.
- **Export** — xlsx/csv/**print is the primary output here**. **Permission** — `reports.executive.view` · **Roles** — Chairman, Vice Chairman.

#### EXE-03 · Operations Health
- **Purpose** — one screen of the things that are wrong right now, ranked. The exception report.
- **KPIs / alert rows** — orders stuck >N days per status (ORD-02) · out-of-stock SKUs with sales in the last 30d (INV-02) · reservation variances (INV-07) · uncollected delivered orders (FIN-06) · shipping statements outstanding >N days (FIN-04) · refunds pending >N days (RET-04) · treasury balance variance (FIN-01) · returns awaiting inspection (RET-05) · stock transfers in transit >N days (INV-04).
- **Sources** — the underlying reports, each reduced to one count + one value.
- **Filters** — warehouse, carrier, severity threshold.
- **Columns** — Alert · Count · Value at risk · Oldest · Owner role · Link.
- **Sort** — value at risk desc.
- **Permission** — `reports.executive.view` · **Roles** — Chairman, Super Admin; individual rows also surfaced on the operational dashboard to the owning role.

---

## C. Data Coverage — Confirmation That Nothing New Is Needed

**Confirmed: all 46 reports are implementable on the current schema. No new column, no new table.**

This section is the proof, not an assertion. Each item below was a candidate gap; each is closed by
naming the data that already exists and the exact derivation. Verification was done against the
migrations, `InventoryService`, `ConfirmDeliveryResultAction`, `TreasuryService` and each model's
`activityLogAttributes()`.

### C.1 Every candidate gap, and why it is closed

| # | Candidate gap | Status | Derivation from existing data |
|---|---|:--:|---|
| 1 | `orders` has no warehouse column — which warehouse fulfilled an order? | **Closed** | `inventory_movements` where `reference_type = 'App\Models\Order'`, `reference_id = orders.id`, `type = 'reservation'` → `warehouse_id`. This is exactly what `InventoryService::warehouseForReservation()` already does in production. The reservation row is **never deleted** — a release or sale writes its own additional row — so the warehouse stays recoverable for the life of the order, including cancelled ones. No morph map is registered, so `reference_type` is the full class name. |
| 2 | No cost snapshot on `order_items` — historical COGS | **Closed, with a stated caveat** | `COALESCE(product_variants.cost_price, products.cost_price)`. Costed at current cost, not cost-at-sale. Mitigated, not hidden: `cost_price` is in the logged attribute list of **both** `Product` and `ProductVariant`, so AUD-03 shows every cost change with old → new → who → when, and SAL-06 flags rows whose SKU moved inside the window. See C.3. |
| 3 | `payments` has no `collected_by` — who took the cash | **Closed** | `treasury_transactions` where `reference_type = 'App\Models\Payment'` and `reference_id = payments.id` → `created_by`. `ConfirmDeliveryResultAction` writes the payment update and the treasury transaction inside one `DB::transaction`, passing `$accountant`, so one cannot exist without the other. This is an exact attribution, not a guess. |
| 4 | `returns` has no `approved_by` / `received_by` / `inspected_by` and no history table | **Closed** | `OrderReturn` uses `RecordsActivity` and its `activityLogAttributes()` **includes `status`**. Every transition therefore writes an `activity_log` row with old status, new status, `causer_id` (the employee) and `created_at`. The refund step additionally has `refunds.processed_by` / `processed_at` as real columns. Retention caveat in C.2. |
| 5 | `warehouse_inventory` has no reorder point | **Closed** | `config('inventory.low_stock_threshold')` already exists and is the threshold `InventoryService::flagLowStock()` uses to notify Warehouse Manager and Vice Chairman. INV-02 reads the same config value, so report and alert agree by construction. A per-run override on the filter bar covers what-if analysis; days-of-cover gives a velocity-aware second band. |
| 6 | `stock_transfers` has no per-status timestamps | **Closed** (report deferred for a different reason) | `created_at` → `updated_at` plus `requested_by` / `approved_by` cover the report as specified. The real issue with INV-04 is not data — see C.4. |
| 7 | No login tracking — "last active" for employees | **Closed by redefining the metric** | EMP-07 reports **"Last recorded action"**: `MAX()` across `orders.created_by_employee_id`, `order_status_history.changed_by`, `inventory_movements.created_by`, `treasury_transactions.created_by`, `expenses.created_by`, `refunds.processed_by`, `delivery_assignments.assigned_by`, `stock_transfers.requested_by`, and `activity_log.causer_id`. Column is labelled as such. Measures work done rather than sessions opened. |
| 8 | `order_status_history.reason` is free text | **Closed by grouping correctly** | ORD-04 groups primarily on `to_status`, a clean enum. Reason text is a secondary "top 20 + other" breakdown and appears in full on detail rows. No lookup table invented. |
| 9 | Activity-log coverage does not include every model | **Closed by scoping the audit reports to what is logged** | 10 models carry `RecordsActivity`: `Order`, `OrderReturn`, `Product`, `ProductVariant`, `Category`, `Customer`, `Employee`, `Treasury`, `TreasuryTransaction`, `InventoryMovement`. Everything not logged still has a first-class actor column (`refunds.processed_by`, `expenses.created_by`, `delivery_assignments.assigned_by`, `shipping_company_statements.created_by`, `stock_transfers.requested_by`/`approved_by`), which is what EMP-01…06 use. AUD-03's watchlist lists its source per row and omits the one category with no trail. See C.2. |
| 10 | No storefront traffic data | **Not needed** | No report above claims a visit-to-order conversion rate. Cart abandonment is computable from `carts` / `cart_items` with no resulting order, and that is what is offered. |
| 11 | Missing indexes | **Not a data gap** | Indexes store no new data and change no schema semantics. Section H.1 is index-only and is the one migration this module wants — for speed, not for capability. Reports are functionally correct without it. |

### C.2 Two caveats to print on the reports themselves

Neither blocks implementation; both are honesty requirements.

1. **Audit retention is one year.** `config/activitylog.php` prunes older entries. This bounds
   **AUD-01, AUD-02, AUD-03, AUD-04** and the activity-derived half of **RET-05** and **EMP-01**.
   Every audit-sourced report prints its retention window in the header, so a reader is never shown a
   silently truncated history. Order status history is *not* affected — `order_status_history` is a
   permanent table with no pruning, which is why AUD-02 merges it in for orders.
2. **Coupon and promotion edits leave no trail.** `Coupon` and `Promotion` do not use
   `RecordsActivity`. AUD-03 therefore omits that watch category rather than showing an incomplete one.

**Optional, zero-schema fix for both blind spots** (not required by any report above, listed only so
the choice is visible): adding `use RecordsActivity;` plus a two-method override to `Coupon`,
`Promotion`, `Payment`, `Refund` and `Expense` adds **no columns and no tables** — `activity_log`
already exists — and would widen AUD-01/AUD-03 from the day it ships. It backfills nothing. Treat it
as a nice-to-have in Phase 2, not a prerequisite.

### C.3 The five reports carrying an accuracy caveat

All five are implementable now. Each states its basis on its own header.

| Report | Caveat | Why it is acceptable |
|---|---|---|
| **SAL-06** Gross Margin | COGS at current cost, not cost-at-sale | Cost changes are audit-logged with old → new → who → when; affected rows are flagged and the footer links to the AUD-03 cost-change view |
| **FIN-05** P&L | Inherits SAL-06's COGS, plus the A.4 shipping basis | Both inputs exist; the shipping line is a single formula in one shared place |
| **RET-05** Return Cycle Time | Intermediate transitions come from `activity_log` (1-year retention) | `status` is logged, so the data is complete inside the window; the window is printed |
| **EMP-07** Workforce Roster | "Last recorded action", not "last login" | Column is labelled accordingly; arguably the better metric for the purpose |
| **ORD-04** Cancellation Analysis | Reason text is free-form | Primary grouping is the `to_status` enum; text is a secondary cut |

### C.4 One report to defer — a missing *feature*, not missing data

**INV-04 (Stock Transfers).** The `stock_transfers` and `stock_transfer_items` tables, the
`StockTransferStatus` enum, the `StockTransfer` model, `StockTransferSeeder` and the
`inventory.transfer` permission all exist. But **no route, controller or Action creates or advances a
stock transfer**: `InventoryController` exposes only `index`, `export` and `adjust`, and
`InventoryMovementType::TransferIn` / `TransferOut` are never written by any code in `app/`.

The report is writable today and would be correct against the table — it would simply have nothing
real to show until the transfer workflow itself is built. **Recommendation: hold INV-04 until stock
transfers ship**, then add it as a single report class against the same infrastructure. That drops
the deliverable to **45 reports now, 46 when transfers exist**.

### C.5 Confirmation

| Question | Answer |
|---|---|
| Do any reports require a new database column? | **No.** |
| Do any reports require a new table? | **No.** |
| Do any reports require a workflow change? | **No.** |
| Is any migration needed at all? | **Index-only** (Section H.1) — recommended for performance, not required for correctness. |
| How many reports can be built immediately? | **45 of 46.** INV-04 waits on the stock-transfer feature, not on data. |
| How many carry a stated accuracy caveat? | **5** (C.3), each disclosed on the report header. |
| Does anything depend on Phase C of `feature-backlog-plan.md`? | **No — Phase C has shipped.** A.4 reflects the net-collection model; `Order::netDueToTreasury()` is the single source of the rule. |

## D. Recommended Dashboard KPIs

### D.1 Executive tiles (EXE-01) — all with `compare_to` delta

| Tile | Formula | Drill-through |
|---|---|---|
| Net revenue | A.4 | SAL-01 |
| Delivered orders | count, status `Delivered` | ORD-01 |
| AOV | net revenue ÷ delivered orders | SAL-01 |
| Orders placed | count by `created_at` | ORD-01 |
| Delivery success rate | delivered ÷ assigned | ORD-05 |
| Cancellation rate | cancelled ÷ placed | ORD-04 |
| Return rate | returns raised ÷ delivered | RET-01 |
| Gross margin % | (revenue − COGS) ÷ revenue, COGS at current cost | SAL-06 |
| Cash on hand | Σ `treasuries.current_balance` | FIN-01 |
| Outstanding receivables | Σ uncollected on delivered orders | FIN-06 |
| Shipping co. outstanding | Σ `outstanding_amount` where `status='open'` | FIN-04 |
| Stock value at cost | Σ qty × cost | INV-01 |
| Out-of-stock SKUs | count where available ≤ 0 | INV-02 |
| New customers | first order in period | SAL-07 |
| Website vs CS split | orders by `order_source` | ORD-06 |

### D.2 Charts
- Revenue + orders dual-axis by `granularity`, with comparison series.
- Order status funnel: Placed → Confirmed → Assigned → Delivered (absolute + %).
- Top 10 products by revenue (bar).
- Revenue by governorate (bar or map).
- Returns by reason (donut).
- Cash in/out by day (stacked).

### D.3 Role-scoped operational tiles (the existing `/admin` dashboard, extended)

Keep the current per-permission tile gating. Add per role:
**Checking** → awaiting checking, avg decision time, own confirm/cancel counts.
**Delivery Manager** → unassigned confirmed orders, in-transit, per-carrier success.
**Accounting** → orders awaiting delivery confirmation, uncollected total, open statements, pending refunds.
**Warehouse Manager** → low/out-of-stock, pending transfers, returns awaiting receipt, today's adjustments.
**CS Team Leader** → team orders today, team cancel rate, agent leaderboard.
**Store Orders** → website orders today, by status (read-only, `visibleTo` already enforces).

---

## E. Role / Permission Matrix

### E.1 New permissions (add to `PermissionSeeder::PERMISSIONS`)

`reports.view` **already exists and is already seeded to Chairman** — it stays as the module gate.
Add beneath it, following the existing `resource.action` convention and the existing rule that
**export is always a separate grant from view**:

```
reports.orders.view
reports.sales.view
reports.inventory.view
reports.returns.view
reports.finance.view
reports.employees.view
reports.audit.view
reports.executive.view
reports.cost.view        // unlocks cost_price / COGS / margin columns anywhere
reports.export           // xlsx/csv download for any report the viewer can already see
```

`reports.cost.view` exists because `products.cost_price` is documented in the schema as
"internal-only, never exposed to storefront" — the same logic applies to an operations employee who
can legitimately see stock levels but not what the company pays for goods.

### E.2 Matrix

Legend: ● full · ◐ scoped by `Order::scopeVisibleTo()` / `Employee::scopeVisibleTo()` · ○ none.
Super Admin is omitted — `Gate::before` bypasses every check, as it does today.

| Report group | Chairman | Vice Chairman | Accounting | Warehouse Mgr | Delivery Mgr | Checking | CS Team Leader | Customer Service | Store Orders |
|---|:--:|:--:|:--:|:--:|:--:|:--:|:--:|:--:|:--:|
| **Orders** (ORD-01…06) | ● | ● | ● | ○ | ● | ● | ◐ | ○ | ◐ |
| **Sales** (SAL-01…05, 07) | ● | ● | ● | ○ | ○ | ○ | ◐ | ○ | ○ |
| **Margin** (SAL-06) | ● | ● | ● | ○ | ○ | ○ | ○ | ○ | ○ |
| **Inventory** (INV-01…07) | ● | ● | ◐¹ | ● | ○ | ◐² | ○ | ○ | ○ |
| **Returns** (RET-01…05) | ● | ● | ● | ● | ○ | ○ | ◐ | ○ | ○ |
| **Finance** (FIN-01…07) | ● | ○ | ● | ○ | ○ | ○ | ○ | ○ | ○ |
| **Employees** (EMP-01…07) | ● | ○ | ○ | ◐³ | ◐³ | ○ | ◐⁴ | ○ | ○ |
| **Audit** (AUD-01, 03, 04) | ● | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ |
| **Entity history** (AUD-02) | ● | ● | ● | ● | ● | ● | ◐ | ◐ | ◐ |
| **Executive** (EXE-01…03) | ● | ● | ○ | ○ | ○ | ○ | ○ | ○ | ○ |
| `reports.cost.view` | ● | ● | ● | ○ | ○ | ○ | ○ | ○ | ○ |
| `reports.export` | ● | ● | ● | ● | ● | ○ | ○ | ○ | ○ |

¹ Accounting gets INV-01 (valuation) and INV-06 (shrinkage), not the operational stock screens.
² Checking gets INV-02 only — it needs to know what is out of stock to make a Backorder decision.
³ Own function only: Warehouse Manager sees EMP-06, Delivery Manager sees EMP-04.
⁴ Own team only, via the existing `Employee::scopeVisibleTo()` / `Order::scopeVisibleTo()` rules — no new scoping code.

**Two-layer enforcement, matching what exists:**
1. Route/controller — `Middleware('permission:reports.<group>.view')` on `ReportController::show`, resolved from the report's own `permission()`.
2. Row — `Order::scopeVisibleTo()` inside every order-sourced report query. A CS Team Leader's export of SAL-01 physically cannot contain another team's orders.

Column-level: cost/margin columns are stripped from the payload (not zeroed) when the viewer lacks
`reports.cost.view` — same approach `DashboardController` already takes with its tiles.

---

## F. Navigation

Insert one group into `AdminLayout.tsx` after **Finance**, before **System**. Items are permission-gated
by the existing `can()` filter, so a role with no report permissions never sees the group at all.

```
📊 Reports                            (group, shown if any child is permitted)
   Executive Dashboard                reports.executive.view
   Orders                             reports.orders.view
   Sales                              reports.sales.view
   Products & Inventory               reports.inventory.view
   Returns                            reports.returns.view
   Financial                          reports.finance.view
   Employee Performance               reports.employees.view
   Audit Trail                        reports.audit.view
```

Each entry links to `/admin/reports?group=<key>` — the catalogue filtered to that group, listing the
reports inside it with a one-line description. Eight menu items, thirty-three reports: the sidebar
does not grow when reports are added.

Two extra entry points, no new menu items:
- **Contextual** — an "Activity" tab on order/product/customer/return detail pages opens AUD-02 pre-filtered to that record.
- **Existing screen** — `/admin/activity-log` stays where it is for `activity.view` holders; AUD-01 is the reportable, exportable, field-level version for `reports.audit.view` holders.

Breadcrumbs reuse the existing `Breadcrumb.tsx`. Translation keys: `admin.navReports`, `admin.reportsOrders`, … in both locale files.

---

## G. Export Architecture

### G.1 One export class, not thirty-three

`ReportExport` wraps any `ReportDefinition`:

```php
class ReportExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping
{
    public function __construct(
        private readonly ReportDefinition $report,
        private readonly Employee $employee,
        private readonly array $filters,
    ) {}

    public function query(): Builder   { return $this->report->query($this->employee, $this->filters); }
    public function headings(): array  { return $this->report->headings($this->employee); }  // translated
    public function map($row): array   { return $this->report->row($row, $this->employee); }
}
```

Same contract the screen renders from — the download can never drift from the table, which is the
rule `OrdersExport` already establishes.

### G.2 Formats

| Format | How | Notes |
|---|---|---|
| **XLSX** | `Excel::download($export, $name)` | Default. Already installed (`maatwebsite/excel ^4.0`). Raw numerics, not formatted strings, so columns stay summable. |
| **CSV** | `Excel::download($export, $name, Excel::CSV)` | Same class, one argument. Write **UTF-8 with BOM** or Excel mangles Arabic. |
| **PDF / Print** | `/admin/reports/{report}/print` → `Reports/Print.tsx` → browser print dialog | **No new dependency.** This is exactly the existing `Orders/Invoice` pattern. |

**On a server-side PDF library:** don't add one. `barryvdh/laravel-dompdf` renders Arabic RTL badly
without extra font and shaping work, and the browser already renders this app's Arabic correctly.
Add a headless-Chrome renderer only if emailed/scheduled PDFs become a hard requirement — that is a
Phase 3 decision, not a Phase 1 dependency.

### G.3 Print layout (`Reports/Print.tsx`)

- `@media print` stylesheet; no sidebar, no nav, no filter bar.
- Header on every page: logo, report name (localized), filter summary in words ("1–30 Sep 2026 · Cairo · Website orders"), generated-at timestamp, generated-by employee name.
- `thead { display: table-header-group }` so column headings repeat across pages.
- `@page { size: A4; margin: 12mm }`; landscape for wide reports.
- `dir` inherited from `<html>` — the root view already reads `app()->getLocale()`.
- Totals row pinned at the end, never split across a page break.

### G.4 Export rules

- Gated by `reports.export` **in addition to** the report's own view permission — the existing
  "seeing a list ≠ walking out with the file" principle, applied to reports.
- The export runs the **identical filter set** the screen has applied; `ExportButton` already carries
  current filters in the href.
- Filename: `{report-key}_{from}_{to}_{timestamp}.{ext}` — no localized filenames (filesystem safety).
- **Row cap:** synchronous export capped at 50,000 rows. Above that, `ShouldQueue` + notify on
  completion through the existing notification system. Phase 3.
- The filter summary is written into the **first sheet rows** of every export, so a downloaded file
  can always be traced back to the query that produced it.

---

## H. Performance

### H.1 Indexes — the only migration this module needs

**Index-only: no new columns, no new tables, no data written.** Every report is functionally correct
without this; none is fast without it. None of these exist today beyond what FKs create implicitly.
Ship it before the first report class, so no report is ever benchmarked against an unindexed table.

```php
// orders — every report filters some subset of these
$table->index(['status', 'created_at'],            'orders_status_created_index');
$table->index(['order_source', 'created_at'],      'orders_source_created_index');
$table->index(['payment_status', 'created_at'],    'orders_payment_created_index');
$table->index(['created_by_employee_id', 'created_at'], 'orders_creator_created_index');
$table->index(['shipping_governorate_id', 'created_at'], 'orders_gov_created_index');
$table->index('created_at',                        'orders_created_index');

// order_status_history — ORD-02/03/04, EMP-03, all lifecycle timing
$table->index(['order_id', 'created_at'],          'osh_order_created_index');
$table->index(['to_status', 'created_at'],         'osh_status_created_index');
$table->index(['changed_by', 'created_at'],        'osh_actor_created_index');

// order_items — SAL-02/03/06, INV-05
$table->index(['product_variant_id', 'order_id'],  'order_items_variant_order_index');

// payments — FIN-02/06
$table->index(['status', 'collected_at'],          'payments_status_collected_index');
$table->index('collected_at',                      'payments_collected_index');

// inventory_movements — INV-03/05/06/07, EMP-06. The biggest table long-term.
$table->index(['warehouse_id', 'product_variant_id', 'created_at'], 'im_wh_variant_created_index');
$table->index(['type', 'created_at'],              'im_type_created_index');
$table->index(['created_by', 'created_at'],        'im_actor_created_index');
$table->index(['reference_type', 'reference_id'],  'im_reference_index');

// treasury_transactions — FIN-01, EMP-05
$table->index(['treasury_id', 'created_at'],       'tt_treasury_created_index');
$table->index(['type', 'created_at'],              'tt_type_created_index');
$table->index(['created_by', 'created_at'],        'tt_actor_created_index');
$table->index(['reference_type', 'reference_id'],  'tt_reference_index');

// returns / return_items / refunds
$table->index(['status', 'created_at'],            'returns_status_created_index');
$table->index(['stage', 'created_at'],             'returns_stage_created_index');
$table->index(['product_variant_id'],              'return_items_variant_index');
$table->index(['status', 'processed_at'],          'refunds_status_processed_index');

// activity_log — AUD-01/02/03/04, EMP-01. Currently indexed on log_name only.
$table->index(['causer_type', 'causer_id', 'created_at'], 'activity_causer_created_index');
$table->index(['log_name', 'event', 'created_at'],        'activity_log_event_created_index');
$table->index('created_at',                               'activity_created_index');
// subject_type+subject_id already indexed by Spatie's morph index.

// expenses, delivery_assignments, stock_transfers
$table->index(['expense_date', 'expense_category_id'], 'expenses_date_category_index');
$table->index(['assigned_at'],                         'da_assigned_index');
$table->index(['status', 'created_at'],                'st_status_created_index');
```

Composite order matters: equality column first, range column last.

### H.2 Query rules

1. **Date ranges as half-open predicates, never `DATE()` on the column.**
   `WHERE created_at >= ? AND created_at < ?` uses the index; `WHERE DATE(created_at) = ?` does not.
   Bucket in the `SELECT`/`GROUP BY`, filter on the raw column.
2. **Aggregate in SQL.** `selectRaw('DATE(created_at) d, COUNT(*) c, SUM(total) t')->groupBy('d')`,
   never `->get()->groupBy()`.
3. **One query per report, plus one for the comparison period.** Not N+1 per bucket.
4. **Cap the range.** `ReportFilterRequest` rejects windows over 366 days for detail reports and over
   92 days for EMP-01's UNION. A report nobody can accidentally run over all time is a report that
   never takes the site down.
5. **Paginate detail reports** (default 50). Summary reports return bounded buckets and need none.
6. **Totals as a separate aggregate query**, not by summing the paginated page.
7. **`chunkById` / `cursor` for exports** — `FromQuery` already streams; don't replace it with `FromCollection`.
8. **Eager-load display relations only** (`with('customer:id,name')`), never whole models.
9. **Warehouse filtering on sales** goes through a single `whereExists` on `inventory_movements`
   (`reference_type='App\Models\Order'`, `type='reservation'`), covered by the
   `im_reference_index` + `im_wh_variant_created_index` pair below. One semi-join, not a fan-out.
10. **Read replica ready** — every report query is read-only; if a replica is added later, route the
    `reports.*` routes' connection and nothing else changes.

### H.3 Caching

- Executive tiles and EXE-03 alerts: cache **5–15 min**, keyed by
  `report:{key}:{employee_role_signature}:{filter_hash}:{locale}`. **The role signature is part of
  the key** — a cache shared across roles would leak scoped rows, which is exactly what
  `scopeVisibleTo()` exists to prevent.
- Closed periods are immutable: a month-end report for a past month caches for 24h.
- Detail reports: no cache. They are filtered ad hoc and cache hit rates would be near zero.
- Invalidate by TTL, not by event. An executive tile that is 10 minutes stale is fine; a cache
  invalidation web across 46 reports is not.

### H.4 Scale path (Phase 3, only when volume demands it)

Don't build these on day one — the indexes above carry the system a long way.

- `report_daily_sales` rollup (`date`, `order_source`, `governorate_id`, `warehouse_id`, `orders`,
  `units`, `gross`, `discount`, `shipping`, `collected`, `refunds`, `cogs`), rebuilt nightly for the
  trailing 3 days and read by SAL-01 / EXE-01 / EXE-02. Recomputable from source at any time.
- `report_daily_inventory` snapshot for stock-value-over-time. **This is the one genuinely optional
  addition in the whole document, and it is a derived cache, not business data** — no report in
  Section B requires it. It matters only if the business later asks "what was stock worth last
  March", which `warehouse_inventory` cannot answer (it holds current state only) and which
  `inventory_movements` can reconstruct but slowly. One row per warehouse per day. Start it early or
  not at all; it is cheap, and every un-snapshotted day is one that has to be replayed from movements.
- Queue exports over 50k rows.
- Archive `activity_log` rows past the retention window into a cold table rather than deleting.

---

## I. Implementation Priority

**No phase is gated on a schema change, because there are none.** The phasing below is purely about
delivering value in a sensible order — every report in every phase could technically be written on
day one.

**Totals: 46 reports specified · 9 in Phase 1 · 28 in Phase 2 · 8 in Phase 3 · 1 deferred (INV-04).**

### Decisions taken (2026-09-21)

| Question | Decision |
|---|---|
| Build scope | **Phases 1 + 2, plus SAL-06 and FIN-05 pulled forward — 39 reports.** Profitability is wanted from the start, not held to Phase 3. |
| `cost_price` reliability | **Mostly filled and trusted.** Cost/valuation columns on INV-01 and INV-05 build as specced, with no coverage disclaimer; SAL-06 / FIN-05 keep only the current-cost basis note (C.3). |
| Role / permission matrix | **Section E.2 approved as written.** Seeded by `PermissionSeeder`; adjustable afterwards in the existing `/admin/roles/{role}` editor. |
| INV-04 Stock Transfers | **Deferred** until the transfer workflow exists (C.4). |
| AUD-03 coupon/promotion edits | **Included.** `RecordsActivity` added to `Coupon` and `Promotion` — zero schema change, no workflow change. `times_used` is deliberately *not* logged: it increments on every order and would bury real edits. |
| Audit retention | Default applies: keep 1 year, print the window on every audit-sourced report (C.2). |
| Fiscal year | Default applies: calendar year. |
| AUD-03 out-of-hours flag | Default applies: omitted, no working-hours setting exists. |

**Revised phasing after these decisions — 39 now, 6 later, 1 deferred:**

- **This build (39):** Phase 1's 9 + Phase 2's 28 + **SAL-06, FIN-05**.
- **Later (6):** EMP-01, EMP-07, AUD-03, EXE-01, EXE-02, EXE-03.
- **Deferred (1):** INV-04.

### Delivered (2026-09-21) — all 39 shipped ✅

| Group | Shipped | Report keys |
|---|:--:|---|
| Orders | 6 | `orders.summary` · `orders.pipeline` · `orders.lifecycle` · `orders.cancellations` · `orders.delivery-performance` · `orders.source-comparison` |
| Sales | 7 | `sales.summary` · `sales.by-product` · `sales.by-category` · `sales.by-geography` · `sales.discounts` · `sales.margin` · `sales.customers` |
| Inventory | 6 | `inventory.stock-on-hand` · `inventory.low-stock` · `inventory.movements` · `inventory.turnover` · `inventory.shrinkage` · `inventory.reservations` |
| Returns | 5 | `returns.summary` · `returns.reasons` · `returns.by-product` · `returns.refunds` · `returns.cycle-time` |
| Finance | 7 | `finance.treasury` · `finance.collections` · `finance.receivables` · `finance.reconciliation` · `finance.expenses` · `finance.transfers` · `finance.profit-loss` |
| Employees | 5 | `employees.customer-service` · `employees.checking` · `employees.delivery` · `employees.accounting` · `employees.warehouse` |
| Audit | 3 | `audit.trail` · `audit.access` · `audit.entity-history` |

Infrastructure: index-only migration · `ReportDefinition` / `ReportColumn` /
`ReportRegistry` / `ReportTranslator` · `AppliesStandardFilters`, `MeasuresSales`,
`QueriesOrderLines` concerns · one `ReportExport` for xlsx + csv · `Reports/Index`,
`Reports/Show`, `Reports/Print` pages · `ReportFilterBar`, `ReportCell` components ·
10 `reports.*` permissions seeded to the E.2 matrix · 290 locale keys in both `ar` and
`en` · `RecordsActivity` on `Coupon` and `Promotion`.

**Two implementation decisions that depart from the spec text above:**

1. **ORD-06 is one row per channel**, not the metrics-down-the-side pivot sketched in
   Section B. A pivot needs its own handling in the table, the export and the print
   view for one report's sake, and it exports worse — a transposed table has no
   sortable measure columns.
2. **Export headings resolve from the admin locale catalog** (`ReportTranslator`), not
   from a parallel `lang/*/reports.php`. Section G assumed the latter; maintaining ~290
   headings in two places would drift silently, with the failure showing only in a
   downloaded file. `lang/*/reports.php` now holds only server-only strings (validation,
   the "System" actor label).

AUD-03's watchlist is built now in the sense that coupon/promotion logging starts immediately — the
report that reads it lands with the executive group, by which time it has real history to show.

### Phase 1 — Foundation + the nine reports management asks for first

1. **Index migration** (H.1) — index-only, before anything else.
2. `ReportDefinition`, `AppliesStandardFilters`, `ComparesPeriods`, `ReportFilterRequest`,
   `ReportController` (4 routes), `ReportExport`, `Reports/Index.tsx`, `Reports/Show.tsx`,
   `ReportFilterBar.tsx`.
3. New permissions into `PermissionSeeder` + the role defaults in E.2. Nav group in `AdminLayout`.
4. Locale keys for everything added, both `ar.json` and `en.json`.
5. Reports (9): **ORD-01, ORD-02, ORD-04, SAL-01, SAL-02, SAL-04, INV-01, INV-02, AUD-01.**
6. XLSX + CSV export working end-to-end on all nine.

*Nine reports covering the daily questions: how many orders, what sold, what's in stock, who changed what.*

### Phase 2 — Depth, returns and the money

1. Reports (28): **ORD-03, ORD-05, ORD-06, SAL-03, SAL-05, SAL-07, INV-03, INV-05, INV-06, INV-07,
   RET-01, RET-02, RET-03, RET-04, RET-05, FIN-01, FIN-02, FIN-03, FIN-04, FIN-06, FIN-07,
   EMP-02, EMP-03, EMP-04, EMP-05, EMP-06, AUD-02, AUD-04.**
2. Print views for all reports; period comparison (`compare_to`) on every summary report.
3. Contextual "Activity" tab (AUD-02) on order / product / customer / return detail pages.
4. *Optional, zero-schema:* `RecordsActivity` on `Coupon`, `Promotion`, `Payment`, `Refund`,
   `Expense` (C.2) — widens AUD-01/AUD-03 from the day it ships, backfills nothing.

### Phase 3 — Executive, margin and scale

1. Reports (8): **SAL-06, FIN-05, EMP-01, EMP-07, AUD-03, EXE-01, EXE-02, EXE-03.**
   Sequenced last because these sit on top of every other report's figures and should be built once
   those have been checked against reality — not because anything is missing.
2. Tile caching (H.3); queued exports over 50k rows; `report_daily_sales` rollup **only if volume
   demands it**.
3. Role-scoped operational tiles on the existing `/admin` dashboard (D.3).
4. Optional, only if scheduled/emailed PDFs become a requirement: headless-Chrome PDF rendering.
5. Optional: `report_daily_inventory` snapshotting (H.4) — a derived cache, not business data.

### Deferred — not on data grounds

**INV-04 (Stock Transfers)** — build it when the stock-transfer workflow itself is built. There is
currently no route, controller or Action for stock transfers, so the report would render an empty
table in production (C.4).

### Phase C dependency — resolved

Phase C (`feature-backlog-plan.md` §C) has **shipped**. `payments.collected_amount` is net of
shipping, `Order::netDueToTreasury()` / `netOfShipping()` are the single source of that rule, and A.4
above reflects it. The only residue is that orders settled before the deploy carry gross
`collected_amount` — SAL-01 and FIN-02 flag periods spanning that date.

---

## Open Questions

*None of these block implementation. Each has a stated default the module ships with if unanswered.*

1. **Working hours** for AUD-03's "outside working hours" flag — no such setting exists anywhere in
   the codebase. **Default if unanswered: drop the flag.** A config value is the alternative, but an
   invented one would make the flag misleading rather than useful.
2. **Fiscal year** — does the business's year start in January? **Default: calendar year**, which is
   what `this_year`/`last_year` assume.
3. **Scheduled reports** (email a weekly P&L to the Chairman) — out of scope above. Worth a Phase 3
   item if wanted; the mail infrastructure (`resources/views/mail`) already exists.
4. **Audit retention** — is 1 year (`config/activitylog.php`) a deliberate business decision or a
   package default nobody revisited? It bounds AUD-01…04 and RET-05. **Default: keep 1 year and print
   the window on every audit report** (C.2). If longer retention is a compliance need, archive to a
   cold table rather than lifting the setting.
5. **Cost price ownership** — who maintains `cost_price`? SAL-06/FIN-05 are only as good as that
   number, and no screen currently emphasises it. **Default: ship SAL-06 with the current-cost
   caveat and the AUD-03 cost-change link** (C.3), so a reader can always see whether margin moved
   because sales moved or because someone edited a cost.
6. **Stock transfers** — is the transfer workflow planned? INV-04 is specified and ready; it just has
   no feature to report on yet (C.4).
