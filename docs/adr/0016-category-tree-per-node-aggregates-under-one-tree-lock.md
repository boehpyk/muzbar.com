# ADR-0016: Category tree — per-node aggregates on an adjacency list; cross-instance invariants decided by the aggregate on facts read under one tree lock

- **Status:** Accepted
- **Date:** 2026-11-14
- **Established by** the `catalog-category-tree` slice ([spec](../specs/catalog-category-tree/feature-spec.md),
  [plan](../specs/catalog-category-tree/technical-plan.md) D1–D4, D7) — the first aggregate outside
  `Identity`, and the first whose invariants span **many instances of itself**.
- **Applies** [ADR-0007](./0007-persistence-conventions-for-domain-aggregates.md) and
  [ADR-0009](./0009-email-verification-tokens-modelled-in-the-domain.md) decision 4 unchanged: a
  parent is a `CategoryId` column, never a `<many-to-one>`, and there is no foreign key.
- **Applies** [ADR-0012](./0012-challenge-retention-and-recurring-background-work.md) decision 2
  (*put in the Domain the part that can be wrong*; an aggregate governs its transitions, not its own
  non-existence) to a tree.
- **Amends** [ADR-0005](./0005-auth-oauth2-google-plus-api-keys.md) (who is an admin) and
  [ADR-0009](./0009-email-verification-tokens-modelled-in-the-domain.md) (the shared-UUID trigger
  fired). See the dated 2026-11-14 amendments at the foot of each.

## Context

The catalog is a tree of categories (*Effects > Pedals > Drive > Fuzz*), edited by one admin a few
times a day, a few hundred nodes at most. Two of its rules are about **the tree, not a node**:

- **C-4, no cycles:** a category is never moved under itself or any of its descendants.
- **C-5, depth ceiling:** no category is deeper than `Category::MAX_DEPTH = 4`.

Plus two that are about **other rows**: C-6 (a parent exists) and C-7 (only a leaf is deleted).

Every textbook answer to "an invariant spans many objects" is "make them one aggregate". Three forces
push against it here:

- **Everything later points at a category, not at the tree.** Slice 3's attributes, Phase 2's
  listings and saved searches all reference one category. Evans: nothing outside an aggregate may
  hold a reference to an entity inside it.
- **The rules are pure logic that a unit test can reach** — "is my id in the new parent's lineage?",
  "does lineage depth plus my subtree height exceed four?" — but they need **facts** only the
  database has.
- **The race is write skew.** Two concurrent moves (A under B, B under A) each read a lineage without
  the other's write; both pass C-4 and both commit a cycle. No single row conflicts, so neither row
  locks nor optimistic versions see it.

## Decision

1. **One aggregate per category** (D1). `Category` holds `id`, `?parentId` (a `CategoryId`), `slug`
   and `name`. A parent knows nothing of its children; the edge is stored once, on the child.
2. **The aggregate decides, on facts passed in** (D2). `Category::create(…, Lineage $parentLineage)`
   and `moveUnder(Lineage $newParentLineage, int $subtreeHeight)` own C-4 and C-5 as pure logic, with
   no I/O. C-4 is checked before C-5, so a move under a descendant always reports the cycle, and both
   are checked before any assignment, so a refused call mutates nothing. The handler reads the facts
   through `CategoryRepository` (`lineageOf()`, `subtreeHeightOf()`). C-3 (sibling-unique slug)
   follows the I-6 precedent: a handler pre-check for the message, two partial unique indexes for the
   guarantee. C-6 and C-7 are handler checks over port queries.
   **The aggregate cannot verify that the lineage it was given is current. Decision 3 is what makes it
   current.** `Category`'s docblock says so, as a design choice rather than a limitation discovered
   later.
3. **One tree-wide, transaction-scoped advisory lock** (D3), behind a Domain port:
   `CategoryTreeLock::exclusively(\Closure $work): mixed`. `PostgresCategoryTreeLock` opens a DBAL
   `transactional()`, takes `pg_advisory_xact_lock(KEY)`, runs `$work`, and on any throwable clears
   the EntityManager and rethrows. **Every** Catalog write handler — create, edit, delete — reads its
   facts and saves inside it. The rule is uniform so that nobody decides per handler whether "this one
   is safe". The port lives in the Domain because "the tree changes one write at a time, atomically"
   is a guarantee the aggregate's correctness depends on; advisory locks are how the adapter keeps it.
4. **Adjacency list** (D4): `parent_id` on the row, read with `WITH RECURSIVE` CTEs whose recursion is
   **bounded by `MAX_DEPTH + 1`** (C-4b — a planted cycle throws `CorruptCategoryTree` instead of
   looping). Depth, path and lineage are **derived, never stored**. The decisive argument is DDD, not
   performance: with an adjacency list **a move changes one row, which is one aggregate**. Every other
   representation stores something per node that depends on its ancestors, so a move rewrites every
   descendant — either N aggregates changed in one use case, or a bulk `UPDATE` around them (the
   bypass ADR-0011 decision 4 refused).
