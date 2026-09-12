# Phase 1 Handover — Core Schema Foundation

**Status: Complete.** Verified via a real migrate + seed against the dockerized MySQL, plus a permanent
Pest regression suite: a product with variants (translatable name/attributes) can be created, and a
customer address resolves through all four base geo levels (Governorate → City → Area). No
order/payment/inventory tables exist yet — that's Phase 2+.

Reference: `WAQAR-DELIVERY-ROADMAP.html` Phase 1 / `PROJECT-SYSTEM-DOCUMENTATION.html` Section 24 (schema)
and Section 25 (roadmap). Also ran per `.claude/skills/ecommerce-workflow/SKILL.md`'s Phase 1 guidance —
see the `/init` (CLAUDE.md) and `/security-review` notes below.

## What landed

**14 tables**, migrated clean: `countries`, `governorates`, `cities`, `areas`, `customers`, `employees`,
`addresses`, `categories`, `products`, `product_variants`, `attributes`, `attribute_values`,
`product_attribute_values`, `variant_attribute_values`. Every field/type/nullability matches
`PROJECT-SYSTEM-DOCUMENTATION.html` Section 24 exactly, **except** where Section 24's *final* schema
already assumes Phase 2 exists (see "Deliberately deferred to Phase 2" below).

**12 Eloquent models** (`app/Models/`), each wired to match: `HasTranslations` (spatie/laravel-translatable)
on every model with a `*` field in the spec, `SoftDeletes` where Section 23 named it, real relationships
(not just FKs), and `Product` implements `HasMedia`/`InteractsWithMedia` with a `product_images` collection.

**Two auth guards, not one `users` table** (spec Section 15, Q19) — `config/auth.php` now defines
`customer` and `employee` guards/providers pointing at the `Customer` and `Employee` models. The default
scaffold's `App\Models\User`, its factory, and the unused `users` table migration were removed. `Employee`
carries `HasRoles` on the `employee` guard explicitly (`protected $guard_name = 'employee'`) — `Customer`
does not get the trait at all, per Section 24's confirmation #13. **No login/registration screens or
controllers were built** — that's later-phase work; this only makes the models and guards resolve
correctly for when that work starts.

**`RoleSeeder`** seeds the 9 base roles (Section 15) on the `employee` guard — no permissions yet, that's
Phase 4's RBAC-matrix-editor work, not schema foundation. **`GeoSeeder`** seeds a small, clearly-marked
sample of Egypt's geography (1 country, 2 governorates, 3 cities, 4 areas) — enough to build and test
against, explicitly *not* the business's real complete coverage (that's admin-dashboard data entry, not
schema work). `DatabaseSeeder` calls both and creates one `Super Admin` employee
(`admin@waqar.test` / `password` — **local dev only**).

**`employees.team_leader_id`** (Question 16) is in from the start, with `teamLeader()`/`teamMembers()`
relationships on the `Employee` model — confirmed via test that the hierarchy resolves correctly.

## Deliberately deferred to Phase 2 (not a gap — sequencing)

Section 24's schema tables show their *final* state, which assumes tables Phase 2 hasn't built yet. Where
that created a dependency Phase 1 can't satisfy, the migration was written against the *base* 4-level
hierarchy instead, with a comment marking what Phase 2 needs to add:

- **`areas`** uses `city_id` (not `district_id`) — `districts` doesn't exist until Phase 2. Section 24's
  final schema has `areas.district_id` nullable; Phase 2's migration needs to decide whether `city_id`
  stays as a fallback or areas resolve through `district_id` only.
- **`addresses`** has `governorate_id`/`city_id`/`area_id` only, no `district_id` yet, same reason.
- **`products`** has no `product_type`/`inventory_tracking_enabled` and no category link — both are
  explicitly Phase 2 tasks (`product_categories` pivot included).

None of this blocks Phase 1's own "done when" — it only means Phase 2 has two small follow-up migrations
(`ALTER TABLE areas ADD district_id`, same for `addresses`) rather than getting it right the first time,
which was correct since `districts` genuinely didn't exist yet.

## Verification

```bash
docker compose run --rm app ./vendor/bin/pest tests/Feature/Phase1SchemaFoundationTest.php
```

4 tests, 15 assertions — product+variant+translatable-attributes creation, address-through-all-4-geo-levels,
role seeding + assignment, team leader hierarchy. Full suite (6 tests total), Pint, and Larastan (level 5,
0 errors) all pass clean.

**`/security-review` couldn't run** — it requires a git repository, and this project has never had one
initialized. That's a call for you to make, not something I did unprompted; `git init` whenever you want
that (and `/code-review`, which the skill also calls for at several later phases) available. In its place
I did a manual pass against the spec's own security checklist for what Phase 1 actually touched: password
hashing (`casts()` → `'password' => 'hashed'`, both `Customer`/`Employee`), mass-assignment protection
(`$fillable` on every model, no `$guarded = []`), and PII hidden from serialization (`password`,
`remember_token`, and — fixed during this pass — `national_id_number` on `Employee`).

## `/init` note

`CLAUDE.md` was generated per the skill's Phase 1 guidance *before* this phase's code was written, so it
documents the Docker-only-environment reality and the Actions/Services/Enums convention to follow —
worth a quick read-through once Phase 2/3 starts writing that layer, to confirm it's still accurate.

## Operational notes (in addition to Phase 0's)

- **File ownership cuts both ways.** Phase 0 covered root-created files being unwritable by the host
  user. Phase 1 hit the reverse: `pint`'s auto-fix (run without `--user root`) failed on host-owned files
  because the container's `www-data` (uid 82) has no write access to files owned by the host user at the
  default `644`. Any command that *writes* to existing files — not just installs new ones — needs the same
  `--user root ... && chown` pattern from `PHASE-0-HANDOVER.md`.
- **The first Pest run in a fresh process takes ~210s** (SQLite in-memory + all migrations, including the
  slow `spatie/laravel-permission` migration), even though later runs in the same process are fast. This
  is this environment's I/O overhead (WSL2/Docker Desktop), not a test or code problem — don't chase it.
- `vendor/pestphp/pest/.temp/` needs to stay world-writable (`chmod 777`, already done) or Pest's result
  cache throws a harmless-but-noisy permission warning after every run.

## What Phase 2 needs to know

Next up: **Schema Extensions** (`WAQAR-DELIVERY-ROADMAP.html` Phase 2) — `product_type` +
`inventory_tracking_enabled` on `products`, the `product_categories` pivot, `districts` (+ the
`areas`/`addresses` follow-up migrations noted above), level-flexible `shipping_rates`, `order_source` +
`delivery_assignment_type` on `orders` (which Phase 2 also creates the base of), the Backorder status,
the delivery module (`delivery_representatives`, `shipping_companies`, `delivery_assignments`,
`shipping_company_statements`), extended `payments` fields, `refunds`, and `return_reasons`. Full
field-level definitions: `PROJECT-SYSTEM-DOCUMENTATION.html` Section 24.
