# Task List: listing-publish

> Ordered, small tasks in DDD canonical order. Each should be reviewable in < 5 minutes and is one
> commit on `feature/listing-publish`. Run `make check` before each commit. Check off as you go.

**Status:** Approved by owner 2026-11-20 (Q8: no rename).

**Rules for the whole slice:**
- Nothing under `Domain/Listing` or `Application/Listing` imports Catalog; nothing under
  `Domain/Catalog` or `Application/Catalog` imports Listing. **No Catalog Domain/Application file
  changes at all.**
- **Every Listing write runs inside `Categories::holdSteady()`** — withdraw included (plan D7).
- **Events are released inside `holdSteady()` and dispatched after it returns** (plan D5). Never
  dispatch inside the closure.
- **The public read never selects `seller_id`**, and filters `status = 'published'` in SQL.
- Every check in `publish()`, `withdraw()` and `revise()` happens before any assignment or
  `recordThat()`.
- No GET writes. Withdraw is POST-only with its own CSRF token.

## Decisions & prerequisites
- [x] **T0:** `/adr` → **ADR-0021** (plan D1–D11 with rejected alternatives and consequences) and the
      dated amendments to **ADR-0008, ADR-0018, ADR-0019, ADR-0020** (plan Risk 7). No code.

## Domain — `Listing`
- [x] **T1:** `AttributeRule` gains `required` (every named constructor takes `bool $required`, no
      default) + `isRequired()`; `AttributeSchema::missingFrom()`; `AttributeValue::toRaw()`. Update
      `CategoriesForListing::schemaOf()` (select `a.is_required`) and `ListingFactory`'s rule
      builder **in the same commit** — the constructors change signature, so nothing compiles
      otherwise.
- [x] **T2:** `ListingStatus::Published`, `::Withdrawn`; exceptions `ListingIncomplete`,
      `ListingNotPublished`, `PublishedListingCannotBeRefiled`.
- [x] **T3:** Event `ListingPublished` (ids + `publishedAt`, docblock: no snapshot, no secret,
      dispatched after commit).
- [x] **T4:** `Listing`: `RecordsEvents`; `publishedAt` property (null in `draft()`) + mapping field
      in `Listing.orm.xml` + the generated migration (one commit: a mapped property without its
      column fails every load); `publish()` (L-6 → no-op → L-2 → stored-value L-9 → L-13 → assign +
      record), `withdraw()` (L-6 → no-op/refuse → assign), `revise()` gains L-15 and L-13-when-
      published; docblock rewritten (lifecycle, never-retroactive contract). Prove `make
      migration.make` is empty afterwards.

## Application
- [x] **T5:** `ReviseListing::$publish` (no default); `ReviseListingHandler` gains `Clock` and
      `DomainEventDispatcher`, publishes inside `holdSteady()` when asked, returns released events
      from the closure, dispatches after (call-site comment, D5). Controller passes `publish: false`
      until T13.
- [x] **T6:** `WithdrawListing` + `WithdrawListingHandler` (inside `holdSteady()`, malformed id →
      `ListingNotFound`).

## Infrastructure — read side
- [x] **T7:** `DbalAttributeSchema::optionLabels()` (one query by id; `[]` without a query).

## Infrastructure
- [x] **T8:** ~~URL/read-model rename~~ — dropped (Q8, owner 2026-11-20). Only the heading of
      `drafts.html.twig` becomes "Your listings" (done in T11).
- [x] **T9:** `DbalSellerDrafts`: rows gain `status`, `publishedAt`; `findPublished()` (status filter in
      SQL, no `seller_id`, malformed id → null with no query; own `@phpstan-type PublicRow`).
- [x] **T10:** Public `ListingController` (`GET /listings/{id}`, `app_listing_show`, no route
      requirements) + `listing/show.html.twig` (title, price, category path, "Listed on", escaped
      description with `nl2br`, Details `<dl>` in `effectiveFor()` order, no seller data — comment
      says why).
