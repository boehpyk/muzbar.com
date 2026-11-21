# ADR-0022: Schema drift — attributes and options are archived, never deleted from under a value; an archived name stays reserved, so restore takes no facts; a value not in force is dormant — kept by the listing, hidden everywhere, ignored by every rule; the move refusal (C-27) is retired

- **Status:** Accepted
- **Date:** 2026-11-21
- **Established by** the `listing-schema-drift` slice ([spec](../specs/listing-schema-drift/feature-spec.md),
  [plan](../specs/listing-schema-drift/technical-plan.md) D1–D10, Q1–Q5; owner approved Q1–Q7 as
  recommended) — Listing slice 4 of 4, and the first slice that **weakens an invariant on purpose**.
- **Applies** [ADR-0012](./0012-challenge-retention-and-recurring-background-work.md) (an aggregate
  governs its transitions, not its non-existence) and
  [ADR-0016](./0016-category-tree-per-node-aggregates-under-one-tree-lock.md) (every Catalog write
  under `CategoryTreeLock::exclusively()`).
- **Implements** [ADR-0004](./0004-dynamic-category-attribute-schema.md)'s soft-delete clause, handed
  here by its 2026-11-18 amendment.
- **Partly supersedes** [ADR-0020](./0020-attribute-values-parts-of-listing-validated-against-catalog-schema-guarded-by-refusal.md)
  decision 6 (C-27 retired) and [ADR-0021](./0021-listing-lifecycle-in-the-aggregate-completeness-on-public-writes-dispatch-after-commit.md)
  decision 3 (L-14 narrowed to in-force values).
- **Amends** ADR-0004, ADR-0016, ADR-0017, ADR-0018, ADR-0020 and ADR-0021 — see the dated
  2026-11-21 amendments at the foot of each.

## Context

Since `listing-attribute-values`, *"every stored value is in force"* has been kept true by refusal:
Catalog refused to delete an attribute in use (C-25), remove an option in use (C-26), or move a
category whose subtree held any value (C-27). That made the catalog rigid exactly where an admin needs
to change it, and left ADR-0004's promise — *"deleting soft-deletes; historical values are retained"*
— unimplemented. The PRD's *Schema Drift* risk asks for metadata changes that never corrupt or destroy
listing rows.

Two different things can happen to a stored value when the catalog changes, and both already have a
precedent: its attribute can **leave the schema** (archived, or moved out of the lineage), or its rule
can **narrow** (an option archived — exactly like number bounds narrowed in slice 2).

## Decision

1. **Archive / restore are transitions on both aggregates (C-28)** (D1). `Attribute` and
   `AttributeOption` gain `?\DateTimeImmutable $archivedAt`, `archive(now)` (a no-op on an archived
   row, keeping the first instant), `restore()`, `archivedAt()`, `isArchived()`. `now` is the
   `Clock`'s, whole seconds. Archiving is a **state**; hard delete stays non-existence, a handler and
   a port as before (ADR-0012). No events.
2. **Hard delete survives for unused rows; Archive is a separate, always-available act** (Q1). C-25
   and C-26 are unchanged and now guard *hard* delete; their refusal points at Archive.
3. **An archived row keeps its name reserved, so restore needs no facts and is never refused (C-29)**
   (Q2, D2). An archived code still counts for C-21 (and C-11) on every door; an archived option label
   still counts for C-17. `codesDefinedOn()`, `isLabelTaken()`, `existsForCategory()` and both unique
   indexes already read every row — unchanged, now on purpose. Re-using a name means restoring it.
4. **Archived rows can be restored** (Q3), by one method and one route each. Dormant values wake up by
   themselves, because nothing was deleted. **An archived attribute is not frozen** (Q6): its edit
   page stays reachable, so a reserved code can be recoded away.
5. **C-18 counts active options** (D3). `countForAttribute()` is renamed `countActiveForAttribute()`
   (a port method whose meaning changes changes its name). `allowOptionRemoval()` is called whenever an
   **active** option leaves the active set — archived or deleted; deleting an archived option never
   meets C-18.
6. **The active schema is the only one Listing sees** (D4). `CategoriesForListing::schemaOf()` filters
   archived attributes and archived options in its **join** conditions. `AttributeSchema`,
   `AttributeRule` and the `Categories` port are unchanged: *archived* never enters Listing's
   language; to Listing an archived attribute is simply not in force.