5. **Hard delete of a leaf only** (D7), checked under the lock. Per ADR-0012 there is no
   `Category::delete()`; `DeleteCategoryHandler` owns the precondition. Nothing references a category
   yet, so there is nothing to soft-delete *for*.

## Alternatives

**Aggregate boundary (D1)**

- **The whole tree as one aggregate** (`CategoryTree` root, categories as internal entities). The
  textbook answer, and the tree is small. Rejected: every later reference would either break Evans'
  rule or make `CategoryTree` a global singleton that every write in three contexts must load; it
  needs a `<one-to-many>` of child entities or a `jsonb` document a listing cannot join against; and
  its one real advantage — a single row to lock — decision 3 gets without it.
- **`Category` holding a `list<CategoryId>` of its children:** two sources of truth for one edge, and
  a move then changes three aggregates (node, old parent, new parent).

**Where the invariants live (D2)**

- **A domain service `CategoryTreePolicy`** that calls the port itself: the Domain doing I/O, and an
  anemic `Category` with its rules moved next door. Passing facts in keeps the rule on the object
  whose state it guards.
- **A Postgres trigger** for cycles and depth: the rule written a second time in PL/pgSQL where no
  unit test reaches it (ADR-0012's objection to a SQL "dead" predicate), raising SQLSTATEs the adapter
  must map back into domain exceptions.

**Concurrency (D3)**

- **No lock:** the write skew above, plus "create child under P" racing "delete P" leaving an orphan.
- **SERIALIZABLE isolation:** correct, but it turns the race into a `40001` every handler must retry.
  A lock is simpler to reason about at this write rate.
- **A Redis lock** (as the pruning job uses): cannot be atomic with the Postgres commit — it is
  released before or after the commit, never *at* it. `_xact_` advisory locks release exactly at
  commit or rollback.
- **`SELECT … FOR UPDATE` on the parent row:** misses the cycle case, because the two racing moves
  lock different rows.

**Storage (D4)**

- **ltree:** excellent `<@` subtree filters under GiST, but labels allow only `[A-Za-z0-9_]` (UUIDs
  need re-encoding), it is an extension, and a move rewrites descendants.
- **Materialized path** (text with a `text_pattern_ops` prefix index): the same move problem.
- **Nested set:** an insert or move rewrites large parts of the table — the worst write profile there
  is.
- **Closure table:** good subtree reads, but a second table of derived pairs to keep in step on every
  move.

None of them is needed for search. A Phase 2 subtree facet is *expand the subtree to ids* (one bounded
CTE over a few hundred cache-resident rows, cacheable), then `listing.category_id = ANY(:ids)` on an
index of `listing`. The tree is never joined per listing row, so its representation does not touch the
200 ms budget. If it ever does, an ltree or closure **read model** can be derived without touching the
aggregate.

**Deletion (D7)**

- **Cascade delete of a subtree:** one click destroys a branch, and later its listings. No.
- **Soft delete now:** ADR-0004 soft-deletes *attributes* because values reference them; categories
  have no references yet. A column for a reference that does not exist is speculative.

## Consequences

- **Easy:** C-4 and C-5 are unit-tested without a database. A move is one row. Later references by
  `CategoryId` survive rename, slug change and move (C-8). Adding a Catalog write means wrapping it in
  `exclusively()` — the only rule to remember.
- **The lock is a deliberate ceiling.** One key serialises every category write in the system: fine
  for one admin writing a few times a day, wrong for concurrent writers at volume. The upgrade path is
  per-subtree locks (lock the root ancestor of every lineage a write reads). The adapter's `KEY`
  constant carries a `ponytail:` comment naming both.
- **DBAL `transactional()`, not `EntityManager::wrapInTransaction()`.** The latter **closes** the
  EntityManager on any throwable, so a plain domain refusal (a cycle) would leave the request holding
  a dead EM. The adapter instead `clear()`s it on failure, which forgets the half-mutated aggregate
  without disabling the EM (AC-16). A unique violation during `flush()` still closes the EM — Doctrine
  behaviour — which is why forms re-render from the DBAL read model, never from the EM.
- **Tests: DAMA holds the xact lock until the test ends**, not until `exclusively()` returns, because
  its outer transaction never commits. AC-17 therefore probes from a **second, non-DAMA connection**
  with `pg_try_advisory_xact_lock` **from inside the work closure**. A probe made after `exclusively()`
  returns would read `false` under DAMA even if the adapter took no lock — a test that passes because
  of the harness, not the code. Release-at-commit is PostgreSQL's semantics and is not asserted.
- **Delete guards accrete.** Slice 3 (attributes) and Phase 2 (listings) create references to a
  category; each adds its own guard to `DeleteCategoryHandler`, or turns deletion into archiving at
  that moment. With no FK (ADR-0009 decision 4), forgetting the guard leaves orphans silently — the
  slice that adds a reference owns the guard.
- **Watch:** a handler that reads tree facts outside `exclusively()` reintroduces the write skew with
  every test still green. AC-17 fails if the call is removed from any of the three handlers; a fourth
  handler needs the same test.

## Amendment, 2026-11-15 — the lock guards attribute and option writes; C-13 is the first accreted delete guard

Recorded by the `catalog-attribute-schema` slice
([ADR-0017](./0017-attribute-schema-attributes-and-options-as-separate-aggregates.md), plan D5, D8).

1. **`CategoryTreeLock` now serialises every Catalog write, attributes and options included** — the
   six new handlers (define, edit, delete attribute; add, relabel, remove option) run entirely inside
   `exclusively()`. The name stays; the port's docblock records the widened scope. A second lock was
   rejected: two locks need an ordering rule, for one admin.
2. **The lock now makes facts true across aggregate types, not only within the tree.** C-17 (option
   labels unique within an attribute) is a pre-check under the lock with a unique index as backstop —
   decision 2's C-3 pattern. **C-18 (a choice attribute keeps at least one option) has no index at
   all:** `Attribute::allowOptionRemoval()` decides on a `countForAttribute()` read that is only true
   under the lock. C-12 (define on category X) and C-13 (delete X) are mirror checks under the same
   lock.
3. **The predicted delete guard has accreted.** `DeleteCategoryHandler` checks C-7 (children) first,
   then **C-13: a category that defines attributes cannot be deleted** (`CategoryHasAttributes`).
   This is the first reference into the tree from another aggregate (`Attribute` → `CategoryId`);
   Phase 2's listings add the next guard in the same place.

## Amendment, 2026-11-16 — `moveUnder()` takes a third fact pair (C-21); the move is the second accretion point

Recorded by the `catalog-schema-inheritance` slice
([ADR-0018](./0018-schema-inheritance-derived-along-the-lineage-codes-unique-per-branch.md), plan D4, D11).

1. **`moveUnder()` takes the schema facts:** `moveUnder(Lineage $newParentLineage, int
   $subtreeHeight, AttributeCodes $codesAlongNewParent, AttributeCodes $codesInSubtree)`. **C-21**
   (no attribute code twice along a branch) is checked **after C-4 and C-5**, before any assignment,
   so decision 2's ordering and no-mutation-on-refusal guarantees extend unchanged. It is C-5's
   shape: one fact about the new parent's lineage, one about the moving subtree. `EditCategoryHandler`
   reads both code sets under the lock, only when the parent changes. `create()` is unchanged.
2. **`EditCategoryHandler`'s move is the second accretion point**, as `DeleteCategoryHandler` is for
   deletion. A move changes the effective schema of every category — and later every listing — in
   the subtree, so the `Listing` slice adds its move guard (or archive rule) there, beside C-21.
3. **Decision 4 now carries the effective schema too:** derived along the lineage, never stored
   (C-22), so a move still writes one row.

## Amendment, 2026-11-17 — the lock gains a shared mode taken by Listing writes; C-23 and C-24 join the accretion points

Recorded by the `listing-draft` slice
([ADR-0019](./0019-listing-context-cross-context-ports-leaf-only-under-one-lock-in-two-modes.md), plan D5, D10).

1. **The lock has a shared mode, and its scope is no longer only the Catalog.** Every Listing write
   runs inside `Categories::holdSteady()`, whose adapter takes
   `pg_advisory_xact_lock_shared(PostgresCategoryTreeLock::KEY)` — the **same key** Catalog writes
   take exclusively. Shared does not conflict with shared, so listing writes never queue behind one
   another; they serialise only against Catalog writes. The shared mode is not on the
   `CategoryTreeLock` port: Catalog never needs it.
2. **The third accreted delete guard is C-23** (a listed-on category cannot be deleted,
   `CategoryHasListings::cannotDelete()`). Order in `DeleteCategoryHandler`: **C-7 → C-13 → C-23**.
3. **C-24 (a listed-on category cannot gain a child) joins create and the move.** It is the second
   accretion point's first Listing guard: in `EditCategoryHandler` it runs after C-4, C-5 and C-21,
   only when the parent changes and the new parent is not null; in `CreateCategoryHandler` it runs
   after C-6, only when the parent is not null. Both read `CategoryUsage::hasListings()` inside
   `exclusively()`. It is a handler check, not an aggregate parameter (ADR-0019 decision 9).
4. **Decision 5's watch item widens.** A Catalog write that can turn a leaf into a branch must read
   `hasListings()` under the lock, and a Listing write that files a listing must go through
   `holdSteady()`; `CategoryTreeLockTest` covers the doors that exist.
