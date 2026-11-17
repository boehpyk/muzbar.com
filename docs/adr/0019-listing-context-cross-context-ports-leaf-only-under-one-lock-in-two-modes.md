# ADR-0019: The Listing context — each context states a cross-context need as its own port, the adapter lives with the data's owner; leaf-only is kept by Listing and Catalog under one lock in two modes; prices are integer minor units with a per-listing currency

- **Status:** Accepted
- **Date:** 2026-11-17
- **Established by** the `listing-draft` slice ([spec](../specs/listing-draft/feature-spec.md),
  [plan](../specs/listing-draft/technical-plan.md) D1–D8, D10) — the first aggregate of the `Listing`
  context, and the first slice in which **two bounded contexts depend on each other**.
- **Applies** [ADR-0007](./0007-persistence-conventions-for-domain-aggregates.md) (XML mapping, DBAL
  types, UUIDv7, `listing_listing`) and [ADR-0009](./0009-email-verification-tokens-modelled-in-the-domain.md)
  decision 4 (a reference is an id column, never an association, and there is no foreign key)
  unchanged — now **across** contexts: `seller_id` points into Identity, `category_id` into Catalog.
- **Applies** [ADR-0012](./0012-challenge-retention-and-recurring-background-work.md) decision 2 (put
  in the Domain the part that can be wrong) to ownership of a draft, and
  [ADR-0015](./0015-intended-action-redirect-and-login-overlay.md) (overlay-trigger shape; no GET
  changes state) to "Sell Gear".
- **Extends** [ADR-0016](./0016-category-tree-per-node-aggregates-under-one-tree-lock.md)'s *aggregate
  decides on facts passed in, the lock makes them true* across a context boundary, and receives the
  first of [ADR-0018](./0018-schema-inheritance-derived-along-the-lineage-codes-unique-per-branch.md)
  decision 8's hand-overs (the move accretion point).
- **Amends** ADR-0016 (the lock gains a shared mode; C-23 is the third delete guard; C-24 is the move's
  first Listing guard) and ADR-0007 (decision 4 refined: a multi-part value object is an embeddable).
  See the dated 2026-11-17 amendments at the foot of each.

## Context

The Catalog is finished: a tree of categories, each with attributes inherited along its lineage. The
next thing the product needs is the thing the catalog exists for — a seller's **listing** — and the
first slice of it is deliberately small: a signed-in seller drafts a listing (title, description,
price, currency, one category) and edits it later. Nothing is public yet.

Small as it is, it is the first time the codebase has to answer three questions:

