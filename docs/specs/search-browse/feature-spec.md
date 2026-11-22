# Feature Spec: search-browse

> The *what & why*. Disposable: it dies once the feature ships and its behaviour lives in tests +
> code. Make it **executable** (measurable, enumerated), not pretty. Must not contradict the
> [Constitution](../../constitution.md) or an accepted ADR.

- **Feature:** `search-browse` — Phase 2 item **1 of 15**, the `Search` context's first slice
  ([roadmap](../../roadmap.md) §Phase 2). Then `search-range-facets` (2), `search-full-text` (3),
  `search-benchmark` (4).
- **Bounded context(s):** **`Search`** (new: Domain, Application, Infrastructure). **`Catalog`** and
  **`Listing`**: **no Domain, Application, mapping or migration change**. Search *reads* their tables
  under the decision in **ADR-0023** (new, task T0 — plan D1). Two existing isolation tests widen by
  exactly one allowed directory (`Infrastructure/Search/`).
- **Related PRD items:** US-2 / FR-3 (facets driven by the selected category), §6 UX (guests search
  without signing up), §8 / Constitution §7 (the 200 ms budget — **measured in slice 4**, not here).
- **Governing ADRs:** [ADR-0002](../../adr/0002-search-postgres-behind-port.md) (Postgres behind a
  `SearchPort`; "design the port and `FacetedQuery` before the read model" — **discharged here**),
  [ADR-0004](../../adr/0004-dynamic-category-attribute-schema.md) (the generic
  `(attribute_id, option_id)` index; zero promoted columns; `is_facet` deferred; the 2026-11-22
  carry-overs — **discharged here**), [ADR-0018](../../adr/0018-schema-inheritance-derived-along-the-lineage-codes-unique-per-branch.md)
  (effective schema = lineage, C-22), [ADR-0019](../../adr/0019-listing-context-cross-context-ports-leaf-only-under-one-lock-in-two-modes.md)
  (decision 2 local id copies; decision 4 "a table name appears only in its own context's
  Infrastructure" — **amended by ADR-0023**), [ADR-0021](../../adr/0021-listing-lifecycle-in-the-aggregate-completeness-on-public-writes-dispatch-after-commit.md)
  (public reads filter `status` in SQL and never select `seller_id`; one 404),
  [ADR-0022](../../adr/0022-schema-drift-archive-reserved-names-dormant-values.md) (dormant and
  stale values; archived rows are no facet and no bucket), **ADR-0023** (new).
- **Status:** Approved by owner 2026-11-22 (Q1–Q13 as recommended; ADR option A)
- **Date:** 2026-11-22

> **What this slice teaches, beyond the feature.**
>
> 1. **A bounded context with no aggregate, honestly.** Search writes nothing, so it has no
>    consistency boundary to defend — an aggregate here would be a class pretending. What it *does*
>    have is a policy that can be wrong: *which of the visitor's filters survive*. That policy goes in
>    the Domain as a pure function on facts passed in (ADR-0012 d.2: put in the Domain the part that
>    can be wrong; ADR-0016: decide on facts, read by a port). Everything else is a query.
> 2. **Parse, don't validate — with two types.** `FilterRequest` is what the visitor asked for (only
>    well-formed ids survive); `FacetedQuery` is what may be run (every selection names an active
>    facet of the browsed category). `SearchPort::search()` accepts only the second, so "an unresolved
>    filter reaches SQL" is a type error, not a code-review item.
> 3. **Aggregates are consistency boundaries for writes, not for reads.** A read model that joins
>    across aggregates — and across contexts — is not a DDD violation; pretending it doesn't exist
>    is. ADR-0023 names the projection, fences it (read-only, one directory, scans), and replaces five
>    unacknowledged UI crossings' worth of precedent with a rule.

---

## Ubiquitous language (this slice)

