# Feature Spec: listing-schema-drift

> The *what & why*. Disposable: it dies once the feature ships and its behaviour lives in tests +
> code. Make it **executable** (measurable, enumerated), not pretty. Must not contradict the
> [Constitution](../../constitution.md) or an accepted ADR.

- **Feature:** `listing-schema-drift`, slice **4 of 4** of the `Listing` skeleton roadmap item (after
  `listing-draft`, `listing-attribute-values`, `listing-publish`; then the `phase1-gate`). The owner
  chose to ship it **before** the gate.
- **Bounded context(s):** **`Catalog`** (archive and restore of attributes and options; C-27 retired;
  the schema reads learn "archived") and **`Listing`** (dormant values: kept, hidden, ignored by every
  rule). Cross-context ports touched: Listing's `Categories::schemaOf()` (now the **active** schema),
  Catalog's `CategoryUsage` (loses `hasAttributeValues()`), Catalog's `AttributeUsage` (unchanged —
  it now guards hard delete only). Neither context's Domain or Application imports the other
  (ADR-0019 decision 3).
- **Related PRD items:** §*Risks — Schema Drift* ("structural changes to metadata … without
  invalidating downstream search indices"), FR-1 (admins delete attributes at runtime), PRD validation
  #2 (no corruption of existing rows).
- **Governing ADRs:** [ADR-0004](../../adr/0004-dynamic-category-attribute-schema.md) (*"Deleting an
  attribute soft-deletes it and hides it from the form/facets; historical values are retained"* — the
  clause this slice finally implements; amendments 1 and 2 handed it here),
  [ADR-0012](../../adr/0012-challenge-retention-and-recurring-background-work.md) (an aggregate governs
  its transitions, not its non-existence), [ADR-0016](../../adr/0016-category-tree-per-node-aggregates-under-one-tree-lock.md),
  [ADR-0017](../../adr/0017-attribute-schema-attributes-and-options-as-separate-aggregates.md) (C-17,
  C-18), [ADR-0018](../../adr/0018-schema-inheritance-derived-along-the-lineage-codes-unique-per-branch.md)
  (C-21, C-22), [ADR-0020](../../adr/0020-attribute-values-parts-of-listing-validated-against-catalog-schema-guarded-by-refusal.md)
  (C-25…C-27 — C-27 **retired** here), [ADR-0021](../../adr/0021-listing-lifecycle-in-the-aggregate-completeness-on-public-writes-dispatch-after-commit.md)
  (L-13, L-14 — L-14 **narrowed** here), and **ADR-0022** (new, task T0)
- **Status:** Approved by owner 2026-11-21 (Q1–Q7 as recommended)
- **Date:** 2026-11-21

> **What this slice teaches, beyond the feature.**
>
> 1. **An invariant that moves from "refuse" to "tolerate".** Slice 2 kept *"every stored value is in
>    force"* true by having Catalog refuse every door that could break it (C-25/C-26/C-27). This slice
>    drops that invariant on purpose and replaces it with a weaker one the Listing side can live with:
>    *"every stored value names a row that exists"* (still C-25/C-26) plus *"a value that is not in
>    force is dormant: kept, hidden, ignored by every rule"* (L-17). The refusal (C-27) disappears not
>    because it was wrong, but because the other aggregate learned to cope.
> 2. **Archiving is a transition, deletion is not** (ADR-0012). `archive()` and `restore()` are
>    methods on the aggregates, with a timestamp from the `Clock`. The hard delete that survives for
>    unused rows stays where it was — a handler and a port, no method on any aggregate.
> 3. **Narrowing a rule vs. leaving the schema are different things.** Archiving an **option**
>    narrows its attribute's rule, exactly like narrowing number bounds: never retroactive, the next
>    write must fix it. Archiving an **attribute**, or moving a category away from it, takes the
>    attribute **out** of the schema: its values go dormant. Two cases, two treatments — and both
>    already have a precedent in the code.

---

## Ubiquitous language (this slice)

| Term | Means | Not to be confused with |
|---|---|---|
| **Archive** (attribute, option) | An admin takes it out of use: sellers no longer see it, it is no longer in any listing's schema (attribute) or any rule's options (option). Nothing is deleted; `archived_at` records when. Reversible by **restore**. | **Delete** (hard, permanent — survives only for rows no listing uses, C-25/C-26). |
| **Restore** | Undo an archive. The row is active again; every value that pointed at it is in force again. | Re-define (a new attribute would get a new id and split its values). |
| **Active** (attribute, option) | Not archived. | "In force" (a property of an attribute **relative to a category**: active *and* in its lineage). |
| **Active schema** (of a category) | The effective schema (C-22) minus archived attributes, each choice with its active options only. What `schemaOf()` returns, what the seller's form shows, what Phase 2 facets will offer. Derived, never stored. | The admin's full view, which also lists archived rows. |
| **Dormant value** | A stored value whose attribute is **not in force** on its listing's category: the attribute is archived, or a category move took it out of the lineage. Kept in its row; shown nowhere; ignored by L-9, L-13 and L-14; carried over by every revise that keeps the category; dropped by a re-file. Wakes up by itself if the attribute is restored or the category moved back. | **Stale value** (in force, but fails today's rule). |
| **Stale value** | A stored value whose attribute **is** in force, but which no longer fits the attribute's current rule: an **archived option**, or a number outside narrowed bounds (the latter exists since slice 2). Shown as stored; never retroactive; the listing's next write must fix it (L-9), and publish refuses it (L-14). | Dormant value. |
| **Reserved name** | An archived attribute's code and an archived option's label still count for uniqueness (C-21/C-11, C-17). Re-using one means restoring it. | — |

