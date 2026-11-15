# ADR-0017: Attribute schema — attributes and options as separate aggregates referencing by id; set-rules on options as cross-instance invariants under the tree lock; definitions stored once on the defining category

- **Status:** Accepted
- **Date:** 2026-11-15
- **Established by** the `catalog-attribute-schema` slice ([spec](../specs/catalog-attribute-schema/feature-spec.md),
  [plan](../specs/catalog-attribute-schema/technical-plan.md) D1–D4, D6, D13, D14) — Catalog slice 2
  of 3, and the first aggregate that has **parts**.
- **Applies** [ADR-0004](./0004-dynamic-category-attribute-schema.md) (attributes are data; no admin
  action ever runs DDL; its `attribute` + `attribute_option` table sketch stands),
  [ADR-0007](./0007-persistence-conventions-for-domain-aggregates.md) and
  [ADR-0009](./0009-email-verification-tokens-modelled-in-the-domain.md) decision 4 (reference by id,
  no association, no FK) unchanged.
- **Extends** [ADR-0016](./0016-category-tree-per-node-aggregates-under-one-tree-lock.md): the same
  *aggregate decides on facts passed in, the lock makes them true, an index is the backstop* pattern,
  now applied across two aggregate types.
- **Amends** ADR-0004 (hard delete until values exist; facet flags deferred) and ADR-0016 (the lock
  now guards attribute and option writes; C-13 is the first accreted delete guard). See the dated
  2026-11-15 amendments at the foot of each.

## Context

An admin defines typed attributes on a category: "Orientation" (a choice of Left/Right), "String
count" (a whole number), "Has case" (yes/no), "Serial number" (text). A **choice** attribute has
options, and the owner's answers settled what kind of thing that set is:

- **It is unbounded.** A "Brand" list runs to thousands of entries (owner, Q5: no cap).
- **Options will depend on options of another attribute.** "Model" options hang under a "Brand"
  option (owner, Q7: out of scope now, expected later). "Standard" will exist under Fender *and*
  under Squier, so label uniqueness becomes per *(attribute, parent option)*.
- **Listings will point at options directly** — ADR-0004's value layer stores
  `listing_attribute_value.option_id`.

The tempting model puts the options inside the attribute, because every option rule — "labels are
unique", "never the last one" — reads like a rule about the set, and the set looks like the
attribute's. `User` already holds `list<OAuthIdentity>` in a `jsonb` column (ADR-0014), so the shape
is familiar. It was the first draft of this plan.

## Decision

1. **`Attribute` is its own aggregate, referencing its category by id** (D1). It holds `id`,
   `categoryId` (a plain `CategoryId` column, no `<many-to-one>`, no FK), `code`, `label`, `type`,
   `required` and `?numberRules`. It holds **no options**. Id, defining category and type never change
   (C-14).
2. **`AttributeOption` is its own aggregate** (D2). It holds `id`, `attributeId` (a plain
   `AttributeId` column), `label` and `labelKey`. Options are **created by their attribute**:
   `Attribute::newOption(AttributeOptionId, AttributeLabel): AttributeOption`, an aggregate acting as
   the factory for another aggregate. That is how C-15 ("only a choice attribute has options") stays
   the attribute's rule while the option is a separate root.
3. **The set-rules on options are cross-instance invariants, handled exactly as ADR-0016 handles the
   tree's** (D5):
   - **C-17, option labels unique within an attribute (case-insensitively):** a handler pre-check
     (`AttributeOptionRepository::isLabelTaken()`) under `CategoryTreeLock` for the message; a
     **unique index `(attribute_id, label_key)`** is the guarantee. For the initial labels of a
     define, `Attribute::defineChoice()` decides in memory on the batch it is handed.
   - **C-18, a choice attribute always has at least one option:** `Attribute::allowOptionRemoval(int
     $optionCount)` decides, on a `countForAttribute()` read under the lock. There is no index that
     could express this; the lock is the only thing making the count true. Without it, two concurrent
     removes of the last two options each count 2 and both succeed — write skew.
4. **Case-insensitive uniqueness through a stored `label_key`, not an expression index** (D3).
   `label_key` = `AttributeLabel::key()` = the simple lower-case mapping (`MB_CASE_LOWER_SIMPLE`, never `mb_strtolower`, whose full mapping lengthens "İ" past `varchar(100)`) of the trimmed label, assigned by the
   aggregate in the same statement as the label. `key()` is the single definition of "same label":
   the pre-check and the stored column both come from it, so there is no PHP-versus-Postgres
   collation drift.
5. **Option order is id order** (D3). Ids are UUIDv7 minted under the lock, so `ORDER BY id` is
   insertion order and there is no `position` column. The ceiling — a backwards wall-clock step
   between two separate adds — is accepted; the upgrade is an additive `position` column backfilled
   from id order, which manual reordering would need anyway.
6. **Definitions are stored once, on the defining category** (D4). In this slice a category's schema
   is exactly its own attributes, and any category, leaf or not, may define them. Inheritance (slice
   3) then becomes **a read-time derivation over the lineage**, under the same lock — so a move stays
   one row (ADR-0016 decision 4, extended), and no attribute or option row is touched by a rename,
   re-slug or move. Per-category code uniqueness (C-11) deliberately permits the same code lower in a
   lineage, which is the natural shape of an override.
