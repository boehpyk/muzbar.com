# ADR-0020: Attribute values are parts of the Listing aggregate, persisted to a typed child table by the repository; Listing validates them against Catalog's effective schema read through its own port under the shared lock; Catalog refuses the writes that would strand them

- **Status:** Accepted
- **Date:** 2026-11-18
- **Established by** the `listing-attribute-values` slice ([spec](../specs/listing-attribute-values/feature-spec.md),
  [plan](../specs/listing-attribute-values/technical-plan.md) D1–D9, D11) — Listing slice 2 of 4, and
  the first slice in which a seller's data refers to the Catalog's **schema**, not only its tree.
- **Applies** [ADR-0004](./0004-dynamic-category-attribute-schema.md) §2 (the value layer: typed
  columns, generic indexes, no runtime DDL), [ADR-0009](./0009-email-verification-tokens-modelled-in-the-domain.md)
  decision 4 (references by id, no foreign key), [ADR-0012](./0012-challenge-retention-and-recurring-background-work.md)
  decision 2 (put in the Domain the part that can be wrong) and
  [ADR-0017](./0017-attribute-schema-attributes-and-options-as-separate-aggregates.md)'s test for
  parts (own references or an unbounded count) — this time with the opposite answer.
- **Extends** [ADR-0019](./0019-listing-context-cross-context-ports-leaf-only-under-one-lock-in-two-modes.md)
  (each context states a need as its own port; the adapter lives with the data's owner; one lock in
  two modes) to the schema, and answers [ADR-0018](./0018-schema-inheritance-derived-along-the-lineage-codes-unique-per-branch.md)
  decision 8 points 1, 3 and 4.
- **Amends** ADR-0004, ADR-0007, ADR-0016, ADR-0018 and ADR-0019 — see the dated 2026-11-18
  amendments at the foot of each.

## Context

A listing so far says what it is called, what it costs and where it is filed. What a buyer will
filter on — Brand, Orientation, String count — lives in the Catalog's attribute schema, inherited
along the lineage (C-22). This slice lets a seller fill those in on a draft: one **value** per
attribute, typed by the attribute's kind (an option, a whole number, yes/no, a short text).

Four questions are new:

- **Is a value a part or an aggregate?** ADR-0017 moved options out of `Attribute` because a Brand
  list is unbounded and listings point at options. Does the same test move values out of `Listing`?