### Invariants

Catalog continues `C-n` after C-27; Listing continues `L-n` after L-16.

| # | Invariant | Who decides | Facts / backstop |
|---|---|---|---|
| **C-28** *(new)* | **Archiving is a reversible state of an attribute or an option**, never a deletion. `archive(now)` sets `archivedAt` (the Clock's whole-second now); on an archived row it is a **no-op that keeps the first instant**. `restore()` clears it; on an active row a no-op. An archived attribute is in **no** active schema; an archived option is in **no** rule's options. Neither touches a listing row. | `Attribute::archive()/restore()`, `AttributeOption::archive()/restore()`. | `archived_at timestamptz(0) NULL` on both tables. Every Catalog write under `CategoryTreeLock::exclusively()`. |
| **C-29** *(new)* | **An archived row keeps its name reserved.** An archived attribute's code still counts for C-21 (and so C-11) on every door — define, recode, move; an archived option's label still counts for C-17. Consequently **restore needs no facts and is never refused.** | Unchanged deciders: `Attribute::define…()/recode()`, `Category::moveUnder()` on `codesDefinedOn()` (which reads archived rows); `isLabelTaken()` + the `(attribute_id, label_key)` index (which include them). | The existing unique indexes, unchanged. |
| **C-18** *(amended)* | A choice attribute always has at least one **active** option. Archiving an active option, or deleting one, is refused when it is the last **active** one. Deleting an **archived** option never meets C-18. | `Attribute::allowOptionRemoval(int $activeOptionCount)`, on `countActiveForAttribute()` read under the lock, called only when an active option is leaving the active set. | None; the lock. |
| **C-25** *(re-scoped)* | An attribute any listing holds a value for — **active, stale or dormant, any status** — cannot be **hard-deleted**. Archive is the in-use path. | `DeleteAttributeHandler` (unchanged). | `AttributeUsage::isInUse()` (unchanged). |
| **C-26** *(re-scoped)* | Likewise for an option: in use → cannot be **hard-deleted**; archive it. Checked after C-18 (C-18 only when the option is active). | `RemoveAttributeOptionHandler`. | `AttributeUsage::isOptionInUse()` (unchanged). |
| ~~**C-27**~~ *(retired)* | ~~A category cannot be moved while any listing in its subtree holds any value.~~ **A move with values is allowed.** Values whose attribute leaves the lineage go dormant (L-17). C-4, C-5, C-21, C-24 still guard the move. | — | `CategoryUsage::hasAttributeValues()` and `CategoryHasListings::cannotMove()` are **deleted** (no caller). |
| **L-17** *(new)* | **Dormant values are kept and ignored.** A stored value whose attribute is not in the listing's category's active schema is dormant. A `revise()` that **keeps** the category carries every dormant value over unchanged; a `revise()` that **re-files** drops it (L-10, unchanged: a re-file keeps only what the new schema asks). L-9 never reads a dormant value; L-13 never counts one; L-14 never re-checks one. An **input** for a dormant attribute is still refused (L-10, "not in force") — no form renders one. | `Listing::revise()`, `Listing::publish()`, via `AttributeValues::onlyFor()` / `::notIn()` on the schema fact. | Shared lock, as before. |
| **L-14** *(narrowed)* | Publish re-checks the category (L-2) and **every stored value in force** (L-9) — dormant values are skipped; stale ones are refused. | `Listing::publish()`. | — |

**Unchanged and worth restating:** L-9 (every value a listing is *given* fits the current rule — so a
stale archived option is refused at the next write, as narrowed bounds are), L-10, L-13 (never
retroactive), C-13 (a category that defines attributes — **archived ones included** — cannot be
deleted), C-20 (hard-deleting an attribute sweeps its options), C-21/C-22, C-23/C-24, status-blindness
of every usage guard (ADR-0021 d.9).

**What these keep true together:** every stored value names an attribute (and option) that **exists**
(C-25/C-26 — nothing is ever deleted from under a value). Every value anyone can **see** — on the
form, on the public page, later in a facet — is for an attribute in force and active. Every value a
seller **writes** fits today's rules. **What is deliberately no longer kept true** (it was, by refusal,
since slice 2): that every stored value is in force. That is the price of allowing the move and the
archive, and L-17 is how it is paid.

