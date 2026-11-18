# Feature Spec: listing-attribute-values

> The *what & why*. Disposable: it dies once the feature ships and its behaviour lives in tests +
> code. Make it **executable** (measurable, enumerated), not pretty. Must not contradict the
> [Constitution](../../constitution.md) or an accepted ADR.

- **Feature:** `listing-attribute-values`, slice **2 of 4** of the `Listing` skeleton roadmap item
  (after `listing-draft`; then `listing-publish`, `listing-schema-drift`; then the `phase1-gate`)
- **Bounded context(s):** **`Listing`** (the value layer, owned by the `Listing` aggregate) and
  **`Catalog`** (three new guards and one new fact it exposes). Neither context's Domain or
  Application layer imports the other (ADR-0019 decision 3).
- **Related PRD items:** US-1 / §6 (a seller describes the gear in the category's own terms), FR-1
  (the category schema drives the listing form), FR-3 groundwork (the typed value rows Phase 2's
  facets will count), PRD validation #2 (this slice is the gate's **dynamic form-render path**)
- **Governing ADRs:** [ADR-0004](../../adr/0004-dynamic-category-attribute-schema.md) §2 (the value
  layer: typed columns, generic indexes, no DDL) and its 2026-11-15 amendment (expires here),
  [ADR-0007](../../adr/0007-persistence-conventions-for-domain-aggregates.md),
  [ADR-0009](../../adr/0009-email-verification-tokens-modelled-in-the-domain.md) d.4 (id references,
  no FK), [ADR-0012](../../adr/0012-challenge-retention-and-recurring-background-work.md) (put in the
  Domain the part that can be wrong), [ADR-0016](../../adr/0016-category-tree-per-node-aggregates-under-one-tree-lock.md)
  (aggregate decides on facts, the lock makes them true, guards accrete),
  [ADR-0017](../../adr/0017-attribute-schema-attributes-and-options-as-separate-aggregates.md) (the
  "own references / unbounded count" test for parts), [ADR-0018](../../adr/0018-schema-inheritance-derived-along-the-lineage-codes-unique-per-branch.md)
  decision 8 (points 1, 3 and 4 are answered here), [ADR-0019](../../adr/0019-listing-context-cross-context-ports-leaf-only-under-one-lock-in-two-modes.md)
  (ports in each context's own words, adapter with the data's owner, one lock in two modes), and
  **ADR-0020** (new, task T0)
- **Status:** Approved by owner 2026-11-18 (Q1–Q9 at the recommended answers in the technical plan).
- **Date:** 2026-11-18

> **What this slice teaches, beyond the feature.**
>
> 1. **A collection of parts inside an aggregate that is not a Doctrine collection.** The values
>    belong to the listing (ADR-0017's test: bounded, nobody outside holds a reference to one), so
>    they are parts of `Listing`. But the Domain may not import `Doctrine\Common\Collections`
>    (Deptrac), and ADR-0004 wants them in a **typed table** for Phase 2's facet indexes, not in a
>    `jsonb` blob. So the aggregate holds an immutable `AttributeValues` value, and the **repository**
>    persists it to its child table — the textbook "the repository stores the whole aggregate"
>    sentence, made literal (plan D2).
> 2. **Parsing that needs a fact.** "42" is a fine value for *String count* and nonsense for
>    *Orientation*. Whether a seller's input is valid depends on the attribute it answers, which is
>    Catalog's — so Listing asks Catalog for an `AttributeSchema` (a fact, like `Lineage`, true only
>    under the lock), and the aggregate decides. This is the `EffectiveSchema` ADR-0018 d.8.4 deferred.
> 3. **A new way for one context to strand another's data, and every door to it.** A value refers to
>    an attribute, an option, and (through its listing) a place in the tree. Each of those can be
>    deleted or moved by an admin. The roadmap rule — *prod is consistent after each deploy on its
>    own* — means every such door ships guarded now, failing closed (refusal), even though the real
>    rule (soft delete, `listing-schema-drift`) comes later.

---

## Ubiquitous language (this slice)

| Term | Means | Not to be confused with |
|---|---|---|
| **Attribute value** (value) | What one listing says for one attribute: an option, a whole number, yes/no, or a short text. At most one per attribute per listing. | The attribute itself (Catalog's definition). An option (Catalog's). |
| **Details** | The seller-facing name for a listing's attribute values: the "Details" section of the edit form. | Description (free text, L-4). |
| **Attribute schema** (of a category) | Listing's view of a category's **effective schema** (ADR-0018 C-22): for every attribute in force on the category, its kind and the rules a value must meet (its options; its bounds). A **fact**: true only while the catalog is held steady. Never stored on the Listing side. | Catalog's admin read model `DbalAttributeSchema` (labels, counts, for display). |
| **Attribute rule** | One entry of the schema: an attribute id, its kind, and — for a choice its option ids, for a number its optional bounds. | Catalog's `NumberRules` (Listing never sees it). |
| **Attribute input** | What the seller submitted per attribute, as text, before it is read against a rule. Empty means "no value". | A value (input becomes a value only after its rule accepts it). |
| **In use** (attribute, option) | At least one listing, of any status, holds a value for that attribute / that option. | "Listed-on" (C-23/C-24: a listing is *filed under* a category). |
| **Re-file** | Changing a listing's category (L-2's second door). Now also re-reads which values still apply (L-10). | Moving a *category* (an admin act, C-27). |
| **Required to publish** | An attribute's `required` flag. Shown on the draft form as a hint; **not enforced on a draft** (D11.2 → `listing-publish`). | A required *core* field (title, price — L-3, L-5). |

