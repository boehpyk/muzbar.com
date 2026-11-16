# Technical Plan: catalog-schema-inheritance

> The *how*. Disposable. Written after the feature-spec is drafted, approved with it before any code.
> Follows DDD canonical order.

## Decisions

**Bold** rows go into **ADR-0018** or a dated amendment (task T0).

### Open questions for the owner (defaults in brackets; the plan is written to the default)

| # | Question | Default |
|---|---|---|
| Q1 | **Override or conflict?** May a subcategory redefine an inherited attribute under the same code? | **Conflict** (D2). No overrides, partial or full. |
| Q2 | Should two attributes on one branch be allowed the **same label** with different codes? | **Yes, no new rule** (D7). Slice 2 already allows equal labels in one category; a blank code is derived from the label, so the common accident collides on the code anyway. |
| Q3 | **Is the catalog populated in prod** (was slice 2 deployed, and has anyone defined attributes)? | Run the D10 detection query once before deploying; if it returns rows, recode them in the admin first. |
| Q4 | Effective-schema order: **root's attributes first**, then each level down, label order within a level? | **Yes** (D8). It is also the natural order for the future listing form: general → specific. |

### Design decisions

| # | Decision | Recommendation | Alternatives considered |
|---|---|---|---|
| **D1** | **The effective schema is derived at read time over the lineage, never stored** (C-22) | Effective schema of C = attributes whose `category_id` is in `lineageOf(C)`. Applies ADR-0017 d.6 and ADR-0016 d.4 unchanged: a move stays one row, a parent edit stays one row, and nothing can drift because there is nothing to keep in step. The admin read model computes it with one bounded recursive CTE. | **A materialised `catalog_category_attribute (category_id, attribute_id)` table** or a **`jsonb` schema cache on the category**: every define on a parent and every move rewrites N rows in one use case (the bypass ADR-0011 d.4 refused), and a missed rewrite is a silently wrong form. **A closure table**: ADR-0016 already rejected it for the tree; it remains an additive *read model* if a measurement ever asks for it. |
| **D2** | **No overrides: an attribute code is unique along every branch** (C-21; answers ADR-0017 d.6's open sentence) | A code defined on C may not be in force on any ancestor or descendant of C. Sibling branches may reuse it ("pickups" under Guitars **and** under Bass). | **Override (shadowing)**: the lower definition is a *different* `Attribute` with a different id, different options and possibly a different type. A Phase 2 facet on the ancestor ("Brand" across *Instruments*) would then miss every listing filed under the override, because values reference `attribute_id` (ADR-0004), and the admin would maintain two Brand lists that drift. Every useful override is really a *partial* one ("required here", "fewer options here"), which needs a new per-category settings table and its own invariants, with no PRD need. **Exclusion** ("Bass hides Pickups"): the same facet split, plus a table. **Globally unique codes**: the smallest mechanism (one unique index, no lock-only rule, no move check), but it reverses the owner's slice-2 Q2 answer and forces "guitar-body-type" / "drum-body-type" prefixes on sibling branches that never share a schema. |
| **D3** | **The aggregates decide C-21 on facts; the lock is the only guarantee** | C-21 has no index (it spans the tree), so it follows **C-18**, not C-11: an aggregate decides, on facts read under `CategoryTreeLock`. Define/recode: `Attribute` decides ("my code must not be in force on my branch"). Move: `Category` decides (D4). The `(category_id, code)` unique index stays as the backstop for the same-category subset (C-11), and the adapter's 23505 translation is unchanged. | **A widened handler pre-check** (change `isCodeTaken()`'s SQL to look along the branch; the smallest diff): a lock-only rule decided in a handler is the anemic shape slice 2 refused for C-18, and the move side would be a second copy of the rule in a second handler. **A domain service** `BranchSchemaPolicy` doing the reads: the Domain doing I/O (ADR-0016 rejected it). **A PL/pgSQL trigger**: the rule written twice, where no unit test reaches it (ADR-0016). |
| **D4** | **`Category::moveUnder()` takes the schema facts; C-21 is checked after C-4 and C-5** | `moveUnder(Lineage $newParentLineage, int $subtreeHeight, AttributeCodes $codesAlongNewParent, AttributeCodes $codesInSubtree)`. The shape is C-5's exactly: one fact about the new parent's lineage, one about my subtree. Order: current-parent no-op → C-4 → C-5 → C-21, all before any assignment. The new exception names the alphabetically first shared code, so the message and the test are deterministic. `create()` is unchanged: a new category has no attributes and no subtree. | **A separate guard `allowMoveUnder()`** (the `allowOptionRemoval()` shape): a caller can forget to call it; making the facts parameters is ADR-0016's own argument ("so a category cannot be built under a parent whose depth the caller did not look up"). **Checking it in `EditCategoryHandler`**: a domain rule in a handler (D3). *Is it odd that a category knows attribute codes?* It knows them as a **fact handed in**, like the depth: its effective schema is a property of the category, and a move is what changes it. |
| **D5** | **Facts: `Lineage::ids()`, `CategoryRepository::descendantsOf()`, `AttributeRepository::codesDefinedOn()`; `isCodeTaken()` removed** | The branch of C = `lineageOf(C)->ids()` + `descendantsOf(C)`. Its codes = `codesDefinedOn(branch)`. A move reads `codesDefinedOn(newParentLineage->ids())` and `codesDefinedOn([M, ...descendantsOf(M)])`. Tree walks stay in the tree's adapter (bounded, corruption-checked like `subtreeHeightOf()`); the attribute adapter does one `WHERE category_id = ANY(:ids)`. `isCodeTaken()` has no caller left once the aggregate decides C-11 as part of C-21: **deleted** with its tests, adapter method and fixture methods. No `except` argument: a recode to the attribute's own code is a no-op in the aggregate (AC-4), so excluding it from the facts would change no outcome. | **One attribute-port method that walks the tree in SQL** (`codesInBranchOf(CategoryId)`): one query instead of three, but the bounded walk and its corruption detection would be duplicated in a second adapter. Three cheap queries on a few-hundred-row tree under an admin's click are not a cost. **Keeping `isCodeTaken()` "for the message"**: the aggregate's exception already carries it. |
| **D6** | **One new VO, `AttributeCodes`** | A sorted, de-duplicated, immutable set of `AttributeCode`: `of(AttributeCode ...)`, `none()`, `contains()`, `firstSharedWith(self): ?AttributeCode` (alphabetical first), `toList()`. It is a **fact type**, like `Lineage`: compared by value, never persisted. De-duplication matters: a subtree may legitimately hold the same code in two sibling branches, and legacy data may hold duplicates (D10). | `list<AttributeCode>`: `moveUnder()` would take two same-typed arrays, and `contains`/intersection would be re-written at three call sites. **An `EffectiveSchema` Domain type**: no Domain consumer yet; the `Listing` slice designs it with the listing form (D11). |
| D7 | **No label rule across the branch** (Q2) | Labels stay format-checked only (C-9), as in slice 2. | A case-insensitive label uniqueness per branch: same mechanics as C-21 on `AttributeLabel::key()`; additive later if the listing form proves confusing. |
| D8 | **Admin UI: one page, two sections, one query** | `GET /admin/categories/{id}/attributes` shows "Defined here" (unchanged rows, Edit/Delete) and "Inherited" (read-only, *"from <defining category>"* linking to its page), from `DbalAttributeSchema::effectiveFor()`, which **replaces** `forCategory()`. Order: lineage depth, then `lower(label)`, then id (Q4). The edit page gains one sentence: *"Changes apply to “<category>” and every category below it."* | A separate "effective schema" page (two places to look). Editing an inherited attribute from the child's page (hides where the change lands). Showing on a parent how many descendants inherit each attribute (no ask). |
| D9 | **No domain events** | Slice-1 D6. A move's first consumer (a search cache, the Listing guard) will design its payload. | `CategoryMoved` with no listener. |
| **D10** | **Data written before this slice: detect once, tolerate in reads, fix by recode** | Slice 2 allowed the same code on an ancestor and a descendant. No migration (nothing to change in the schema, and AC-31 requires an empty diff). Before deploy, the owner runs the query in *Data & migrations* on prod; any row is fixed by recoding one attribute in the admin. If one slips through, nothing breaks: reads list both, and every write decides on de-duplicated facts, so the only effect is that further writes on that branch are refused until it is fixed (AC-20). | **A data migration that `RAISE`s** on a violation: blocks the deploy behind data only an admin can fix. **Auto-recoding** one of the pair: silently rewrites admin data. **Loud reads** (throw on a duplicate, the C-4b reflex): one bad pair would take down the attribute pages, which are the very tool to fix it. |
| **D11** | **Value-layer consequences are handed to `Listing`, with their accretion points named** | No listing values exist, so this slice changes no value. Recorded for the `Listing` slice (roadmap + ADR-0018): (1) **a move** changes the effective schema of every listing in the subtree: values for attributes that leave it, required attributes that arrive. The guard (or archive rule) goes in **`EditCategoryHandler`, beside C-21**, the second accretion point after `DeleteCategoryHandler`. (2) **A new required attribute on a parent** makes existing listings below it incomplete: Listing decides when "required" bites (likely at the next edit). (3) **Deleting a parent's attribute** is ADR-0004's soft delete, already handed over. (4) **The effective-schema query for other contexts** is designed with the listing form. | Designing them now, with no value to test against. |