---

## User story

As an **admin**, I want **to retire an attribute or an option that listings already use, and to
re-organise the category tree even after sellers have filled in details**, so that the catalog can
change without me hunting down every listing first — and **to undo it** if I got it wrong.

As a **seller**, I want **my listing to keep working when the catalog changes under it**: a retired
detail simply stops showing, nothing I entered silently disappears, and I am asked to change a value
only when it is no longer offered and I next edit.

As **muzbar**, I want **ADR-0004's promise kept: schema changes never corrupt or destroy existing
listing rows**, which is half of the Phase 1 gate.

Secondary and ranked (Constitution §2): as the **developer**, I want to see an invariant deliberately
weakened and a refusal removed because the other side of the boundary learned to tolerate the state.

---

## In scope

**Domain (`Catalog`):** `Attribute` and `AttributeOption` gain `archive(\DateTimeImmutable)`,
`restore()`, `archivedAt()`, `isArchived()`. `Attribute::allowOptionRemoval()`'s count is the
**active** count (renamed parameter, docblock). `AttributeOptionRepository::countForAttribute()` →
`countActiveForAttribute()`. `CategoryUsage::hasAttributeValues()` and
`CategoryHasListings::cannotMove()`/`isMove()` **deleted**.

**Domain (`Listing`):** `AttributeValues::onlyFor(AttributeSchema)`, `::notIn(AttributeSchema)`;
`Listing::revise()` carries dormant values over on an unchanged category; `Listing::publish()`
re-checks only in-force values. No new exception, no new event.

**Application (`Catalog`):** commands + handlers `ArchiveAttribute`, `RestoreAttribute`,
`ArchiveAttributeOption`, `RestoreAttributeOption` (all under `exclusively()`, the first Catalog
handlers to take the `Clock`). `RemoveAttributeOptionHandler`: C-18 only for an active option.
`EditCategoryHandler`: C-27 removed. **No Listing Application change.**