- **How does an aggregate own a collection of parts that must live in a typed table**, when the Domain
  may not import `Doctrine\Common\Collections` (Deptrac's catch-all `Vendor` layer) and ADR-0004
  wants typed columns with generic indexes rather than a `jsonb` blob?
- **Parsing that needs a fact.** "42" is a fine *String count* and nonsense for *Orientation*.
  Whether input is valid depends on the attribute it answers — Catalog's data — read at the moment
  of the write.
- **A new way for one context to strand another's data.** A value names an attribute, an option, and
  (through its listing) a place in the tree. An admin can delete the attribute, remove the option or
  move the category. The roadmap rule — prod is consistent after each deploy on its own — means every
  such door ships guarded now, even though the real rule (soft delete, `listing-schema-drift`) comes
  later.

## Decision

1. **Values are parts of the `Listing` aggregate, not their own aggregate** (D1). ADR-0017's test
   applied honestly: a part leaves when it has **its own references** or an **unbounded count**. A
   value has neither — nothing points at a value (Phase 2 search *reads* rows, it holds no
   reference), and a listing has at most one per attribute of its category's schema, dozens at most.
   Every value rule is the listing's: one per attribute (L-8), fits *the listing's* category's
   schema (L-9), dropped on *the listing's* re-file (L-10). The listing is the consistency boundary.

2. **The aggregate holds an immutable `AttributeValues` value; the repository persists it to
   `listing_attribute_value`; a `postLoad` listener hydrates it** (D2). `Listing` has
   `private AttributeValues $attributeValues`, **not** a Doctrine-mapped field.
   `DoctrineListingRepository::save()` flushes the listing, then — on the same connection, inside the
   caller's transaction — deletes the listing's rows and inserts the current values in one statement.
   `ListingAttributeValuesLoader` (a `postLoad` entity listener) reads the rows and sets the property
   by reflection, which is what Doctrine itself does for every mapped field. postLoad fires once per
   hydration, so a second `get()` in one EntityManager never clobbers unsaved changes, and every load
   path (a fresh EM, Foundry's `refresh()`) gets values. Delete-and-reinsert is legitimate under
   ADR-0012: the rows are the aggregate's own state, rewritten whole, the way Doctrine rewrites a
   `jsonb` column. `ponytail:` rewrite-all on every save; diff against a load snapshot if a listing
   ever has hundreds of values.

3. **Listing asks Catalog for the schema through its existing `Categories` port:
   `schemaOf(CategoryId): AttributeSchema`** (D3). This is ADR-0018 d.8.4's `EffectiveSchema`, in
   Listing's words. `AttributeSchema` is a **fact** type (the `Lineage` / `CategoryStatus` sentence):
   true only inside `holdSteady()`, never stored. It carries attribute ids, kinds, option ids and
   bounds — **no labels**, because no Domain code shows anything. Its adapter is
   `CategoriesForListing`, which already lives with the catalog's data and already holds the lock.

4. **The aggregate reads the seller's input against the schema, inside `revise()`** (D4). The
   `Price::fromDecimal(string, Currency)` situation: parsing needs a fact, so it happens in the
   Domain, next to the fact. The command carries `array<string, string>` (attribute id → raw text);
   the handler builds an `AttributeInput`; `revise(…, AttributeSchema $schema, AttributeInput $input)`
   decides **L-6 → L-2 → L-10 (on re-file, keep only inputs in force on the new category) → L-9 (read
   each input against its `AttributeRule`, refuse) → assign**, every check before any assignment.
   One `revise()` keeps the category change and the values in one decision, which L-10 needs: values
   posted with a re-file were rendered for the *old* category. With the category unchanged, an input
   for an attribute not in force is **refused**, not dropped.

5. **The schema fact carries every option id of every choice attribute in force** (D5). One bounded
   recursive query over the lineage with an `array_agg` of option ids per attribute. `ponytail:` a
   5 000-entry Brand list is 5 000 ids per save; the upgrade is to load only the option ids the input
   mentions.

6. **Catalog refuses the writes that would strand a value — three handler checks under the exclusive
   lock** (D6), ADR-0019 decision 9's reasoning (a fact about someone else's data that the Catalog
   aggregate would combine with none of its own state):
   - **C-25** — an attribute in use cannot be deleted (`DeleteAttributeHandler`, after `get()`).
   - **C-26** — an option in use cannot be removed (`RemoveAttributeOptionHandler`, **after** C-18's
     `allowOptionRemoval()`: the aggregate's own rule first).
   - **C-27** — a category cannot be moved while any listing filed in its subtree holds any value
     (`EditCategoryHandler`, only when the parent changes, after C-24; order C-4 → C-5 → C-21 → C-24 →
     C-27). **Coarse on purpose** (owner, Q1): the precise rule — refuse only when a value would leave
     the effective schema — needs the old-minus-new lineage's attribute ids handed across, for a rare
     admin act that `listing-schema-drift` replaces anyway.

   New Catalog port **`AttributeUsage`** (`isInUse(AttributeId)`, `isOptionInUse(AttributeId,
   AttributeOptionId)` — the attribute id lets the query use the `(attribute_id, option_id)` index);
   `CategoryUsage` gains `hasAttributeValues(list<CategoryId>)`. New exception **`AttributeInUse`**
   (`cannotDelete()`, `cannotRemoveOption()`); `CategoryHasListings` gains `cannotMove()`.

7. **What is *not* guarded, on purpose** (D7). Recode, relabel (attribute or option), `required`,
   number rules, and defining new attributes strand nothing: values refer to ids, an attribute's type
   never changes (C-14), and bounds and `required` bite at the listing's next write and at publish —
   **never retroactively** (ADR-0018 D11.2). `required` is not enforced on a draft at all (L-11).

8. **Listing's own words for Catalog's ids and kinds** (D8). Local copies `AttributeId` and
   `AttributeOptionId` (ADR-0019 decision 2; the tenth and eleventh copies of the UUID shape) and
   `AttributeKind` (`Choice | Number | Boolean | Text`), backed by the **same strings** as Catalog's
   `AttributeType` so the adapter maps by value — never by import. An unknown string fails closed.

9. **Storage: one row per value, one typed column per kind, a hand-written CHECK** (D9).
   `listing_attribute_value (listing_id, attribute_id, option_id, value_int, value_bool,
   value_text)`, PK `(listing_id, attribute_id)` (the L-8 backstop, and load-by-listing),
   `idx_listing_attribute_value_option (attribute_id, option_id)` (C-25, C-26 and the Phase 1 gate's
   facet count), `CHECK (num_nonnulls(option_id, value_int, value_bool, value_text) = 1)`.
   `value_int` is `bigint` (bounds are PHP int64 — the `::int` lesson), `value_bool` is added to
   ADR-0004's sketch, `value_text` is `varchar(200)`. No FK. The table is **not mapped by Doctrine**
   and is excluded from the schema diff by `doctrine.dbal.schema_filter`, so the hand-written CHECK
   cannot re-diff.

10. **The edit form is built from Catalog's admin read model, not from the Domain fact** (D11).
    `SellController::edit()` reads `DbalAttributeSchema::effectiveFor()` (labels, units, `required`,
    order) and a new `optionsFor()` (all options of the listed choice attributes in one query). The
    Domain decides on `schemaOf()`, read again under the lock at POST; a schema change between render
    and submit only produces a refusal — fail closed. This is the **fourth** cross-context crossing,
    a UI read like `DbalCategoryOutline` (ADR-0019's consequences).

## Alternatives

**Part or aggregate (D1)**

- **`ListingAttributeValue` as its own aggregate** (the `AttributeOption` shape): a root per value, a
  repository, and L-8/L-10 become cross-instance invariants needing the lock *and* an index — for a
  reference nobody holds. Options left `Attribute` because a Brand list is unbounded and listings
  point at options; neither is true of a value.

**How the parts are persisted (D2)**

- **A Doctrine `<one-to-many>` to a value entity.** The Domain would hold a
  `Doctrine\Common\Collections\Collection` — a vendor import Deptrac rejects — or swap the
  `PersistentCollection` for a plain array and rely on `orphanRemoval`, where the UnitOfWork runs
  **inserts before deletions**, so re-saving a value for the same attribute collides on the
  `(listing_id, attribute_id)` key.
- **A `jsonb` column on `listing_listing`** (the `User`/`OAuthIdentity` shape, ADR-0014). One write
  path, but ADR-0004 §2 wants typed columns with generic `(attribute_id, option_id)` indexes for facet
  counts; `jsonb` *plus* the table is the two write paths ADR-0004 warns about.
- **Hydrate in `get()`:** misses every other load path (Foundry, fresh-EM tests) and must special-case
  the identity map.

**Where the schema comes from (D3)**

- **A new port `AttributeSchemas`:** a second seam with the same lock contract for a question about
  the same catalog.
- **Import a Catalog-side `EffectiveSchema`:** a Conformist import the isolation scan forbids.
- **Labels in the fact so the form can use it:** presentation inside a Domain type no Domain code
  reads.

**Who parses (D4)**

- **A separate `describe()` after `revise()`:** two calls in one handler, and a re-file would check
  values rendered for the old schema against the new one — every dropped attribute refused.
- **Typed value objects built by the form or handler:** the handler would need the schema to know
  which type to build, and "refuse vs drop" would leave the aggregate.

**Option ids in the fact (D5)**

- **Load only the options the input mentions:** a second port argument that leaks the input's shape
  into "what is the schema?", for a cost nobody has measured.

**Catalog's guards (D6)**

- **Soft delete now** (ADR-0004's original clause): `archived_at`, hiding from forms, and C-21's "does
  an archived code block?" — the whole of `listing-schema-drift`. Refusal is the fail-closed
  placeholder the roadmap asked for.
- **Facts into `Attribute::allowOptionRemoval()`:** C-18 combines a count with the attribute's own
  type; C-26 is a yes/no about listings.
- **Attribute questions on `CategoryUsage`:** the port's name would lie.
- **A precise move guard:** see decision 6.

**Not guarding (D7)**

- **Refuse a bound change that would invalidate stored values:** retroactive by another name, and one
  seller's draft would veto an admin's schema.

**Foreign ids and kinds (D8)**

- **Import Catalog's `AttributeType` and id classes:** forbidden by the isolation scan (ADR-0019 d.2).

**Storage (D9)**

- **A `kind` column:** derivable from which typed column is set.
- **Number-range, boolean and text indexes now:** no query yet; one migration each when Phase 2 writes
  one.
- **`value_text` as `text`:** Q3 bounds it at 200.

**The form (D11)**

- **Render from `schemaOf()`:** needs labels in the Domain fact.
- **A Listing-side copy of the lineage CTE:** `catalog_*` SQL under `Infrastructure/Listing`, which
  the data-ownership scan forbids.

## Consequences

- **Easy:** L-8…L-11 are unit-tested on `Listing` without a database. Adding "Fretboard wood" on the
  root shows up on every draft below it with no migration and no row touched — the Phase 1 gate's
  form-render half. Phase 2's facet counts read typed rows through one generic index.
- **The repository is the only writer of values.** An EntityManager flush alone persists **none** —
  any code that saves a `Listing` some other way (a fixture, an import, a test helper) silently loses
  its values. `ListingFactory::withValues()` goes through `ListingRepository::save()` for that reason.
- **The table is invisible to every Doctrine safety net.** Unmapped and filtered out of the diff: no
  `make migration.make`, no `doctrine:schema:validate`. Its shape is guarded only by an
  `information_schema` / `pg_constraint` test (AC-6); any future column change is a hand-written
  migration plus that test.
- **Reflection writes a private Domain property from Infrastructure** — in exactly one place, the
  loader. A load path that skipped it leaves the property uninitialised, which throws on first read:
  loud, not a silent empty set.
- **`required` and bounds are never retroactive.** Narrowing bounds or marking an attribute required
  leaves stored values alone; the listing's next save checks current rules (L-9) and `listing-publish`
  enforces `required`. A seller can be asked to fix a value they did not change — by design, in the
  same form.
- **The coarse move refusal is a placeholder.** Moving a category whose subtree has any listing with
  any value is refused until `listing-schema-drift` brings soft delete and a precise rule. Admins
  will meet it; the message says why.
- **The lock's watch list grows.** Every Listing write that changes values goes through
  `holdSteady()` and reads `schemaOf()` there; every Catalog write that deletes an attribute or option,
  or changes a parent, reads the matching usage under `exclusively()`. `CategoryTreeLockTest` covers
  the doors that exist; no index can see a missed one.
- **Four crossings now.** Besides the cross-context adapters, `SellController` reads two of
  Catalog's read models (`DbalCategoryOutline`, now also `DbalAttributeSchema`). A change to either is
  a change to `/sell`; `SellTest` is what notices.
- **Eleven copies of the UUID id shape.** ADR-0009's trait refactor should be scheduled before
  `listing-publish`.
- **No domain events** (plan D10): a draft's details are private; `listing-publish` designs
  `ListingPublished`.
