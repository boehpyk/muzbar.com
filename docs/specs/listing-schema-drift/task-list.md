# Task List: listing-schema-drift

> Ordered, small tasks in DDD canonical order. Each should be reviewable in < 5 minutes and is one
> commit on `feature/listing-schema-drift`. Run `make check` before each commit. Check off as you go.

**Status:** Approved by owner 2026-11-21 (Q1–Q7 as recommended).

**Rules for the whole slice:**
- Nothing under `Domain/Listing` or `Application/Listing` imports Catalog; nothing under
  `Domain/Catalog` or `Application/Catalog` imports Listing. **"Archived" never enters Listing's
  language** — to Listing an archived attribute is simply not in force (plan D4).
- **Every Catalog write runs inside `CategoryTreeLock::exclusively()`** — the four new handlers too,
  each with a `CategoryTreeLockTest` case.
- **Nothing in this slice writes a `listing_attribute_value` row** except a seller's own revise. An
  archive, a restore or a move leaves every listing row byte-identical.
- Archive / restore are aggregate methods; hard delete stays handler + port (ADR-0012).
- `archived_at` comes from the `Clock` port, whole seconds. Never `new \DateTimeImmutable()`.
- No dead code: what C-27 needed is deleted in the same commit that retires it.
- No GET writes. Every new admin action is POST-only with its own CSRF token (403 before lookup).

## Decisions & prerequisites
- [x] **T0:** `/adr` → **ADR-0022** (plan D1–D10, Q1–Q5 with rejected alternatives and consequences)
      and the dated amendments to **ADR-0004, ADR-0016, ADR-0017, ADR-0018, ADR-0020, ADR-0021**
      (plan Risk 7). No code.

## Domain — `Catalog`
- [x] **T1:** `Attribute` and `AttributeOption`: `archivedAt` property (null in every constructor),
      `archive()` (no-op keeps the first instant), `restore()`, `archivedAt()`, `isArchived()`;
      `<field>` in both `.orm.xml`; the generated migration — **one commit** (a mapped property without
      its column fails every load). Prove `make migration.make` is empty afterwards. Docblocks: C-28,
      C-29 at `restore()`.
- [x] **T2:** `countForAttribute()` → `countActiveForAttribute()` on the port + adapter (`AND
      archived_at IS NULL`) + test doubles (`ObservedAttributeOptionRepository`,
      `RacingAttributeOptionRepository`); `allowOptionRemoval(int $activeOptionCount)` docblock;
      `RemoveAttributeOptionHandler` checks C-18 only for an active option. Docblocks "archived rows
      included, on purpose" on `codesDefinedOn()`, `existsForCategory()`, `isLabelTaken()`;
      `AttributeUsage` re-scoped to hard delete.

## Domain — `Listing`
- [x] **T3:** `AttributeValues::onlyFor()` / `::notIn()`.
- [x] **T4:** `Listing::revise()` carries dormant values over when the category is unchanged (L-17);
      `publish()` re-reads `onlyFor($schema)` only (L-14 narrowed). Docblock: L-17, stale vs dormant.

## Application — `Catalog`
- [x] **T5:** `ArchiveAttribute` + `RestoreAttribute` and handlers (`exclusively()`, `Clock` for
      archive, malformed id → `AttributeNotFound`).
- [x] **T6:** `ArchiveAttributeOption` + `RestoreAttributeOption` and handlers (option-of-attribute
      rule as in `RemoveAttributeOptionHandler`; archive: C-18 on `countActiveForAttribute()` when the
      option is active).
- [x] **T7:** Retire C-27: remove the block from `EditCategoryHandler` (rewrite the accretion-point
      comment), delete `CategoryUsage::hasAttributeValues()`, `ListingCategoryUsage::
      hasAttributeValues()`, `CategoryHasListings::cannotMove()`/`isMove()`, the
      `CategoryAdminController` message arm and `ObservedCategoryUsage`'s recording — and **invert**
      the C-27 assertions in `ListingValueGuardsTest`, `StatusBlindGuardsTest`,
      `ListingCategoryUsageTest` (comment naming this slice). One commit, so the suite is green at it.

## Infrastructure — read side
- [x] **T8:** `CategoriesForListing::schemaOf()`: archived filters **in the join conditions** (D4).
- [x] **T9:** `DbalAttributeSchema`: `effectiveFor()` active only + active `option_count`;
      `archivedDefinedOn()`; `archivedAt` on `find()` / `optionsOf()` (own phpstan types);
      `optionsFor(…, keep)` with `archived` per row. `optionLabels()` unchanged.