**Infrastructure:** `archived_at` on `Attribute.orm.xml` / `AttributeOption.orm.xml` + the generated
migration. `CategoriesForListing::schemaOf()` filters archived attributes and options.
`DbalAttributeSchema`: `effectiveFor()` returns active attributes with **active** option counts; new
`archivedDefinedOn()`; `find()` / `optionsOf()` report `archived`; `optionsFor()` keeps the archived
options a listing stores. `ListingCategoryUsage::hasAttributeValues()` deleted. Admin: archive /
restore routes and buttons, an "Archived" section, refusal wording that points at archive. Seller edit
page: a stale archived option shown selected as "… (no longer offered)" with its own refusal message.

**Docs:** ADR-0022 + amendments (T0), roadmap (tick; the gate's facet-query note), CLAUDE.md,
`docs/infrastructure.md` (deploy window), `FORboehpyk.md`.

---

## Non-goals (explicit, hold the line)

| Deliberately excluded | Belongs to / why |
|---|---|
| Facets, search, the facet-count query | `phase1-gate` / Phase 2. This slice only states what they must filter (plan Risk 3). |
| An "impact preview" (*"12 listings hold a value for Brand"*) before archiving or moving | Q7. A read model with no decision behind it; the action is reversible. |
| Freezing an archived attribute (refusing relabel / recode / rules / new options on it) | Q6. Harmless edits, no invariant needs it; the UI simply leads with Restore. |
| A sweep that clears dormant or stale values, or notifies sellers | Never retroactive (D11.2, ADR-0021). The values are the point of archiving. |
| Archiving a **category** | Not on the roadmap; C-7/C-13/C-23 keep category delete strict. |
| Showing dormant values to the seller ("hidden details") | Q4: they are invisible by definition; restore is how they return. |
| A new domain event (`AttributeArchived`, …) | No consumer: Search is Postgres itself and reads `archived_at` (ADR-0002). Catalog has no events (ADR-0017 D10). |
| Merging two attributes / moving values between attributes | A data-migration tool, not schema drift. |
| Changing `AttributeUsage` or making C-25/C-26 dormancy-aware | Dormant values are real rows naming real ids; that is exactly why hard delete must still see them. |
| Dependent options, `is_facet`/`is_searchable` | ADR-0017 d.9 / ADR-0004 amendment 1: later. |

---

## Acceptance criteria (the Definition of Done checklist)

Fixture tree — listing-publish's, plus one new root **Vintage** (no listings) defining **`era`**
(text, **required**). Built in C-21-respecting order with `->inCategory()`:

```
Instruments                       condition  (choice: New, Used)          required
├── Guitars                       brand      (choice: Fender, Gibson)     required
│   └── Electric (leaf)           strings    (number, 4…12)
│                                 has_case   (boolean)
│                                 serial     (text)
└── Bass (leaf)                   bass_strings (number, no bounds)
Vintage                           era        (text)                       required
```

Ann has **D** on Electric: condition = Used, brand = Fender, strings = 6, **published**. The Clock is a
fixed whole-second instant **T**. "Rows of D" = D's `listing_listing` row and its
`listing_attribute_value` rows.

### Aggregates (Domain unit tests, no kernel)

- [ ] **AC-1 (C-28, attribute)** — `archive(T)`: `isArchived()`, `archivedAt()` = T. `archive(T+60s)`
      again: `archivedAt()` still T. `restore()`: not archived, `archivedAt()` null; `restore()` again
      changes nothing. A freshly defined attribute is not archived.
- [ ] **AC-2 (C-28, option; C-18 wording)** — the same four cases on `AttributeOption`.
      `allowOptionRemoval(1)` still throws `AttributeNeedsAnOption`; `(2)` passes.
- [ ] **AC-3 (L-17, revise keeps the category)** — D holds brand = Fender; schema without brand.
      `revise()` with condition = New, strings = 8 (no brand input): values are condition = New,
      strings = 8 **and brand = Fender** (carried over, byte-equal `AttributeValue`). Revise clearing
      every in-force input: only brand remains. An **input** for brand → `InvalidAttributeValue`
      (`NotInForce`), nothing changed.
- [ ] **AC-4 (L-17 / L-10, re-file drops)** — withdrawn D with a dormant brand re-filed to Bass:
      brand is gone, along with everything Bass does not ask for.
- [ ] **AC-5 (L-17 / L-13 / L-14, publish)** — withdrawn D, complete in force, plus a dormant value for
      an attribute absent from the schema: `publish()` succeeds. Schema whose brand rule lists only
      Gibson (Fender archived): `publish()` → `InvalidAttributeValue` (`NotAnOption`) for brand,
      nothing changed (stale is refused, L-14). Schema where a **required** attribute is archived (absent):
      not reported missing.
- [ ] **AC-6 (fact helpers)** — `AttributeValues::onlyFor($schema)` ∪ `notIn($schema)` equals the
      original set and the two are disjoint; both on `none()` are `none()`.
- [ ] **AC-7 (shape, isolation)** — `Listing`'s public method list unchanged. `Attribute`'s and
      `AttributeOption`'s gain exactly `archive`, `restore`, `archivedAt`, `isArchived` (allow-lists
      updated **on purpose**). `ListingIsolationTest`, `CatalogIsolationTest` pass unchanged.

