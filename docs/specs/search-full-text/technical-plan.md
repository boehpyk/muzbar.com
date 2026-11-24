# Technical Plan: search-full-text

> The *how*. Disposable. Written after the feature-spec is drafted, approved with it before any code.
> Follows DDD canonical order. **Owner answers:** pending (feature-spec §Owner decisions Q1–Q7).

## Pre-planning checks (roadmap item 3 demands them) — run 2026-11-23, dev stack

| Check | Result |
|---|---|
| `postgres:16-alpine` contrib (`SELECT … FROM pg_available_extensions`) | **PostgreSQL 16.13**; `pg_trgm` 1.6 and `unaccent` 1.1 **available**, not installed. Same image in prod (`docker-compose.yml`) and CI (`ci.yml` service). |
| Trusted? (`pg_available_extension_versions.trusted`) | **Both `t`.** Installable by a non-superuser with `CREATE` on the database. **Proved** inside `BEGIN … ROLLBACK`: a `NOSUPERUSER` role granted `CREATE` on `muzbar_test` and on schema `public` created both. |
| Migration role | `muzbar` — **`rolsuper = t`**, owner of `muzbar` and `muzbar_test`. It is the image's `POSTGRES_USER`, and the box's `DATABASE_URL` uses `DB_USERNAME` = the same `POSTGRES_USER` (`docker-compose.yml`, `.env.example`), so the box role is the initdb superuser too. **Not observed on the box** — AC-22's pre-deploy query confirms it; if it ever is not a superuser, trust + `CREATE` suffices. |
| `unaccent` volatility | `unaccent(text)` and `unaccent(regdictionary, text)` are **`STABLE`** → cannot appear in a generated column or index expression directly. An `IMMUTABLE` wrapper with a **fixed, schema-qualified dictionary** (`public.unaccent('public.unaccent'::regdictionary, $1)`) works. |
| **`unaccent` maps letters to tsquery syntax** | `unaccent.rules` (1 650 lines) maps, among others, `ʼ`→`'`, `ʹ ʻ ʽ ˈ`→`'`, `ʺ`→`"`, `ː`→`:`, `ˆ`→`^`, `ŉ`→`'n`, `¼`→` 1/4`. Several sources are `\p{L}` (modifier letters, `\p{Lm}`). **Measured:** `to_tsquery('simple', unaccent('ʼfoo'))` → `ERROR: syntax error in tsquery: "'foo"`; same for `fooː1`, `x ː y`, `a ʼb c`; `to_tsquery('simple', unaccent('donʼt:*'))` silently becomes `'don':* <-> 't':*` (a letter turned into a phrase operator). → D3. |
| Parser-built query survives all of them | `plainto_tsquery('simple', search_fold(q))` + `:*` appended to its text form: `fooː barʼ` → `'foo' & 'bar':*`; `a & b \| !c <-> (d):* 'e' \f` → `'a' & 'b' & 'c' & 'd' & 'e' & 'f':*`; `x'y\\z` → `'x' & 'y' & 'z':*`; `AC-DC` → `'ac-dc' & 'ac' & 'dc':*`; `''` and a 3 000-char word → empty query (`numnode` 0), no error. |
| Doctrine re-diff (in `muzbar_test`, reverted) | Baseline `doctrine:schema:update --dump-sql`: *Nothing to update*. Unmapped generated `tsvector` column on `listing_listing` → `ALTER TABLE listing_listing DROP search_doc`. Unfiltered extra table → `DROP TABLE search_probe`. Both reverted; baseline clean again. → D2. |
| Index usability (prototype, rolled back) | FTS: `EXISTS (… t.document @@ search_tsquery('fender strat'))` → `Bitmap Index Scan on idx_search_listing_text_document`, `Index Cond: (document @@ '''fender'' & ''strat'':*'::tsquery)` — the `IMMUTABLE` function is folded to a constant. Trigram: `'q' <% title_folded` → `Index Cond: (title_folded %> 'q')` on the GIN trgm index **only with `enable_seqscan = off`**; joined to `listing_listing` on empty tables the planner chose a full pkey scan + `Filter` even with seq scans, nested loops and merge joins off (AC-16's note). |
| `word_similarity` at the default threshold (0.6) | `stratocster`/"Fender Stratocaster 1962" 0.67 ✓; `gibsn`/"Gibson Les Paul" 0.67 ✓; `jagur`/"Fender Jaguar" 0.67 ✓; `fender jaguar`/"fender stratocaster sunburst" **0.5 ✗** (the fallback does not flood with every Fender). |
| Collation | `muzbar`, `muzbar_test`: `en_US.utf8`, libc provider. Irrelevant to the design (nothing uses `[[:alnum:]]` or `lower()` in SQL). |

## Decisions

### D1 — Still no aggregate, no event, no new port method

Search writes nothing from PHP (ADR-0023 d.3). The new policies — the sanitizer (S-15) and *when to
fall back* (S-18) — are pure Domain functions. `SearchPort`'s two signatures are **unchanged**:
`search()` still accepts only a resolved `FacetedQuery`, which may now carry `SearchTerms`. The
fallback sequence (exact total → maybe similar) is the adapter's, because it needs a statement; the
*rule* it applies is `SearchTerms::fallsBackAt()`.

### D2 — Where the search document lives → **ADR-0025** (new, `/adr`, task T0) — owner Q1

The vector is derived from two `listing_listing` columns. Three homes:

| | **A. Search-owned side table, trigger-maintained** *(recommended)* | B. Side table written by Listing's repository | C. Generated column / expression index on `listing_listing` |
|---|---|---|---|
| Doctrine | table hidden by `schema_filter` (the `listing_attribute_value` escape hatch); trigger and functions are not assets Doctrine reads → **no diff, ever** | same table, same hatch | **re-diffed forever** (measured: unmapped column → `DROP`; expression index → CLAUDE.md footgun). Also impossible as written: `unaccent` is `STABLE`. |
| Writers | **one**: the trigger, in the writer's transaction — `DoctrineListingRepository::save()`, Foundry `create()`, an EM flush, a future import, a hand `UPDATE` | every write path must call `save()` — the footgun that already bit values ("a Foundry `create()` persists none, silently") | the database |
| Who knows the mangling | the migration only (both functions + trigger in one file) | Listing's Infrastructure learns Search's fold and configuration | — |
| Listing's code | unchanged | `save()` gains a statement for a Search need | — |
| Cost | logic in the database: invisible to PHP readers, not unit-testable → a **shape test** (AC-6) and a **behaviour test** (AC-7) instead | the drift ADR-0002 rejected Meilisearch to avoid, in miniature | — |

**Recommended: A.** A trigger is what a generated column would be if a generated column could read
across a table boundary and if Doctrine could see it: the database keeps a derived value in the same
transaction as its inputs. It keeps Listing ignorant of Search, gives ADR-0002's "write and read mangle
identically" a single file, and makes "silently empty" impossible — there is no second writer to
forget. ADR-0025 records it and **replaces ADR-0023 fence 5** ("no Search table, no Search
migration") with: *Search may own derived tables written only by the database, in the writer's
transaction; Search's PHP still writes nothing* (fence 1 unchanged; `SearchIsolationTest` unchanged).

Rejected alongside: a Search table maintained by **listeners** (ADR-0023 option C — Listing emits no
revise event, and an async sync path is exactly the drift); **no index at all** (`to_tsvector` over
every published row per request — `search-benchmark` would fail on arrival).

### D3 — The sanitizer has two halves; the parser, never a string, builds the tsquery

The Constitution's "strips every tsquery operator" and ADR-0002's "`:*` on the last token only" are
kept, but split by what each side can guarantee:

- **PHP — `SearchTerms::parse()` (S-15, the security-critical class).** Format only, never throws:
  valid UTF-8 or nothing; first 100 characters; split on `/[^\p{L}\p{M}\p{N}]+/u`; drop runs holding
  no `\p{L}`/`\p{N}`; first 8 terms; nothing → `null`. The bound string therefore holds only letters,
  marks, digits and single spaces — no operator, quote, backslash, NUL or invalid byte reaches SQL
  (the NUL-truncation and SQLSTATE 22021 footguns cannot occur). PHP does **not** fold, lower-case or
  append `:*`: that would be a second definition of the mangling.
- **SQL — `search_tsquery(text)` (S-16).** `plainto_tsquery('simple', search_fold($1))`: the *text
  parser* tokenizes, so nothing in the input is ever read as an operator — including what the fold
  produces from `ʼ`/`ː`; PostgreSQL quotes every lexeme itself. Then `(t::text || ':*')::tsquery`
  re-reads PostgreSQL's **own** canonical output with `:*` after its last lexeme (`'a' & 'b'` →
  `'a' & 'b':*`); `numnode(t) = 0` returns the empty query unchanged. `plainto_tsquery` only ever
  emits an `&` chain, so the appended `:*` binds to the last lexeme and nothing else.

**Rejected:** PHP builds `a & b & c:*` and SQL runs `to_tsquery('simple', search_fold(:q))` — the
roadmap's literal reading — **measured injectable** (pre-planning table): the fold turns letters into
syntax *after* the sanitizer ran. Folding in PHP instead would be a second mangling. `websearch_to_tsquery`
honours `"…"`, `or` and `-` (a visitor-facing syntax — a non-goal) and still cannot add `:*`.

`SearchIsolationTest` gains a scan: `Infrastructure/Search` code never contains `to_tsquery(`,
`plainto_tsquery(`, `websearch_to_tsquery(`, `phraseto_tsquery(` or `unaccent(` — only the
migration does. That makes mutation (b) loud at the source.

### D4 — Configuration `simple` — owner Q4

Sellers write in English, Swedish, German, Russian…; titles are mostly brand + model + year. `english`
stems names (`Marshall` → `marshal`, harmless only because both sides do it) and **drops stopwords**
(`The Who`, `A`-series), and stems Swedish into nonsense. `simple`: lower-case, no stemming, no
stopwords — predictable, language-neutral; the prefix on the last term absorbs most plurals at the end
of a query, and S-18 catches the rest when nothing matched. The configuration is a literal `'simple'`
in exactly two places — the trigger function and `search_tsquery()` — in **one migration**; a shape
test (AC-6) and the round-trip test (AC-9) pin it. The fold folds `å ä ö` to `a a o` on both sides:
recall over precision, consistent.

### D5 — The database objects (one migration)

```sql
CREATE EXTENSION IF NOT EXISTS unaccent;
CREATE EXTENSION IF NOT EXISTS pg_trgm;

CREATE FUNCTION search_fold(text) RETURNS text
  LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT
  AS $$ SELECT public.unaccent('public.unaccent'::regdictionary, $1) $$;

CREATE FUNCTION search_tsquery(text) RETURNS tsquery
  LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT
  AS $$ SELECT CASE WHEN numnode(t) = 0 THEN t ELSE (t::text || ':*')::tsquery END
        FROM plainto_tsquery('simple', public.search_fold($1)) AS t $$;

CREATE TABLE search_listing_text (
  listing_id   uuid PRIMARY KEY REFERENCES listing_listing (id) ON DELETE CASCADE,
  document     tsvector NOT NULL,
  title_folded text NOT NULL
);
CREATE INDEX idx_search_listing_text_document ON search_listing_text USING gin (document);
CREATE INDEX idx_search_listing_text_title ON search_listing_text USING gin (title_folded gin_trgm_ops);

CREATE FUNCTION search_listing_text_sync() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  INSERT INTO search_listing_text (listing_id, document, title_folded)
  VALUES (NEW.id,
          to_tsvector('simple', public.search_fold(NEW.title || ' ' || NEW.description)),
          public.search_fold(NEW.title))
  ON CONFLICT (listing_id) DO UPDATE
    SET document = EXCLUDED.document, title_folded = EXCLUDED.title_folded;
  RETURN NULL;
END $$;

CREATE TRIGGER trg_search_listing_text
  AFTER INSERT OR UPDATE OF title, description ON listing_listing
  FOR EACH ROW EXECUTE FUNCTION search_listing_text_sync();

UPDATE listing_listing SET title = title;   -- the backfill IS the write path
```

- **Schema-qualified calls** (`public.unaccent`, `public.search_fold`) so the functions behave the
  same under `pg_dump`/`pg_restore`'s empty `search_path`. No `SET search_path` clause on the SQL
  functions: it would stop the planner inlining/constant-folding them (the `Index Cond` above).
- **The backfill fires the trigger** rather than repeating its expression: it cannot disagree with
  the write path, and the same statement is the runbook's **rebuild** (AC-22). Inside the migration's
  transaction `CREATE TRIGGER` holds `SHARE ROW EXCLUSIVE` on `listing_listing` until commit, so no
  concurrent write slips between trigger and backfill; deploy.yml already stops the worker for the
  migration window. Pre-launch row counts are tiny.
- **`UPDATE OF title, description`:** a publish/withdraw (`status`, `published_at` only) does not
  fire it. A revise fires it every time (`revise()` swaps VO instances, so Doctrine always lists
  `title`/`description` in the `SET`) — harmless, it recomputes the same row.
- **FK with `ON DELETE CASCADE`** — not an inter-aggregate reference (ADR-0009 is about aggregates;
  this row is a derivation of one), and invisible to Doctrine because its referencing table is
  filtered. No listing is ever deleted today; when GDPR erasure arrives, the text goes with the row.
- **No weights** (`setweight`): nothing ranks (D8). Adding them later = redefine the trigger
  function + run the rebuild.
- `down()`: drop trigger, functions, table, extensions — in reverse.
- `doctrine.yaml`: `schema_filter: '~^(?!(prelaunch_signup|listing_attribute_value|search_listing_text)$)~'`
  with a comment in the file's existing style.
- **Naming:** `search_listing_text` — `<context>_<what>`; Search has no aggregate, so ADR-0007's
  `<context>_<aggregate>` is read as "`<context>_` prefix names the owner". Its `\b` boundary keeps
  `ListingIsolationTest`'s `\blisting_\w+` from matching it.

### D6 — Trigram over the title only — owner Q5

`title_folded` (≤ 120 characters) carries the GIN trigram index. Typos worth forgiving are in names
(`stratocster`, `gibsn`); a trigram index over 5 000-character descriptions is the large, slow half and
would make long descriptions near-match everything. The fallback predicate is
`t.title_folded %> search_fold(:q)` (`word_similarity(q, title) ≥ pg_trgm.word_similarity_threshold`,
default **0.6**). **No `SET` of that GUC** — sessions are per-request PHP-FPM connections and a `SET`
leaks into whatever runs next; tuning, if ever, is `ALTER DATABASE … SET`, `search-benchmark`'s call.

### D7 — Fallback only on nothing; never mixed — owner Q2

`SearchTerms::FALLBACK_BELOW = 1`, `fallsBackAt(int $exactTotal): bool` → `$exactTotal <
self::FALLBACK_BELOW`. The adapter:

```
total ← count(Exact)
if text ≠ null and text.fallsBackAt(total): match ← Similar; total ← count(Similar)
hits, counts ← with match
```

Mode is decided **once per request, with every selection applied**, then every statement uses it.
Consequences, each intended: a page is all-exact or all-similar, so newest-first stays meaningful and
**no ranks combine — RRF is not built** (ADR-0002 said "if needed"; ADR-0025 records the trigger: a
threshold above 1, or relevance ordering). PRD §1's "without sifting through irrelevant keyword
results" is the reason similar matches never pad an exact page. A selected facet's disjunctive count
(its own selection omitted) is computed in the request's mode even if, without that selection, exact
would have found rows — counts and hits agree with each other, which is the invariant that matters.

### D8 — Free text is a filter; order unchanged — owner Q3

S-8 stands: `published_at DESC, id DESC`. No `ts_rank`, no `ORDER BY` change, no new statement.
Relevance order would need weights (D5), an unindexable per-page sort over every match, and a control
to choose it (`search-ui`).

### D9 — The fragment, the statements, the budget

All inside `PostgresSearch::matching($query, $omit, TextMatch $match)`:

| Match | Predicate appended once, after `status` (never skipped by `$omit`) |
|---|---|
| `Exact` | `AND EXISTS (SELECT 1 FROM search_listing_text t WHERE t.listing_id = l.id AND t.document @@ search_tsquery(:q))` |
| `Similar` | `AND EXISTS (SELECT 1 FROM search_listing_text t WHERE t.listing_id = l.id AND t.title_folded %> search_fold(:q))` |

`:q` = `SearchTerms::toString()`, bound as a string. No statement is added in `Exact` mode; `Similar`
adds the second total. Budget: **≤ 5 + k** (exact) / **≤ 6 + k** (similar) with a category; **≤ 3 / ≤
4** without. `SearchResult` carries `textMatch()`. The table name and both function names appear only
in `Infrastructure/Search` (and the migration).

### D10 — Controller and template

- `BrowseController`: `q` from the `$request->query->all()` already read once — `is_string` or
  `null` (never `get('q')`/`getString('q')`: `?q[]=x` would 400 — mutation (o)). The link carrier
  gains `q` = `$query->text()?->toString()` (what survived, never the echo).
- `browse.html.twig`: inside the always-rendered form, `<label>Search <input type="search" name="q"
  value="{{ q }}" maxlength="{{ constant('App\\Domain\\Search\\ValueObject\\SearchTerms::MAX_LENGTH') }}"></label>`
  above the facets; when `result.textMatch` is `Similar`, a `<p class="search-similar">` with the
  Q7 wording. Unstyled, no `<script>`. `maxlength` mirrors the Domain cap and is not the guard.

## Domain layer (pure PHP) — `src/Domain/Search/`

- **Aggregate / entity changes:** none (D1).
- **Value objects** (`ValueObject/`):
  - `SearchTerms` — `parse(string): ?self`, `terms(): list<string>`, `toString(): string`,
    `fallsBackAt(int): bool`; constants `MAX_LENGTH = 100`, `MAX_TERMS = 8`, `FALLBACK_BELOW = 1`.
    Docblock: S-15, *why PHP does not fold*, *why the parser builds the query* (the `ʼfoo`
    measurement).
  - `TextMatch` — enum `Exact | Similar`.
  - `FilterRequest::parse($values, $ranges, $price, ?string $text)` → `text(): ?SearchTerms`.
  - `FacetedQuery::text(): ?SearchTerms` — carried from the request, independent of the schema.
  - `SearchResult` gains `?TextMatch $textMatch` (`null` without text).
- **Domain events:** none.
- **Ports:** `SearchPort` — signatures unchanged; `search()` docblock gains S-17/S-18 and "ordered
  newest first, with or without text".

## Application layer — `src/Application/Search/`

- **Query:** `BrowseListings` gains `public ?string $text` — required (no default), `null` for none.
- **Handler:** passes it to `FilterRequest::parse()`. No other change; no transaction, no lock.

## Infrastructure layer

- **Migration** (`src/migrations/Version2026112…`): D5, verbatim intent. `isTransactional()` default.
- **`config/packages/doctrine.yaml`:** `schema_filter` + comment (D5).
- **`PostgresSearch`:** text predicate in `matching()` (D9); fallback sequence in `search()` (D7);
  `textMatch` into `SearchResult`; docblock: S-16/S-17/S-18, "never `to_tsquery` here", the GUC rule.
- **`BrowseController`, `browse.html.twig`:** D10.
- **DI / Deptrac / Doctrine mapping:** none. Listing: **none**.

## Interface boundary & input contract

- **HTTP:** `GET /browse?q=<text>&category=…&f[…]…&r[…]…&price[…]…&page=…` — all optional. **200**
  for any `q`; 404 only for a bad category (unchanged).
- **Application:** `BrowseListings(?string $category, array $filters, array $ranges, array $price,
  ?string $text, int $page)`; throws only `UnknownCategory`.
- **Domain → SQL:** one string of `[\p{L}\p{M}\p{N} ]`, bound, passed only to `search_tsquery()` /
  `search_fold()`.

## Data & migrations

- One migration (D5): 2 extensions, 3 functions, 1 table, 2 GIN indexes, 1 trigger, the backfill.
- **Pre-deploy** (AC-22, `docs/infrastructure.md`): extensions available + role rights on the box.
- **Post-deploy** (AC-22): no listing without a `search_listing_text` row.
- **Rebuild** runbook: `UPDATE listing_listing SET title = title;` after a PostgreSQL major upgrade
  (`unaccent.rules` may change) or any change to the fold/configuration.
- **Restore rehearsal:** the next one (docs/infrastructure.md) must show both extensions, the
  functions and the trigger restored; `pg_dump` emits `CREATE EXTENSION` and creates triggers after
  data, so a restore does not re-fire them.

## Test plan

- **Domain unit** — `SearchTermsTest` (AC-1…AC-3, incl. the fuzz loop), `FilterRequestTest` /
  `FacetedQueryTest` (AC-4; every existing `parse()` call gains `null`), `SearchIsolationTest` (AC-5 +
  the new query-function and table-name scans).
- **Integration** — `SearchTextSchemaTest` (AC-6), `SearchTextSyncTest` (AC-7), `tests/Support/
  BuildsTheTextFixture` + `FullTextSearchTest` (AC-9…AC-15), `SearchIndexUsageTest` (AC-16).
- **Functional** — `BrowsePageTest` gains AC-17…AC-20 (`QueryRecordingStatement` for AC-19).
- **Manual, recorded** — AC-8 (dump + flip), AC-22 (box queries), AC-24 (`make test.db` from scratch).
- **Mutation proof** — AC-21 (a)…(u), each applied, run, reverted; catcher named in the commit.

## ADRs

- **ADR-0025 (new, T0):** *Full-text search: a Search-owned text index kept by a database trigger;
  one fold for write and read; the parser builds the query.* D2 (A over B/C, owner Q1), D3 (the
  measured injection and the two-halves sanitizer), D4 (`simple`), D6 (trigram title only, no GUC
  `SET`), D7 (fallback on nothing, RRF trigger), D8 (filter, not ranker). Replaces ADR-0023 fence 5.
- **ADR-0023 amendment:** fence 5 → ADR-0025; the text predicate lives in the one fragment and is no
  facet's own selection (S-17); new scans (query functions, table name); **watch item: a rename or
  type change of `listing_listing.title`/`description` must update `search_listing_text_sync()` in
  the same migration** — the trigger body breaks at the next write, not at the rename.
- **ADR-0002 amendment:** "too few rows" = none (`FALLBACK_BELOW = 1`); RRF not built, with its
  trigger; configuration `simple`; "the sanitizer strips every operator and appends `:*`" is now kept
  by the PHP sanitizer **plus** `search_tsquery()` (D3).
- ADR-0004/0007/0019: no amendment (ADR-0007's table naming is read, not changed — D5).

## Risks / open questions

1. **The trigger is the coupling's sharpest edge.** A Doctrine-generated migration renaming
   `title`/`description` would leave the trigger function naming a column that no longer exists:
   **every listing write then fails**. AC-6/AC-7 catch it in CI (migrations run on `muzbar_test`);
   CLAUDE.md gains the watch item.
2. **Invisible to Doctrine, like `listing_attribute_value`.** `schema:validate` and `migration.make`
   see nothing of the table, functions or trigger; AC-6 is their only shape guard.
3. **Planner choice on tiny tables** (pre-planning table): the trigram leg may need
   `enable_indexscan = off` in the EXPLAIN replay to rule out a full pkey scan. That proves
   *usability*; *choice* on 10 000 rows is `search-benchmark`'s — it should also measure the
   `word_similarity` selectivity estimates, which are poor.
4. **`simple` + no stemming** costs recall on non-last plurals (`guitar fender` ≠ "Fender guitars").
   Accepted in Q4; the fallback does not fire when anything else matched.
5. **`unaccent` folds `å ä ö`** — a Swedish reader may consider `år` ≠ `ar`. Consistent on both
   sides; recall over precision.
6. **Parser edge cases**: `5.1` is one lexeme (`version` token) on the write side but two terms after
   S-15 splits on `.` — `q=5.1` is not an exact match for "Model 5.1"; falls back if nothing else
   matched. Accepted; listed so nobody "fixes" the sanitizer by keeping `.` (it would reach the
   parser only through `plainto_tsquery`, so it is safe — but it is a decision, not a bug fix).
7. **Box role** not observed directly — AC-22's pre-deploy query is the check, logged **before** the
   deploy.