## Infrastructure — UI
- [x] **T10:** `AttributeAdminController`: the four POST routes (CSRF → 403 first, 303 + flash,
      `AttributeNeedsAnOption` → error flash); C-25/C-26 flashes point at Archive.
- [x] **T11:** Admin templates: Archive per active attribute and option; "Archived" section on the
      defining category's list (with "Archived on …", Restore, Delete); "Archived options" on the edit
      page; the archived-attribute banner with Restore.
- [x] **T12:** `SellController::edit()`: values before schema (still five queries); `keep` = stored
      choice option ids; *"<label> (no longer offered)"*; the *"<label> is no longer offered. Choose
      another."* refusal.

## Tests (qa)
- [x] **T13:** Domain unit: `AttributeTest`, `AttributeOptionTest` (AC-1, AC-2, allow-lists AC-7),
      `AttributeValuesTest` (AC-6), `ListingTest` (AC-3…AC-5); isolation scans green (AC-7).
- [x] **T14:** Factories: `AttributeFactory->archived()`, `AttributeOptionFactory->archived()`, through
      `archive()`. No factory may build a dormant value — tests make one through the handlers.
- [x] **T15:** Integration: schema shape (AC-8); new `SchemaDriftTest` (AC-9, AC-10, AC-14 — fresh
      repository before every read-back, raw-row snapshots); Catalog handler tests (AC-11, AC-12,
      AC-13).
- [x] **T16:** `CategoryTreeLockTest` (four new handlers; `EditCategoryHandler` asks nothing about
      values) and `NoDdlTest` (four new handlers). (AC-15)
- [x] **T17:** Functional: admin pages and actions (AC-16…AC-19), `SellTest` (AC-20 incl. the
      five-query budget), `PublicListingTest` (AC-21), GET-no-write + Sentry silence (AC-22).
- [ ] **T18:** Bite proof: AC-23 mutations (a)…(o) one at a time; each fails a named test. Revert,
      record in the commit message, run `make test` twice and once with `zend.assertions=-1`. (AC-23,
      AC-24)

## Docs
- [ ] **T19:** Update the docs:
      - **`docs/roadmap.md`:** tick `listing-schema-drift`; add to the `phase1-gate` line that the
        facet-count query must scope to the subtree + `published`, offer only active attributes, and
        join active options (plan Risk 3) — the `EXPLAIN` is taken on **that** query.
      - **`CLAUDE.md`:** status line (Listing slice 4 of 4); *Cross-context dependencies*: C-27
        retired, `hasAttributeValues()` gone, C-25/C-26 guard hard delete only and count dormant
        values; footguns: **dormant values are kept by revise and dropped by re-file — never "clean
        them up"**; **archived names stay reserved, so restore takes no facts — do not free them
        without a C-21/C-17 check on restore**; `effectiveFor()`/`schemaOf()` are active-only and
        `optionLabels()` is deliberately not; `countActiveForAttribute()`; facet queries must filter
        archived rows (Risk 3).
      - **`docs/infrastructure.md`:** deploy-window note extended (`archived_at`).
      - **`FORboehpyk.md`:** the chapter — "Letting go of an invariant": refuse vs tolerate, archive is
        a transition and delete is not, dormant vs stale, why a reserved name makes restore free.

## Verify
- [ ] **T20:** `/verify` → reviewer PASS, AC-1…AC-24 checked, empty `make migration.make`, both
      isolation scans green. Then by hand on the dev stack (Chromium):
      1. As admin, archive Brand (used by a published listing) → its public page loses "Brand", the
         seller's edit page has no Brand field; the seller fixes a typo; restore Brand → "Brand:
         Fender" is back on the public page.
      2. Archive Fender → public page still says Fender; the edit page shows "Fender (no longer
         offered)" selected; saving asks for another option.
      3. Archive Gibson too → refused ("at least one option sellers can pick").
      4. Delete an archived, unused attribute → gone; delete an in-use one → flash points at Archive.
      5. Move a category with published listings under another root → succeeds; the public page hides
         the details that left; move it back → they return.
      6. Sentry stays silent for every refusal.

      If a hand-check contradicts the green suite, suspect the runtime (opcache) before the code.