### Use cases against a real database (Integration, DAMA)

- [ ] **AC-8 (schema)** — after the migration, `catalog_attribute.archived_at` and
      `catalog_attribute_option.archived_at` are `timestamp(0) with time zone`, nullable, no default
      other than NULL (`NULL::timestamp with time zone` accepted), and NULL on every pre-existing row.
      No new index. `make migration.make` afterwards generates **nothing**.
- [ ] **AC-9 (archive an attribute in use)** — `ArchiveAttribute(brand)` succeeds; then:
      `catalog_attribute.archived_at` = T; **rows of D byte-identical**; D still `published`;
      `schemaOf(Electric)` has no brand rule; `effectiveFor(Electric)` has no brand row;
      `archivedDefinedOn(Guitars)` lists brand. Ann's revise of D's title (no brand input) → saved,
      still published, and **D's brand row is still there** (L-17). `RestoreAttribute(brand)` → brand
      back in `schemaOf()` with D's Fender in force; nothing else written.
- [ ] **AC-10 (archive an option in use)** — `ArchiveAttributeOption(brand, Fender)` succeeds; rows of
      D byte-identical; `schemaOf(Electric)`'s brand rule lists Gibson only; `optionsFor([brand])`
      lists Gibson only, and `optionsFor([brand], keep: [Fender])` lists Gibson and Fender with
      Fender `archived`. Ann's revise keeping brand = Fender → `InvalidAttributeValue` (`NotAnOption`),
      nothing written; with Gibson → saved. Restore → Fender offered again.
- [ ] **AC-11 (C-18 counts active options)** — brand with Fender archived: archiving Gibson →
      `AttributeNeedsAnOption`; deleting Gibson (unused) → `AttributeNeedsAnOption`; deleting Fender
      when **unused** → succeeds although Gibson is then the only option. A three-option attribute with
      one archived and two active: archiving one active succeeds.
- [ ] **AC-12 (hard delete survives for unused rows only — C-25/C-26/C-20)** — deleting brand, active
      or archived, while any listing (any status) holds a value for it — **including only a dormant
      value** — → `AttributeInUse`; rows unchanged. An archived attribute nobody uses → deleted, its
      options swept (C-20). An archived option nobody uses → deleted.
- [ ] **AC-13 (C-29, names stay reserved)** — with brand archived: defining `brand` on Electric, or
      recoding `serial` to `brand`, → `AttributeCodeAlreadyTaken`; moving a category that defines a
      `brand` under Guitars → `CategoryMoveWouldDuplicateAttributeCode`. With Fender archived: adding
      option "fender" to brand → `DuplicateAttributeOption`. Restore is never refused, in every one of
      these states.