- **How does one context ask another a question?** A listing is filed under a category (Catalog's)
  and sold by a seller (Identity's user). Both contexts' Domain and Application layers are supposed
  to know nothing of each other — Deptrac enforces layers, `CatalogIsolationTest` enforces contexts.
- **Who protects a rule whose doors are in two contexts?** The owner decided (2026-11-16) that **a
  listing is filed under a leaf category** (L-2). Listing can check that when a listing is drafted or
  re-filed. But a listing stops being on a leaf **without being touched at all** when an admin creates
  a subcategory under its category, or moves one under it. Three doors, two owners — and, like C-21,
  no index that can see the rule.
- **What is money?** The first value object of two parts (an amount and a currency), entered as
  decimal text by sellers who type "1234,50", and a domain where `(int) (1.15 * 100)` is `114`.

## Decision

1. **`Listing` is its own bounded context; this slice gives it one aggregate, `Listing`** (D1). It
   holds `ListingId`, `SellerId`, `CategoryId`, `ListingTitle`, `ListingDescription`, `Price` and
   `ListingStatus` — one row per listing in `listing_listing`. Attribute values (next slice) will be
   owned by this aggregate (ADR-0004 §2). Invariants start their own series, **L-n**; Catalog's
   continue after C-22.

2. **Listing names foreign ids in its own language, with its own value objects** (D2). `SellerId` and
   `App\Domain\Listing\ValueObject\CategoryId` are copies of the UUID id shape, documented as
   *"Listing's name for an id another context owns; never import theirs"*. The context says
   **seller**, not user. The only places that translate are the two adapters
   (`Catalog\CategoryId::fromString($listingCategoryId->toString())`).

3. **A cross-context need is a port in the needing context's own Domain, in its own words** (D3).
   - Listing → Catalog: `Domain/Listing/Port/Categories` — *"what is this category's status?"*
     (`statusOf(CategoryId): CategoryStatus`, one of `Missing | Leaf | Branch`) and *"hold the catalog
     steady while I file this"* (`holdSteady(\Closure): mixed`).
   - Catalog → Listing: `Domain/Catalog/Port/CategoryUsage` — *"is anything filed under this
     category?"* (`hasListings(CategoryId): bool`).

   Neither context's Domain or Application imports the other. The two adapters are the **only**
   classes that know both — an anti-corruption layer of exactly two files.

4. **A cross-context adapter lives with the context that owns the data it reads** (D4).
   `CategoriesForListing` implements Listing's port but lives in `Infrastructure/Catalog/Integration/`
   and reads `catalog_category`; `ListingCategoryUsage` implements Catalog's port but lives in
   `Infrastructure/Listing/Integration/` and reads `listing_listing`. **A table name appears only in
   its own context's Infrastructure** — `ListingIsolationTest` scans for it — so a schema change in
   either context stays inside one directory.

5. **Leaf-only (L-2) has two owners and three doors; one lock in two modes makes the facts true** (D5).
   - **Listing's half:** `Listing::draft()` and `revise()` decide L-2 on a `CategoryStatus` read
     through `Categories::statusOf()` **inside** `Categories::holdSteady()`. A revise that keeps its
     category checks nothing (the `moveUnder()`-to-current-parent shape).
   - **Catalog's half:** **C-23** (a listed-on category cannot be deleted) and **C-24** (a listed-on
     category cannot gain a child, by create-under or move-under) refuse when
     `CategoryUsage::hasListings()` is true, read **inside** `CategoryTreeLock::exclusively()`.
   - **The lock:** `holdSteady()` takes `pg_advisory_xact_lock_shared(PostgresCategoryTreeLock::KEY)`;
     Catalog writes keep the exclusive `pg_advisory_xact_lock(KEY)`. Shared conflicts with exclusive
     and not with shared, so listing writes run in parallel with each other and serialise only against
     the rare admin write.
   - **The race it closes** is ADR-0016's write skew: a seller reads "Electric is a leaf" while an
     admin reads "Electric has no listings"; without the lock both commit and a listing sits on a
     branch. With it, whichever acquires second reads after the other's commit (READ COMMITTED takes
     a fresh snapshot per statement, and every fact is read after the lock is granted).
   - **Every** Listing write runs inside `holdSteady()`, including a revise that keeps its category —
     the rule is uniform so nobody decides per handler whether "this one is safe" (ADR-0016 d.3).
     Moving a listed-on category **elsewhere** stays allowed: it remains a leaf and its listings go
     with it.

6. **`Price` is one value object of two parts, mapped as a Doctrine embeddable** (D6).
   `Price(int $amountInMinorUnits, Currency $currency)` — the business concept is one thing
   ("1234.50 EUR"), so the aggregate holds one `Price`. Mapped `<embedded … column-prefix="price_"/>`
   → `price_amount bigint`, `price_currency varchar(3)` (native `enum-type`). This **refines**
   ADR-0007 decision 4, whose rejection of embeddables was argued for single-value objects (dated
   amendment). Embeddables need a second mapping block for the context, prefixed
   `App\Domain\Listing\ValueObject`.

