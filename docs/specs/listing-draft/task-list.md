# Task List: listing-draft

> Ordered, small tasks in DDD canonical order. Each should be reviewable in < 5 minutes and is one
> commit on `feature/listing-draft`. Run `make check` before each commit. Check off as you go.

**Status:** Approved by owner 2026-11-16 (Q1 answered: USD, EUR, SEK, UAH; Q2–Q8 at defaults).

**Rules for the whole slice:**
- Nothing under `src/src/Domain/Listing/` or `src/src/Application/Listing/` imports another context;
  nothing under `Domain/Catalog` or `Application/Catalog` imports `Listing`.
- **A table name appears only in its own context's Infrastructure** (plan D4): `catalog_*` SQL only
  under `Infrastructure/Catalog`, `listing_*` only under `Infrastructure/Listing`.
- Every Listing write runs entirely inside `Categories::holdSteady()`; every `hasListings()` read
  runs inside `CategoryTreeLock::exclusively()`, next to the write it justifies.
- A port injected into an **already-routed** handler lands **with** its adapter, alias and
  `Observed*` fixture in the same commit (CLAUDE.md: Symfony compiles lazily).
- No float on any price path. No GET writes. No seller email rendered anywhere.

## Decisions & prerequisites
- [x] **T0:** `/adr` → **ADR-0019** (plan D1–D8, D10 with rejected alternatives, and the Phase 2
      cross-currency consequence) and the dated **ADR-0016** and **ADR-0007** amendments (plan
      Risk 7). No code.

