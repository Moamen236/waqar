---
name: ecommerce-workflow
description: Maps whatever part of the Fashion E-Commerce Platform is being worked on (a phase, a module, a task) to the right built-in Claude Code skill — design, dataviz, init, run, code-review, security-review, simplify — and explains why. Use this before starting or reviewing work on products, inventory, checkout, orders, treasury, admin dashboard, or any other module in E-Commerce documentation.md.
---

# E-Commerce Workflow Guide

This project skill operationalizes the **"Recommended Claude Code Skills"** section of
[`E-Commerce documentation.md`](../../../E-Commerce%20documentation.md). It does not replace that
section — read it there for the full table and rationale. This skill's job is to take whatever the
user is currently doing and tell you which built-in skill to reach for, then optionally invoke it.

## How to use this skill

1. Identify what's being worked on right now: a development phase (1–10), a module (e.g.
   Checkout, Treasury, Admin Reports), or a free-form task description.
2. Look it up in the mapping below.
3. State which skill(s) apply and why, in one or two lines.
4. If the user's request is actually asking you to *do* the work (not just plan it), invoke the
   matched skill directly via the `Skill` tool rather than just describing it.
5. If nothing matches, say so plainly and proceed with the task normally — don't force-fit a skill.

## Mapping

| If the task involves... | Use | Why |
| --- | --- | --- |
| Laying out a storefront or admin screen before coding it (Homepage, Product Listing/Details, Checkout, Account, Admin Dashboard) | `/design` | Settles layout, states, and RTL/LTR variants before React/Inertia wiring |
| Building charts, KPI tiles, or the Reports screens (Sales, Orders, Inventory, Financial) | `/dataviz` | Correct chart types and color usage before implementation |
| Bootstrapping the repo, or the `Actions/Services/Enums` conventions aren't being followed consistently | `/init` | Generates/refreshes `CLAUDE.md` so those conventions are picked up automatically |
| Verifying a flow actually works (checkout, COD collection, stock reservation, returns) | `/run` | Launches the app and drives it — catches what tests miss |
| A diff/PR touches checkout, stock reservation, treasury, COD, or refunds | `/code-review` | These are the money- and concurrency-sensitive paths called out under "Concurrency Protection" and "Critical Tests" |
| Wrapping up a phase, or preparing for Phase 10 production hardening | `/code-review ultra` | Deep multi-agent review across the whole branch before calling a phase done |
| Auth, Roles & Permissions, admin routes, or payment/COD changes | `/security-review` | Checked directly against the doc's own "Security" checklist |
| Code works but is messy after a feature lands | `/simplify` | Reuse/efficiency cleanup pass, not a bug hunt |
| Anything involving an AI assistant, chat, or generated content | *(none — out of scope)* | The spec explicitly excludes AI features; see "Explicitly Excluded Features" |

## Phase quick-reference

- **Phase 1 (Foundation):** `/init` once scaffolding exists, `/security-review` for auth/roles.
- **Phase 2 (Catalog):** `/design` for product/category/collection screens.
- **Phase 3 (Inventory):** `/code-review` for stock reservation and transfer logic.
- **Phase 4 (Storefront):** `/design` for customer-facing pages.
- **Phase 5 (Checkout):** `/design` for the checkout flow, `/code-review` for the order-creation transaction, `/run` to verify end-to-end.
- **Phase 6 (Order Operations):** `/code-review` for status transitions and COD collection, `/run` to verify.
- **Phase 7 (Returns & Reviews):** `/code-review` for refund logic.
- **Phase 8 (Treasury):** `/dataviz` for financial reports, `/code-review` for transfers/reconciliation.
- **Phase 9 (Marketing):** `/design` for promo/coupon UI.
- **Phase 10 (Production):** `/code-review ultra` and `/security-review` as a final pass, `/simplify` for cleanup.