7. **Four types, fixed at definition** (D6): `Choice`, `Number` (integer only, owner Q1), `Boolean`,
   `Text`. Four named constructors put each type's required parts in its signature: `defineNumber`
   takes `NumberRules`, `defineChoice` takes a non-empty list of initial labels. `defineChoice` takes
   the labels **as facts to decide on and does not keep them**; the handler builds the options through
   `newOption()`.
8. **Defining a choice attribute saves several aggregates in one transaction: options first, then
   the attribute** (D13). **The attribute row is the commit point of the whole definition:** written
   **last** on define, removed **first** on delete (D7). If the transaction were ever split, the only
   possible leftover is an option without an attribute — inert, since every option route goes through
   its attribute. The reverse order could leave a choice attribute with zero options, violating C-18.
   Inside one transaction, a refusal at either save rolls back both. Both call sites name the other's
   order (the ADR-0009 rule: a deliberate contradiction carries its comment).
9. **Forward compatibility with dependent options** (D14). Nothing is added now — no
   `parent_option_id`. What is guaranteed: options are roots, so a Model option can later hold a
   `?AttributeOptionId $parentOptionId`; C-17 lives **only** in the index and the port's
   `isLabelTaken(AttributeId, AttributeLabel, ?AttributeOptionId $except)`, never in an in-memory list
   on `Attribute` beyond the define-time batch. Widening it adds a `?parentOptionId` argument and
   replaces the index with the slice-1 two-partial-index pair (`… WHERE (parent_option_id IS NULL)` and
   `(attribute_id, parent_option_id, label_key) WHERE (parent_option_id IS NOT NULL)`), because NULLs
   are distinct in one plain index (the C-3 lesson).

## Alternatives

**Options as a `list<AttributeOption>` inside `Attribute`, in a `jsonb` column** (the first draft, and
the `User`/`OAuthIdentity` shape). **Rejected**, for four independent reasons:

1. **The set is unbounded.** An aggregate is loaded, checked and rewritten whole on every change.
   Relabelling one brand would rewrite a multi-hundred-KB row. That is a boundary drawn around
   storage, not around consistency.
2. **Dependent options.** A Model option belongs under a Brand option of *another* attribute. An
   option inside Brand's document cannot be the target of that reference without breaking Evans' rule
   (nothing outside an aggregate holds a reference to an entity inside it).
3. **Per-parent uniqueness.** "Standard" under two brands needs a nested structure inside one
   document; as rows it is a wider unique index.
4. **Listings reference options directly** (`option_id`). A root is something you may hold a
   reference to; an entity inside another aggregate is not.

**Attributes inside `Category`, or a `CategorySchema` aggregate per category** (D1). Every listing
value references `attribute_id`, which is an outside reference into another aggregate — the ADR-0016
D1 argument. Slice 3 also needs attributes addressable one by one along a lineage.

**A unique expression index `(attribute_id, lower(label))`** (D3). DBAL 4.5 cannot declare one in a
mapping, and its PostgreSQL introspection joins `pg_attribute` on `indkey`, where an expression column
has `attnum = 0` — so the index is read back as a different index and **re-diffed by every
`make migration.make`, forever**. This is the partial-index footgun, but worse: no normalised spelling
fixes it. `schema_filter` filters by asset name per table; using it to hide one index is undocumented
and fragile. A generated column (`GENERATED ALWAYS AS (lower(label)) STORED`) has the same problem;
`citext` is an extension DBAL types as text, so the diff noise remains. **Store the normalised form
instead.**

**A `position int` column** with `max + 1` under the lock: one more column, one more read, and a
gap/renumber question on remove, all for an order the UUIDv7 id already gives.

**An option `code` as identity:** a typo would be permanent, and nothing needs one yet.

**A second lock for attributes and options:** two locks require a lock-ordering rule, for one admin.
**`SELECT … FOR UPDATE` on the attribute row** would close C-18's race but is a second concurrency
mechanism to reason about.

**A mutable attribute type**, single-table inheritance per type, or a `range` type (D6): schema drift
by design, mapping machinery for four small branches, and no PRD need, respectively.

**Attribute first, then options on define** (D13): the possible leftover would violate C-18.

## Consequences

- **Easy:** relabelling one of five thousand brands writes one row. A listing, and later a dependent
  Model option, can reference an option by id, and that id survives a relabel (C-19). C-15/C-18 are
  unit-tested on `Attribute` without a database. Adding an attribute or option is an `INSERT`, never
  DDL.
- **`AttributeOption::create()` is public and marked `@internal`.** PHP has no package visibility, so
  nothing stops a caller building an option without asking its attribute. Handlers and the Foundry
  factory go through `Attribute::newOption()`. **This is a convention, not an enforcement** — a
  reviewer must catch a direct `create()` call outside `Attribute`.
- **`label_key` is a persisted derived field.** It exists only for the index, is always set from
  `label->key()` together with the label, and has no setter. A mapping that fills it from anything
  else silently breaks C-17's backstop.
- **The lock now carries invariants no index backs** (C-18). A handler that counts options outside
  `exclusively()` reintroduces write skew with every test green; the recording-double test covers all
  six attribute handlers.
- **A unique violation inside `flush()` closes the EntityManager** — every form re-renders from DBAL
  read models and every option action redirects (303), so the next page gets a fresh EM.
- **The edit page lists all options unpaginated** (owner, Q10). Pagination or a filter is additive.
- **Watch:** the UUIDv7-order ceiling (decision 5), and the hard-delete clause in ADR-0004's
  amendment, which expires with the first listing value.