7. **Dormant values are kept and ignored (L-17)** (Q4, D5). A stored value whose attribute is not in
   the active schema of its listing's category is **dormant**. A `revise()` that keeps the category
   carries every dormant value over (`AttributeValues::onlyFor()` / `::notIn()`); a re-file drops them
   (L-10 unchanged). L-9, L-13 and L-14 never read one. Dormant is a fact about the pair (value,
   today's schema), **never stored**.
8. **An archived option is a stale value, not a dormant one** (Q5, D6). Its attribute is in force, so
   it is a narrowed rule: shown as stored on the public page, never retroactive, refused at the next
   write (L-9) and at publish. **L-14 is narrowed:** publish re-checks the category and every stored
   value **in force** — dormant skipped, stale refused.
9. **C-27 is retired, with its port method and exception arm deleted** (D7). A move with values is
   allowed; values whose attribute leaves the lineage go dormant and wake if the move is undone.
   `CategoryUsage::hasAttributeValues()`, `ListingCategoryUsage::hasAttributeValues()` and
   `CategoryHasListings::cannotMove()`/`isMove()` are deleted (no dead code). Move order:
   **C-4 → C-5 → C-21 → C-24**.
10. **No new index** (D8). `archived_at` filters rows already found by `category_id`/`attribute_id`/PK
    on small tables. Two nullable `timestamptz(0)` columns, no backfill.
11. **The admin read model shows active by default; archived rows only where the admin asks** (D9).
    `effectiveFor()` returns active attributes with active option counts; new `archivedDefinedOn()`;
    `find()`/`optionsOf()` report `archivedAt` under their own phpstan types; `optionsFor(…, keep)`
    adds back the archived options a listing stores. `optionLabels()` is unchanged.
12. **The seller form shows a stale option selected, labelled "… (no longer offered)"** (D10), and
    the Domain's `NotAnOption` refusal is worded *"… is no longer offered. Choose another."* at the
    boundary. A new draft is never offered an archived option.

## Alternatives

- **`bool $archived`** (loses "archived on …"), **a `status` enum** (a second column to keep in step),
  **an archive table** (moves rows, breaks every id reference) — D1.
- **Delete soft-deletes in place, one button** — the admin cannot see which of two things it did, and
  a typo attribute would reserve its code for ever (Q1).
- **Archived names freed** (partial unique indexes, filtered `codesDefinedOn()`, C-21/C-17 checks on
  restore) — restore becomes a **fourth door to C-21**, for the facet-splitting override ADR-0018
  rejected (Q2, D2).
- **C-18 counts all options** — a choice whose every option is archived renders an empty select, and
  if `required`, nothing below it could be published again. **A separate `allowOptionArchival()`** —
  the same rule twice (D3).
- **`AttributeRule::isArchived()` in the fact** — Listing would need a word for a Catalog concept and
  every rule would have to ignore it (D4).
- **Drop dormant values on the next save** — "retained" until the seller fixes a typo is not
  retention. **Mark rows `dormant`** — a stored copy of a derived fact, rewritten by every archive and
  move (C-22). **Carry over on re-file too** — values from an unrelated branch would wake unasked (D5).
- **Publish skips stale values too** — a listing would go public claiming an option no seller can
  choose (D6).
- **Narrow C-27 to "refuse when a value would leave the schema"** — with L-17 there is nothing left to
  protect. **Keep `hasAttributeValues()` for later** — a port method with no caller (D7).
- **Partial indexes now** — no measured need, and each is a re-diff risk (D8).
- **A `bool $includeArchived` flag on `effectiveFor()`** — the public page forgetting it is a leak;
  **filtering in PHP** — the rule is SQL (D9).
- **Leave a stale option out of the select** — "blank = clear" would drop a value the seller could
  see. **A new `InvalidAttributeValueReason::Archived`** — the Domain cannot tell archived from
  never-existed and does not need to (D10).

## Consequences

- **Easy:** an admin can retire a Brand or re-organise the tree after sellers have filled in details,
  and undo either; no listing row is written by an archive, a restore or a move. ADR-0004's promise
  is kept.
- **The invariant is weaker on purpose.** Kept true: every stored value names a row that **exists**
  (C-25/C-26); every value anyone **sees** is in force and active; every value a seller **writes**
  fits today's rules. No longer kept true: that every stored value is in force.
- **A reserved name can puzzle an admin** — *"code “brand” is taken"* with nothing visible on the
  branch. The archived row is listed on its defining category's page with Restore; if it bites, the
  refusal can name it (a message change, not a rule change).
- **The `phase1-gate` facet query must speak "active".** A naive `GROUP BY option_id` over
  `listing_attribute_value` counts dormant values, stale values and archived attributes. The gate's
  query must scope to the browsed subtree and `status = 'published'`, offer only active-schema
  attributes, and join options `AND archived_at IS NULL` — and its `EXPLAIN` must measure **that**
  query. A listing with a stale Fender is found by unfiltered search but by no Brand bucket, by
  design.
- **Dormant values accumulate**, and block hard delete of their attribute while they exist. Bounded by
  catalog churn × listings; Phase 2's expiry and GDPR erasure are their natural end. No sweep — it
  would be retroactive and needs an owner decision.
- **Restore can make a published listing show a value its seller has not seen for months.** That is
  what restore means: the value was the seller's, kept on purpose.
- **Watch:** restore is safe only because names stay reserved. Any change that lets an archived name
  be reused (a filtered `codesDefinedOn()`, a partial index) turns restore into a fourth door to C-21
  and C-17 — it must then read the branch under the lock and get a `CategoryTreeLockTest` case. The
  four new handlers run under `exclusively()` and are covered there.