- [ ] **AC-14 (the move — C-27 retired)** — moving **Electric under Vintage** (D published, with values)
      **succeeds**; rows of D byte-identical; D still `published`. `schemaOf(Electric)` = era, strings,
      has_case, serial (condition and brand are dormant on D). Ann's next revise without era →
      `ListingIncomplete` naming era (L-13, never retroactive); with era = "1970s" → saved, and D's
      condition and brand rows **still there**. Moving Electric back under Guitars → condition and
      brand in force again with D's values; era's value is now dormant. C-24 (under a listed-on
      category) and C-21 still refuse their moves.
- [ ] **AC-15 (the lock, NoDdl)** — `CategoryTreeLockTest`: the four new handlers read and save inside
      `exclusively()`; `EditCategoryHandler` no longer asks `CategoryUsage` anything about values (the
      observed double records zero calls). `NoDdlTest` drives the four new handlers: `public`'s columns
      and indexes unchanged.

### HTTP surface (Functional)

- [ ] **AC-16 (admin: attribute list)** — `GET /admin/categories/{Guitars}/attributes` after archiving
      brand: "Defined here" no longer lists brand; a section **"Archived"** lists it with
      *"Archived on 21 Nov 2026"*, a **Restore** and a **Delete** button (POST forms, own CSRF tokens).
      Each active row has an **Archive** button. Electric's page lists neither brand as inherited nor
      the Archived section of Guitars (archived rows show only on their defining category).
