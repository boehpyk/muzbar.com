# ADR-0023: Search is a read-only projection over Catalog's and Listing's tables — one adapter, fenced by scans; no aggregate, no lock

- **Status:** Accepted
- **Date:** 2026-11-22
- **Established by** the `search-browse` slice ([spec](../specs/search-browse/feature-spec.md),
  [plan](../specs/search-browse/technical-plan.md) D1–D6; owner approved Q1 option A, 2026-11-22) —
  Phase 2 item 1, the `Search` context's first slice.
- **Implements** [ADR-0002](./0002-search-postgres-behind-port.md) (Postgres behind a `SearchPort`).
- **Amends** [ADR-0019](./0019-listing-context-cross-context-ports-leaf-only-under-one-lock-in-two-modes.md)
  decision 4 — see its dated 2026-11-22 amendment.

## Context

Browsing needs one question answered in one place: *which published listings below this category hold
these values, and how many hold each other value?* The phase1-gate proved the answer is **one
statement** joining `catalog_category`, `catalog_attribute`, `catalog_attribute_option`,
`listing_listing` and `listing_attribute_value`, with four "active" clauses each guarded by a fixture
row only it excludes.

ADR-0019 decision 4 says a table name appears only in its own context's Infrastructure, and
`ListingIsolationTest` enforces it over all of `src/`. Meanwhile five "UI crossings" (Listing
controllers reading Catalog's read models) have bent that rule without restating it.

The Constitution's own context table already gives the answer's shape: Search owns *"the read model /
faceted query port (no aggregate — a port)"*, and §4.5 says the search index — today Postgres itself —
is **derived**.

## Decision

1. **Search is a read-only projection — a CQRS read side, Conformist to two upstream schemas** (plan
   D1, option A). Its one adapter, `Infrastructure/Search/Persistence/Doctrine/PostgresSearch`, reads
   Catalog's and Listing's tables in single statements. Aggregates are consistency boundaries for
   **writes**; a read model joining them is not a violation — an unacknowledged one is.
2. **The fence** — what makes it a rule rather than a sixth bend:
   1. **Read-only:** `SearchIsolationTest` fails on upper-case, word-bounded
      `INSERT|UPDATE|DELETE|TRUNCATE|ALTER|CREATE|DROP|MERGE` in `Infrastructure/Search/`'s code
      (comments stripped).
   2. **One directory:** `ListingIsolationTest`'s two table-ownership scans admit
      `Infrastructure/Search/` and nothing else.
   3. **Privacy:** the same scan fails on `seller_id` anywhere in `Infrastructure/Search/`.
   4. **Search's Domain and Application stay isolated** — local id copies (`CategoryId`,
      `AttributeId`, `OptionId`; ADR-0019 decision 2). `Shared` admitted.
   5. **No Search table, no Search migration.** A Search-owned table later is a new ADR.
3. **No aggregate, no domain event, no repository** (D2). Search writes nothing, so it has no
   consistency boundary to defend. Its Domain holds the policies that can be wrong — *which filters
   survive* (S-5) and the page clamp (S-8) — as pure functions deciding on facts the port reads.
4. **The port takes only a resolved query** (D3/D4): `facetSchemaFor(?CategoryId, FilterRequest):
   ?FacetSchema` reads the facts; `search(FacetedQuery): SearchResult` accepts only what
   `FacetedQuery::resolve()` built. An unresolved filter reaching SQL is a type error.
5. **No lock, no transaction** (D6). Each statement reads its own READ COMMITTED snapshot; a
   concurrent admin write can make one count stale for one page view. A public read queueing behind
   `CategoryTreeLock::exclusively()` would cost more. Do not "fix" it with `holdSteady()`.

## Alternatives

- **B. Composition through ports with id lists** — Search asks Catalog for subtree/facet/active-option
  ids and hands them to a Listing-side adapter holding the count SQL. Keeps ADR-0019 d.4 literally,
  but moves Search's core SQL into Listing's directory, ships every active option id of a 5 000-entry
  Brand as parameters (or an inverted archived list — easy to get wrong), splits the four "active"
  clauses across two round trips, and gives `search-benchmark` two places to tune. Same coupling,
  spread wider: 2 ports + 2 adapters + a composer instead of 1 adapter.
- **C. A Search-owned denormalised table** maintained by listeners. Listing emits only
  `ListingPublished` — no revise/withdraw/archive events — and this is exactly ADR-0002's rejected
  sync path (write and read drift → silently empty). Reconsider only if `search-benchmark` fails
  *and* hot-column promotion (ADR-0004 §3) does not fix it.
- **D. Owner-published SQL views** (a Published Language at the database level). The subtree is a
  parameterised recursion a view cannot take, views are DDL Doctrine does not diff, and there is one
  reader. Reconsider if a second reader of the joined shape appears.

## Consequences

- **Easy:** one directory for `search-benchmark` (4) to tune, for `listing-expiry` (11) to add its
  predicate to the shared matching fragment, for `search-alert-match` (14) to reuse. Swapping to
  Meilisearch (ADR-0002) rebinds `SearchPort`; this projection becomes the indexer's source.
- **Hard — the coupling is real:** a column rename or semantic change in `listing_listing`,
  `listing_attribute_value`, `catalog_category`, `catalog_attribute` or `catalog_attribute_option`
  breaks Search **at runtime, not compile time**. Caught by `BrowseListingsTest` (the lifted
  phase1-gate fixture).
- **Watch item:** every such schema change, and every new "not public" condition (e.g.
  `listing-expiry`'s), must grep `Infrastructure/Search/` and update the matching fragment — once,
  where S-1/S-2/S-6 have their single definition.
- The "crossings" count in ADR-0019 stops: Search's reads are the rule, not a sixth crossing.
