# Feature Spec: catalog-schema-inheritance

> The *what & why*. Disposable: it dies once the feature ships and its behaviour lives in tests +
> code. Make it **executable** (measurable, enumerated), not pretty. Must not contradict the
> [Constitution](../../constitution.md) or an accepted ADR.

- **Feature:** `catalog-schema-inheritance`, slice **3 of 3** of the `Catalog` roadmap item (after
  `catalog-category-tree` and `catalog-attribute-schema`)
- **Bounded context(s):** `Catalog` only. Nothing under `Domain/Catalog` or `Application/Catalog`
  imports another context.
- **Related PRD items:** FR-1 (attribute schemas per category), PRD §6 (selecting a category
  "morphs" the listing form, which needs the category's *whole* schema), PRD §9 *Schema Drift*,
  PRD validation #2 / the Phase 1 gate (*"without corrupting existing rows"*: this slice designs the
  schema half; the value half belongs to the `Listing` slice)
- **Governing ADRs:** [ADR-0004](../../adr/0004-dynamic-category-attribute-schema.md) (no admin
  action runs DDL), [ADR-0009](../../adr/0009-email-verification-tokens-modelled-in-the-domain.md)
  (reference by id, no FK), [ADR-0016](../../adr/0016-category-tree-per-node-aggregates-under-one-tree-lock.md)
  (aggregate decides on facts, the lock makes them true; *depth, path and lineage are derived, never
  stored*; a move is one row), [ADR-0017](../../adr/0017-attribute-schema-attributes-and-options-as-separate-aggregates.md)
  decision 6 (*definitions stored once, on the defining category; inheritance is a read-time
  derivation over the lineage*), and **ADR-0018** (new, task T0)
- **Status:** Draft, awaiting owner approval (D1–D11; questions Q1–Q4 in the technical plan)
- **Date:** 2026-11-16

> **What this slice teaches, beyond the feature.** Slice 2 left one sentence for this slice to
> answer: C-11 makes a code unique *per defining category*, which "deliberately permits the same code
> lower in a lineage, the natural shape of an override". This slice decides it is **a conflict, not
> an override** (plan D2), and that turns C-11 into a rule that spans *both* aggregate types and the
> tree at once: **an attribute code appears at most once along any branch** (C-21). No index can
> express that, so, like C-18, the lock is the only guarantee and an aggregate must decide.
>
> The rule has two doors. Defining or recoding an attribute is the obvious one; **moving a category
> is the other**, because a move changes the effective schema of its whole subtree without touching
> a single attribute row. `Category::moveUnder()` already decides C-5 on *a fact about the new
> parent's lineage* (its depth) and *a fact about its own subtree* (its height). C-21 on a move has
> exactly the same shape: the codes in force along the new parent's lineage, and the codes defined
> in the subtree. The category gains a third check, not a new kind of check.
>
> And the effective schema itself is **never stored** (C-22). A parent's new attribute, a parent's
> relabel, a move: each writes exactly the rows it always wrote, and every descendant sees the change
> on its next read.

---

## Ubiquitous language (this slice)

| Term | Means | Not to be confused with |
|---|---|---|
| **Own attributes** (of a category) | The attributes whose defining category it is. Slice 2's "attribute schema". | The effective schema. |
| **Inherited attributes** | The own attributes of every **proper ancestor** of the category. Read-only from the category's point of view: they are edited on the category that defines them. | Copies. Nothing is copied (C-22). |
| **Effective schema** | Own + inherited attributes: the attributes defined on every category in its **lineage** (root first, the category last). This is the schema a listing filed under the category will be held to. | A stored set. It is derived on every read. |
| **Branch** (of a category) | Its lineage (ancestors and itself) **plus** all its descendants: every category whose effective schema contains, or is contained in, this category's. | The subtree (descendants only). The lineage (ancestors only). |
| **Code in force** | A code used by some attribute in a given set of categories. The fact C-21 is decided on. | A code "taken" in one category (C-11). |
| **Override** *(rejected)* | A lower category redefining an inherited attribute under the same code. This slice refuses it (plan D2). | |

### Invariants (continuing Catalog's `C-n` series; C-1 … C-8 `Category`, C-9 … C-20 attributes/options)