- [ ] **AC-17 (admin: archive / restore)** — `POST /admin/attributes/{id}/archive` with a valid token →
      303 to the attribute list, flash *"Archived “Brand”. Sellers no longer see it; listings keep their
      values."*; `/restore` → *"Restored “Brand”."*. Options: `POST
      /admin/attributes/{id}/options/{optionId}/archive` → 303 to the edit page, *"Archived “Fender”."*;
      `/restore` → *"Restored “Fender”."*; the last active option → error flash *"A choice needs at
      least one option sellers can pick."*. Bad/missing token → **403**, before any lookup; GET → 405;
      unknown id → 404. The attribute edit page lists archived options under **"Archived options"**
      with Restore/Delete, and an archived attribute's edit page shows *"Archived on … — sellers don't
      see it."* with a Restore button.
- [ ] **AC-18 (admin: refusals point at archive)** — deleting an in-use attribute → error flash
      *"Listings use “Brand”, so it cannot be deleted. Archive it instead — listings keep their
      values."*; an in-use option → *"Listings use “Fender”, so it cannot be deleted. Archive it
      instead."*.
- [ ] **AC-19 (admin: the move)** — moving Electric under Vintage on the category edit form → 303,
      the usual success flash; the old *"… have details filled in, so it cannot be moved yet."* message
      no longer exists in the code.
- [ ] **AC-20 (seller edit page)** — with brand archived: D's edit page has **no** brand field and a
      Save does not touch D's brand row. With Fender archived: the brand `<select>` has Fender
      **selected**, labelled *"Fender (no longer offered)"*; a new draft's brand select does not list
      Fender. Submitting Fender → 422, *"Fender is no longer offered. Choose another."* on brand,
      nothing written. The page issues **five** data queries, as before (listing-attribute-values
      AC-25).
- [ ] **AC-21 (public page)** — with brand archived, or after Electric moved under Vintage:
      `/listings/{D}` shows **no** Brand entry (and no Condition after the move), still 200. With only
      Fender archived: shows *"Brand: Fender"* (a stale value is shown as stored — Q5). Restore / move
      back: Brand reappears. Query budget unchanged (≤ 5, listing-publish AC-24).
- [ ] **AC-22 (no GET writes, silence)** — every new or changed GET leaves every table unchanged;
      every user-error refusal in the failure contract is not logged at `error`
      (`RecordingSentryTransport` empty).

### Cross-cutting

- [ ] **AC-23 (tests bite)** — each mutation fails a named test (recorded in the commit message):
      - (a) `revise()` drops dormant values (no carry-over) → AC-3 / AC-9 / AC-14
      - (b) `revise()` carries dormant values over on a re-file too → AC-4
      - (c) `publish()` re-reads dormant values (no `onlyFor()`) → AC-5
      - (d) `publish()` skips stale values as well (treats an archived option as dormant) → AC-5
      - (e) `schemaOf()` without the attribute `archived_at` filter → AC-9
      - (f) `schemaOf()` without the option `archived_at` filter → AC-10
      - (g) `effectiveFor()` without the `archived_at` filter → AC-9 / AC-20 / AC-21
      - (h) `codesDefinedOn()` ignores archived attributes → AC-13
      - (i) C-18 counted over all options, not active ones → AC-11
      - (j) an archive handler outside `exclusively()` → AC-15
      - (k) C-27 put back in `EditCategoryHandler` → AC-14 / AC-19
      - (l) `archive()` overwrites an earlier `archivedAt` → AC-1
      - (m) `optionsFor()` ignores `keep` → AC-20
      - (n) `archivedAt` not from the Clock (`new \DateTimeImmutable()`) → AC-1 / AC-9
      - (o) `isInUse()` filtered to active attributes / in-force values → AC-12
- [ ] **AC-24** — `make check` green, `make test` passes **twice in a row**, Deptrac 0 violations with
      `--fail-on-uncovered`, `make migration.make` empty, CI-mode run
      (`php -d zend.assertions=-1 vendor/bin/phpunit`) green. Tests that asserted C-27
      (listing-attribute-values' move refusal, listing-publish AC-14's "moving Electric",
      `StatusBlindGuardsTest`, `ListingValueGuardsTest`) are **inverted on purpose**, each with a
      one-line comment naming this slice.

---

## Failure contract

| Condition | Expected behaviour | Kind |
|---|---|---|
| Archive the last **active** option of a choice | `AttributeNeedsAnOption`; error flash *"A choice needs at least one option sellers can pick."*; nothing changed. | user error |
| Hard-delete an attribute / option any listing uses (even only as a dormant value) | `AttributeInUse`; the AC-18 flash pointing at Archive; nothing changed. | user error |
| Define / recode / move onto a code held by an archived attribute on the branch | `AttributeCodeAlreadyTaken` / `CategoryMoveWouldDuplicateAttributeCode`, existing messages (they name the code; the archived row is listed on its defining category). | user error |
| Add an option whose label matches an archived option | `DuplicateAttributeOption`, existing message; the archived option is listed on the same page with Restore. | user error |
| Archive an archived row / restore an active one (double-submit) | No-op, success flash. | idempotent |
| Archive / restore with a bad or missing CSRF token | 403 before any lookup; nothing changed. | tampering |
| Unknown / malformed attribute or option id on an archive/restore route | 404 (the existing admin behaviour for these ids). | — |
| Delete a category whose only attributes are archived | Refused by C-13 as today (*"… defines attributes"*); delete the unused archived attributes first. | user error |
| Seller saves a listing with a stale archived option | `InvalidAttributeValue` (`NotAnOption`) → 422, *"Fender is no longer offered. Choose another."* on that field; nothing written. Same on "Save and publish". | user error |
| Seller's form was rendered before an attribute was archived, and is submitted with it | `NotInForce` → 422, *"This detail no longer applies. Reload the page."* (unchanged); the dormant value already stored is untouched. | user error |
| Admin archives / moves while listings are published | Allowed. Rows untouched, listings stay published; the public page hides dormant values at once; the next seller write meets the current rules (L-9, L-13). | by design |
| Seller re-files a listing holding dormant values | Re-filed; dormant values dropped with everything else the new category does not ask (L-10). | by design |
| Seller save and admin archive/move at the same moment | Serialised by the tree lock (shared vs exclusive), as before. | concurrency |
| Deploy window: code up before migration | Doctrine loads of `Attribute`/`AttributeOption` and every query naming `archived_at` (`schemaOf()`, `effectiveFor()`) 500 for seconds: admin attribute pages, the seller edit page and its POSTs, the public listing page. Logged → Sentry. Accepted, as the three previous Listing slices. | deploy |
| Postgres down | The usual 500; each command is one transaction. | dependency |