7. **Money is integer minor units, parsed in the Domain, never through a float** (D7).
   `Price::fromDecimal(string, Currency)`: trim; ASCII digits only, at most 7 integer digits, `.` or
   `,` as the separator (Ukrainian and Swedish sellers type a comma), at most
   `Currency::minorUnits()` fraction digits; integer and fraction combined with string padding and
   integer arithmetic. Range `1 … 999 999 999` minor units. `toDecimalString()` renders it back.
   `Currency` is a closed allow-list — **`USD`, `EUR`, `SEK`, `UAH`** (owner, 2026-11-16) — and
   carries `minorUnits()` even though all four have 2, so a 0-decimal currency (JPY) is an explicit
   addition, not a silent ×100 bug. The parser is in the Domain because "how many decimals does this
   currency have" is business knowledge, and a float on any path is a money bug.

8. **L-6 (only the seller revises) is decided by the aggregate; the HTTP answer to anyone else is
   404** (D8). `revise(SellerId $by, …)` checks `isSoldBy($by)` **first**, before any other check and
   any assignment, and throws `NotTheSeller`. The controller maps `NotTheSeller` and
   `ListingNotFound` to the same 404, because a 403 would confirm that a private draft exists. The
   read side ("my drafts", the edit page) filters by seller **in SQL**. The seller id always comes
   from the authenticated user, never from the request.

9. **C-23 and C-24 are handler checks, not aggregate parameters** (D10). C-24 is a fact about the
   chosen parent, exactly like C-6 ("the parent exists"); `Category` would combine it with none of its
   own state, so `create(…, bool $parentHasListings)` would be an `if ($flag) throw` wearing the
   aggregate's clothes. C-23 joins C-7 and C-13 in `DeleteCategoryHandler` (an aggregate governs its
   transitions, not its own non-existence). Orders — structural reasons first:
   - delete: **C-7 → C-13 → C-23**;
   - edit: **C-4 → C-5 → C-21** (aggregate) **→ C-24** (handler), only when the parent changes and
     the new parent is not null;
   - create: **C-6 → C-24 → C-3 → C-5**, only when the parent is not null.

   One exception, `CategoryHasListings`, with two named constructors: `cannotDelete()` and
   `cannotGainChild()`.

## Alternatives

**Where `Listing` lives (D1)**

- **Listing inside `Catalog`.** Constitution §4 names `Listing` as its own context. Catalog is
  admin-edited schema; listings are seller-edited data with a lifecycle. Merging them would put every
  seller's save under the admin's tree lock *exclusively*.

**Foreign ids (D2)**

- **Import the other contexts' value objects** (Conformist). Two Domain-level dependencies for two
  value classes, and the isolation test would need a "shared kernel" allow-list nobody has decided.
- **A `Shared/` id kernel.** That is ADR-0009's UUID-trait refactor, which touches Identity — its own
  slice. After this one there are nine copies of the id shape; it is now worth scheduling.

**How the contexts ask each other (D3)**

- **Catalog calls a Listing port** (`ListingRepository::existsInCategory()` from
  `DeleteCategoryHandler`): an `Application/Catalog → Domain/Listing` import, and Catalog's handlers
  then change whenever Listing's model does.
- **Domain events** (Listing publishes, Catalog keeps a count): a stored projection of a fact that has
  to be true *at the moment of the admin's write*. An eventually consistent count cannot guard an
  invariant.
- **A shared `CategoryRegistry` service:** a third place that knows both contexts.

**Where the adapters live (D4)**

- **Each adapter in the consuming context**, querying the other's tables. The obvious placement, and
  it scatters `catalog_category` SQL across two contexts' directories, so a Catalog schema change
  silently breaks Listing.
- **Adapters calling the other context's repository ports:** `CategoryRepository::get()` hydrates a
  whole aggregate into the EntityManager to answer yes/no, and the provider side has no `isLeaf`.

**How leaf-only is kept (D5)**

- **Listing writes take the Catalog lock exclusively.** Correct, but every seller's save queues
  behind every other seller's — a global write mutex on the busiest table.
