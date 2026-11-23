# Feature Spec: search-full-text

> The *what & why*. Disposable: it dies once the feature ships and its behaviour lives in tests +
> code. Make it **executable** (measurable, enumerated), not pretty. Must not contradict the
> [Constitution](../../constitution.md) or an accepted ADR.

- **Feature:** `search-full-text` — Phase 2 item **3 of 15** ([roadmap](../../roadmap.md) §Phase 2).
  After `search-range-facets` (2); before `search-benchmark` (4), which owns latency, load, planner
  choice on real data and index tuning.
- **Bounded context(s):** **`Search`** (Domain, Application, Infrastructure) and **one migration**
  that gives Search its first database objects: the `unaccent` and `pg_trgm` extensions, two SQL
  functions, one table `search_listing_text`, two GIN indexes, and a trigger on `listing_listing`.
  **`Listing`: no PHP change** — its table gains a trigger it does not know about (plan D2).
  **`Catalog`:** no change.
- **Related PRD items:** US-2 / FR-3 (find exact matches — free text **narrows**, it does not replace
  the facets; PRD §1: "without sifting through irrelevant keyword results"), §6 UX (guests search
  without signing up). Constitution §8: *the search-query sanitizer is the single most
  security-critical class*. Latency: §8 / Constitution §7, **measured in slice 4**, not here.
- **Governing ADRs:** [ADR-0002](../../adr/0002-search-postgres-behind-port.md) (FTS primary,
  `pg_trgm` fallback "only when FTS returns too few rows", RRF "if needed", `unaccent` both sides,
  the sanitizer), [ADR-0023](../../adr/0023-search-is-a-read-only-projection-over-catalog-and-listing-tables.md)
  (read-only projection, one matching fragment; **fence 5: "a Search-owned table later is a new
  ADR"** — this slice is that ADR, **ADR-0025**), [ADR-0007](../../adr/0007-persistence-conventions-for-domain-aggregates.md)
  and CLAUDE.md's *no expression indexes* footgun (the reason the vector cannot live on
  `listing_listing`).
- **Status:** Approved 2026-11-23 (owner took every recommendation, Q1–Q7)
- **Date:** 2026-11-23

> **What this slice teaches, beyond the feature.**
>
> 1. **A derived value Doctrine cannot see belongs to the database, and the database keeps it.**
>    A `tsvector` is to title + description what a generated column is to its inputs. Doctrine diffs
>    an unmapped column on `listing_listing` as `DROP` and an expression index as a different index,
>    forever (both re-proved for this plan). So the vector lives in a Search-owned side table, hidden
>    by `schema_filter`, written by a **trigger in the writer's own transaction** — every writer,
>    every time, including a Foundry `create()`. ADR-0002's dreaded failure, *"write and read drift →
>    search silently returns nothing"*, needs two writers; this design has one.
> 2. **The sanitizer cannot be the only thing standing between user text and the query parser.**
>    Measured while planning: `unaccent` maps *letters* to tsquery syntax — `ʼ` (U+02BC, a `\p{L}`)
>    becomes `'`, `ː` becomes `:`. A sanitizer that keeps letters, followed by the fold, followed by
>    `to_tsquery()`, turns `ʼfoo` into `syntax error in tsquery` — a 500 from one letter. So the
>    sanitizer strips (PHP, unit-tested), and **PostgreSQL's own text parser** builds the query
>    (`plainto_tsquery`, every lexeme quoted by PostgreSQL), with `:*` appended to the parser's last
>    lexeme. No user-derived string ever reaches `to_tsquery()`'s grammar.
> 3. **Free text is a filter, not a ranker.** Results stay newest first (S-8 unchanged); the typo
>    fallback fires only when the exact match finds **nothing**, so exact and similar matches are
>    never mixed — and two rankings that never combine need no RRF. YAGNI, recorded with its trigger.

---

## Ubiquitous language (this slice)

Terms from `search-browse` and `search-range-facets` (browse, subtree, facet, bucket, count,
selection, filter request, faceted query, facet schema, dropped, hit, range, price filter, blank) keep
their meaning.