- [x] **T11:** "Your listings": title, status word per row, "View" link for published.
- [x] **T12:** `ListingFormType` option `published` (category `disabled` + help text); edit page:
      status, buttons per status (`action=save|publish`, in the template), public link, withdraw
      form.
- [x] **T13:** `SellController::edit()`: read `action`; `publish` only for Draft/Withdrawn with an
      unchanged category; the three success redirects + flashes; map `ListingIncomplete` (per field /
      on the form) and `PublishedListingCannotBeRefiled` (on `category`).
- [x] **T14:** `SellController::withdraw()` (POST, CSRF → 403 before lookup, ownership → 404,
      `ListingNotPublished` → error flash, 303).

## Tests (qa)
- [x] **T15:** Domain unit: `ListingTest` (AC-1…AC-4, AC-6 allow-list and the `RecordsEvents`
      assertion changed **on purpose**), `AttributeSchemaTest::missingFrom`, `AttributeValueTest`
      round trip (AC-5); `ListingIsolationTest`/`CatalogIsolationTest` green (AC-7).
- [x] **T16:** `ListingFactory::published()` / `->withdrawn()` (through the aggregate and
      `ListingRepository::save()`, **releasing events**), a fixed whole-second test Clock.
- [x] **T17:** Integration: schema (AC-8), handlers with a recording dispatcher and fresh EM reads
      (AC-9, AC-10, AC-12, AC-13), `schemaOf()` required (AC-11), status-blind Catalog guards
      (AC-14).
- [ ] **T18:** `CategoryTreeLockTest`: revise+publish reads and save inside `holdSteady()`, dispatch
      outside it; withdraw inside it. (AC-15)
- [ ] **T19:** Functional: `SellTest` (AC-16…AC-20, AC-26), new `PublicListingTest` (AC-21…AC-25 —
      content, privacy incl. the SQL capture, 404 parity across four cases × three viewers, query
      budget with a 50-option attribute, no GET writes), Sentry silence (AC-27).
- [ ] **T20:** Bite proof: AC-28 mutations (a)…(o) one at a time; each fails a named test. Revert,
      record in the commit message, run `make test` twice and once with `zend.assertions=-1`. (AC-28,
      AC-29)

## Docs
- [ ] **T21:** Update the docs:
      - **`docs/roadmap.md`:** tick `listing-publish`; re-order the "Candidates before
        `listing-publish`" line per Q7 — `uuid-id-trait` immediately after this slice, `public-styles`
        before Phase 2's public search UI; add a **seller contact channels** item before public launch
        (Q4).
      - **`CLAUDE.md`:** status line (Listing slice 3 of 4); under *Cross-context dependencies*: the
        guards are status-blind on purpose, the fifth crossing; footguns: **dispatch after
        `holdSteady()` returns, never inside**; `ListingFactory::published()` releases events;
        `required` is never retroactive (do not add a sweep); the public read never selects
        `seller_id`.
      - **`docs/infrastructure.md`:** deploy-window note extended (`published_at`).
      - **`FORboehpyk.md`:** the chapter — "Going public": a state machine where an invariant's force
        depends on the state, why "never retroactive" needs no mechanism, "after save" vs "after
        commit", and privacy as a column you never select.

## Verify
- [ ] **T22:** `/verify` → reviewer PASS, AC-1…AC-29 checked, empty `make migration.make`, both
      isolation scans green. Then by hand on the dev stack (Chromium):
      1. Draft with required details empty → "Save and publish" → 422 with every missing field
         marked; fill them → lands on `/listings/{id}`, shows path, price, details, "Listed on".
      2. Signed out, open the public URL → same page, no email anywhere in the source.
      3. Edit the published listing: category disabled; clear a required detail → 422; fix a typo →
         saved, still public.
      4. As admin: mark another attribute required and narrow a number's bounds → the public page is
         unchanged; the seller's next save asks for both.
      5. Withdraw → public URL 404 (also for the seller); "Your listings" says "Withdrawn"; re-file
         and "Save and publish again" → public with a new "Listed on".
      6. Sentry stays silent for every refusal.

      If a hand-check contradicts the green suite, suspect the runtime (opcache) before the code.