## Domain layer (pure PHP): `src/src/Domain/Catalog/`

### Value objects

- **`AttributeCodes`** (new, `ValueObject/`): `final readonly`; `of(AttributeCode ...$codes): self`
  sorts by `toString()` and drops duplicates; `none(): self`; `contains(AttributeCode): bool`;
  `firstSharedWith(self): ?AttributeCode`; `toList(): list<AttributeCode>`. Docblock: a fact, true
  only under the lock (the `Lineage` sentence), and why it de-duplicates (sibling branches, D10).
- **`Lineage::ids(): list<CategoryId>`** (new reader, root first).

### Aggregates

```
Attribute (changed signatures only; no method added or removed)
  static defineChoice(…, list<AttributeLabel> $initialOptions, AttributeCodes $codesInBranch): self
  static defineNumber(…, NumberRules, AttributeCodes $codesInBranch): self
  static defineBoolean(…, bool $required, AttributeCodes $codesInBranch): self
  static defineText(…, bool $required, AttributeCodes $codesInBranch): self
      C-21 first: $codesInBranch->contains($code) → AttributeCodeAlreadyTaken::inBranch($code); then C-18, C-17
  recode(AttributeCode $code, AttributeCodes $codesInBranch): void
      $code equals current → return (no check); contains → AttributeCodeAlreadyTaken::inBranch; else assign

Category (changed signature only)
  moveUnder(Lineage $newParentLineage, int $subtreeHeight,
            AttributeCodes $codesAlongNewParent, AttributeCodes $codesInSubtree): void
      current parent → return; C-4; C-5; C-21: $codesAlongNewParent->firstSharedWith($codesInSubtree)
      !== null → CategoryMoveWouldDuplicateAttributeCode::for($shared); then assign
```