### Invariants

Listing continues its `L-n` series after L-7; Catalog continues `C-n` after C-24.

| # | Invariant | Who decides | Facts / backstop |
|---|---|---|---|
| **L-8** | A listing holds **at most one value per attribute**. | `AttributeValues` (keyed by attribute id). | PK `(listing_id, attribute_id)`. |
| **L-9** | **Every value a listing is given is for an attribute in force on its category's effective schema, and fits that attribute's rule:** choice → one of *that attribute's* options; number → a whole number within the attribute's bounds (inclusive, each optional); boolean → yes or no; text → 1–200 characters after trimming, one line, no control characters, valid UTF-8. Checked on **every** write of the values (every revise), against the rules **current at that write**. | `Listing::revise()` via `AttributeSchema::read()`, on the schema read through `Categories::schemaOf()` inside `holdSteady()`. | Typed columns + `CHECK (num_nonnulls(...) = 1)`. No index can see the rule; the **shared lock** is the guarantee on Listing's side, **C-25/C-26/C-27** on Catalog's. |
| **L-10** | **Re-filing keeps only the values whose attribute is in force on the new category; the rest are dropped.** Kept values are re-checked (L-9). With the category unchanged, a value for an attribute not in force is **refused**, not dropped. | `Listing::revise()` (only when the category changes — L-2's shape). | — |
| **L-11** | **`required` is not enforced on a draft.** A draft with every required attribute empty saves. | `Listing` (by having no such check); `listing-publish` adds it (D11.2). | — |
| **C-25** | **An attribute in use cannot be deleted.** | `DeleteAttributeHandler`, under `CategoryTreeLock::exclusively()`. | `AttributeUsage::isInUse()` |
| **C-26** | **An option in use cannot be removed.** Checked after C-18 (never the last option). | `RemoveAttributeOptionHandler`, under the lock. | `AttributeUsage::isOptionInUse()` |
| **C-27** | **A category cannot be moved while any listing filed in its subtree holds any value** (D11.1, coarse, fail closed — Q1). Only when the parent changes. Order: C-4 → C-5 → C-21 → C-24 → C-27. | `EditCategoryHandler`, beside C-21/C-24, under the lock. | `CategoryUsage::hasAttributeValues()` |

**What these keep true together:** every stored value is for an attribute in its listing's effective
schema, and every stored choice value names an existing option of that attribute — Listing keeps it on
its writes (L-9, L-10), Catalog on its writes (C-25, C-26, C-27), one lock in two modes between them.

**What is deliberately *not* kept true for stored values:** that a number is within the attribute's
*current* bounds, and that required attributes are filled. Narrowing bounds and marking an attribute
required stay allowed; both bite at the listing's **next write** (L-9 checks current rules on every
revise) and at publish, **never retroactively** (ADR-0018 D11.2, roadmap).

