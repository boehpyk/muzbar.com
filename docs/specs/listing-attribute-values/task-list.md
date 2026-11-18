# Task List: listing-attribute-values

> Ordered, small tasks in DDD canonical order. Each should be reviewable in < 5 minutes and is one
> commit on `feature/listing-attribute-values`. Run `make check` before each commit. Check off as you go.

**Status:** Approved by owner 2026-11-18 (Q1–Q9 at the recommendations).

**Rules for the whole slice:**
- Nothing under `Domain/Listing` or `Application/Listing` imports Catalog; nothing under
  `Domain/Catalog` or `Application/Catalog` imports Listing.
- **A table name appears only in its own context's Infrastructure:** `listing_attribute_value` only
  under `Infrastructure/Listing`, `catalog_attribute*` only under `Infrastructure/Catalog`.
- Every write of values runs inside `Categories::holdSteady()`, with `schemaOf()` read there; every
  usage read (C-25, C-26, C-27) runs inside `CategoryTreeLock::exclusively()`, next to the write it
  justifies.
- **`ListingRepository::save()` is the only writer of `listing_attribute_value`.** An EM flush alone
  persists no values.
- A port injected into an already-routed handler lands **with** its adapter, alias and `Observed*`
  fixture in the same commit.
- No GET writes. Form field types never answer before the Domain (no `IntegerType`, no `Length`).

## Decisions & prerequisites
- [x] **T0:** `/adr` → **ADR-0020** (plan D1–D9, D11 with rejected alternatives and consequences) and
      the dated amendments to **ADR-0004, ADR-0007, ADR-0016, ADR-0018, ADR-0019** (plan Risk 7). No code.