- Docblocks: `Category`'s *contract for the rules that span the tree* gains C-21 as the third fact
  pair, with the C-5 parallel. `Attribute`'s gains: "C-11 is now the same-category case of C-21; the
  index remains its backstop". Both name C-22: nothing here writes another category's schema.
- `$codesInBranch` is placed **last** in each constructor so the diff at call sites is an appended
  argument.

### Exceptions

- **`CategoryMoveWouldDuplicateAttributeCode`** (new, `\DomainException`): `for(AttributeCode)`,
  exposes `code()` for the form message.
- **`AttributeCodeAlreadyTaken::inBranch(AttributeCode)`** (new named constructor). `inCategory()`
  stays for the adapter's index translation. Both expose the code for the one form message.

### Ports

```
CategoryRepository
  + descendantsOf(CategoryId): list<CategoryId>     every category below, excluding itself; bounded
                                                    walk; CorruptCategoryTree past the bound
AttributeRepository
  + codesDefinedOn(list<CategoryId> $categories): AttributeCodes      [] → none(), no query
  - isCodeTaken(CategoryId, AttributeCode, ?AttributeId)              removed (D5)
```

Both docblocks keep the "only true inside `exclusively()`" sentence. `CategoryTreeLock` unchanged.

### Events

None (D9).

## Application layer: `src/src/Application/Catalog/`

No new command. Three handlers change, each still entirely inside one `exclusively()`:

- **`DefineAttributeHandler`**: after `categories->get()` (C-12), read
  `$codes = attributes->codesDefinedOn([...categories->lineageOf($id)->ids(), ...categories->descendantsOf($id)])`
  and pass `$codes` to the `define…` call. The `isCodeTaken()` pre-check is deleted; its comment is
  replaced by one naming C-21 and the index backstop. The three-line branch read is a private
  `codesInBranchOf(CategoryId)` in this handler and in `EditAttributeHandler` (two copies; extract
  when a third caller appears).
- **`EditAttributeHandler`**: gains `CategoryRepository`. Only when the code changed: read the
  branch codes of `$attribute->categoryId()` and call `recode($code, $codes)`; the C-11 pre-check
  block is deleted.
- **`EditCategoryHandler`**: gains `AttributeRepository`. Only when the parent changed: read
  `codesDefinedOn($newParentLineage->ids())` and
  `codesDefinedOn([$category->id(), ...categories->descendantsOf($category->id())])`, then
  `moveUnder($lineage, $height, $codesAlongNewParent, $codesInSubtree)`. Its order comment gains
  C-21 and the D11 note: *"the Listing slice adds its move guard here"*.

