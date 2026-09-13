# Phase 6 Handover — i18n & RTL

**Status: Complete.** Verified with 86 Pest tests (534 assertions — the full Phase 1–5 suite re-run
alongside this phase's 12 new ones), Pint (258 files), Larastan (level 5), tsc, ESLint and Prettier all
clean, a production build, and a headless-Chromium pass over both languages in both areas.

Reference: `WAQAR-DELIVERY-ROADMAP.html` Phase 6 / `PROJECT-SYSTEM-DOCUMENTATION.html` Section 16, plus
Questions 2, 11 and 20.

## The roadmap's own completion bar

> Done when — switching locale flips direction and every string (DB content + static UI) on both
> storefront and admin, with no hard-coded English left in Arabic mode.

`Phase6LocalizationTest.php` covers the mechanism end to end, and `tools/check-translations.py` proves
the "no hard-coded English left" half mechanically rather than by eye: **0 missing keys across four
catalogs** (storefront ar/en, admin ar/en), and the tool exits non-zero if that ever stops being true.

## How locale works now

**The URL is the single source of truth** (Q20). Every storefront *and* admin route lives under
`/{locale}/…` — Section 16's "never a separate architecture" principle, applied even though only Arabic
is turned on for staff in v1. Two people sharing an `/en/product/x` link both see English regardless of
their own session.

- `SetLocale` validates the segment, sets the app locale, and registers it as a **default route
  parameter**, so `route()` — and Ziggy's client-side `route()`, through its `defaults` — keep a visitor
  inside their language without threading `locale` through hundreds of call sites.
- A bare URL **redirects** rather than rendering: `/shop` → `/ar/shop`. Arabic by default, or the
  visitor's last explicit choice from a preference cookie.
- The locale switcher navigates to the **sibling URL**, built server-side with the query string intact —
  switching language mid-search keeps the search.

## Two bugs this phase's own work surfaced

Both are the kind that look like the framework misbehaving until you find the actual rule.

**Route parameters are passed to controller actions positionally, not by name.** Adding `{locale}` as the
first URI segment therefore shifted every scalar argument by one — `ProductController::show(Request,
string $slug)` received `"en"` as the slug and 404'd on every product page. The fix is one line in
`SetLocale`: forget the route parameter once it has been applied. Every existing controller signature
then stays correct, and URL generation is unaffected because the registered default fills `{locale}` back
in. There is a regression test named after the mechanism, because the next person to add a route segment
will hit this again.

**Inertia shares props *before* calling `$next()`** — i.e. before route-group middleware — so the shared
locale was read one step too early and always reported the fallback. The shared value is now a closure,
resolved when the response is built.

## RTL — a real pass, as Section 16 demands

The spec is explicit that RTL must be QA'd component by component, not assumed to follow from a `dir`
attribute. Anvogue ships **no** RTL companion sheet (unlike Larkon, which has `app-rtl.min.css`), and its
compiled theme carries ~175 hard-coded directional declarations.

- `tools/build-rtl-css.py` generates `theme-rtl.css` from the compiled theme — 97 rules mirrored and
  rescoped under `[dir="rtl"]`, which outranks the LTR rule on specificity. Centering idioms
  (`left: 50%`, paired with a translate the script cannot see) and `@font-face` are deliberately left
  alone. Re-run it if the theme is ever re-vendored; the output is committed and reviewed.
- `tools/logical-properties.py` converted **108 physical Tailwind utilities** in the ported markup to
  logical ones (`pl-` → `ps-`, `left-` → `start-`, `text-left` → `text-start`), so they mirror from `dir`
  natively. It is value-aware on purpose: an earlier pass ate `left-content` and `right-content` —
  structural class names from the template's own markup — and even prose inside comments.
- Admin swaps in Larkon's own `app-rtl.min.css` under Arabic. Loading the LTR build under `dir="rtl"` is
  what broke that layout before this phase, and the brand-colour overrides were checked to still apply
  against the mirrored sheet.

## The font nobody's build check would have caught

Neither theme ships Arabic glyphs. **Arabic rendered as tofu boxes — empty rectangles — in both the
storefront and the admin**, and every test, typecheck and lint pass was green while it did. Only the
screenshot pass found it.

Cairo is vendored (`tools/fetch-arabic-font.sh`, woff2 only, arabic + latin subsets, 64 KB) rather than
pulled from Google at runtime — partly to match how Phosphor and icomoon are already handled, and partly
because an external `@import url(...)` written in `storefront.css` is **silently dropped** by the
Tailwind/Vite CSS pipeline.

The two apps order the stack differently, on purpose:

- **Storefront** lists Cairo *after* Instrument Sans. Font fallback is per-glyph, so Latin keeps the
  theme face and Arabic picks up Cairo automatically — in either locale, even mid-sentence.
- **Admin** puts Cairo *first*, because Q2 makes Arabic the primary script there and Cairo's Latin subset
  covers the SKUs, emails and numbers that stay Latin.

One wrinkle worth remembering: the Larkon theme sets `font-family` on `body` **and separately on every
heading**, so the page title alone stayed tofu after the body rule was in place.

## What is translated

| Layer | Where | Count |
|---|---|---|
| Storefront UI | `resources/js/storefront/locales/{ar,en}.json` | 338 keys |
| Admin UI | `resources/js/admin/locales/{ar,en}.json` | 327 keys |
| Server strings | `lang/{ar,en}.json` | 78 strings |
| Validation / auth / passwords / pagination | `lang/ar/*.php` | full set |
| Database content | `spatie/laravel-translatable` | already in place since Phase 1 |

Order statuses, review states and FAQ copy are keyed by the **server's own value**, so Section 03's enum
stays the contract and only the rendering is localised. All three notification classes are translated
too, including mail subjects and the database-channel payload the account's Notifications tab reads.

## Deliberate decisions worth flagging

- **A custom hook, not react-i18next.** Section 23 lists "react-i18next (or a lighter custom hook)". Two
  locales, catalogs small enough to ship in the bundle, no namespaces and no lazy loading — react-i18next's
  weight buys features this app has no use for, and the hook stays fully type-checked.
- **Validation messages are the PHP-array format**, not the JSON the spec prefers for app strings. Laravel
  resolves validation lines exclusively from `lang/{locale}/validation.php`; there is no JSON equivalent
  to use. Every other server string is JSON, as specified.
- **Arabic renders Western digits** for prices (`250.00 ج.م`). Egyptian e-commerce overwhelmingly uses
  them; Eastern Arabic numerals in a cart total read as unfamiliar rather than localised. One function to
  change if the business disagrees.
- ~~**Admin has no locale switcher.** Q2 ships staff Arabic-only for v1. `/en/admin/…` already works and
  is tested — turning it on is adding a switcher, not a translation project.~~
  **Superseded (2026-09-13):** the switcher was added on request, and the claim held — it was a UI
  change, not a translation project, because this phase kept the English catalog complete. See the
  README log entry for that date.
- **`/fr/shop` redirects to `/ar/fr/shop`, which then 404s.** A two-hop 404 rather than a direct one. The
  first segment of an unknown path is genuinely ambiguous — it could be a legitimate route — so the
  redirect preserves the "add the missing locale" intent that makes old `/shop` links keep working.
- **The generated demo product artwork has English baked into the image.** It is demo artwork (Phase 5's
  handover explains why none of the template's imagery was usable); real product photography uploaded
  through `/admin/products` is unaffected.

## Still open from Phase 5's security review

Two **confirmed High** findings remain unfixed — neither is touched by this phase:

1. **Unvalidated image upload** on `CategoryController` / `CollectionController` — the `image` field has
   no `mimes` rule, so an `.html` or `.svg` is stored and served same-origin as `text/html`.
2. **Stored XSS via product description** — `dangerouslySetInnerHTML` on unpurified rich text.

Both are small fixes and both cross a real privilege boundary. They should go in before launch.

## What Phase 7 needs to know

Next up: **QA & Hardening** (`WAQAR-DELIVERY-ROADMAP.html` Phase 7) — activitylog wiring, soft-delete
confirmation, the full Pest suite, and laramint's architecture graph as a sign-off gate.

- **Run `tools/check-translations.py` in CI.** It exits non-zero on a missing key and is the cheapest
  guard against a screen quietly shipping raw key names.
- **The screenshot pass earns its keep.** Every gate in this project was green while Arabic rendered as
  empty boxes across the entire site. `waqar-visual-verification` in the memory notes has the two
  non-obvious Chromium flags.
- **A CSP would close the same-origin leg of both open security findings**, and there is no
  `Content-Security-Policy` header anywhere in the repo — worth adding as part of hardening rather than
  as a separate task.