| # | Invariant | Who decides | Facts / backstop |
|---|---|---|---|
| **C-11** *(narrowed in role, unchanged in effect)* | Code unique within the defining category. | Now a special case of C-21, decided by the aggregate. | **Unique index `(category_id, code)` stays**, as the backstop for that subset. 23505 → `AttributeCodeAlreadyTaken`. |
| **C-21** | **An attribute code appears at most once in any category's effective schema.** Equivalently: no two attributes on one branch share a code. Checked on **define**, **recode** and **category move**. Sibling branches may reuse a code. | Define/recode: `Attribute::define…()` / `recode()`, on the codes in force along the defining category's branch. Move: `Category::moveUnder()`, on the codes in force along the new parent's lineage and the codes defined in the moving subtree. | Facts read under `CategoryTreeLock`. **No index can express it: the lock is the whole guarantee** (the C-18 situation). |
| **C-22** | **The effective schema is derived, never stored.** Defining, editing or deleting an attribute writes only that attribute's (and its options') rows; creating, moving or renaming a category writes only that category's row. Descendants' effective schemas change through the derivation alone. | No code exists that could write anything else. | ADR-0016 decision 4, ADR-0017 decision 6. |

Creating a category needs no C-21 check: a new category has no attributes and no descendants, so
its effective schema is its parent's, which already satisfies C-21.

---

## User story

As an **admin**, I want **a subcategory to have every attribute of its ancestors, and to see them
on its attribute page**, so that I define "Condition" once on *Instruments* rather than on every
leaf, and a fix to "Condition" reaches every category below it at once.

As **muzbar**, I want **no category's schema to ever contain the same code twice**, however
attributes are defined, recoded or categories moved, so that a listing form, and later a facet
query, never has to choose between two attributes that claim to be the same thing.

Secondary and ranked (Constitution §2): as the **developer**, I want this slice to show how an
invariant that spans two aggregate types and the tree is decided by the aggregates on facts, and
how a *derived* concept (the effective schema) stays derived.

---

## In scope

**Domain (`Catalog`):**
- VO `AttributeCodes`: a sorted, de-duplicated set of codes, a fact handed to an aggregate.
- `Lineage::ids()`.
- `Attribute::defineChoice/Number/Boolean/Text()` and `recode()` take the codes in force along the
  branch and decide C-21.
- `Category::moveUnder()` takes the new parent's codes and the subtree's codes and decides C-21
  after C-4 and C-5.
- Exception `CategoryMoveWouldDuplicateAttributeCode`; named constructor
  `AttributeCodeAlreadyTaken::inBranch()`.
- Ports: `CategoryRepository::descendantsOf()`, `AttributeRepository::codesDefinedOn()`.
  `AttributeRepository::isCodeTaken()` is **removed** (subsumed by C-21).

**Application:** `DefineAttributeHandler`, `EditAttributeHandler`, `EditCategoryHandler` read the
new facts under the lock and pass them in.

**Infrastructure:**
- Adapter methods for the two new port methods.
- `DbalAttributeSchema::effectiveFor()` replaces `forCategory()`: the whole effective schema in one
  query.
- The attribute list page shows "Defined here" and "Inherited" sections; the attribute edit page
  says where its changes apply.
- Failure-contract messages for the two new refusals.

---

## Non-goals (explicit, hold the line)

| Deliberately excluded | Belongs to / why |
|---|---|
| **Overrides** of an inherited attribute (same code lower down), including partial ones ("required here only", "fewer options here") | Plan D2: refused. A per-category settings table is additive if the owner ever needs it, and gets its own ADR. |
| **Hiding/excluding** an inherited attribute in a subcategory | Plan D2. Define the attribute lower instead. |
| A **label** rule across the branch (two "Brand" labels with different codes) | Plan D7 / Q2. Slice 2 allows equal labels within one category; this slice does not tighten it. |
| **Listing-value consequences** of a move, of a new *required* attribute on a parent, or of deleting a parent's attribute | The `Listing` slice (no values exist yet). The accretion points are named in plan D11. |
| A **Domain port / Application query for the effective schema** for other contexts (the morphing listing form) | The `Listing` slice designs it with its consumer. This slice has only the admin read model. |
| A stored or cached effective schema (table, `jsonb`, closure table) | C-22, ADR-0016 d.4. |
| Domain events | No consumer (slice-1 D6). |
| Moving an attribute to another category | C-14, unchanged. |
| Facets, search, the Schema-Mutation PoC gate itself | Phase 1 gate / Phase 2. |
| Live Components, JavaScript, Tailwind | Slice-1 D9. |