Idempotency is unchanged: a repeated define hits C-21; a repeated move is the current-parent no-op.

## Infrastructure layer: `src/src/Infrastructure/Catalog/`

### Persistence

- **`DoctrineCategoryRepository::descendantsOf()`**: `WITH RECURSIVE down (id, n)` from the
  `subtreeHeightOf()` query, bounded by `WALK_BOUND`; return every `id` except the start; throw
  `CorruptCategoryTree` if `max(n) > WALK_BOUND` (the same detection as `subtreeHeightOf()`).
- **`DoctrineAttributeRepository::codesDefinedOn()`**: `[]` → `AttributeCodes::none()` without a
  query; else `SELECT code FROM catalog_attribute WHERE category_id = ANY(:ids)` (DBAL, same
  connection, so inside the lock's transaction; ids bound as a `uuid[]` / `ArrayParameterType` per
  DBAL 4), mapped through `AttributeCode::fromString()`. Served by the existing
  `uniq_catalog_attribute_category_code` index (leading `category_id`). `isCodeTaken()` deleted.
- **`DbalAttributeSchema::effectiveFor(string $categoryId)`** replaces `forCategory()`:

  ```sql
  WITH RECURSIVE up (id, parent_id, depth_from_self) AS (
      SELECT id, parent_id, 0 FROM catalog_category WHERE id = :category
      UNION ALL
      SELECT c.id, c.parent_id, up.depth_from_self + 1
      FROM catalog_category c JOIN up ON c.id = up.parent_id
      WHERE up.depth_from_self < :bound          -- MAX_DEPTH: a planted cycle cannot loop
  )
  SELECT <COLUMNS>, count(o.id) AS option_count,
         cat.id AS defined_on_id, cat.name AS defined_on_name, (up.depth_from_self > 0) AS inherited
  FROM up
  JOIN catalog_category cat ON cat.id = up.id
  JOIN catalog_attribute a ON a.category_id = up.id
  LEFT JOIN catalog_attribute_option o ON o.attribute_id = a.id
  GROUP BY a.id, cat.id, cat.name, up.depth_from_self
  ORDER BY up.depth_from_self DESC, lower(a.label), a.id
  ```

  On a healthy tree each lineage row appears once. A planted cycle repeats rows up to the bound,
  so the page may list an attribute twice, but it terminates: that is all the failure contract
  promises for a corrupt tree (writes are what refuse it, via `CorruptCategoryTree`). The
  `AttributeRow` phpstan type gains `definedOnId`, `definedOnName`, `inherited`.
  Existing `forCategory()` tests move to `effectiveFor()` (the own-only case is a root).

### HTTP / UI

- **`AttributeAdminController::index()`**: `effectiveFor()`; the template splits rows on
  `inherited`. `new()` / `edit()`: the `addRefusal()` mapping for `AttributeCodeAlreadyTaken` uses the
  AC-25 message (one message for both named constructors).
- **`CategoryAdminController::edit()`**: catches `CategoryMoveWouldDuplicateAttributeCode` with the
  other refusals and maps it to the **parent** field (AC-27).