Catalog writes that touch values' meaning but strand nothing, and so get **no guard**: recode
(values refer to `attribute_id`, never the code), relabel an attribute or an option (ids survive,
C-19), change `required`, change number rules, define a new attribute anywhere (nothing refers to it
yet). The type of an attribute never changes (C-14).

---

## User story

As a **seller with a draft**, I want **the edit page to ask for exactly the details of my gear's
category — Brand and Orientation for an electric guitar, String count for a bass — and to tell me
plainly when a value doesn't fit**, so that my listing says what buyers will filter on.

As **muzbar**, I want **every stored detail to mean what its attribute means, whatever admins later
do to the catalog**, so that Phase 2's facets count real, typed values and the Phase 1 gate can
add "Fretboard Wood" without corrupting a single existing row.

Secondary and ranked (Constitution §2): as the **developer**, I want to see how an aggregate owns a
collection of parts persisted to a typed child table **without the ORM in the Domain**, and how a
fact owned by one context (the schema) is used to decide an invariant in another.

---

## In scope

**Domain (`Listing`):** VOs `AttributeId`, `AttributeOptionId` (Listing's local copies),
`AttributeKind` enum, `AttributeValue`, `AttributeValues`, `AttributeInput`, `AttributeRule`,
`AttributeSchema`; exception `InvalidAttributeValue`; `Listing` gains `attributeValues()` and a
widened `revise()`; port `Categories` gains `schemaOf()`.

**Domain (`Catalog`):** port `AttributeUsage`; `CategoryUsage` gains `hasAttributeValues()`;
exception `AttributeInUse`; `CategoryHasListings` gains `cannotMove()`.

**Application:** `ReviseListing` gains `attributes`; `ReviseListingHandler` reads the schema inside
`holdSteady()`. `DeleteAttributeHandler` (C-25), `RemoveAttributeOptionHandler` (C-26),
`EditCategoryHandler` (C-27) gain their guards.

**Infrastructure:** table `listing_attribute_value` + migration; `DoctrineListingRepository::save()`
writes the values; a `postLoad` listener hydrates them; `CategoriesForListing::schemaOf()`;
`ListingAttributeUsage` adapter; `ListingCategoryUsage::hasAttributeValues()`;
`DbalAttributeSchema::optionsFor()`; `DbalSellerDrafts::valuesOf()`; the "Details" section on the
edit form; `/sell` now redirects to the new draft's edit page; admin refusal messages.

**Docs housekeeping:** `docs/roadmap.md` lines 227, 247, 278 still say "`/verify` pending" for
`catalog-attribute-schema`, `catalog-schema-inheritance` and `listing-draft`, which passed on
2026-11-15, 2026-11-16 and 2026-11-17.

---

## Non-goals (explicit, hold the line)

| Deliberately excluded | Belongs to / why |
|---|---|
| Enforcing `required`; enforcing narrowed bounds on stored values | `listing-publish` (D11.2: at publish and at the next write, never retroactively). |
| Soft delete of attributes/options (`archived_at`), allowing a move with values | `listing-schema-drift` — this slice **refuses** instead (fail closed). |
| A precise move guard (only values for attributes inherited from above the moved category) | Q1. Coarse now; precise or archive-based in `listing-schema-drift`. |
| Multi-select choice values ("Pickups: humbucker *and* single-coil") | Not asked for; additive (the PK would change to include `option_id`). |
| Decimal numbers | `NumberRules` are whole numbers (owner, catalog Q1). |
| Dependent options (Model under Brand) | ADR-0017 d.9, later. |
| The Live Component wizard / morphing form; a typeahead for long option lists | Phase 2. A plain `<select>`, even for thousands of brands (Q9). |
| Details on the **create** form `/sell` | The category is not known until submit; without JS the form can't morph. Create → edit page (Q4). |
| Facet indexes beyond `(attribute_id, option_id)`; facet queries; `EXPLAIN` | Phase 2 / `phase1-gate` (number-range and boolean indexes are one migration each, when a query needs them). |
| Hot-column promotion | Phase 2 latency gate (roadmap). |
| Domain events (`ListingDescribed`, …) | Plan D10: nothing consumes a private draft's details. |
| A shared UUID trait (now **eleven** copies) | ADR-0009's refactor, its own slice (candidate before `listing-publish`). |
| Styling | The SCSS pipeline is still not installed (listing-draft Q7). |

---

## Acceptance criteria (the Definition of Done checklist)

Fixture tree and schema (`CategoryFactory`, `AttributeFactory` built **in C-21-respecting order**,
`->inCategory()` always):

```
Instruments                       condition  (choice: New, Used)          required
├── Guitars                       brand      (choice: Fender, Gibson)     required
│   └── Electric (leaf)           strings    (number, 4…12)
│                                 has_case   (boolean)
│                                 serial     (text)
└── Bass (leaf)                   bass_strings (number, no bounds)
```

Effective schema of **Electric**: condition, brand, strings, has_case, serial. Of **Bass**:
condition, bass_strings. Two verified users, **Ann** (seller) and **Bob**. Ann has a draft **D** on
Electric.

### Values and aggregate (Domain unit tests, no kernel)

- [x] **AC-1 (`AttributeRule::read()`, L-9)** — for each, input → result:
      - choice(options A, B): `"A-id"` → option A; `" A-id "` → option A (trimmed); `""` and `"  "`
        → no value; `"C-id"` (another attribute's option), `"not-a-uuid"` → `InvalidAttributeValue`
        (not an option).
      - number(4…12): `"4"`, `"12"`, `" 7 "`, `"007"` → 4, 12, 7, 7; `"3"`, `"13"`, `"-1"` → out of range
        (*amended 2026-11-18 in /implement:* `"-1"` was listed as "not a whole number", which
        contradicts Q6 and this AC's own `"-5"`; the code won, on purpose);
        `"7.0"`, `"7,5"`, `"1e1"`, `"+7"`, `"1 0"`, `"٧"` (Arabic-Indic), `"1234567890123456789"`
        (19 digits) → not a whole number. number(no bounds): `"-5"` and `"999999999999999999"` accepted.
        number(min 4 only): `"1000000"` accepted, `"3"` refused.
      - boolean: `"yes"` → true, `"no"` → false; `"Yes"`, `"1"`, `"true"`, `"on"` → refused.
      - text: `" SN-123 "` → `"SN-123"`; 200 × `"ї"` accepted; 201 characters, `"a\nb"`, `"a\tb"`,
        `"a\0b"`, `"\xC3\x28"` (invalid UTF-8) → refused.
      Every refusal names the attribute (`InvalidAttributeValue::attributeId()`).
- [x] **AC-2 (`AttributeSchema::read()`)** — reads an `AttributeInput` against its rules into
      `AttributeValues`, skipping empty inputs; an input for an attribute not in the schema →
      `InvalidAttributeValue` (not in force). `AttributeValues` holds at most one value per attribute
      (L-8: building it with two for one id throws `\InvalidArgumentException`); `equals()` is
      order-independent.
- [x] **AC-3 (`Listing::revise()` with values)** — against Electric's schema:
      - Valid values for all five → `attributeValues()` returns exactly them.
      - Required attributes left empty → succeeds (L-11).
      - An invalid value → `InvalidAttributeValue`, and **every** reader (title, description, price,
        category, values) is unchanged.
      - By Bob → `NotTheSeller` even when a value is also invalid (L-6 first).
      - **Same category**, input for `bass_strings` (not in force) → refused, nothing changed.
      - **Re-file** Electric → Bass with input for condition, brand, strings: `condition` is kept,
        `brand` and `strings` are dropped, no refusal (L-10); with condition = an invalid value → refused
        (kept values are still checked).
      - Re-file to a `Branch` / `Missing` category → L-2's refusal, before any value is looked at.
- [x] **AC-4** — `Listing::draft()` produces `AttributeValues::none()`. `Listing`'s public methods are
      exactly listing-draft's AC-6 list plus `attributeValues` (allow-list test updated **on purpose**).
- [x] **AC-5 (isolation)** — `ListingIsolationTest` and `CatalogIsolationTest` pass; the
      data-ownership scan now also finds `listing_attribute_value` only under `Infrastructure/Listing`
      and `catalog_attribute` only under `Infrastructure/Catalog`, each scan counting > 0 files.

### Use cases against a real database (Integration, DAMA)

- [x] **AC-6 (schema)** — after the migration, `listing_attribute_value` has exactly the columns of
      plan *Data & migrations* (types, nullability), PK `(listing_id, attribute_id)`, index
      `idx_listing_attribute_value_option (attribute_id, option_id)`, the check constraint
      `chk_listing_attribute_value_one_value`, and **no foreign key**. Inserting a row with two typed
      columns set, or none, fails with SQLSTATE 23514. `make migration.make` afterwards generates
      **nothing**.
- [x] **AC-7 (persist)** — Ann revising D with condition = Used, brand = Fender, strings = "6",
      has_case = "yes", serial = "SN-1" writes exactly five rows, each with only its typed column set
      (`option_id` ×2, `value_int` = 6, `value_bool` = true, `value_text` = 'SN-1'). A **fresh
      EntityManager** load of D returns equal `AttributeValues`. Revising again with serial = "" and
      strings = "8" leaves four rows (serial's row gone, strings = 8). Revising with nothing changed
      leaves the same rows.
- [x] **AC-8 (refusals write nothing)** — each AC-1 refusal class, sent through `ReviseListingHandler`,
      leaves `listing_listing` and `listing_attribute_value` identical to a snapshot taken before.
- [x] **AC-9 (re-file, L-10)** — with AC-7's values, re-filing D to Bass leaves exactly one row
      (condition); `brand`, `strings`, `has_case`, `serial` rows are gone.
- [x] **AC-10 (never retroactive)** — with strings = 6 stored, an admin narrows strings to 7…12: the
      edit succeeds and D's row still says 6. Ann's next revise sending strings = "6" is refused on
      strings; sending "7" succeeds. Marking `has_case` required and defining a new required attribute
      on Instruments both succeed and leave D's rows unchanged; Ann's next revise without them succeeds
      (L-11).
- [x] **AC-11 (`Categories::schemaOf()`)** — for Electric: exactly the five attributes (inherited from
      Instruments and Guitars included, Bass's excluded), each with its kind; condition's and brand's
      option ids in id order; strings' bounds 4 and 12. For Bass: two. Unknown id → empty schema. A
      legacy duplicate code on one branch (ADR-0018 d.7) yields both attributes without error.
- [x] **AC-12 (C-25)** — with D holding a brand value: `DeleteAttribute(brand)` throws
      `AttributeInUse`; the attribute and both options remain. `DeleteAttribute(serial)` when no
      listing holds a serial value succeeds even though D is filed under Electric.
- [x] **AC-13 (C-26)** — with D holding brand = Fender: removing Fender throws `AttributeInUse`;
      removing Gibson succeeds. With Fender the **only** option and in use, the refusal is C-18's
      `AttributeNeedsAnOption` (C-18 → C-26).
- [x] **AC-14 (C-27)** — with D holding any value:
      - moving **Electric** under **Instruments** throws `CategoryHasListings` (cannot move) and its
        `parent_id` is unchanged; moving **Guitars** (D's ancestor) under **Bass** is refused the same
        way (the subtree, not only the category);
      - with D holding **no** values, both moves succeed (C-24 only looks at the new parent);
      - renaming and re-slugging Electric succeed; saving Electric with its current parent reads no
        values;
      - order: Guitars under Electric reports the cycle (C-4 first); with a second draft on Bass,
        Guitars under Bass reports C-24 (`cannotGainChild`) before C-27.
- [x] **AC-15 (unguarded on purpose)** — with D holding a value for every attribute: recoding strings,
      relabelling brand, relabelling the Fender option, toggling `required` on condition, and changing
      strings' bounds all succeed, and D's five rows are byte-identical before and after.
- [x] **AC-16 (the lock, recording doubles)** — `ReviseListingHandler` calls `statusOf()`,
      `schemaOf()` and `save()` inside `holdSteady()`. `DeleteAttributeHandler`,
      `RemoveAttributeOptionHandler` and `EditCategoryHandler` (parent changed) call their usage reads
      inside `exclusively()`. Each removal of a lock fails a named test.
- [x] **AC-17 (values persist only through the repository; hydrate on every load)** — a listing loaded
      by `EntityManager::find()` in a fresh EM, and one refreshed by Foundry, both carry their stored
      values. `ListingFactory::withValues()` writes rows through `ListingRepository::save()` (asserted
      by counting rows). `NoDdlTest` still passes with the three guarded handlers.

### HTTP surface (Functional)

- [x] **AC-18 (the Details section)** — `GET /sell/drafts/{D}/edit` shows a fieldset "Details" with one
      field per attribute of Electric's effective schema, in `effectiveFor()` order (root first, then
      label), each labelled by the attribute's label, a number's unit appended in parentheses
      ("Strings (pcs)"), and required ones followed by "required to publish". Fields: choice → `<select>`
      with an empty "—" first, then options in id order; number and text → `<input type="text">`;
      boolean → `<select>` "—" / "Yes" / "No". Fields are prefilled from the stored values. A draft on a
      category with no attributes shows no Details section.
- [x] **AC-19 (Phase 1 gate preview)** — after `DefineAttributeHandler` defines a choice "Fretboard
      wood" (Rosewood, Maple) on **Instruments**, D's edit page shows it with no deploy, no migration,
      and `listing_attribute_value` rows unchanged.
- [x] **AC-20 (create → details)** — `/sell` still shows no Details section. A valid POST now responds
      **303 to the new draft's edit page** (changed on purpose from `/sell/drafts`, Q4; listing-draft's
      AC-25 test updated, not deleted) with the flash *"Draft saved. Add the details below."*
- [x] **AC-21 (save details)** — a valid POST with details responds 303 to `/sell/drafts` with *"Draft
      saved."*; the rows are AC-7's. A POST that changes the category responds 303 **to the edit page**
      with *"Draft saved. The details now follow “Bass”: check them below."*, and AC-9's rows remain.
- [x] **AC-22 (refusals on the form)** — each *user error* row of the failure contract re-renders with
      **422**, its message on that attribute's field, every other field keeping its submitted value,
      and nothing written.
- [x] **AC-23 (someone else's draft)** — Bob's GET/POST on D's edit URL, with or without `attributes`,
      is the same 404 as before (listing-draft AC-29); D's rows unchanged.
- [x] **AC-24 (admin refusals)** —
      - deleting brand in use: error flash *"“Brand” is used by listings, so it cannot be deleted."*;
      - removing Fender in use: error flash *"“Fender” is used by listings, so it cannot be removed."*;
      - moving Electric: **422** on **parent**: *"Listings under “Electric” have details filled in, so it
        cannot be moved yet."*
- [x] **AC-25 (query budget)** — the edit page issues **five** SQL queries for its data (draft, category
      outline, effective schema, options of all choice attributes, stored values), the same count with
      one choice attribute as with four (`QueryRecordingStatement`).
- [x] **AC-26 (no GET writes)** — the edit and create GETs leave every table unchanged.
- [x] **AC-27** — every *user error* refusal is not logged at `error`; `RecordingSentryTransport` stays
      empty.

### Cross-cutting

- [x] **AC-28 (tests bite)** — each mutation fails a named test (recorded in the commit message):
      - (a) `AttributeRule` accepts any well-formed UUID for a choice → AC-1 / AC-22
      - (b) drop the number bound check → AC-1 / AC-10
      - (c) assign values before checking them → AC-3 (check-before-assign)
      - (d) keep every value on re-file → AC-3 / AC-9
      - (e) drop (instead of refuse) on an unchanged category → AC-3
      - (f) remove C-25 → AC-12; (g) remove C-26 → AC-13; (h) remove C-27 → AC-14
      - (i) read `schemaOf()` outside `holdSteady()` → AC-16
      - (j) move any usage read out of `exclusively()` → AC-16
      - (k) `save()` inserts without first deleting the listing's rows → AC-7 (cleared value survives /
        23505)
      - (l) remove the `postLoad` hydration → AC-7 / AC-17
      - (m) `schemaOf()` reads only the category's own attributes, not the lineage → AC-11
      - (n) text rule without the control-character check → AC-1
- [x] **AC-29** — `make check` green, `make test` passes **twice in a row**, Deptrac 0 violations with
      `--fail-on-uncovered`, `make migration.make` empty; roadmap's three stale "`/verify` pending"
      notes fixed.

---

## Failure contract

| Condition | Expected behaviour | Kind |
|---|---|---|
| Choice value not an option of that attribute (tampered select, or an option removed since render) | `InvalidAttributeValue`. 422 on that field: *"Choose one of the listed options."* (also the field's Symfony `invalid_message`, so a vanished option reads the same) | user error |
| Number not a whole number | 422: *"Enter a whole number, like 6."* | user error |
| Number outside bounds (incl. bounds narrowed since the value was stored) | 422: *"Enter a whole number from 4 to 12."* / *"… of at least 4."* / *"… of at most 12."* | user error |
| Boolean not yes/no (tampered) | 422: *"Choose Yes or No."* | user error |
| Text over 200 chars, multi-line, control characters, invalid UTF-8 | 422: *"Keep this to one line of up to 200 characters."* | user error |
| Input for an attribute not in force (tampered key, or an attribute deleted between render and submit) | Unknown field → Symfony extra-field error, 422, nothing written. A known field whose attribute left the schema → `InvalidAttributeValue` (not in force), 422: *"This detail no longer applies. Reload the page."* | user error |
| Re-file to another leaf | Saved; values outside the new schema **dropped**, flash says so (AC-21). | by design |
| Required attribute empty on a draft | Saved (L-11). | by design |
| Admin deletes an attribute in use | `AttributeInUse`. Error flash (AC-24). Nothing changes. | user error |
| Admin removes an option in use | `AttributeInUse`. Error flash. Nothing changes. | user error |
| Admin moves a category whose subtree has listings with details | `CategoryHasListings::cannotMove()`. 422 on parent. Nothing changes. | user error |
| Admin narrows bounds / marks required / recodes / relabels while values exist | Allowed. Stored values untouched; the next seller save re-checks current bounds. | by design |
| Seller save and admin schema write at the same moment | Serialised by the one lock (shared vs exclusive). Whichever acquires second decides on committed facts and refuses if needed. Seller saves never wait for each other. | concurrency |
| A stored value whose attribute or option is gone (raw SQL only; impossible through the app) | Edit page: the field is not rendered (attribute) or the select shows "—" (option); the next save rewrites the row set from what was submitted, so the stray row disappears. | legacy data |
| Someone else's / unknown / malformed draft id | 404, identical body (unchanged). | authz |
| Deploy window: code up before migration | Edit page, attribute delete, option remove and category move 500 until `migrate` completes (seconds). Logged at `error` → Sentry. Accepted (same as listing-draft). | deploy |
| Postgres down | The usual 500; each command is one transaction. | dependency |