- **`SELECT … FOR SHARE` on the category row.** Blocks delete and rename, but *create child* and
  *move under* write a **different** row (the child's), so it misses both C-24 doors — the same reason
  ADR-0016 rejected `FOR UPDATE` for cycles.
- **SERIALIZABLE isolation.** Correct; turns the race into `40001` retries in four handlers.
- **A stored `has_listings` flag or `listing_count` on `catalog_category`:** a second write path from
  Listing into Catalog's row — cross-context aggregate mutation, kept in step by hand.
- **Relax leaf-only to "allowed, but hidden":** the owner decided leaf-only.

**Price storage (D6)**

- **Two private scalar fields on `Listing`, reassembled in `price()`.** No config change, but the
  aggregate then models the table — two loose columns it must keep consistent. Kept as the fallback
  if readonly embeddable hydration misbehaves.
- **One `jsonb` column:** Phase 2 must sort and range-filter on the amount; a `jsonb` path is the
  wrong index.
- **A custom DBAL type over two columns:** a DBAL type is one column.
- **`decimal`/`numeric` storage:** the Domain still needs the currency's exponent to validate input,
  and an integer of minor units makes "no float anywhere" checkable by a source scan.

**Parsing money (D7)**

- **`moneyphp/money`:** a dependency, and its types inside the Domain would be a vendor import that
  Deptrac rejects.
- **Symfony `MoneyType`:** the rule would live in a form, and it converts through a float.
- **Thousands separators** ("1 234,50"): locale-dependent and ambiguous ("1,234" is 1234 or 1.234?).
  Refused; the error message shows the accepted shape.

**Ownership (D8)**

- **A Symfony Voter:** authorization outside the Domain, for a business rule with an obvious future
  exception (admin moderation) that should be visible where the listing is.
- **A handler-only check:** ADR-0012's test — put in the Domain the part that can be wrong.
- **403 for someone else's draft:** leaks existence.

**Catalog's guards (D10)**

- **Facts into `Category::create()` / `moveUnder()`** (the C-21 shape). C-21 combines two facts
  (the new parent's codes *and* the moving subtree's own); C-24 looks at one fact about someone else.
- **Two exceptions:** one concept ("listed-on"), and two named constructors already carry the
  difference.

## Consequences

- **Easy:** L-2 and L-6 are unit-tested on `Listing` without a database. Neither context's Domain or
  Application knows the other exists; the isolation scans prove it. Listing writes never wait for
  each other. "No float" is a grep, not a promise.
- **The lock's scope now crosses a context boundary — this is the watch item.** A new Listing write
  that files a listing (an import, a bulk re-file, the Phase 2 wizard) **must** go through
  `Categories::holdSteady()`, and a new Catalog write that can turn a leaf into a branch (a "merge
  categories" tool) **must** read `hasListings()` under `exclusively()`. No index or constraint will
  catch a missed door. `CategoryTreeLockTest` covers the doors that exist; a new one needs its case.
- **The one key now also orders listing writes against admin writes.** ADR-0016's `ponytail:` ceiling
  still holds and per-subtree keys are still the upgrade path; listing writes do not contend with
  each other, only with admin writes.
- **`clear()` on refusal now runs inside Listing requests too.** Safe: no Listing caller holds other
  managed entities across a handler.
- **Deploy window:** between `up -d` and `migrate`, the new Catalog guards query a table that does not
  exist yet — admin create/move/delete and `/sell` 500 for seconds. Accepted, recorded in
  `docs/infrastructure.md`.
- **Orphans on erasure:** with no FK, an erased seller leaves rows behind; the GDPR-erasure design owns
  them (ADR-0009 d.4). Nothing here reads a seller's email.
- **Phase 2 consequence — cross-currency comparison is undecided.** A listing stores an amount and a
  code; `Price::equals()` compares both, and **no ordering across currencies is defined**. Price
  facets and sorting across currencies must be decided by Phase 2 before `price_amount` is indexed:
  convert at query time against a rates table, facet per currency, or restrict a search to one
  currency. Until then, nothing sorts or filters on price.
- **No domain events** (plan D9): a draft is private and nothing consumes `ListingDrafted`; the first
  real consumer (`listing-publish`) wants `ListingPublished` and designs its payload.