## Domain — `Listing`
- [x] **T1:** `AttributeId`, `AttributeOptionId` (Listing's copies + `Invalid…` exceptions),
      `AttributeKind` enum.
- [x] **T2:** `AttributeValue` (four named constructors, kind-exclusive readers, text rule re-applied)
      and `AttributeValues` (`none`, `of` refusing duplicates — L-8, `for`, sorted `toList`, `equals`).
- [x] **T3:** `InvalidAttributeValue` (six reasons, `attributeKey()`), `AttributeRule` with `read()`
      (L-9 per kind: option membership, ASCII whole numbers ≤ 18 digits + inclusive bounds, `yes`/`no`,
      1–200-char one-line text), `AttributeSchema` (`read()`, `has()`, `none()`), `AttributeInput`
      (`fromArray`, `onlyFor`).
- [x] **T4:** `Listing`: `attributeValues` property (`none()` in `draft()`), widened `revise()`
      (L-6 → L-2 → L-10 → L-9 → assign), `attributeValues()` reader; docblock (L-9 contract, the
      non-rules). (The port method `Categories::schemaOf()` lands in T9 with its adapter: adding it
      here would leave `CategoriesForListing` not implementing its interface — a fatal.)

## Domain — `Catalog`
- [x] **T5:** Port `AttributeUsage`; exception `AttributeInUse` (`cannotDelete`, `cannotRemoveOption`);
      `CategoryHasListings::cannotMove()`. Nothing injects them yet. (`CategoryUsage::hasAttributeValues()`
      lands in T10 with its adapter, for the same reason as T9.)

## Application
- [x] **T6:** `ReviseListing::$attributes`; `ReviseListingHandler` reads `schemaOf()` inside
      `holdSteady()`, builds `AttributeInput`, passes both to `revise()`. **Commit order: after T9, in one commit with
      T4's `revise()` change** (the handler calls both; phpstan fails on either alone); listed here for
      DDD order. Until T14 the controller passes
      `attributes: []`.

## Infrastructure — persistence and the seam
- [x] **T7:** Migration (hand-written: table, PK, `chk_listing_attribute_value_one_value`,
      `idx_listing_attribute_value_option`; no FK); widen `schema_filter` to exclude
      `listing_attribute_value` (comment extended). Prove `make migration.make` is empty.
- [x] **T8:** `DoctrineListingRepository::save()` writes values (delete + multi-row insert, typed
      params, comment "only writer"); `ListingAttributeValuesLoader` (`postLoad`, reflection, refuses a
      row with ≠ 1 typed column). Prove a save → fresh-EM load round trip and a Foundry `refresh()`
      before committing (Risk 4).
- [x] **T9:** Port method `Categories::schemaOf()` (docblock: only true inside `holdSteady()`) **and**
      `CategoriesForListing::schemaOf()` (bounded lineage CTE, `array_agg` option ids,
      `::bigint` bounds, kind mapped by value, unknown kind → `\LogicException`, `ponytail:` note on
      option counts). Update `ObservedCategories` so `CategoryTreeLockTest` compiles.
- [x] **T10:** Catalog guards, in one commit: `ListingAttributeUsage` adapter + `AttributeUsage` alias;
      C-25 in `DeleteAttributeHandler`, C-26 in `RemoveAttributeOptionHandler` (after C-18);
      `ListingCategoryUsage::hasAttributeValues()` + C-27 in `EditCategoryHandler` (after C-24, reusing
      the `descendantsOf()` result); accretion-point comments; `ObservedAttributeUsage` fixture.
- [x] **T11:** Admin refusal mapping: `AttributeAdminController` (delete → flash, option remove →
      flash), `CategoryAdminController::edit()` (`cannotMove` → 422 on `parent`, its own message).

## Infrastructure — UI
- [x] **T12:** `DbalAttributeSchema::optionsFor()` (one query; `[]` without a query for no ids) and
      `DbalSellerDrafts::valuesOf()`.
- [x] **T13:** `ListingFormType` `attributes` sub-form (types per plan D12, labels + unit, "required to
      publish" help, `invalid_message`s from the contract) and `ListingFormData::$attributes`;
      `_form.html.twig` Details fieldset.
- [x] **T14:** `SellController`: `new` → 303 to the edit page with *"Draft saved. Add the details
      below."*; `edit` builds the schema rows (effective + options + prefill), maps
      `InvalidAttributeValue` to `attributes[<key>]` with the contract's messages, redirects back to the
      edit page with the re-file notice when the category changed.

## Tests (qa)
- [x] **T15:** Domain unit: `AttributeRuleTest` (every AC-1 input), `AttributeSchemaTest`,
      `AttributeValuesTest`, `AttributeInputTest`, `ListingTest` additions (AC-3 incl.
      check-before-assign, AC-4 allow-list updated on purpose), id VO tests. (AC-1…AC-4)
- [x] **T16:** `ListingIsolationTest` data-ownership scan extended. (AC-5)
- [x] **T17:** Integration: `ListingFactory::withValues()` (through the aggregate and
      `ListingRepository::save()`); `ListingAttributeValuesSchemaTest` (AC-6 incl. two 23514 inserts);
      `ListingHandlersTest` additions (AC-7…AC-10, fresh EM before each read-back); adapter tests
      (AC-11, usage adapters); attribute/category handler additions (AC-12…AC-15);
      `ListingValuesPersistenceTest` (AC-17); `NoDdlTest` green.
- [x] **T18:** `CategoryTreeLockTest`: `schemaOf()` and `save()` inside `holdSteady()`; the three
      usage reads inside `exclusively()` (and C-27 skipped for an unchanged parent). (AC-16)
- [x] **T19:** Functional: `SellTest` additions (Details section, order, prefill, "Fretboard wood"
      gate preview, create → edit redirect with the listing-draft AC-25 assertion updated on purpose,
      save, re-file notice, every refusal's field + message + 422, 404 parity, query count 5 with 1 vs 4
      choice attributes, no-GET-writes), admin refusal tests, Sentry silence. (AC-18…AC-27)
- [ ] **T20:** Bite proof: AC-28 mutations (a)…(n) one at a time; each fails a named test. Revert,
      record in the commit message, run `make test` twice. (AC-28, AC-29)

## Docs
- [ ] **T21:** Update the docs:
      - **`docs/roadmap.md`:** fix the three stale "`/verify` pending" notes — line 227
        `catalog-attribute-schema` (passed 2026-11-15), line 247 `catalog-schema-inheritance` (passed
        2026-11-16), line 278 `listing-draft` (passed 2026-11-17); tick `listing-attribute-values`;
        note the UUID-trait refactor is now at eleven copies.
      - **`CLAUDE.md`:** status line (Listing slice 2 of 4); under *Cross-context dependencies*:
        `Categories::schemaOf()`, `AttributeUsage`, the watch items (values written only inside
        `holdSteady()`; attribute/option delete and parent changes read usage under `exclusively()`);
        footguns: **the repository is the only writer of values** (an EM flush persists none),
        `listing_attribute_value` is unmapped and hidden by `schema_filter` (AC-6 is its only shape
        guard), `ListingFactory::withValues()` must go through `save()`.
      - **`docs/infrastructure.md`:** deploy-window note extended (edit page, attribute/option delete,
        category move).
      - **`FORboehpyk.md`:** the chapter — "Parts without the ORM": why values are parts and options
        were not (ADR-0017's test run twice with opposite answers), why the Domain can't hold a
        Doctrine collection and what the UnitOfWork's insert-before-delete order would have done,
        parsing that needs a fact, and refusing now so the real rule can come later.

## Verify
- [ ] **T22:** `/verify` → reviewer PASS, AC-1…AC-29 checked, empty `make migration.make`, both
      isolation scans green. Then by hand on the dev stack:
      1. Create a draft on a leaf → land on its edit page with the Details section.
      2. Fill every kind; save; reopen — prefilled. Enter "7,5" for a number, a 201-char text: 422s.
      3. Re-file to a sibling leaf → back on the edit page with the notice; shared details kept.
      4. As admin: delete an attribute in use, remove an option in use, move the leaf's parent — three
         refusals. Narrow a number's bounds below the stored value — allowed; the draft's next save
         asks for a new number.
      5. Define "Fretboard wood" on the root → it appears on the draft's edit page.
      6. Sentry stays silent for every refusal.

      If a hand-check contradicts the green suite, suspect the runtime (opcache) before the code.