| Term | Means | Not to be confused with |
|---|---|---|
| **Search text** (`q`) | What a visitor typed into the one text box, untrusted. | A tsquery (never built from it directly). |
| **Search terms** (`SearchTerms`) | The search text **sanitized** (S-15): at most 8 terms, each a run of letters, marks and digits, from the first 100 characters. What reaches SQL, as one space-joined string. Never empty — no terms means *no text filter*. | Lexemes (PostgreSQL's, after folding and parsing). |
| **Fold** (`search_fold()`) | The one mangling applied to text on **both** sides: `unaccent` with a fixed dictionary (`Strätocaster` → `Stratocaster`). An `IMMUTABLE` SQL function. Case is folded by the `simple` configuration and by `pg_trgm`, not by PHP. | Lower-casing in PHP (forbidden: a second definition). |
| **Search document** | A listing's title + description, folded and parsed with the `simple` configuration into a `tsvector`, stored in `search_listing_text.document`. Derived; never written by PHP. | The listing (Listing's aggregate). |
| **Exact match** | A listing whose search document holds **every** term, the last one as a **prefix** (`strat` → `stratocaster`). | Equality of strings. |
| **Similar match** | The typo fallback: a listing whose **folded title** is word-similar to the folded search terms (`pg_trgm`, `%>`, threshold 0.6). Used **only** when the exact match found nothing (S-18). | Relevance ranking (none exists). |
| **Text match** (`TextMatch`) | Which of the two a page was answered with: `Exact` or `Similar`. Decided once per request; every statement of that request uses it. | A per-row flag. |

### Invariants

Search's series continues from S-14. Each is a **Domain rule** (pure function, unit-tested), a
**query rule** (a SQL clause with a fixture row only it excludes), or a **database rule** (a
migration object with a shape test).

| # | Rule | Where it lives | Proved by |
|---|---|---|---|
| **S-15** | **The sanitizer.** Search text survives as search terms iff it is valid UTF-8 and yields at least one term. Only the first `MAX_LENGTH = 100` characters are read. A term is a maximal run of `[\p{L}\p{M}\p{N}]` holding at least one letter or digit; **every other character is a separator** — all tsquery syntax (`& \| ! ( ) : * < > -`), quotes, backslash, whitespace, control characters (incl. NUL), punctuation, symbols. The first `MAX_TERMS = 8` terms are kept. PHP neither lower-cases nor strips accents. Never throws. | **Domain:** `SearchTerms::parse()`. | AC-1. |
| **S-16** | **One mangling, one query builder.** The document is `to_tsvector('simple', search_fold(title ‖ ' ' ‖ description))`; the query is `search_tsquery(terms)` = `plainto_tsquery('simple', search_fold(terms))` with `:*` appended to its **last lexeme only**; the similar match compares `search_fold(terms)` with the stored `search_fold(title)`. `Infrastructure/Search` calls **only** `search_tsquery()` and `search_fold()` — never `to_tsquery`, `plainto_tsquery`, `websearch_to_tsquery` or `unaccent` directly. | **Database:** the migration's two functions + trigger. Scan: `SearchIsolationTest`. | AC-2, AC-9, AC-10, AC-19. |
| **S-17** | **Text narrows, everywhere.** With search terms, a listing matches iff it is a text match (exact, or similar under S-18), **AND** every selection and the price filter. The text predicate lives in the one matching fragment and is **no facet's own selection**: it applies to the total, the hits and **every** count, with or without a category. | Query: one `EXISTS` in `matching()`, never omitted. | AC-11, AC-12. |
| **S-18** | **Fallback only on nothing.** The request is answered by exact matching unless its exact total (every selection applied) is **below `SearchTerms::FALLBACK_BELOW = 1`**; then **every** statement of the request — total, hits, counts — uses the similar match instead. Exact and similar hits are never mixed on one page; the page says which it shows. | **Domain:** `SearchTerms::fallsBackAt(int)`. Adapter: one extra total statement. | AC-3, AC-13, AC-14. |
| **S-19** | **The text index is derived and always current.** Every `listing_listing` row, of every status, has exactly one `search_listing_text` row equal to the S-16 function of its **current** title and description — written by the trigger in the writer's transaction (`AFTER INSERT OR UPDATE OF title, description`), deleted with the listing (`ON DELETE CASCADE`). No PHP writes it. | **Database:** trigger + FK. Scans: `SearchIsolationTest` (no write verb, table named only in `Infrastructure/Search`). | AC-6, AC-7, AC-8. |
| **S-8 (unchanged)** | Hits stay `published_at DESC, id DESC` **with** search terms. No rank is computed. | Query. | AC-15. |

