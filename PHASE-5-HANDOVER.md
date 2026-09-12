# Phase 5 Handover — Storefront Rebuild (Anvogue → React/Tailwind)

**Status: Complete.** Verified with 60 Pest tests (356 assertions — the full Phase 1–4 suite re-run
alongside this phase's 25 new ones), Pint (238 files) and Larastan (level 5) both clean, a production
`vite build` producing separate storefront/admin bundles, a `migrate:fresh --seed --force` against the
real dockerized MySQL, and a real end-to-end curl walkthrough against the running app — guest browse →
add to cart → server-resolved shipping → COD checkout → order tracking, plus an authenticated pass over
every account route.

Reference: `WAQAR-DELIVERY-ROADMAP.html` Phase 5 / `PROJECT-SYSTEM-DOCUMENTATION.html` Sections 08, 11,
13, 17, and Section 20 items #14, #15, #16.

## The roadmap's own completion bar

> Done when — a guest can browse, add to cart, check out COD-only, and track the order by number +
> email, entirely on the rebuilt Tailwind/React pages.

`Phase5StorefrontTest.php`'s first test — *"it walks a guest through browse → cart → COD checkout →
order tracking, with no login anywhere"* — is exactly this, driven through the real HTTP routes rather
than by calling Actions directly. It also asserts the rule that matters most underneath it: after
checkout the variant's stock is **reserved (2), not deducted (still 50)**, because deduction only ever
happens on Accounting-confirmed Delivered (Section 07). The same walkthrough was repeated by hand with
curl against the running container, ending at order #1001 and a populated tracking timeline.

## The template question, answered first

The template pages are the source of the markup, not a loose inspiration — the same standing direction
that governs the admin side. Concretely:

**Anvogue's real compiled theme CSS is what styles the storefront.** `dist/output-scss.css` (the
template's own compiled SCSS — `.button-main`, `.heading3`, `.product-item`, `.top-nav`,
`.modal-cart-block`, the shop/cart/checkout page styles) is vendored verbatim to
`resources/css/anvogue/theme.css`, with icomoon's `@font-face` + font files alongside it. The SCSS
*source* is still dropped — there is no sass step in the pipeline, which is what Section 23 and the
roadmap actually require. Unlike the admin's Larkon bundle this one is a **Vite build input rather than
a static `<link>`**, because it carries no local `url()` references of its own (only a Google Fonts
`@import`), so there is nothing fragile for Vite to rewrite.

**Layer order is what makes it behave like the template.** `resources/css/storefront.css` declares
`@layer theme, base, anvogue, components, utilities;` before importing Tailwind, so Anvogue's component
CSS sits between Tailwind's base and its utilities — mirroring the template's original
`output-scss.css` → `output-tailwind.css` load order, so a utility class still beats the theme component
class it's combined with on the same element. Verified in the built CSS: `theme` → `base` → `anvogue` →
`utilities`, with `.button-main` inside the `anvogue` block.

**Each page is ported from its confirmed Section 17 base**, with that section's required changes applied
rather than carried over:

| Page | Template base | Applied changes |
|---|---|---|
| `Home` | `index.html` | Real categories/collections/product queries; brand rail dropped (products have no brands); newsletter modal dropped |
| `Shop/Index` | `shop-breadcrumb1.html` | Brands + stray height/weight filters removed; **Rating and Availability filters added** (§20 #15); facets read the real attribute catalog |
| `Product/Show` | `product-default.html` | `brand` gone; real per-variant SKU; Size Guide reads the variant's Section 06 weight range; Compare + Quick View dropped |
| `Cart/Index` | `cart.html` | Free-text shipping estimator → the real geo cascade + server-resolved rate; fake "cart expires in" countdown dropped |
| `Checkout/Index` | `checkout2.html` | Every non-COD option and every card field removed (Q12); "Pickup in store" removed (Q4); Country/State/City/Zip → Governorate → City → District → Area, Postal Code dropped |
| `Account/*` | `my-account.html` | Billing tab dropped (no card data exists); **Reviews / Notifications / Recently-Viewed tabs added** (§20 #16); tabs became real routes |
| `OrderTracking/Index` | `order-tracking.html` | Generic progress bar → the real 5-stage customer timeline + Postponed/Cancelled/Returned (Section 03) |
| `Auth/*` | `login.html`, `register.html`, `forgot-password.html` | Name + Phone added to registration (Section 13); reset-password page added (template has none) |
| `Wishlist/Index` | `wishlist.html` | Wired to real `wishlists`/`wishlist_items`, rendered inside the account shell |
| `Search/Index` | `search-result.html` | Plain MySQL search, no Scout |
| `Pages/*`, `Errors/NotFound` | `about.html`, `contact.html`, `faqs.html`, `page-not-found.html` | Static routes, no CMS; FAQ answers rewritten from this system's actual rules |
| Shell | `index.html` header/footer/menu-mobile/menu_bar | Currency switcher removed (Q11); cosmetic language switcher removed (Phase 6 does the real one); mega-menu → real category tree; no blog anywhere |

**Dropped outright, as Section 17 decided:** every `blog-*` page and the whole blog module,
`compare.html` (Q3), `store-list.html` (Q4), and every unused homepage/shop/product variant.

The template's own `main.js` / `shop.js` / `product-detail.js` are **not** loaded — they mutate the DOM
directly and assume a non-React lifecycle. Every interaction they drove (mobile menu, sub-nav, search
modal, mini-cart drawer, tabs, quantity steppers, accordions) is reimplemented in React state against
the same class names and the same markup, the same way `AdminLayout.tsx` reimplemented Larkon's `app.js`
in Phase 4.

## What landed

**Customer auth on the `customer` guard** — login/register/logout, forgot-password and reset-password
on the `customers` password broker, `guest:customer` / `auth:customer` route groups, and
`redirectGuestsTo`/`redirectUsersTo` now routing non-admin traffic to the customer login and account
rather than the placeholder. Email + password only; no social/OTP login, matching both the rule and the
template.

**Cart (`CartService`)** — guest carts keyed by a session token, customer carts by `customer_id`, exactly
one of the two per row (Section 24). **No line price is ever stored**: every read recomputes from
`product_variants`, so a mid-session price change shows immediately. Guest carts merge into the
customer's on login, duplicate lines folded rather than duplicated. Stock is checked on the *combined*
line quantity, so adding one at a time can't walk past available stock.

**Checkout** reuses Phase 3's `CreateOrderAction` exactly as Customer Service's `/admin/orders/create`
does — same server-side pricing, same shipping resolution, same coupon validation, same reservation,
same COD payment record — with `OrderSource::Website` and no `createdByEmployee`. The request carries an
address and nothing about money.

**Shipping cascade + quote endpoint** (`/api/shipping/{governorates,cities,districts,areas,quote}`) —
`quote` returns the *server-resolved* rate plus the recomputed cart totals, so the browser never
computes or supplies a price. Verified live that the seeded area-level override (35) beats the
governorate base rate (60) for an address inside it, and that the base rate applies elsewhere.

**Account area** — dashboard, orders + order detail with the real timeline, customer-initiated cancel
(only while still pending — past Checking it's that department's call, per Section 03), addresses with
the full geo cascade and a single enforced default, profile + password, and the three tabs the template
lacks: Reviews, Notifications, Recently Viewed.

**New schema (3 migrations)** — `reviews` (Section 24's Engagement block, with `order_item_id` marking
verified-purchase and a pending/approved/rejected moderation state), `wishlists` + `wishlist_items`
(product-level, not variant-level), and Laravel's standard `notifications` table. Plus `ReviewStatus`
enum and the `Review`/`Wishlist`/`WishlistItem` models.

**`CouponService`** — extracted from `CreateOrderAction`'s private methods so the cart can preview the
same discount the order will be created with. The Action still calls it itself at order time; the cart's
preview is never accepted as input, and the applied coupon is stored as a *code* (in session — `carts`
has no coupon column), re-resolved on every read. Covered by a test that deactivates a coupon mid-session
and watches the discount drop to zero.

**Sample data for the storefront to run against** — `ShippingRateSeeder` (new; governorate base rates
plus one cheaper area override, so the most-specific-match fallback is exercised by real data, not only
by tests) and an extended `ProductSeeder` (Color/Size attributes with real values, six colour×size
variants carrying Section 06 size-guide weight ranges, a collection, and new/featured/on-sale flags).

## Deliberate scope decisions — worth flagging directly

- **Recently Viewed is a cookie, not a table.** Section 24 defines no `recently_viewed` table, and the
  list has to work for a guest browsing before they sign in. `App\Support\RecentlyViewed` stores product
  ids only — everything displayed is re-read from the catalog, so a tampered cookie can at worst list
  products the customer could already browse to.
- **The Notifications tab is real but empty.** It reads the real `notifications` table; the events that
  *write* to it (order placed/confirmed/shipped/delivered, return requested) are Section 23's
  notification work and aren't wired yet. Showing an honestly empty list beats seeding placeholders.
- **Guest checkout attaches the order to a customer record**, because `orders.customer_id` isn't
  nullable — reusing the record that already owns that email if there is one, exactly as Customer
  Service's phone-order flow does, and otherwise creating one with a generated password that can't be
  logged into until its owner resets it. The guest is never signed in and never gains access to that
  account. **Worth a second opinion before launch**: it does mean a guest who knows someone's email can
  place a COD order that lands in that person's order history. Nothing is charged and the goods go to
  the address typed at checkout, so the exposure is nuisance rather than loss — but it's a product
  decision, not a technical one.
- **Country is a fixed single option in the cascade, not a dropdown.** The seeded geography is
  Egypt-only (Section 11's sample data); offering a country choice would offer choices that resolve to
  no shipping rate. The level exists in the schema and the field becomes a real select the moment a
  second country is seeded.
- **The Contact form is presentational.** There is no contact-message model in Section 24 and inventing
  one wasn't this phase's scope; the page routes customers to the phone/email Customer Service actually
  works from.
- **Settings drops the template's Gender / Day-of-Birth / avatar fields.** `customers` carries name,
  email and phone (Section 24) — adding columns to fill template decoration isn't this phase's job.
- **No `/admin/shipping-rates` screen exists.** This is the one thing standing between this storefront
  and real-world use: without at least one `shipping_rates` row, checkout correctly refuses every order,
  and today rates can only be created by seeder or tinker. It's absent from Section 14's route list and
  from both Phase 4's and Phase 5's task tables, so it wasn't built here — but it is a genuine gap and
  the obvious first follow-up, in the same shape as Phase 4's Products/Categories addendum.
- **Product images.** `ProductPresenter` serves whatever `spatie/laravel-medialibrary` holds and falls
  back to the template's placeholder; the seeded demo products have no images, so the catalog renders
  placeholders until real ones are uploaded through `/admin/products`.
- **No visual screenshot pass.** No browser is available in this environment, so fidelity was verified
  structurally — the built CSS contains every Anvogue class the ported markup uses, in the intended
  cascade layer — not by eye. A quick look at `http://localhost:26991` is worth doing before sign-off.
- **The phosphor-icons package ships all four font formats** (woff2/woff/ttf/svg) for three weights, so
  `public/build/assets` carries ~9 MB of font files of which browsers fetch only the woff2. Harmless
  (nothing unused is downloaded) but worth trimming in Phase 7's pass if build size matters.

## Bugs and real problems this phase's verification caught

- **The test suite was destroying the development database on every run.** The `app` container exports
  `DB_CONNECTION`/`DB_DATABASE` (docker-compose.yml), PHP surfaces those in `$_SERVER`, and `$_SERVER` is
  the *first* source Laravel's `Env` repository reads — so `phpunit.xml`'s `<env>` entries never applied,
  even with `force="true"` (they only reach `$_ENV` and putenv). Every `pest` run was pointing
  `RefreshDatabase` at the live `waqar` database and dropping every table in it. Found by noticing the
  geo cascade had gone empty right after a test run, then confirmed with a probe test dumping all four
  env sources. **This is also the real cause of the "two concurrent test runs corrupt each other"
  symptom `PHASE-4-HANDOVER.md` documented** — both runs were migrating the same live database.
  Fixed in `tests/TestCase.php::createApplication()`, which pins the suite to `waqar_testing`;
  `docker/mysql/init/01-create-test-database.sql` creates and grants that database on first boot of a
  fresh volume. Deliberately still MySQL rather than sqlite, so the suite keeps catching MySQL-only
  strictness (the gap an earlier phase's `WarehouseSeeder` bug slipped through). Verified: seed 2
  products → run the full suite → still 2 products, and `waqar_testing` now has the 72 tables.
- **Inertia's `assertInertia()` could not find any page component**, because this project's two Pages
  directories match neither of the package's defaults. Added `config/inertia.php` pointing
  `pages.paths` at both `resources/js/storefront/Pages` and `resources/js/admin/Pages`. Without it every
  Inertia assertion fails on components that do exist — which would have quietly blocked this kind of
  test for the admin side too.
- **Inertia 404s rendered a view that doesn't exist.** The exception handler runs *outside* the
  middleware pipeline, so `HandleInertiaRequests::rootView()` never ran and Inertia reached for its
  default `app` view — this project has `storefront` and `admin`, not `app`, so every storefront 404 was
  a 500. Caught by a route smoke test, fixed with an explicit `Inertia::setRootView('storefront')` in the
  handler.
- **36 Larastan errors, same root cause as Phase 3 and 4.** Relations without generic PHPDoc resolve to
  `Model`, so every `->getTranslation()`/`->color_hex`/`->slug` through one was "undefined". Fixed the
  same documented way — real `@return BelongsToMany<X, $this>` / `@return HasMany<X, $this>` annotations
  on the Product/Variant/Attribute/AttributeValue/Category/Collection/Address/Order relations — plus the
  nested-closure array-shape gap, fixed the same way `GeoTree` was: one method per shape with its own
  explicit `@return array{...}` (`ProductPresenter::variant()`/`option()`,
  `ShopController::attributeOptions()`, `Account\OrderController::orderCard()`). No suppression.
- **Four Tailwind v3 → v4 behaviour differences** the ported markup depends on, restored explicitly in
  `storefront.css` rather than by rewriting every class: an un-coloured `border` (v4 defaults to
  `currentColor`, v3 to gray-200 — would have drawn black borders throughout), buttons losing their
  pointer cursor, the removed `flex-shrink-*`/`flex-grow-*` utilities the template uses heavily, and the
  radius scale shifting down a step (`rounded`, `rounded-sm`). `bg-opacity-10` — also removed in v4 —
  was rewritten as `bg-<color>/10` in `StatusTag.tsx` instead, since that one only appears in markup this
  phase authored.

## Operational notes (in addition to Phase 0–4's)

- **The Pest suite now has its own database** (`waqar_testing`). On an existing volume it already exists;
  on a fresh `docker compose up` the mysql init script creates it. If a suite run ever errors with
  "Unknown database 'waqar_testing'", create it by hand:
  `docker compose exec mysql mysql -h 127.0.0.1 -u root -proot -e "CREATE DATABASE waqar_testing; GRANT ALL ON waqar_testing.* TO 'waqar'@'%';"`
- `npm install`, `vite build` and `pint` still need `--user root` followed by the standard `chown` pass
  (Phase 0/4's note, unchanged).
- The first run of the suite against a brand-new empty `waqar_testing` had one flake before settling;
  every run since has been 60/60. Worth knowing rather than chasing if it reappears on a fresh volume.

## What Phase 6 needs to know

Next up: **i18n & RTL** (`WAQAR-DELIVERY-ROADMAP.html` Phase 6). Groundwork already in place:

- **Every storefront string is in one of two places** — JSX text in `resources/js/storefront/`, or
  `getTranslation(..., app()->getLocale())` on the server. There is no third place; nothing reads a
  translatable column directly.
- **`routes/store.php` hard-codes no URLs** — every link goes through Ziggy's `route()`. Wrapping the
  whole file in a `{locale}` prefix is a routing change, not a page change. `routes/web.php` already
  loads it as a group for exactly that reason.
- **The root views already read `app()->getLocale()`** for `<html lang dir>`, so Phase 6 only adds the
  middleware that sets it per request.
- **Tailwind's `rtl:`/`ltr:` variants are live** — the storefront runs a real Tailwind v4 build (not a
  precompiled stylesheet), which is what makes those variants available. **Anvogue's own theme CSS is
  LTR-only**, though, exactly like Larkon's on the admin side: `theme.css` has hard-coded `left`/`right`
  offsets throughout (`.top-nav`, `.sub-menu`, `.login-popup`, the modals). Expect to need an RTL
  companion sheet layered over `anvogue` — the template ships no `app-rtl` equivalent, so unlike the
  admin there is nothing to swap in, it has to be written.
- **Admin stays LTR** and English-only for v1 (Q2, and the standing note that the wired-in Larkon CSS is
  the LTR-only build).

---

## Addendum — every gap above, closed

Requested immediately after the phase landed, rather than deferred. Verified with **74 Pest tests (447
assertions)** — the full Phase 1–5 suite plus 14 new ones in `Phase5GapsTest.php` — Pint (246 files) and
Larastan both clean, and a rebuilt production bundle. Each heading below is one of the items flagged
above; the original text is left in place so the reasoning stays readable.

### 1. `/admin/shipping-rates` — the gap that blocked real-world use

Built, behind a new `delivery.rates.manage` permission seeded to Delivery Manager. `geo_type` +
`geo_id` are a polymorphic pointer no `exists:` rule can validate, so `ShippingRateController` checks
the id against the table the chosen level names and rejects a duplicate against the unique
`(geo_type, geo_id)` index with a readable error instead of a raw `QueryException`. The list page warns
about **governorates with no rate at any level** — the exact state in which checkout refuses every order
— and the form flattens each level into one labelled list ("Nasr City — Cairo") rather than making the
maintainer walk a second cascade per rate.

The test that matters walks the whole loop: a checkout that **fails** with no rates → a Delivery Manager
adds one through the real HTTP routes → the same checkout **succeeds at the rate just entered**. A second
test adds an area override, sees the quote drop to it, deletes it, and watches the quote fall back to the
governorate rate.

### 2. Notifications — the tab was real but empty

Wired, on the `database` + `mail` channels: `OrderPlacedNotification`, `OrderStatusUpdatedNotification`
and `ReturnRequestedNotification`.

They hang off **model observers** (`OrderObserver`, `OrderReturnObserver`) rather than off each Action.
That's deliberate: the customer-facing status *is* the event (Section 03's mapping table), it lives in one
column, and every Action that moves an order already writes it — five separate call sites would drift, and
a sixth would be missed the next time someone adds a transition. Every send goes through
`DB::afterCommit()`, because those Actions all run in a transaction: **a covering test places an order
that oversells and rolls back, and asserts nothing was sent.** Statuses the customer can't act on
(Checking opening then confirming an order — both "Processing") stay silent by design.

Not queued yet, on purpose: there's no Supervisor-managed worker until Phase 8, and queueing would leave
these sitting in Redis undelivered — including the database channel the account tab reads. Adding
`implements ShouldQueue` to the three classes is the change to make there. The account nav now carries an
unread badge.

### 3. Guest checkout attaching an order to a stranger's account

Fixed properly rather than documented away, with a new `customers.is_guest` flag:

- email belongs to **nobody** → create a guest record (generated password, unusable until reset);
- email belongs to an **existing guest record** → reuse it, refreshing the name/phone the courier will
  actually call, so repeat guest orders don't pile up duplicates;
- email belongs to a **registered account** → refused, with "please sign in to place this order".

Registration was the other half of it: a plain `unique:customers,email` rule would have permanently locked
a past guest out of their own address. `RegisterController` now **claims** the guest record instead —
same row, password set, `is_guest` cleared — so their earlier orders land in the account they just made
rather than being orphaned under a row nobody can sign into. A test proves exactly that, and another
proves registering over a *real* account is still blocked. Customer-Service-created customers are
unaffected: those are given a real password by an employee, so they're real accounts, not guest records.

### 4. Country was a hard-coded single option

Now a real select driven by the `countries` table. `GeoTree::countries()` returns the tree rooted one
level higher; admin screens keep `GeoTree::tree()`, which is already scoped to the country they operate
in. When exactly one country is configured it's auto-selected so nobody picks from a list of one, and an
address loaded from a saved record infers its country from its governorate rather than making the customer
re-pick. Only `governorate_id` downward is ever submitted — the country is implied, which is why
`orders`/`addresses` carry no `country_id` (Section 24).

### 5. The contact form was presentational

It sends — to `config('mail.support_address')`, over the business's own SMTP (Section 23), with the
customer's address on `Reply-To` so support can just hit reply. Still **no contact-message table**:
Section 24 defines none and Customer Service works from the inbox. Because it's a public endpoint that
sends mail it also got a honeypot field and a 3-per-10-minutes IP limit, both covered by test.

### 6. Product images — and every other image in the template

The honest finding, and it turned out to be bigger than the gap as written: **the Anvogue download ships
no photography at all.** Every file under `assets/images/collection/` is byte-identical (one grey square,
md5 `95668b93…`); the banners are one shared placeholder; the hero slider is a grey "670 × 805" box; the
about-us tiles are one repeated file. The licensed stock was stripped from the template before
distribution. Lifting "real imagery" from it was never possible.

So it's all generated, in Anvogue's own palette, and labelled "DEMO ARTWORK" on its face so nobody
mistakes it for the business's own:

- **Product images** are attached by `ProductSeeder` through the same medialibrary `product_images`
  collection `/admin/products` uploads into — distinct per product, exercising the real media path end to
  end rather than the hard-coded fallback.
- **Hero, banners, collection fallback, about-us tiles, 404 art and the favicon** are SVGs under
  `public/storefront/images/generated/`, replacing the stripped PNGs, which were then deleted along with
  the now-unused payment-card icons (COD-only has no card logos to show). `public/storefront/images` went
  from **3.5 MB of identical grey squares to 44 KB**.

This is the one place the build knowingly ships something the business must replace. It is visible on its
face rather than hidden, which is the point.

### 7. Build weight — phosphor fonts and the admin bundle

Both fixed; `public/build/assets` went from **~12 MB to ~3 MB** (and `public/storefront/images` from
3.5 MB to 44 KB alongside it — see item 6).

- Phosphor's CSS is vendored to `resources/css/phosphor/` with the `@font-face` `src` list trimmed to
  **woff2 only**. Upstream lists woff2/woff/ttf/svg and Vite emits every referenced file — ~9 MB of fonts
  no browser this app supports ever downloaded. The npm package was removed as now-unused.
- Page components resolve **lazily** in both entries (`import.meta.glob` without `eager`), so each page is
  its own Rollup chunk. The admin entry dropped from **1.65 MB to ~4 KB**; apexcharts, quill and
  react-select now load only on the pages that use them. This was Phase 4's deferred item and is no longer
  waiting on Phase 7.

### 8. Visual verification — and what it caught

Chromium runs headless inside the `app` container, so fidelity was checked against **rendered pages**, not
just structurally. It earned its keep immediately:

- It's what surfaced that the whole template ships one grey placeholder (item 6) — the structural check
  couldn't see that, because the markup and CSS were correct.
- It confirmed the template's own hover behaviour is intact rather than broken: on a listing card the
  product name fades out and the colour swatches fade in (`.product-item:hover.grid-type` in
  `theme.css`), which looks like "missing swatches" in a static screenshot until you read the rule.
- One finding was an artefact of the harness, not the app: media URLs are absolute against `APP_URL`, so
  inside the container `localhost:26991` doesn't resolve and images render broken. Fixed in the capture
  command with `--host-resolver-rules`, not in the app — the URLs are correct.

Two flags matter when repeating this: `--run-all-compositor-stages-before-draw` (without it
`--screenshot` hangs forever on this Chromium build) and `--host-resolver-rules`.

### Deliberately still unchanged

- **Settings has no Gender / Day-of-Birth / avatar.** Not a gap — `customers` carries name, email and
  phone (Section 24), and adding columns to satisfy template decoration would be inventing schema.
- **Dashboard widgets** remain the Phase 0 placeholder: Question 18 defers their design to a separate
  follow-up, by the spec's own words.
- **`/security-review` still can't run** — there is no git repository. Four phases have now flagged this;
  `git init` is a one-line fix whenever you want it, and worth it before launch given how much auth and
  money-handling code now exists.

### Reproducing the visual check

```bash
docker build -t waqar-shot -f - . <<'EOF'
FROM waqar-app:latest
USER root
RUN apk add --no-cache chromium
EOF

docker run --rm --network waqar_waqar -v "$PWD/shots:/shots" waqar-shot \
  chromium-browser --headless --no-sandbox --disable-gpu --disable-dev-shm-usage \
  --hide-scrollbars --run-all-compositor-stages-before-draw \
  --host-resolver-rules="MAP localhost:26991 nginx:80" \
  --window-size=1440,1700 --virtual-time-budget=9000 \
  --screenshot=/shots/home.png http://localhost:26991/
```

Both of those flags are load-bearing, and neither is obvious:

- **`--run-all-compositor-stages-before-draw`** — without it `--screenshot` hangs indefinitely on this
  Chromium build (152) rather than failing, which looks like a broken container.
- **`--host-resolver-rules`** — medialibrary URLs are absolute against `APP_URL`, so the page must be
  fetched as `localhost:26991` and that name mapped to the `nginx` container, or every product image
  renders broken in the capture while being perfectly fine in a real browser.

The `apk add chromium` layer takes a couple of minutes; building the image once makes repeat runs quick.