---

## Acceptance criteria (the Definition of Done checklist)

The fixture tree used below (all ids by `CategoryFactory`, attributes by `AttributeFactory`):

```
Instruments            defines  condition ("Condition", choice)
├── Guitars            defines  strings   ("String count", number)
│   └── Electric       defines  pickups   ("Pickups", choice)
│       └── Seven      defines  scale     ("Scale length", number)
└── Bass               defines  (nothing)
```

### Values and aggregates (Domain unit tests, no kernel)

- [ ] **AC-1 (`AttributeCodes`):** `AttributeCodes::of(c("b"), c("a"), c("b"))` lists `a, b`
      (sorted, de-duplicated). `none()` is empty. `contains()` compares by value.
      `firstSharedWith()` returns the **alphabetically first** code in both sets (`{a,c,d}` ∩
      `{d,c}` → `c`) and `null` for disjoint sets or when either is empty.
- [ ] **AC-2 (`Lineage::ids()`):** returns the ids root first, ending with the category itself;
      `Lineage::ofRoot()->ids()` is `[]`.
- [ ] **AC-3 (C-21 at define):** each of the four `define…` constructors throws
      `AttributeCodeAlreadyTaken` when its code is in the `AttributeCodes` it is given, and succeeds
      when it is not. C-21 is checked **first**: `defineChoice` with a code in force **and** an empty
      option list throws `AttributeCodeAlreadyTaken`, not `InvalidAttributeOptions`.
- [ ] **AC-4 (C-21 at recode):** `recode(code, codes)` with `code` in `codes` throws
      `AttributeCodeAlreadyTaken` and `code()` is unchanged; with `code` not in `codes` it changes
      `code()`. Recoding to its **own current** code is a no-op that checks nothing, even when that
      code is in `codes` (which it always is, being in force on its own category): the
      `moveUnder()`-to-current-parent shape.
- [ ] **AC-5 (C-21 at move):** `moveUnder(lineage, height, parentCodes, subtreeCodes)` with
      `{condition, strings}` and `{pickups, scale}` succeeds; with `{condition, scale}` and
      `{pickups, scale}` it throws `CategoryMoveWouldDuplicateAttributeCode` whose message names
      `scale`, and `parentId()` is unchanged.
- [ ] **AC-6 (order of the move checks):** a move that is a cycle **and** collides reports
      `CategoryMoveWouldCreateCycle`; one that is too deep **and** collides reports
      `CategoryTreeTooDeep`. A move to the current parent returns without checking anything,
      including colliding code sets.
- [ ] **AC-7:** `Category` and `Attribute` still do not use `RecordsEvents`; their public method
      names still equal the slice-2/slice-1 allow-lists (no method added or removed, only
      parameters). Nothing in `Domain/Catalog` or `Application/Catalog` imports another context.

### Use cases against a real database (Integration, DAMA)

- [ ] **AC-8 (effective schema, read model):** `DbalAttributeSchema::effectiveFor()` returns, for
      `Seven`, the codes `condition, strings, pickups, scale` in that order (lineage depth, root
      first; then label case-insensitively; then id), each row carrying `definedOnId`,
      `definedOnName` and `inherited` (`false` only for `scale`). For `Guitars`: `condition,
      strings`. For `Bass`: `condition`. For `Instruments`: `condition`, `inherited = false`. For an
      unknown id: `[]`.
- [ ] **AC-9 (C-21 define, ancestor):** `DefineAttribute(code: "strings")` on `Electric` throws
      `AttributeCodeAlreadyTaken`; `catalog_attribute` and `catalog_attribute_option` are unchanged.
- [ ] **AC-10 (C-21 define, descendant):** `DefineAttribute(code: "pickups")` on `Guitars`, and
      `DefineAttribute(code: "scale")` on `Instruments`, each throw `AttributeCodeAlreadyTaken`; both
      tables unchanged.
- [ ] **AC-11 (C-21 define, sibling branch is fine):** `DefineAttribute(code: "pickups")` on `Bass`
      succeeds. Afterwards `effectiveFor(Bass)` is `condition, pickups` and `effectiveFor(Electric)`
      still contains exactly one `pickups`.