## Domain — `Listing` (`src/src/Domain/Listing/`)
- [x] **T1:** `ListingId`, `SellerId`, `CategoryId` (Listing's) + `InvalidListingId`,
      `InvalidSellerId`, `InvalidCategoryId`. Docblocks per plan (ADR-0009 trait note; "Listing's
      name for an id another context owns").
- [x] **T2:** `ListingTitle`, `ListingDescription` + their exceptions. (L-3, L-4)
- [ ] **T3:** `Currency` (`USD|EUR|SEK|UAH`, `minorUnits()`, `fromCode()`), `Price` (`fromMinorUnits`,
      `fromDecimal` — string arithmetic only, `toDecimalString`, `equals`), `InvalidPrice`. (L-5, D7)
- [ ] **T4:** `ListingStatus`, `CategoryStatus`, exceptions `UnknownCategory`, `CategoryIsNotALeaf`,
      `NotTheSeller`, `ListingNotFound`; the `Listing` aggregate (`draft`, `revise`, `isSoldBy`,
      readers), every check before any assignment. (L-1, L-2, L-6, L-7)
- [ ] **T5:** Ports `ListingRepository` and `Categories` (docblocks: "only true inside
      `holdSteady()`"). Nothing injects them yet.

## Application — `Listing` (`src/src/Application/Listing/`)
- [ ] **T6:** `DraftListing` + `DraftListingHandler` (inside `holdSteady()`; malformed category id →
      `UnknownCategory::withMalformedId`).
- [ ] **T7:** `ReviseListing` + `ReviseListingHandler` (inside `holdSteady()`; malformed listing id →
      `ListingNotFound::withMalformedId`; status read unconditionally).

## Infrastructure — persistence and the cross-context seam
- [ ] **T8:** Listing persistence: five DBAL types + `doctrine.yaml` registrations;
      `Listing.orm.xml`; `embeddable/Price.orm.xml`; the two mapping blocks (`Listing`,
      `ListingValueObjects`); `DoctrineListingRepository`; the migration (generated, then read; no FK;
      two named indexes); `ListingRepository` alias. Prove a save → fresh-EM load round trip of a
      readonly `Price` before committing (plan Risk 5). (AC-20)
- [ ] **T9:** `PostgresCategoryTreeLock::sharedly()` (shared private `run()`), the
      `Infrastructure/Catalog/Integration/CategoriesForListing` adapter (`holdSteady` → `sharedly`,
      one-query `statusOf`), the `Categories` alias, and docblock updates on `KEY` and the
      `ponytail:` ceiling. (AC-17 first half)
- [ ] **T10:** Catalog guards, in one commit: port `CategoryUsage`, exception `CategoryHasListings`,
      adapter `Infrastructure/Listing/Integration/ListingCategoryUsage` + alias; C-23 in
      `DeleteCategoryHandler` (after C-13), C-24 in `CreateCategoryHandler` (parent not null, after
      C-6) and `EditCategoryHandler` (parent changed and not null, after `moveUnder()`); the
      accretion-point comments; `CategoryAdminController` refusal mapping (flash on delete, `parent`
      field on new/edit); an `ObservedCategoryUsage` fixture so `CategoryTreeLockTest` still
      compiles. (C-23, C-24, AC-30)

## Infrastructure — UI
- [ ] **T11:** `DbalSellerDrafts` (`forSeller`, `find`; seller filter in both SQL statements;
      malformed id → `null` without a query) and `LeafCategoryChoices` (from
      `DbalCategoryOutline::rows()`, leaves only, "A › B › C" labels).
- [ ] **T12:** `ListingFormType` / `ListingFormData`; `SellController` (`/sell`, `/sell/drafts`,
      `/sell/drafts/{id}/edit`, `#[IsGranted('ROLE_USER')]`, refusal → field mapping, 404 for
      `NotTheSeller`/`ListingNotFound`, PRG 303s); templates `listing/{sell,drafts,edit,_form}.html.twig`.
- [ ] **T13:** `security.yaml` `^/sell(/|$)` rule with the anchoring comment; "Sell Gear" in
      `base.html.twig`'s `auth_nav` block (ADR-0015 shape, guest vs signed-in); update the block
      comment.

## Tests (qa)
- [ ] **T14:** Domain unit: `PriceTest` (incl. `"0.29"`/`"1.15"` and the `float` source scan),
      `CurrencyTest`, `ListingTitleTest`, `ListingDescriptionTest`, id VO tests, `ListingTest`
      (incl. check-before-assign on every refusal and the method allow-list),
      `LeafCategoryChoicesTest`. (AC-1…AC-6)
- [ ] **T15:** `ListingIsolationTest` — context scan copied from `CatalogIsolationTest` plus the
      data-ownership scan, each asserting it scanned > 0 files. (AC-7, AC-8)
- [ ] **T16:** Integration: `ListingFactory` (through `draft()`, `->inCategory()`, docblock
      warning it bypasses L-2); `ListingHandlersTest` (AC-9…AC-12, fresh repository before each
      read-back); `CategoryHandlersTest` additions (AC-13…AC-16); adapter tests (AC-17);
      `ListingSchemaTest` (AC-20). Verify once that `holdSteady()` then `exclusively()` in one DAMA
      session does not deadlock (plan Risk 3) and note the result in the test's docblock.
- [ ] **T17:** `CategoryTreeLockTest`: `ObservedCategories` double; listing handlers read and save
      inside `holdSteady()`; the three Catalog handlers read `hasListings()` inside `exclusively()`
      (and create/edit skip it for a root / unchanged parent); the real-mode second-session probes
      from inside each closure, control first. (AC-18, AC-19)
- [ ] **T18:** Functional: `SellTest` (anonymous redirects + anchored 404s, intended action, form
      fields and leaf-only select, empty catalog, create, extra `seller` field, every refusal's
      field + message + 422, my drafts incl. query count and isolation between Ann and Bob, edit,
      404 parity, no-GET-writes snapshot); `LoginOverlayMarkupTest` updated on purpose (select
      triggers by text); `CategoryAdminTest` additions; Sentry silence. (AC-21…AC-32)
- [ ] **T19:** Bite proof: AC-33 mutations (a)…(h) one at a time; each must fail a named test.
      Revert, record in the commit message, run `make test` twice. (AC-33, AC-34)

## Docs
- [ ] **T20:** Update the docs:
      - **`CLAUDE.md`:** status line (Listing slice 1 of 4); a short *Cross-context dependencies*
        section: each context states its need as its own port, the adapter lives with the data's
        owner, the lock's shared mode, the "every new filing write goes through `holdSteady()`, every
        new leaf-breaking Catalog write reads `hasListings()`" watch item; `ListingFactory`
        bypasses L-2; money is minor units, never a float.
      - **`docs/roadmap.md`:** tick `listing-draft`; note the SCSS pipeline (Q7) and the UUID trait
        refactor (Risk 4) as candidates before `listing-publish`.
      - **`docs/infrastructure.md`:** the deploy-window note (guards query `listing_listing`; 500s
        until `migrate` completes).
      - **`FORboehpyk.md`:** the chapter — "One rule, two owners": why the listing can't protect
        leaf-only alone, the readers-writer lock as the seller/admin traffic light, why `1.15 * 100`
        is 114, and why the adapters live with the data's owner.

## Verify
- [ ] **T21:** `/verify` → reviewer PASS, AC-1…AC-34 checked, empty `make migration.make`, both
      isolation scans green. Then by hand on the dev stack:
      1. As a guest click "Sell Gear", sign in in the overlay, land on `/sell`.
      2. Draft "Fender Precision V", 1234,50 EUR, on a leaf; see it in "my drafts"; edit it.
      3. As a second user, open the first user's edit URL: 404.
      4. As admin: try to delete that leaf, create a child under it, move another category under
         it — three refusals. Move the leaf itself elsewhere — allowed.
      5. Check Mailpit/Sentry stay silent for all refusals.

      If a hand-check contradicts the green suite, suspect the runtime (opcache) before the code.