- **Templates:** `attributes.html.twig` gains the "Inherited" table (no actions, no tokens) and the
  *"No attributes of its own."* empty state; `attribute_edit.html.twig` gains the AC-28 sentence
  (the category name from the controller's existing `category()` lookup).

### DI wiring

None new: `EditCategoryHandler` and `EditAttributeHandler` are autowired, and both ports already
have aliases.

### Async / schedule / external

None.

## Interface boundary & input contract

No route, form field or command field is added or removed. What changes is what the existing
surfaces refuse:

| Surface | Newly refuses |
|---|---|
| `POST /admin/categories/{id}/attributes/new/{type}` | a code in force on the branch → 422 on `code` |
| `POST /admin/attributes/{id}/edit` | recode to a code in force on the branch → 422 on `code` |
| `POST /admin/categories/{id}/edit` | a parent whose lineage shares a code with the subtree → 422 on `parent` |

## Data & migrations

**No migration.** No table, column or index changes (C-22: nothing derived is stored). AC-31
asserts the empty diff.

**Pre-deploy check (D10, Q3)**, run once on prod by the owner via
`make console cmd="dbal:run-sql '…'"` (read-only). It lists every pair of attributes sharing a code
where one's category is a proper ancestor of the other's:

```sql
WITH RECURSIVE anc (category_id, ancestor_id, n) AS (
    SELECT id, parent_id, 1 FROM catalog_category WHERE parent_id IS NOT NULL
    UNION ALL
    SELECT anc.category_id, c.parent_id, anc.n + 1
    FROM anc JOIN catalog_category c ON c.id = anc.ancestor_id
    WHERE c.parent_id IS NOT NULL AND anc.n < 5
)
SELECT lower_a.code, upper_a.category_id AS ancestor, lower_a.category_id AS descendant
FROM anc
JOIN catalog_attribute upper_a ON upper_a.category_id = anc.ancestor_id
JOIN catalog_attribute lower_a ON lower_a.category_id = anc.category_id AND lower_a.code = upper_a.code;
```

Zero rows → deploy. Any row → recode one of each pair in the admin first. The query also goes in an
integration test against a planted pair, so it is known to find what it claims (the "a docblock
claiming coverage" lesson), and into `docs/infrastructure.md`'s Catalog notes.

**Search impact:** none. The effective schema is a few-hundred-row admin read; a Phase 2 facet
expands a subtree to ids exactly as ADR-0016 describes. Inheritance adds the *lineage* walk for the
listing form, bounded at four rows.

## Test plan

- **Domain unit:** `AttributeCodesTest` (AC-1), `LineageTest` + `ids()` (AC-2), `AttributeTest`
  (AC-3, AC-4; existing call sites pass `AttributeCodes::none()`), `CategoryTest` (AC-5, AC-6;
  existing `moveUnder()` calls pass `none(), none()`), allow-list tests unchanged (AC-7).
- **Integration (DAMA):**
  - A shared fixture-tree builder for the five-category tree, in the test class (not a new factory).
  - `CategoryHandlersTest` / `AttributeHandlersTest` additions: AC-9…AC-18, AC-20, with table
    snapshots for AC-16/AC-17 and a **fresh repository** before each read-back.
  - `DoctrineCategoryRepositoryTest` (`descendantsOf`, planted cycle) and
    `DoctrineAttributeRepositoryTest` (`codesDefinedOn`, `effectiveFor`, the D10 query): AC-8,
    AC-19. The `isCodeTaken()` tests are deleted with the method, on purpose; AC-12 keeps the
    backstop test.
  - `CategoryTreeLockTest`: `ObservedCategoryRepository` / `ObservedAttributeRepository` record the
    new methods; `RacingAttributeRepository` loses `isCodeTaken()` and forwards `codesDefinedOn()`
    (AC-21). `NoDdlTest` additions (AC-22).
  - `AttributeFactory` passes `AttributeCodes::none()`: it bypasses the branch check by design
    (Risk 3). AC-20 plants its duplicate with raw SQL, as stated.
- **Functional:** `AttributeAdminTest` (AC-23…AC-26, AC-28; the slice-2 duplicate-code message
  assertion is updated to the AC-25 text), `CategoryAdminTest` (AC-27).
- **Bite proof:** AC-30 (a)…(g).
- **Performance:** none (admin-only reads; no facet path).

## Risks / open questions

1. **Q1–Q4 above.** Q1 is the decision this slice exists for; Q3 decides whether the D10 check is a
   formality.
2. **A lock-only invariant with three doors** (define, recode, move). A future fourth write that
   changes a code or a parent (an import, a "merge categories" tool) must read the same facts under
   the lock; nothing else will catch it. ADR-0018 names this as the watch item, and AC-21 covers the
   three that exist.
3. **`AttributeFactory` bypasses C-21** (it passes `none()`). Tests that need a lawful tree must
   build it in an order that respects C-21, or the factory hides a refusal. Called out in the
   factory's docblock.
4. **`Category` now imports an attribute VO.** Same context, a fact type, no aggregate reference;
   Deptrac is indifferent. A reviewer may still ask; D4 is the answer.
5. **ADR work (T0):**
   - **ADR-0018** *"Schema inheritance: the effective schema is derived along the lineage; no
     overrides, so an attribute code is unique along every branch, decided by the aggregates on
     facts read under the tree lock"*: D1–D6, D10, D11, with override, exclusion, global codes,
     materialisation and the handler pre-check as rejected alternatives.
   - **Dated ADR-0017 amendment:** decision 6's open sentence is answered (conflict, not override);
     C-11 is now the same-category case of C-21, decided by `Attribute`; `isCodeTaken()` is removed;
     the index stays as the backstop.
   - **Dated ADR-0016 amendment:** `moveUnder()` takes a third fact pair (C-21), checked after C-4
     and C-5; `EditCategoryHandler`'s move is the **second accretion point** (the Listing slice's
     move guard), as `DeleteCategoryHandler` is for deletion.