**What these keep true together:** a word a seller wrote is found by the same word typed with or
without its accents, in any case, by any prefix of the last word typed; nothing a visitor types can
change the structure of the query; every number on the page counts the same listings the hits list.
**What is deliberately not kept true:** that more relevant listings come first (S-8), that similar
matches appear beside exact ones (S-18), or that a plural finds a singular in a non-last term
(`simple` does not stem — plan D4).

## User story

As a **guest buyer**, I want to **type what I am looking for — "jazz bass", even misspelt "jaz bas"
or "Strätocaster" — and narrow it with the facets I already use**, so that **I reach the listing I
have in mind without knowing which category it was filed under, and without signing up**.

## In scope

- `Search` Domain: `SearchTerms` (S-15, S-18 policy); `TextMatch` enum; `FilterRequest::parse()`
  gains the search text (4th parameter); `FacetedQuery` carries `text(): ?SearchTerms`, independent
  of the schema (like price); `SearchResult` carries `textMatch(): ?TextMatch`.
- `Search` Application: `BrowseListings` gains `?string $text` (required field, `null` for none).
- `Search` Infrastructure: one migration (plan D2/D5: extensions, `search_fold()`,
  `search_tsquery()`, `search_listing_text`, its two GIN indexes, the trigger, the backfill);
  `schema_filter` hides the table; `PostgresSearch` adds the text predicate to the one fragment and
  the fallback sequence; `BrowseController` reads `q` (shape only) and carries the surviving terms on
  every link; `browse.html.twig` gains one `<input type="search" name="q">` and the similar-match
  notice. The form now always renders the text box (it applies without a category, like price).
- ADR-0025 (new); amendments to ADR-0023 and ADR-0002 (plan §ADRs). Runbook entries in
  `docs/infrastructure.md` (pre-deploy check, post-deploy check, rebuild).

## Non-goals (explicit — hold the line)

- **Relevance ordering**, `ts_rank`, title/description weights, a sort control, **RRF** — S-8 holds;
  RRF's trigger is recorded in ADR-0025 (a sort by relevance, or a fallback threshold above 1).
- **Stemming / per-language configurations** (`english`, `swedish`), synonyms, a dictionary of brand
  aliases ("strat" ⇄ "Stratocaster"), phrase search, `OR`/`-exclude` syntax for visitors.
- **Search over attribute values or option labels** ("Fender" as text matching the Brand option) —
  facets own structure; a listing whose title omits its brand is found by the facet, not the box.
- **Trigram over the description** — title only (plan D6).
- **Latency, load, `ANALYZE`d planner choice, threshold tuning, `GUC` changes** — `search-benchmark`
  (4). **Rate limiting `/browse`** — slice 4's carry-over, unchanged.
- **Autocomplete / typeahead**, highlighting, "did you mean", styling, JS → `search-ui` (7) /
  `public-styles` (5).
- **Search text in a saved search / alert** — slices 13–14 decide whether alerts take `q`.
- Any change to Listing's PHP, mapping or form.

## Acceptance criteria (the Definition of Done checklist)

### A. The Domain rules (unit, no kernel)

