# ADR-0018: Schema inheritance — the effective schema is derived along the lineage; no overrides, so an attribute code is unique along every branch, decided by the aggregates on facts read under the tree lock

- **Status:** Accepted
- **Date:** 2026-11-16
- **Established by** the `catalog-schema-inheritance` slice ([spec](../specs/catalog-schema-inheritance/feature-spec.md),
  [plan](../specs/catalog-schema-inheritance/technical-plan.md) D1–D6, D10, D11) — Catalog slice 3
  of 3, and the first invariant that spans **two aggregate types and the tree at once**.
- **Applies** [ADR-0016](./0016-category-tree-per-node-aggregates-under-one-tree-lock.md) decision 4
  (derived, never stored; a move is one row) and
  [ADR-0017](./0017-attribute-schema-attributes-and-options-as-separate-aggregates.md) decision 6
  (definitions stored once, on the defining category) unchanged.
- **Extends** ADR-0016's *aggregate decides on facts passed in, the lock makes them true, an index is
  the backstop where one exists* to a rule with **three doors** (define, recode, move), two of them on
  a different aggregate type from the third.
- **Amends** ADR-0017 (decision 6's open sentence answered: conflict, not override; C-11 subsumed;
  `isCodeTaken()` removed) and ADR-0016 (`moveUnder()` takes a third fact pair; the move is the second
  accretion point). See the dated 2026-11-16 amendments at the foot of each.

## Context

Slice 2 let an admin define attributes on any category. Slice 3 makes a category's **effective
schema** its own attributes plus those of every proper ancestor: "Condition" on *Instruments* applies
to *Electric Guitars* four levels down.

ADR-0017 decision 6 left one sentence open: per-category code uniqueness (C-11) "deliberately
permits the same code lower in a lineage, which is the natural shape of an override". Whether a
subcategory may **redefine** an inherited attribute is the question this slice exists for (owner Q1).

The forces:

- **Values will reference `attribute_id`** (ADR-0004). Whatever two definitions share a code, a
  listing's value points at exactly one of them.
- **Phase 2 facets span subtrees.** "Brand" across *Instruments* filters every listing below it by
  that one attribute's options.
- **A move changes the effective schema of a whole subtree** — by changing one row (ADR-0016 d.4).
- **No index can see a branch.** "No two attributes on one branch share a code" spans rows of
  `catalog_attribute` *and* the shape of `catalog_category`.

## Decision