| Term | Means | Not to be confused with |
|---|---|---|
| **Browse** | Look at the published listings filed at or below one category (or all of them), newest first, narrowed by facets. `GET /browse`. | Search (the context, and later free-text — slice 3). |
| **Browsed category** | The category in `?category=`; absent means *all categories*. Leaf or branch, same behaviour. | The listing's own category (always a leaf, L-2). |
| **Subtree** | The browsed category and every descendant. A listing is *in* the browse when its category is in the subtree. | Lineage (upwards). |
| **Facet** | An **active choice or boolean** attribute in the browsed category's **effective schema** (its own + every ancestor's, C-22). Number and text attributes are not facets in this slice. | Attribute (Catalog's word for the definition). `is_facet` (a flag that does not exist yet). |
| **Bucket** | One value of a facet a visitor can select: an **active option** of a choice facet, or *Yes* / *No* of a boolean facet. Shows a **count**. | Option (Catalog's row; an archived option is never a bucket). |
| **Count** | For a bucket: the number of browsable listings that hold that value **and** match every selection on **other** facets (disjunctive). | Total (listings matching *every* selection). |
| **Selection** | The buckets chosen for one facet. Several buckets of one facet = **any of** (OR); selections on different facets = **all of** (AND). | Filter (the URL parameter before it is resolved). |
| **Filter request** | What the URL asked for: `f[<attribute id>][]=<option id \| yes \| no>`, after malformed values are dropped. Untrusted. | Faceted query (resolved). |
| **Faceted query** | A filter request **resolved** against the browsed category's facet schema: only selections naming a facet and a bucket of it remain; plus the page. The only input `SearchPort::search()` accepts. | Filter request. `SearchAlert` params (Notification, slice 13). |
| **Facet schema** | The facts a filter request is resolved against: the browsed category's facets (id, kind, label, order) and which of the *requested* option ids are active options of which facet. Read through the port; never stored. | Effective schema (Catalog's; includes number/text and `required`). |
| **Dropped** | A filter value that does not survive resolution. Silently omitted — the page renders as if it was never asked; never a 4xx/5xx. | Refused (a write's answer). |
| **Hit** | One listing in the result page: id, title, price, currency, published at. Never seller data. | Listing (the aggregate). |
| **Newest first** | `published_at DESC, id DESC` — the moment it last became public (L-16), ties broken by id. | Creation order (the UUIDv7 id). |

### Invariants

`Search` starts its own series. None is an aggregate invariant — Search has no aggregate (plan D2).
Each is either a **Domain rule** (decided by a pure function, unit-tested) or a **query rule** (a SQL
clause with a fixture row that only it excludes — the phase1-gate discipline).

| # | Rule | Where it lives | Proved by |
|---|---|---|---|
| **S-1** | Only `published` listings are found or counted. Draft and withdrawn never appear. | Query: `l.status = 'published'` in the one shared "matching listings" fragment. | Fixture rows Dr, W (AC-6). |
| **S-2** | Only listings whose category is in the **subtree** of the browsed category; no category → every published listing. | Query: the depth-bounded `subtree` CTE. | Fixture row P2 (AC-7). |
| **S-3** | A **facet** is an attribute that is `choice` or `boolean`, **active** (`archived_at IS NULL`), and **in the browsed category's lineage**. No category → no facets. | Query: `facetSchemaFor()`. | Fixture rows F (archived attribute, AC-8) and Brand-at-Instruments (lineage, AC-9). |
| **S-4** | A **bucket** of a choice facet is an **active** option. A stale value (archived option) is in no bucket and matches no selection; a dormant value (attribute archived or out of lineage) counts nowhere. | Query: `o.archived_at IS NULL` in the counts; S-3 for dormant. | Fixture row S (AC-10); dormant-by-move (AC-11). |
| **S-5** | **A selection survives only if** its attribute is a facet of the browsed category (S-3) **and** every surviving value is a bucket of *that* facet: an active option of that attribute for a choice, `yes`/`no` for a boolean. Everything else is **dropped**: malformed ids, unknown ids, archived attribute or option, an option of another attribute, `yes` on a choice, an option id on a boolean, duplicates, values beyond the caps. A selection left with no value is dropped whole. | **Domain:** `FilterRequest::parse()` (format, caps), `FacetedQuery::resolve()` (against `FacetSchema`). | Unit tests (AC-1, AC-2); integration (AC-12). |
| **S-6** | Within one facet the selected buckets are OR'd; across facets, AND'd. | Query: one `EXISTS (… IN (:vN))` per selection (DBAL expands the list). | AC-13. |
| **S-7** | A bucket's **count is disjunctive**: it ignores the selection on its own facet and applies every other. **Total** applies all. | Query: the own-facet selection is left out of that facet's count query. | AC-14. |
| **S-8** | Results are ordered `published_at DESC, id DESC`, `FacetedQuery::PER_PAGE = 20` per page. `page` is clamped to `1 … FacetedQuery::MAX_PAGE = 50`; non-numeric → 1. A page past the last is an empty page (200). | **Domain** (page clamp); query (order, limit/offset). | AC-3, AC-15. |
| **S-9** | **No Search read selects `seller_id`**, and nothing in `Infrastructure/Search` writes. | Query; `SearchIsolationTest` scans. | AC-20, AC-24. |

**What these keep true together:** every number on the browse page counts published listings in the
browsed subtree holding a value that is in force and selectable right now; every selection that
reaches SQL names such a value. **What is deliberately not kept true:** that the page is consistent
with itself to the millisecond. Its 4–25 statements each take their own READ COMMITTED snapshot and
**no lock** (plan D6): an admin archiving an option between two of them can make one count off by its
listings for one page view. A read that queues behind admin writes would cost more than that.

## User story

As a **guest buyer**, I want to **open a category, see its newest published listings, and narrow them
by the category's choice and yes/no details with a count beside each value**, so that **I reach the
exact configuration I want without signing up** — and the owner gets a deployable `SearchPort` whose
"active" rules are proved, before any styled UI is built on it.

## In scope

- `Search` Domain: `SearchPort` (two methods), `FilterRequest`, `FacetSchema`, `FacetedQuery`,
  `Selection`, `FacetKind`, result types (`SearchResult`, `ListingHit`, `Facet`, `FacetBucket`), local
  id copies `CategoryId` / `AttributeId` / `OptionId` (each with its own exception), `UnknownCategory`.
- `Search` Application: `BrowseListings` query + `BrowseListingsHandler`.
- `Search` Infrastructure: `PostgresSearch` (the adapter, DBAL, read-only); `BrowseController`
  (`GET /browse`); `templates/search/browse.html.twig` (unstyled, JS-free, semantic, stable classes);
  DI alias; one "Browse" link in the layout nav (Q10).
- **Carry-over 1:** `SchemaMutationGateTest`'s `FACET_COUNT`, its section B fixture and its section C
  `EXPLAIN` test **move** into Search's tests, generalised to the adapter's real SQL (Q9).
- **Carry-over 2:** ADR-0023 — how Search reads Catalog's and Listing's tables (plan D1), with
  amendments to ADR-0019, ADR-0002 and ADR-0004.
- `SearchIsolationTest`; the two table-ownership scans in `ListingIsolationTest` admit
  `Infrastructure/Search/`.

## Non-goals (explicit — hold the line)

- **Number ranges and price** (filter or sort) → `search-range-facets` (2). Price-across-currencies
  is that slice's owner decision.
- **Free text** (FTS, `pg_trgm`, `unaccent`, the tsquery sanitizer) → `search-full-text` (3).
- **Latency proof, `ANALYZE`d 10k rows, planner choice without switches, index tuning, hot-column
  promotion, keyset paging** → `search-benchmark` (4). This slice adds **no migration and no index**.
- **Styling and the first public layout** → `public-styles` (5). **Progressive enhancement, live
  facets, "show more" on long bucket lists, the 0-result "Subscribe" slot** → `search-ui` (7).
- **Text attributes as facets** — never in Phase 2's plan; a text value is free text.
- **`is_facet` / `is_searchable` flags** (ADR-0004 amendment 2026-11-15 point 2): still deferred.
  Every active choice/boolean attribute is a facet (Q11).
- **Dependent options** (Model under Brand, D14).
- **Expired listings** → `listing-expiry` (11) adds its predicate to the shared fragment.
- **Search alerts** → slices 13–14 (they will reuse `SearchPort` — one definition of "matches").
- **Sorting choices** other than newest first. **Seller data** of any kind on hits.
- **Any write**, any event, any cache (HTTP, Redis or otherwise), any lock.

## Acceptance criteria (the Definition of Done checklist)

### A. The Domain rules (unit, no kernel)

- [ ] **AC-1 (`FilterRequest::parse()` — format)** — From `array<string, list<string>>`: an attribute
      key that is not a well-formed UUID is dropped; a value that is neither a well-formed UUID nor
      exactly `yes`/`no` is dropped (`YES`, `true`, `1`, `" yes"`, `"<uuid>\n"` all dropped);
      duplicate values collapse; an attribute left with no value is dropped. At most
      `FilterRequest::MAX_ATTRIBUTES = 20` attributes (first 20 in request order) and
      `MAX_VALUES = 50` values each survive. Nothing throws.
- [ ] **AC-2 (`FacetedQuery::resolve()` — S-5)** — Against a hand-built `FacetSchema`: a selection
      on an attribute not in the schema is dropped; on a choice facet only option ids the schema lists
      **for that attribute** survive (another facet's option, an unlisted option, `yes`/`no` are
      dropped); on a boolean facet only `yes`/`no` survive (an option id is dropped); a selection left
      empty is dropped whole; with no category the schema is empty and every selection is dropped.
- [ ] **AC-3 (page — S-8)** — `resolve()` clamps the page: `0`, `-3` → 1; `51`, `PHP_INT_MAX` → 50;
      `PER_PAGE` is 20, `offset()` is `(page - 1) * 20`.
- [ ] **AC-4 (`Facet::of()`)** — Builds one facet from a `FacetAttribute`, the counts and the
      selection: buckets ordered by label (case-insensitively, `MB_CASE_LOWER_SIMPLE`, then id);
      booleans *Yes* then *No*; an unselected bucket with count 0 is omitted; a **selected** bucket
      is kept even at count 0 (so it can be unchecked), labelled from the facet schema; a facet with
      no bucket is omitted unless it has a selection.
- [ ] **AC-5 (isolation)** — `SearchIsolationTest`: `Domain/Search` and `Application/Search` reference
      no other context (`Shared` admitted); the code of `Infrastructure/Search` (comments stripped)
      contains no upper-case, word-bounded `INSERT`, `UPDATE`, `DELETE`, `TRUNCATE`, `ALTER`, `CREATE`, `DROP`, `MERGE` (so `createNotFoundException` passes) and no
      `seller_id`; each scan asserts it scanned > 0 files. The new ids are in `UuidIdShapeTest`'s ring.

### B. The "active" fixture, lifted (integration, DAMA, through `BrowseListingsHandler`)

The fixture is `SchemaMutationGateTest` section B's, unchanged in substance (values tree; Fretboard
wood + Finish defined through `DefineAttributeHandler`; listings P1, P1b, P2, Dr, W, S, F), moved into
a `BuildsTheFacetFixture` trait. Every assertion goes through the **handler** — never the adapter's
methods one by one — because S-3's clauses live in `facetSchemaFor()` and S-1/S-2/S-4's in the counts.

- [ ] **AC-6 (S-1)** — Browsing Guitars, Fretboard wood shows `Maple 1, Rosewood 2`; the control
      query shows four Rosewood values below Guitars (P1, P1b, Dr, W) and three statuses stored.
      Total at Guitars counts P1, P1b, S, F only.
- [ ] **AC-7 (S-2)** — At Instruments Rosewood is 3 (P2 added); at Guitars 2.
- [ ] **AC-8 (S-3, archived attribute)** — At Instruments Finish shows `Gloss 1`; after
      `ArchiveAttributeHandler` it is **no facet** (absent from the result), `f[<Finish id>][]=<Gloss id>`
      is dropped (total = unfiltered total), and F's stored value rows are byte-identical.
- [ ] **AC-9 (S-3, lineage)** — Brand is a facet at Guitars (`Fender 3, Gibson 1`) and **not** at
      Instruments, though published listings below Instruments hold Brand values (control count 4).
- [ ] **AC-10 (S-4, archived option)** — After `ArchiveAttributeOptionHandler` on Maple: no Maple
      bucket (`Rosewood 2` only); selecting Maple is dropped (total unchanged); S's value rows are
      byte-identical and still stored (control).
- [ ] **AC-11 (dormant by move)** — Moving Bass (holding P2, whose Fretboard wood value comes from
      Instruments) under a new root category *Other* through `EditCategoryHandler`: browsing *Other*
      shows no Fretboard wood facet; Instruments' Rosewood count drops to 2. No fifth clause exists
      in the SQL for it (subtree ∩ lineage cannot hold a dormant value).
- [ ] **AC-12 (S-5 end to end)** — Through the handler, a request mixing one good selection with
      each kind of bad one (malformed, unknown uuid, Gibson's id under Fretboard wood, `yes` on Brand,
      archived option, attribute of a sibling branch) returns **exactly** the result of the good
      selection alone.

### C. Semantics

- [ ] **AC-13 (S-6)** — At Guitars: `Brand ∈ {Fender, Gibson}` totals 4 (OR); adding
      `Fretboard wood = Rosewood` totals 2 (AND: P1, P1b).
- [ ] **AC-14 (S-7, disjunctive)** — At Guitars with `Brand = Gibson` selected: Brand still shows
      `Fender 3, Gibson 1` (own selection ignored); Fretboard wood shows `Rosewood 1` (Gibson applied);
      total is 1.
- [ ] **AC-15 (S-8, order and paging)** — Own fixture. A listing drafted first but published last is first; two
      listings published in the same second are ordered by id descending; with 21 published listings
      page 1 has 20, page 2 has 1, page 3 has 0 (and the total is still 21).
- [ ] **AC-16 (boolean facet)** — Own fixture (values tree; published Electric listings with
      *Has case* = yes, = yes, = no, and one with no value): at Electric *Has case* shows
      `Yes 2, No 1` (`false` counted too); `f[<Has case id>][]=no` totals 1; `yes` + `no` together
      total 3 (any value, not the one without).
- [ ] **AC-17 (no category)** — `/browse` with no category lists every published listing newest
      first, no facets, any `f[…]` dropped.

### D. The index (usability, not latency — that is slice 4)

- [ ] **AC-18 (EXPLAIN on the real statements)** — The test drives the handler with the
      `QueryRecordingStatement` installed (extended to record bound values — plan D9), takes the
      adapter's **facet-count** statement and its **hits** statement *with a selection*, and replays
      each as `EXPLAIN (FORMAT JSON)` under `SET LOCAL enable_seqscan = off, enable_nestloop = off,
      enable_mergejoin = off`. The facet-count plan contains a node on
      `idx_listing_attribute_value_option` whose `Index Cond` contains `attribute_id =` (a full
      index scan does not count); **neither plan** has a `Seq Scan` on `listing_attribute_value`. On
      failure the plan is printed. The docblock says what it does **not** prove (planner choice on
      real data, latency — `search-benchmark`).

### E. The page (functional, `KernelBrowser`)

- [ ] **AC-19 (renders, anonymously, without JS)** — `GET /browse?category=<Guitars>` → 200 for a
      guest: heading *Guitars*; breadcrumb links *Instruments › Guitars*; links to child categories;
      one `<form method="get" action="/browse">` with a hidden `category`, one `<fieldset>` per facet
      (`<legend>` = attribute label), one checkbox per bucket named `f[<attribute id>][]` with value
      `<option id>|yes|no` and label `Label (count)`, checked when selected; a submit button; a
      *Clear filters* link; the total; an `<ol>` of hits each linking `/listings/{id}` with title,
      price + currency and date; *Previous*/*Next* links that keep `category` and `f`. Facet
      checkboxes work by submitting the form — no `<script>` is required for any of it.
- [ ] **AC-20 (no seller data)** — Every statement the GET sends (recorded) is free of `seller_id`
      (and the recorder saw > 0 Search statements); the page contains none of the seller's account
      fields (the `PublicListingTest` scan, reused).
- [ ] **AC-21 (one 404)** — `?category=` with an unknown UUID, a malformed string, `"<uuid>\n"`,
      and `category[]=x` each answer 404 with bodies **identical to each other** after
      `ReadsListingPages::normalised()`. A malformed one issues **no** Search statement.
- [ ] **AC-22 (hostile parameters are a 200)** — Each of `?f=x`, `?f[]=x`, `?f[a][b][c]=1`,
      `?f[<uuid>]=<not-an-array>`, `?page[]=1`, `?page=abc`, `?page=-1`, `?page=99999999999999999999`,
      a 30-attribute `f`, and `%00` / invalid UTF-8 in keys and values answers **200** with the
      dropped parts ignored. (`InputBag::all('f')` / `getInt('page')` would 400 — plan D8.)
- [ ] **AC-23 (bounded statements)** — The GET sends at most `5 + k` statements, `k` = facets with a
      surviving selection (outline, facet schema, total, hits, unselected-facet counts, one per
      selected facet); the count is **the same** for a Brand of 2 options and of 50 (no per-option
      or per-hit query); `/browse` with no category sends at most 3. No statement is a write.
- [ ] **AC-24 (selections survive navigation where they apply)** — Category links carry `f`; going
      from Guitars (Brand = Fender selected) to Electric keeps Brand selected (inherited), going to
      Instruments drops it (not in lineage) — both through S-5, no special code.

### F. Proof and docs

- [ ] **AC-25 (tests bite)** — Each mutation fails a named test (recorded in the commit message):
  - (a) drop `l.status = 'published'` from the matching fragment → AC-6
  - (b) drop the `subtree` join → AC-7
  - (c) drop `a.archived_at IS NULL` from `facetSchemaFor()` → AC-8
  - (d) drop `o.archived_at IS NULL` from the count statement → AC-10
  - (e) `facetSchemaFor()` reads attributes of any category instead of the lineage → AC-9
  - (f) `v.attribute_id::text = ANY(…)` in the count statement → AC-18
  - (g) `facetSchemaFor()` reports a requested option without `archived_at IS NULL` → AC-10 (selection kept)
  - (h) `resolve()` keeps an option id the schema lists under a different attribute → AC-2, AC-12
  - (i) the own-facet selection applied to its own count → AC-14
  - (j) AND within a facet (one `EXISTS` per value) → AC-13
  - (k) `ORDER BY l.id DESC` only → AC-15
  - (l) `SELECT l.*` in the hits statement → AC-20 and AC-5
  - (m) `$request->query->all('f')` in the controller → AC-22
  - (n) a route `requirements` or a 400 for a malformed category → AC-21
  - (o) labels fetched per bucket (a query in the bucket loop) → AC-23
  - (p) only `value_bool = true` counted for booleans → AC-16
  - (q) `FilterRequest` without the attribute cap → AC-1
- [ ] **AC-26 (the gate test shrinks, nothing is lost)** — `SchemaMutationGateTest` keeps section A
      only; `FACET_COUNT` and sections B/C are gone from it; every phase1-gate AC-6…AC-13 assertion
      has a named successor in AC-6…AC-11/AC-18 (listed in the commit message).
- [ ] **AC-27 (docs)** — ADR-0023 accepted; dated amendments on ADR-0019 (decision 4), ADR-0002
      (the port's shape), ADR-0004 (carry-overs discharged, `is_facet` still deferred); CLAUDE.md
      status + a *Search* footgun block; roadmap item 1 ticked; FORboehpyk.md chapter.
- [ ] **AC-28 (gates)** — `make check` green; `php -d zend.assertions=-1 vendor/bin/phpunit` green;
      `make test` twice in a row green.

## Failure contract

| Condition | Expected behaviour |
|---|---|
| `category` absent or empty | All published listings, no facets (S-3), 200. |
| `category` malformed, non-scalar, or a UUID naming no category | **404**, one body for all (AC-21). No Search statement for a malformed one. |
| `f` not an array, nested too deep, keys/values malformed, unknown, archived, of another facet, over the caps | Dropped silently (S-5); 200 (AC-22). The form shows only what survived. |
| `page` non-numeric, ≤ 0, huge, an array | Clamped (S-8); 200. |
| `page` past the last page | 200, empty hit list, total shown, link to page 1. |
| Browsed category has no published listings | 200, "No listings found.", no facets, child links still shown. |
| A facet whose every bucket counts 0 and has no selection | Not rendered. |
| An option archived between the schema read and the count read | One stale count for one page view (accepted, plan D6). No error. |
| A category deleted between the outline read and the schema read | 404 (the schema read decides). Deleted after: page renders without breadcrumb (tolerant lookup). |
| PostgreSQL down | The usual 500 → Sentry (`error`); no fallback. `/health/ready` already reports it. |
| A statement exceeds the budget | Not detected here — `search-benchmark` (4) owns latency. |

## Open questions for the owner

The plan is written to the **recommendation** in each; answer only where you disagree.

1. **ADR-0023 — how Search reads Catalog's and Listing's tables** (plan D1). **(A) Search is a
   read-only projection context**: its one adapter joins `catalog_*` and `listing_*` in single
   statements; ADR-0019 decision 4 gains one exception, fenced by scans (read-only, one directory, no
   `seller_id`). **(B) Composition through ports with id lists**: Search asks Catalog for subtree,
   facet and option ids and hands them to a Listing-side adapter that holds the count SQL.
   *Recommend A* — B moves Search's core SQL into Listing's directory, ships active-option id lists
   (a 5 000-entry Brand) as parameters, and gives slice 4 two places to tune.
2. **URL shape.** *Recommend* `GET /browse?category=<uuid>&f[<attribute uuid>][]=<option uuid|yes|no>&page=n`
   — the whole query in the query string ("a search is a link", roadmap item 7), ids never change on
   rename or move. Alternatives: `/browse/{categoryId}`, or a slug path `/browse/instruments/guitars`
   (readable, but breaks on every rename/move — needs redirects).
3. **No category.** *Recommend* `/browse` = all published listings, no facets, root categories as
   links. Alternative: require a category.
4. **Unknown / malformed category.** *Recommend* one 404 (the `/listings/{id}` rule). Alternative:
   fall back to all categories (hides dead links).
5. **Paging.** *Recommend* 20 per page, page clamped to 1…50 (OFFSET ≤ 980), past-the-end = empty
   page. Alternative sizes, or redirect past-the-end to the last page.
6. **Facet semantics.** *Recommend* OR within a facet, AND across, **disjunctive counts** (S-6, S-7):
   costs one extra statement per selected facet. Alternative: single-select + conjunctive counts (one
   facet statement) — but then the selected facet's other buckets read 0.
7. **Bucket order.** *Recommend* alphabetical by label. Alternative: by count, descending.
8. **Zero-count buckets.** *Recommend* hidden unless selected. Alternative: shown greyed (needs every
   option of a 5 000-entry Brand loaded — no).
9. **Carry-over 1: move or copy?** *Recommend* **move** — `FACET_COUNT` and sections B/C leave
   `SchemaMutationGateTest` (one definition of an active count, not two that drift). Alternative: keep
   the gate test's private copy as a frozen record of the gate.
10. **A "Browse" link in the layout nav.** *Recommend* yes (one line). Alternative: unlinked until
    `search-ui`.
11. **`is_facet`.** *Recommend* still deferred: every active choice/boolean attribute is a facet
    (ADR-0004 amendment). Add the flag when an admin first needs to hide one.
12. **Selections across category links.** *Recommend* links carry `f` and S-5 re-resolves (inherited
    facets survive, others drop). Alternative: every category link resets the filters.
13. **Crawlers.** The facet URL space is combinatorial. *Recommend* `<meta name="robots"
    content="noindex">` on `/browse` until `search-ui` decides canonical URLs. Alternative: nothing yet
    (`/` is the coming-soon stub, so nothing links here publicly except the nav).