- [ ] **AC-1 (S-15, the sanitizer)** — `SearchTerms::parse(string): ?self`:
      - `"Fender  Strat"` → terms `["Fender", "Strat"]`, `toString()` `"Fender Strat"` (case and
        accents kept — `"Strätocaster"` stays `"Strätocaster"`);
      - each of `& | ! ( ) : * < > - ' " \ ,` `.` `/` `@` `#` `%` `+` `=` `~` `^` `` ` `` `{` `}` `[` `]`
        `;` `?`, tab, CR, LF, NUL, U+00A0 and U+2028 is a separator: `"a&b|!c(d):*<->'e'\\f"` →
        `["a","b","c","d","e","f"]`; `"fender:*"` → `["fender"]` (the `:*` is **not** kept);
      - letters of any script and combining marks are kept: `"гитара"`, `"ギター"`, `"e\u{0301}"`;
        a run of marks alone (`"\u{0301}"`) is no term; digits are terms (`"1962"`, `"5"`);
      - `""`, `"   "`, `"&|!"`, `"'''"`, `":*"` → `null`; invalid UTF-8 (`"\xC3\x28"`) → `null`;
      - a 10 000-character input reads only its first 100 characters (a term crossing character 100
        is cut there); a 12-term input keeps the first 8;
      - nothing throws, for any input in a fuzz loop of 1 000 random byte strings, and every surviving
        term matches `^[\p{L}\p{M}\p{N}]+$`.
- [ ] **AC-2 (S-16's PHP half)** — `toString()` holds only `[\p{L}\p{M}\p{N} ]`, single-spaced, no
      leading/trailing space: the bound string can contain no tsquery operator, quote, backslash or
      control character, whatever was typed.
- [ ] **AC-3 (S-18's policy)** — `fallsBackAt(0)` is true; `fallsBackAt(1)` and `fallsBackAt(500)`
      are false. `FALLBACK_BELOW` is `1`.
- [ ] **AC-4 (carried through)** — `FilterRequest::parse(…, $text)` exposes `text(): ?SearchTerms`;
      `FacetedQuery::resolve()` carries it **independently of the schema** (survives with no category
      and with `FacetSchema::none()`); all existing `parse()` call sites pass `null` and stay green.
- [ ] **AC-5 (isolation)** — `SearchIsolationTest` green: Search's Domain/Application import no other
      context.

### B. The database objects (integration, DAMA)

- [ ] **AC-6 (shape, S-19)** — An `information_schema`/`pg_catalog` test asserts: extensions
      `unaccent` and `pg_trgm` installed; `search_fold(text)` and `search_tsquery(text)` exist and
      are `IMMUTABLE`; `search_listing_text` has exactly `listing_id uuid` (PK, FK →
      `listing_listing(id)` `ON DELETE CASCADE`), `document tsvector NOT NULL`, `title_folded text
      NOT NULL`; GIN indexes `idx_search_listing_text_document (document)` and
      `idx_search_listing_text_title (title_folded gin_trgm_ops)`; a row-level `AFTER INSERT OR
      UPDATE OF title, description` trigger on `listing_listing`. (The table is invisible to every
      Doctrine safety net, like `listing_attribute_value`; this test is its only shape guard.)
- [ ] **AC-7 (S-19, kept current by every writer)** — A draft created through `DraftListingHandler`
      **and** one created by a bare Foundry `create()` each have one `search_listing_text` row;
      `ReviseListing` changing only the description updates `document` (the new word is found, the
      old one is not); changing only the title updates `title_folded`; publishing (`ReviseListing` with `$publish`) and
      `WithdrawListing` leave the row byte-identical; the number of rows equals the number of
      listings after the fixture is built.
- [ ] **AC-8 (no re-diff, proved by flipping)** — After `make migrate`, `doctrine:schema:update
      --dump-sql` (dev and test) reports nothing and `doctrine:schema:validate` is green. Flipped:
      with `search_listing_text` removed from `schema_filter`, the dump proposes `DROP TABLE
      search_listing_text` (recorded in the commit message, then reverted). *Measured while planning:
      an unmapped column on `listing_listing` dumps `ALTER TABLE listing_listing DROP …`, an
      unfiltered extra table `DROP TABLE …`.*
- [ ] **AC-9 (S-16, one mangling — round trip)** — For **every** published listing in the text
      fixture, searching its own full title (as typed by the seller, through the handler) finds it,
      exact. Titles include accents, `ß`, Cyrillic, a hyphenated model (`AC-DC`), an apostrophe, a
      number, and the plural `Guitars` searched as `guitars fender`.
- [ ] **AC-10 (S-16, the parser builds the query — the injection corpus)** — Through the handler, each
      of these search texts answers without an exception and with the total named:
      `"ʼfoo"`, `"fooː1"`, `"x ː y"`, `"ŉ x¼"`, `"a & b | !c <-> (d):* 'e' \\f"`, `"'"`,
      `"\\"`, `"''''"`, `":*"`, `"fender:*A"`, `"!fender"` (→ same total as `"fender"`, **not** its
      negation), `"fender | gibson"` (→ same total as `"fender gibson"`, i.e. AND, not OR), and a
      10 000-character word. *Measured while planning: `to_tsquery('simple', unaccent('ʼfoo'))` raises
      `syntax error in tsquery: "'foo"`.*

### C. Integration (DAMA, through `BrowseListingsHandler`)

Own fixture (`BuildsTheTextFixture`, on `BuildsTheValuesTree` + the facet fixture's Brand). Published
on **Electric**: **T1** "Fender Stratocaster 1962 reissue" (Brand Fender, EUR), **T2** "Strätocaster
Ŝpecial — Gitarr" (Gibson, EUR), **T4** "Gibson Les Paul", description "Not a stratocaster" (Gibson,
USD, **published last**), **T5** "AC-DC Fender Guitars & Guitar's case, Straße" (Gibson, EUR), **Tc**
"Гитара Фендер" (Gibson, EUR). Published on **Bass**: **T3** "Fender Jazz Bass V", description "five
strings, left-handed" (SEK). Not public: **Td** draft "Fender Stratocaster prototype", **Tw** withdrawn
"Stratocaster withdrawn" (both Electric). Every listing holds its required values.

- [ ] **AC-11 (S-17 with S-1/S-2/S-6)** — With no category, `q=stratocaster` totals 3 (T1, T2, T4 —
      T4 through its description): Td and Tw are never found. At Bass, `q=stratocaster` finds nothing
      (exact 0, similar 0 — T1/T2 are outside the subtree). `q=fender` + Brand = Fender at Electric
      totals 1 (T1); `q=stratocaster` + `price[currency]=USD`
      totals 1 (T4).
- [ ] **AC-12 (S-17 — counts apply the text)** — At Electric with `q=stratocaster`, Brand's buckets are
      Fender 1 (T1), Gibson 2 (T2, T4); with Brand = Gibson also selected, Brand's own buckets are
      unchanged (disjunctive) and the total is 2.
- [ ] **AC-13 (prefix on the last term only)** — `q=fender strat` finds T1; `q=strat fender` finds
      **nothing exact** (`strat` is not a prefix there) — so it falls back (S-18); `q=jaz` finds T3
      exact; `q=STRATOCASTER` and `q=stratocaster` give the same total; `q=Stratocäster` finds what
      `q=stratocaster` finds (fold on the read side), and T2 (`Strätocaster`) is found by
      `q=stratocaster` (fold on the write side).
- [ ] **AC-14 (S-18, fallback only on nothing)** — `q=stratocster` (typo) has exact total 0, so it is
      answered `Similar` and finds T1, T2 (title) and **not** T4 (description only — trigram is
      title only) nor Td/Tw; facets and total use the similar match. `q=gibsn` finds T4 `Similar`.
      `q=fender` (exact total > 0) is answered `Exact` and never includes a similar-only listing.
      `q=zzzzqx` is `Similar` with total 0 (an empty page, 200). `SearchResult::textMatch()` is
      `null` without `q`.
- [ ] **AC-15 (S-8 with text)** — With `q=stratocaster` the hits are ordered `published_at DESC, id
      DESC`, exactly as without `q` — the fixture publishes T4 last so a rank order would differ.

### D. The index (usability, not latency — slice 4)

- [ ] **AC-16 (EXPLAIN on the real statements)** — `SearchIndexUsageTest` gains two recorded browses
      (`ANALYZE` of `search_listing_text` added inside DAMA): `q=stratocaster` — the hits statement has
      a node on `idx_search_listing_text_document` whose `Index Cond` contains `@@`; `q=stratocster`
      — the similar hits statement has a node on `idx_search_listing_text_title` whose `Index Cond`
      contains `%>`. Neither has a `Seq Scan` on `search_listing_text`. *(Planning note: on tiny tables
      the planner prefers a full pkey scan with a `Filter` even with seq scans off; if so the trigram
      replay adds `enable_indexscan = off` so only bitmap paths remain — usability, not choice.)* *(Amended
      at `/verify`: **both** replays set it — AC-24's from-scratch `muzbar_test` planned a full pkey
      scan for the `@@` leg too.)*

### E. The page (functional, `KernelBrowser`)

- [ ] **AC-17 (renders without JS)** — `/browse` with and without a category renders `<input
      type="search" name="q" maxlength="100">` inside the GET form, its `value` the **surviving terms**
      (`?q=fender%20%26%20strat:*` shows `fender strat`). A `Similar` page shows a notice containing
      the terms and the words "similar titles"; an `Exact` page shows none.
- [ ] **AC-18 (hostile `q` is a 200)** — Each of `?q[]=x`, `?q[a]=x`, `?q=%00`, `?q=%C3%28`,
      `?q=%CA%BCfoo` (`ʼfoo`), `?q=` (blank = absent), a 100 000-byte `q`, and every AC-10 string
      answers **200**; `?q=&f[…]…` behaves as without `q`.
- [ ] **AC-19 (bounded statements, and only the two functions)** — With `k` surviving selections:
      `Exact` sends at most `5 + k` statements (no new statement); `Similar` at most `6 + k` (one
      extra total); no category: ≤ 3 / ≤ 4. No statement is a write; none names `seller_id`; every
      statement naming `search_listing_text` calls `search_tsquery(` or `search_fold(` and none calls
      `to_tsquery(`, `plainto_tsquery(`, `websearch_to_tsquery(` or `unaccent(` (recorded SQL).
- [ ] **AC-20 (links keep the text)** — *Previous/Next*, breadcrumb, child-category links and the form
      carry `q` (the surviving terms); *Clear filters* clears it; a category link from a `Similar`
      page re-decides the match for the new category (no carried mode).

### F. Proof, deploy and docs

- [ ] **AC-21 (tests bite)** — Each mutation fails a named test (recorded in the commit message):
  - (a) the sanitizer keeps `:` / `*` / `'` / `&` / `\` → AC-1, AC-2
  - (b) the adapter calls `to_tsquery('simple', search_fold(:q) || ':*')` (sanitize → fold → grammar) → AC-10, AC-19
  - (c) `search_tsquery()` puts `:*` on every lexeme → AC-13 (`strat fender` would match T1)
  - (d) `search_tsquery()` puts `:*` on none → AC-13 (`jaz`)
  - (e) the read side not folded (`plainto_tsquery('simple', :q)`) → AC-13 (`Stratocäster`)
  - (f) the write side not folded → AC-9, AC-13 (T2)
  - (g) `english` on one side only → AC-9 (`guitars fender`)
  - (h) the text predicate omitted from the count statements → AC-12
  - (i) the text predicate treated as the facet's own selection (`$omit`) → AC-12
  - (j) fallback threshold `< 3` / union of exact and similar → AC-14 (`fender` gains similar-only rows)
  - (k) fallback never taken → AC-14
  - (l) the similar mode used for the hits only, not the counts → AC-14
  - (m) trigger `UPDATE OF title` only (description missed) → AC-7
  - (n) trigger missing `INSERT` → AC-7 (Foundry draft)
  - (o) `$request->query->get('q')` / `->getString('q')` in the controller → AC-18 (`?q[]=x` → 400)
  - (p) hits ordered by `ts_rank` → AC-15
  - (q) `q` not carried on links → AC-20
  - (r) the sanitizer lower-cases or strips accents in PHP → AC-1
  - (s) `MAX_TERMS` / `MAX_LENGTH` not applied → AC-1
  - (t) the trigram predicate on `document`/description text → AC-14 (T4 by `stratocster`)
  - (u) `search_listing_text` dropped from `schema_filter` → AC-8 (manual flip)
- [ ] **AC-22 (deploy checks)** — `docs/infrastructure.md` §*Search: full-text — pre/post-deploy*:
      **before** — on the box, `SELECT name FROM pg_available_extensions WHERE name IN
      ('unaccent','pg_trgm')` returns both and `SELECT rolsuper FROM pg_roles WHERE rolname =
      current_user` returns `t` (or, if not, the role has `CREATE` on the database — both extensions
      are *trusted*); **after** — `SELECT count(*) FROM listing_listing l WHERE NOT EXISTS (SELECT 1
      FROM search_listing_text t WHERE t.listing_id = l.id)` returns `0`. Both results logged there.
      Plus the **rebuild** statement (`UPDATE listing_listing SET title = title`) and when to run it
      (after a PostgreSQL major upgrade or any change to `search_fold()`/the configuration).
- [ ] **AC-23 (docs)** — ADR-0025 accepted; dated amendments on ADR-0023 (fence 5 replaced; the scan
      for query functions; the trigger as a watch item) and ADR-0002 (*too few* = none; RRF's
      trigger; `simple`); CLAUDE.md status + Search footguns; roadmap item 3 ticked; FORboehpyk.md
      chapter.
- [ ] **AC-24 (gates)** — `make check` green; `php -d zend.assertions=-1 vendor/bin/phpunit` green;
      `make test` twice in a row green; `make test.db` from a dropped `muzbar_test` green (the
      migration installs the extensions there too).

## Failure contract

| Condition | Expected behaviour |
|---|---|
| `q` not a string, an array, blank, only operators/punctuation, invalid UTF-8 | No text filter (S-15); 200 (AC-18). |
| `q` longer than 100 characters / more than 8 terms | Read up to the cap; the rest ignored, silently. The box shows what survived. |
| A letter that folds to tsquery syntax (`ʼ`, `ː`, `ŉ`, `¼`) | Folded inside the parser; never parsed as an operator; 200 (AC-10). |
| A term that folds to nothing | No lexeme; **beside another term** it constrains nothing; never an error. *(Amended at `/verify`, 2026-11-23 — the test won, on purpose:)* a query whose **every** term folds to nothing (a lone `ʼ`) is an empty tsquery: it matches nothing in either mode — `Similar`, 0 results, 200. Treating it as "no filter" would need a SQL branch to keep a box showing a term that filters nothing; not worth it. |
| Exact match finds nothing | The request is answered by similar matching (S-18) and the page says so. |
| Similar match finds nothing | Empty page, 200, the existing 0-result state. |
| A listing write (any path, any status) | The trigger rewrites its row in the same transaction; a failing trigger fails the write (loud). |
| A `listing_listing.title`/`description` rename or type change | **Breaks every listing write at runtime** (the trigger body names the columns). Caught by AC-6/AC-7 in CI; watch item in CLAUDE.md. |
| PostgreSQL major upgrade changes `unaccent.rules` | Stored documents use the old fold until rebuilt (runbook, AC-22). |
| `unaccent`/`pg_trgm` not installable on the box | The migration fails and the deploy stops before switching (deploy.yml migrates first). Prevented by AC-22's pre-deploy check. |
| Admin narrows/archives between the schema read and the count read | One stale page view (ADR-0023 d.5). No error. |
| PostgreSQL down | 500 → Sentry, as before. |
| Latency | Not measured here — slice 4. |

## Owner decisions — answered 2026-11-23: every recommendation accepted

Each has a recommendation; plan §Decisions has the reasoning.

1. **Where the search document lives** (ADR-0025, plan D2). **Recommended: A** — a Search-owned side
   table `search_listing_text`, hidden by `schema_filter`, written **only by a trigger** on
   `listing_listing`. B: the same table written by `DoctrineListingRepository::save()` (the
   `listing_attribute_value` pattern — Listing learns Search's mangling; every write path must go
   through `save()`, the footgun that already bit). C: a generated column / expression index on
   `listing_listing` — **ruled out** (re-diffed forever, re-proved while planning; `unaccent` is not
   even `IMMUTABLE`).
2. **"Too few rows" for the trigram fallback** (plan D7). **Recommended: zero** (`FALLBACK_BELOW =
   1`) — exact and similar never mix, so newest-first stays honest and RRF is never needed.
   Alternative: e.g. fewer than 3, as a **union** — then a similar-only listing can sit above an
   exact one on a newest-first page, which forces relevance ordering, which forces RRF.
3. **Ordering with text** (plan D8). **Recommended: newest first, unchanged** (text is a filter;
   PRD: facets first). Alternative: by relevance (`ts_rank`) — needs weights in the document
   (migration + rebuild), a sort control (`search-ui`), and an unindexable `ORDER BY` per page.
4. **Text search configuration** (plan D4). **Recommended: `simple`** — no stemming, no stopwords;
   predictable across English/Swedish/German sellers and brand/model names (`english` stems
   `Marshall` → `marshal` and drops `The`, `A`, `It`). Cost: `guitar fender` does not find "Fender
   guitars" exact (the last term's prefix does: `fender guitar` does).
5. **Trigram scope** (plan D6). **Recommended: title only** — typos that matter are in brand/model
   names; a GIN trigram index over 5 000-character descriptions is the expensive half. Alternative:
   title + description.
6. **Caps** — **Recommended:** 100 characters, 8 terms (a title is ≤ 120 characters; the caps bound
   the query, not the visitor).
7. **The similar-match notice** — **Recommended wording:** *"No exact matches for “{terms}”. Showing
   listings with similar titles."*