1. **The effective schema is derived at read time over the lineage, never stored** (D1, C-22).
   Effective schema of C = attributes whose `category_id` is in `lineageOf(C)`. Defining, editing or
   deleting an attribute writes only its own (and its options') rows; creating, moving or renaming a
   category writes only that category's row. The admin read model computes it with one bounded
   recursive CTE.
2. **No overrides: an attribute code appears at most once along any branch** (D2, C-21). A code
   defined on C may not be in force on any ancestor or descendant of C. **Sibling branches may reuse
   a code** ("pickups" under *Guitars* and under *Bass*). This answers ADR-0017 d.6: conflict, not
   override — no full, partial or exclusion overrides.
3. **The aggregates decide C-21 on facts; the lock is the only guarantee** (D3). C-21 has no index,
   so it follows C-18, not C-11. On define and recode, `Attribute` decides ("my code is not in force
   on my branch"); on a move, `Category` decides. The `(category_id, code)` unique index stays as the
   backstop for the same-category subset (C-11), with its 23505 translation unchanged.
4. **`Category::moveUnder()` takes the schema facts and checks C-21 after C-4 and C-5** (D4):
   `moveUnder(Lineage $newParentLineage, int $subtreeHeight, AttributeCodes $codesAlongNewParent,
   AttributeCodes $codesInSubtree)`. It is C-5's shape exactly — one fact about the new parent's
   lineage, one about my subtree. Order: current-parent no-op → C-4 → C-5 → C-21, all before any
   assignment. The refusal names the alphabetically first shared code, so the message is
   deterministic. A category knows attribute codes only as a **fact handed in**, like its depth: its
   effective schema is a property of the category, and a move is what changes it. `create()` needs no
   check — a new category has no attributes and no subtree.
5. **Facts: `Lineage::ids()`, `CategoryRepository::descendantsOf()`,
   `AttributeRepository::codesDefinedOn()`; `isCodeTaken()` is removed** (D5). The branch of C is
   `lineageOf(C)->ids()` + `descendantsOf(C)`; its codes are `codesDefinedOn(branch)`. A move reads
   `codesDefinedOn(newParentLineage->ids())` and `codesDefinedOn([M, ...descendantsOf(M)])`. Tree
   walks stay in the tree's adapter (bounded, `CorruptCategoryTree` past the bound); the attribute
   adapter does one `WHERE category_id = ANY(:ids)`. With C-11 decided by the aggregate as part of
   C-21, `isCodeTaken()` has no caller and is deleted with its tests. A recode to the attribute's own
   code is a no-op in the aggregate, so the facts need no `except` argument.
6. **One new value object, `AttributeCodes`** (D6): a sorted, de-duplicated, immutable set of
   `AttributeCode` (`of`, `none`, `contains`, `firstSharedWith`, `toList`). A **fact type** like
   `Lineage`: compared by value, true only under the lock, never persisted. It de-duplicates because a
   subtree may lawfully hold one code in two sibling branches, and legacy data may hold duplicates
   (decision 7).
7. **Data written before this slice: detect once, tolerate in reads, fix by recode** (D10). Slice 2
   allowed a code on both an ancestor and a descendant. There is **no migration**. Before deploy the
   owner runs the read-only detection query from the plan's *Data & migrations* on prod (it is also an
   integration test, so it is known to find what it claims, and goes into `docs/infrastructure.md`);
   any row is fixed by recoding one attribute of the pair in the admin. If one slips through, reads
   list both and every write decides on de-duplicated facts, so the only effect is that a write on
   that branch involving the duplicated code is refused until it is fixed; other codes are unaffected.
8. **Value-layer consequences are handed to `Listing`, with their accretion points named** (D11). No
   listing values exist, so this slice changes none. For the `Listing` slice:
   1. **A move** changes the effective schema of every listing in the subtree (values for attributes
      that leave it, required attributes that arrive). Its guard or archive rule goes in
      **`EditCategoryHandler`, beside C-21** — the second accretion point after
      `DeleteCategoryHandler`.
   2. **A new required attribute on a parent** makes existing listings below it incomplete; `Listing`
      decides when "required" bites.
   3. **Deleting a parent's attribute** is ADR-0004's soft delete, already handed over.
   4. **The effective-schema query for other contexts** (an `EffectiveSchema` type) is designed with
      the listing form, not now.

## Alternatives

**What a lower definition with the same code means (D2)**

- **Override (shadowing).** The lower definition is a *different* `Attribute` — different id,
  options, possibly type. A Phase 2 facet on the ancestor ("Brand" across *Instruments*) would miss
  every listing filed under the override, because values reference `attribute_id`, and the admin
  would maintain two Brand lists that drift. Every *useful* override is really partial ("required
  here", "fewer options here"), which needs a per-category settings table with its own invariants,
  and there is no PRD need.
- **Exclusion** ("Bass hides Pickups"): the same facet split, plus a table.
- **Globally unique codes.** The smallest mechanism — one unique index, no lock-only rule, no move
  check — but it reverses the owner's slice-2 Q2 answer and forces "guitar-body-type" /
  "drum-body-type" prefixes on sibling branches that never share a schema.

**Storage (D1)**

- **A materialised `catalog_category_attribute (category_id, attribute_id)` table, or a `jsonb`
  schema cache on the category.** Every define on a parent and every move rewrites N rows in one use
  case — the bypass ADR-0011 d.4 refused — and a missed rewrite is a silently wrong form.
- **A closure table:** ADR-0016 already rejected it for the tree; it remains an additive *read model*
  if a measurement ever asks for one.

**Where C-21 is decided (D3, D4)**

- **A widened handler pre-check** (change `isCodeTaken()`'s SQL to look along the branch; the
  smallest diff). A lock-only rule decided in a handler is the anemic shape slice 2 refused for C-18,
  and the move side would be a second copy of the rule in a second handler.
- **A domain service `BranchSchemaPolicy` doing the reads:** the Domain doing I/O (ADR-0016 rejected
  `CategoryTreePolicy` for the same reason).
- **A PL/pgSQL trigger:** the rule written twice, in a place no unit test reaches, raising SQLSTATEs
  to be mapped back (ADR-0016).
- **A separate guard `Category::allowMoveUnder()`** (the `allowOptionRemoval()` shape): a caller can
  forget to call it. Making the facts parameters is ADR-0016's own argument.

**Facts (D5, D6)**

- **One attribute-port method that walks the tree in SQL** (`codesInBranchOf(CategoryId)`): one
  query instead of three, but the bounded walk and its corruption detection would be duplicated in a
  second adapter.
- **Keeping `isCodeTaken()` "for the message":** the aggregate's exception already carries it.
- **`list<AttributeCode>` instead of a VO:** two same-typed arrays in `moveUnder()`, and intersection
  rewritten at three call sites. **An `EffectiveSchema` Domain type:** no Domain consumer yet.

**Legacy data (D10)**

- **A data migration that `RAISE`s:** blocks the deploy behind data only an admin can fix.
- **Auto-recoding one of a pair:** silently rewrites admin data.
- **Loud reads** (throw on a duplicate): one bad pair would take down the attribute pages, which are
  the very tool to fix it.

## Consequences

- **Easy:** C-21 is unit-tested on `Attribute` and `Category` without a database. Relabelling
  "Condition" on *Instruments* changes it on every descendant by writing one row. A move is still one
  row. A Phase 2 facet on an ancestor sees every listing below it, because there is only ever one
  attribute per code per branch.
- **A lock-only invariant with three doors.** Define, recode and move each read the branch codes
  under `CategoryTreeLock`. **A future fourth write that changes a code or a parent** (an import, a
  "merge categories" tool) must read the same facts under the lock; nothing else will catch it — no
  index, no constraint. `CategoryTreeLockTest` covers the three that exist; a fourth needs the same
  test. This is the watch item.
- **`Category` imports an attribute VO.** Same context, a fact type, no aggregate reference; Deptrac
  is indifferent. Decision 4 is the answer when a reviewer asks.
- **`AttributeFactory` bypasses C-21** (it passes `AttributeCodes::none()`). Tests that need a lawful
  tree must build it in an order that respects C-21, or the factory hides a refusal.
- **Moves get more expensive by two cheap queries** on a few-hundred-row tree, under an admin's
  click. Not a cost worth a design.
- **Watch:** a legacy duplicate (decision 7) blocks writes on its branch that involve its code until recoded — by design,
  but it looks like a bug to an admin who does not know the history. The AC-25/AC-27 messages name
  the code.

## Amendment, 2026-11-18 — decision 8 points 1, 3 and 4 answered

Recorded by the `listing-attribute-values` slice
([ADR-0020](./0020-attribute-values-parts-of-listing-validated-against-catalog-schema-guarded-by-refusal.md), plan D3, D6).

1. **Point 1 (the move):** answered by **C-27**, coarse — a category cannot be moved while any listing
   in its subtree holds any value. Checked in `EditCategoryHandler`, beside C-21, after C-24. A
   precise or archive-based rule is `listing-schema-drift`'s.
2. **Point 2 (a new required attribute):** handed to `listing-publish` unchanged. `required` is not
   enforced on a draft (L-11) and never retroactively.
3. **Point 3 (deleting an attribute):** answered **by refusal for now** — C-25 (attribute in use) and
   C-26 (option in use). Soft delete moves to `listing-schema-drift` (ADR-0004's 2026-11-18
   amendment).
4. **Point 4 (the effective schema for other contexts):** answered by Listing's
   `Categories::schemaOf(): AttributeSchema` — a fact in Listing's own words, adapted by
   `CategoriesForListing` over the same lineage query. Still derived, never stored (C-22).