- [ ] **AC-12 (C-11 still holds):** a second `condition` on `Instruments` throws
      `AttributeCodeAlreadyTaken` (now from the aggregate). The slice-2 backstop test (racing
      repository double, raw duplicate insert → `AttributeCodeAlreadyTaken`, not a DBAL exception)
      still passes unchanged.
- [ ] **AC-13 (C-21 recode):** `EditAttribute` recoding `pickups` to `strings` (ancestor's) or to
      `scale` (descendant's) throws `AttributeCodeAlreadyTaken` and a fresh repository shows
      `pickups`. Recoding it to `pickup-count` succeeds. Saving it with its own unchanged code
      succeeds.
- [ ] **AC-14 (C-21 move, refused):**
      - `Bass` defines `strings`; moving `Bass` under `Guitars` throws
        `CategoryMoveWouldDuplicateAttributeCode` naming `strings`.
      - `Bass` defines `scale` (only); moving `Electric` under `Bass` throws, naming `scale`:
        **a descendant's code counts** (`Seven` is two levels below the moving category).
      - After each refusal, `catalog_category.parent_id` of the moving category is unchanged.
- [ ] **AC-15 (C-21 move, accepted):** with `Bass` defining `pickups`, moving `Seven` under `Bass`
      succeeds (`{condition, pickups}` ∩ `{scale}` = ∅), and `effectiveFor(Seven)` becomes
      `condition, pickups, scale` (the `pickups` now being Bass's). A subtree in which two
      **sibling** leaves both define `fx` moves under a parent with no `fx` without refusal.
- [ ] **AC-16 (C-22, a move is one row):** after AC-15's successful move, a snapshot of every
      `catalog_attribute` and `catalog_attribute_option` row (all columns) is identical to the one
      taken before, and exactly one `catalog_category` row differs: the moved one, in `parent_id`
      only.
- [ ] **AC-17 (C-22, parent changes reach descendants with no writes below):**
      - `DefineAttribute(code: "finish")` on `Instruments` adds exactly one `catalog_attribute` row,
        and `effectiveFor(Seven)` and `effectiveFor(Bass)` both contain `finish`.
      - `EditAttribute` relabelling `condition` to "State" changes exactly one row, and
        `effectiveFor(Seven)` shows "State".
      - `DeleteAttribute(condition)` removes it from `effectiveFor()` of all five categories.
- [ ] **AC-18 (create inherits):** `CreateCategory` under `Electric` writes one `catalog_category`
      row and no attribute row, and the new category's `effectiveFor()` is `condition, strings,
      pickups`.
- [ ] **AC-19 (new fact reads):**
      - `CategoryRepository::descendantsOf(Electric)` returns exactly `[Seven]`;
        `descendantsOf(Instruments)` returns the four others in any order; a leaf returns `[]`.
      - A planted 2-cycle (raw SQL) makes `descendantsOf()` throw `CorruptCategoryTree` rather than
        loop.
      - `AttributeRepository::codesDefinedOn([Instruments, Electric])` is `{condition, pickups}`;
        with `[]` it is empty and issues **no** query.
- [ ] **AC-20 (pre-existing duplicates are tolerated, not trusted):** a `strings` attribute planted
      on `Electric` by raw SQL (bypassing C-21, as slice 2 allowed) makes `effectiveFor(Electric)`
      list `strings` twice without error. `EditAttribute` recoding the planted one to `string-gauge`
      succeeds; recoding it to anything still in force is refused.
- [ ] **AC-21 (the lock):** the recording-double test (`CategoryTreeLockTest`) asserts that
      `descendantsOf()`, `lineageOf()` and `codesDefinedOn()` are called **inside**
      `exclusively()` by `DefineAttributeHandler`, `EditAttributeHandler` (when the code changes)
      and `EditCategoryHandler` (when the parent changes). Removing `exclusively()` from any one of
      the three fails it.
- [ ] **AC-22 (no DDL):** `NoDdlTest` still passes with a C-21-refused define and a refused move
      added to its run.

### Admin HTTP surface (Functional)

No route is added or removed; the slice-2/slice-1 authz tests cover every route touched.

- [ ] **AC-23 (list shows both sections):** `GET /admin/categories/{Electric}/attributes` shows a
      "Defined here" table (`Pickups`, with Edit and Delete) and an "Inherited" table (`Condition`
      then `String count`, root first). Each inherited row shows label, code, type, required and
      details as slice 2 does, plus *"from <a>Instruments</a>"* / *"from <a>Guitars</a>"* linking to
      that category's attribute page, and has **no** Edit or Delete control and no CSRF token. A root
      category has no "Inherited" table; a category with no own attributes shows *"No attributes of
      its own."*
- [ ] **AC-24 (one query):** the whole list page issues **one** SQL query for its attribute rows
      (own + inherited + option counts), asserted with `QueryRecordingStatement` as slice 2 does.
- [ ] **AC-25 (define refusal on the form):** defining `strings` on `Electric` re-renders with
      **422**, nothing persisted, the code field showing *"“strings” is already used by an attribute
      of this category or of a category above or below it."*, and every other field keeping its
      value. The same message for a same-category duplicate (the slice-2 message changes **on
      purpose**; its test is updated, not deleted). A blank code derived to a colliding value gives
      the same error.
- [ ] **AC-26 (edit refusal on the form):** recoding to a code in force re-renders with 422 and the
      AC-25 message on the code field.
- [ ] **AC-27 (move refusal on the form):** on `/admin/categories/{Bass}/edit`, choosing `Guitars`
      as parent while `Bass` defines `strings` re-renders with 422, nothing persisted, and the parent
      field showing *"Cannot move here: attribute code “strings” would appear twice in one branch.
      Recode or delete one of them first."*
- [ ] **AC-28 (edit page says where changes land):** the attribute edit page for `Condition` shows
      *"Changes apply to “Instruments” and every category below it."*

### Cross-cutting

- [ ] **AC-29:** the two new refusals are user errors: not logged at `error`, no Sentry event.
- [ ] **AC-30 (tests bite):** each mutation makes a named test fail (record which in the commit
      message):
      - (a) drop descendants from the define/recode branch (lineage only): AC-10 fails
      - (b) drop ancestors from it (descendants only): AC-9 fails
      - (c) remove the C-21 check from `moveUnder()`: AC-14 fails
      - (d) read only the moving category's own codes, not its descendants': AC-14's second case
        fails
      - (e) remove `exclusively()` from `EditCategoryHandler`: AC-21 fails
      - (f) make `firstSharedWith()` return any shared code instead of the first: AC-1 fails
      - (g) remove the own-code early return from `recode()`: AC-4 fails
- [ ] **AC-31:** `make check` is green, `make test` passes **twice in a row**, Deptrac 0
      violations with `--fail-on-uncovered`, and `make migration.make` generates **nothing** (this
      slice has no migration).

---

## Failure contract

| Condition | Expected behaviour | Kind |
|---|---|---|
| Define/recode with a code in force on the branch (ancestor, self or descendant) | `AttributeCodeAlreadyTaken`. 422. **code**: *"“<code>” is already used by an attribute of this category or of a category above or below it."* | user error |
| Same, but lost to the `(category_id, code)` index race | Same exception (adapter), same message. Re-render reads DBAL only. | user error |
| Move whose subtree and new lineage share a code | `CategoryMoveWouldDuplicateAttributeCode`. 422. **parent**: *"Cannot move here: attribute code “<code>” would appear twice in one branch. Recode or delete one of them first."* Nothing changes. | user error |
| Move that is also a cycle / too deep | The slice-1 message for the cycle / depth (checked first, AC-6) | user error |
| Duplicate codes on one branch already stored (data from before this slice) | Shown twice in the list, no error. Every define, recode or move touching that branch is decided on de-duplicated facts; the admin fixes it by recoding one (AC-20). Detected before deploy by the plan's query (D10). | legacy data |
| Stored cycle in the tree on a move or branch read | `CorruptCategoryTree` from `descendantsOf()` / `lineageOf()`: 500, logged at `error` → Sentry. No write. | corruption |
| Stored cycle when listing effective attributes | The read model's walk is bounded by `MAX_DEPTH + 1`; the page renders and does not hang. | corruption |
| Two admin writes at once (define on a parent vs move of a child) | Serialized by `CategoryTreeLock`; the second decides on committed facts. | concurrency |
| Postgres down | The usual 500; each command is one transaction. | dependency |
