# Technical Plan: listing-draft

> The *how*. Disposable. Written after the feature-spec is drafted, approved with it before any code.
> Follows DDD canonical order.

## Decisions

**Bold** rows go into **ADR-0019** or a dated amendment (task T0).

### Open questions for the owner (defaults in brackets; the plan is written to the default)

| # | Question | Default |
|---|---|---|
| Q1 | **Which currencies?** (closed allow-list; adding one later is a code change + test, no migration) | **Owner (2026-11-16): `USD`, `EUR`, `SEK`, `UAH`.** All four have 2 minor units, but `Currency::minorUnits()` exists so a 0-decimal currency (JPY) is an explicit addition, not a silent ×100 bug. |
| Q2 | Which currency is **preselected** on `/sell`? | **`UAH`.** No per-user preference yet. |
| Q3 | **Price bounds:** zero / "free" / "negotiable" allowed? Ceiling? | **≥ 1 minor unit, ≤ 9 999 999.99.** No free, no "price on request" (each is a nullable price or a flag, additive when asked). |
| Q4 | **Is a draft allowed to be incomplete?** | **Title and price required, description optional,** even on a draft. "Required" for *attributes* is decided at publish (`listing-publish`, D11.2); the core fields are what makes a draft listable in "my drafts" at all. |
| Q5 | Title 3–120 chars, description ≤ 5000 chars? | **Yes.** |
| Q6 | A **cap or rate limit** on drafts per seller? | **None in this slice.** Only verified accounts can reach `/sell`; drafts are private. A `sell_draft` limiter is one config block when abuse appears. |
| Q7 | The **public SCSS pipeline** (ADR-0006) is not installed. Stand it up here? | **No.** Unstyled semantic markup on `base.html.twig`, like `/account` and `/login`. `sass-bundle` + `public.css` + tokens is its own small infra item (suggest adding it to the roadmap before `listing-publish`, which is the first page anyone else sees). |
| Q8 | The category picker: **leaves only, labelled by full path**, admin outline order? | **Yes** (AC-24). A plain `<select>`; a search-as-you-type picker is Phase 2's wizard. |

### Design decisions

| # | Decision | Recommendation | Alternatives considered |
|---|---|---|---|
| **D1** | **`Listing` is a new bounded context with one aggregate, `Listing`** | `Listing` holds `ListingId`, `SellerId`, `CategoryId`, `ListingTitle`, `ListingDescription`, `Price`, `ListingStatus`. One row per listing in `listing_listing` (ADR-0007 naming). Values (next slice) will be owned by this aggregate (ADR-0004 §2). | **Listing inside `Catalog`**: Constitution §4 names `Listing` as its own context; Catalog is admin-edited schema, listings are seller-edited data with a lifecycle. Merging them would put a seller's edit under the admin's tree lock *exclusively*. |
| **D2** | **Listing holds its own id types for foreign references: `SellerId`, `CategoryId` (Listing namespace)** | `ListingIsolationTest` forbids importing `App\Domain\Identity\…\UserId` or `App\Domain\Catalog\…\CategoryId`. The Listing context's language says **seller**, not user. A foreign id is a string with a format; a ~40-line copy is the price of zero Domain coupling. The adapters translate (`Catalog\CategoryId::fromString($listingCategoryId->toString())`). | **Import the other contexts' VOs** (Conformist): two Domain-level dependencies for two value classes, and the isolation test would need a "shared kernel" allow-list nobody has decided. **A `Shared/` id kernel**: ADR-0009's trait refactor, which touches Identity — its own slice (Risk 4). |
| **D3** | **The first cross-context dependency, both ways: each context states its need as a port in its *own* Domain, in its *own* words** | Listing → Catalog: `Domain/Listing/Port/Categories` — *"does this category exist and is it a leaf?"* and *"hold the catalog steady while I file this"*. Catalog → Listing: `Domain/Catalog/Port/CategoryUsage` — *"is anything filed under this category?"*. Neither Domain or Application layer imports the other (AC-7, AC-8). The two adapters are the **only** classes that know both contexts — an anti-corruption layer of exactly two files. | **Catalog calls a Listing port** (`ListingRepository::existsInCategory`) from `DeleteCategoryHandler`: an `Application/Catalog → Domain/Listing` import, and Catalog's handlers then depend on Listing's model changing. **Domain events** (Listing publishes, Catalog keeps a count): a stored projection of a fact that has to be true *at the moment of the admin's write* — eventually-consistent counts cannot guard an invariant. **A shared `CategoryRegistry` service**: a third place that knows both. |
| **D4** | **A cross-context adapter lives with the context that owns the data it reads** | `CategoriesForListing` (implements Listing's port) lives in `Infrastructure/Catalog/Integration/` and reads `catalog_category`. `ListingCategoryUsage` (implements Catalog's port) lives in `Infrastructure/Listing/Integration/` and reads `listing_listing`. **A table name appears only in its own context's Infrastructure** — asserted by AC-7's data-ownership scan, so a refactor of either schema stays inside one directory. | **Each adapter in the consuming context**, querying the other's tables: the "obvious" placement, and it scatters `catalog_category` SQL across two contexts' directories, so a Catalog schema change silently breaks Listing. **Adapters calling the other context's repository ports**: `CategoryRepository::get()` hydrates a whole aggregate into the EM to answer a yes/no, and the provider side has no `isLeaf`. |
| **D5** | **L-2 (leaf-only) has two owners and three doors; one lock in two modes makes the facts true** | **Listing's half:** `Listing::draft()`/`revise()` decide L-2 on `CategoryStatus` read through `Categories::statusOf()` inside `Categories::holdSteady()`. **Catalog's half:** C-23 (delete) and C-24 (create under, move under) refuse when `CategoryUsage::hasListings()` is true, inside `CategoryTreeLock::exclusively()`. **The lock:** `holdSteady()` takes `pg_advisory_xact_lock_shared(PostgresCategoryTreeLock::KEY)`; Catalog writes keep `pg_advisory_xact_lock(KEY)`. Shared conflicts with exclusive, not with shared: listing writes run in parallel with each other and serialise only against the (rare) admin write. **The race it closes** (write skew, ADR-0016's exact shape): a seller reads "Electric is a leaf" while an admin reads "Electric has no listings"; without the lock both commit and a listing sits on a non-leaf. With it, whichever commits second sees the other's commit — READ COMMITTED takes a fresh snapshot per statement, and every fact is read *after* the lock is granted. **Every** Listing write runs inside `holdSteady()`, including a revise that keeps its category: the rule is uniform so nobody decides per handler whether "this one is safe" (ADR-0016 d.3). | **Listing writes take the Catalog lock exclusively**: correct, but every seller's save then queues behind every other seller's — a global write mutex on the busiest table. **`SELECT … FOR SHARE` on the category row**: blocks delete and rename, but *create child* and *move under* write a **different** row (the child's), so it misses both C-24 doors — the same reason ADR-0016 rejected `FOR UPDATE` for cycles. **SERIALIZABLE**: correct, turns the race into `40001` retries in four handlers. **A stored `has_listings` flag / `listing_count` on `catalog_category`**: a second write path from Listing into Catalog's row (cross-context aggregate mutation), kept in step by hand. **Relax leaf-only to "allowed, hidden"**: the owner decided leaf-only (2026-11-16). |
| **D6** | **`Price` is one value object of two parts, mapped as a Doctrine embeddable** | `Price(int $amountInMinorUnits, Currency $currency)` — the business concept is one thing ("1234.50 EUR"), so the aggregate holds one `Price`. Mapped `<embedded name="price" class="…\ValueObject\Price" column-prefix="price_"/>` → `price_amount bigint`, `price_currency varchar(3)` (enum-type). ADR-0007 d.4 rejected embeddables **for single-value VOs** (a mapping document per one-field object); its reason does not apply to a two-field one, so this *refines* rather than reverses it (dated amendment). Embeddables need a **second mapping block** for the context (prefix `App\Domain\Listing\ValueObject`), because the Listing block's prefix is `…\Entity`. | **Two private scalar fields on `Listing`, reassembled in `price()`**: no config change, but the aggregate then models the table (two loose columns it must keep consistent) — the project's own "model the business, not the table". **One `jsonb` column**: Phase 2 must sort and range-filter on the amount; a `jsonb` path is the wrong index. **A custom DBAL type over two columns**: DBAL types are one column each. **`decimal`/`numeric` storage**: the Domain still needs the currency's exponent to validate input; an integer of minor units makes "no floats anywhere" checkable (AC-1). |
| **D7** | **Money in minor units, parsed in the Domain, never through a float** | `Price::fromDecimal(string, Currency)`: trim; `^\d{1,7}(?:[.,]\d{1,N})?$` with N = `currency->minorUnits()` (ASCII digits only; `.` or `,` separator, because Ukrainian and Swedish sellers type a comma); integer and fraction parts combined with string padding and `intdiv`-free integer arithmetic. `toDecimalString()` for prefilling the form. Range 1…999 999 999. The parser is in the **Domain**, not the form, because "how many decimals does this currency have" is business knowledge, and a float on any path is a money bug (`(int) (1.15 * 100)` is 114). | **`moneyphp/money`**: a dependency (and its `Currency`/`Money` types inside the Domain would be a vendor import Deptrac rejects). **Symfony `MoneyType`** for parsing: the rule would live in a form, and it converts through float. **Thousands separators** ("1 234,50"): locale-dependent and ambiguous ("1,234" is 1234 or 1.234?); refused, the message shows the accepted shape. |
| **D8** | **L-6 (only the seller revises) is decided by the aggregate; the HTTP answer for a non-owner is 404** | `revise(SellerId $by, …)` checks `isSoldBy($by)` **first** and throws `NotTheSeller`. The controller maps `NotTheSeller` and `ListingNotFound` to the same 404 (a 403 would confirm a private draft exists). The **read side** (edit page `GET`, "my drafts") filters by seller in SQL. The seller id always comes from the authenticated `SecurityUser::id()`, never from the request. | **A Symfony Voter**: authorization outside the Domain; the rule "only its seller may change a listing" is a business rule with an obvious future exception (admin moderation) that should be visible where the listing is. **Handler-only check**: the ADR-0012 test — put in the Domain the part that can be wrong. **403**: leaks existence. |
| **D9** | **No domain events** | `ListingDrafted` names a fact, but a draft is private and nothing can consume it: Search indexes published listings, Notification matches published listings, and `listing-publish` adds `ListingPublished`. ADR-0009's precedent (keep an event with no listener) applied to an event that *starts a flow* whose consumer was the next slice; here the next slice's consumer wants a **different** event. Catalog's slice-1 D6 rule applies: the first consumer designs its payload. | `ListingDrafted` / `ListingRevised` with `RecordsEvents` and a dispatcher call in two handlers, read by nothing. |
| **D10** | **C-23/C-24 are handler checks, not aggregate parameters** | C-24 is a fact **about the chosen parent**, exactly like C-6 ("the parent exists"), which is a handler check; `Category` would combine it with none of its own state — `create(…, bool $parentHasListings)` would be `if ($flag) throw` wearing the aggregate's clothes. C-23 joins C-7 and C-13 in `DeleteCategoryHandler` (ADR-0016 d.5: an aggregate governs its transitions, not its own non-existence). Order: delete **C-7 → C-13 → C-23**; edit **C-4 → C-5 → C-21 (aggregate) → C-24 (handler)** — structural reasons first; create **C-6 → C-24 → C-3 → C-5**. One exception, `CategoryHasListings`, with `cannotDelete()` and `cannotGainChild()`. | **Facts into `Category::create()` / `moveUnder()`** (the C-21 shape): C-21 compares the parent's lineage *with the moving subtree's own codes* — a real combination of two facts. C-24 looks at one fact about someone else. **Two exceptions**: one concept ("listed-on"), two named constructors carry the difference. |
| D11 | **Draft order = id order; no timestamps** | UUIDv7 ids are minted in time order (ADR-0017 d.5's argument), so "newest first" is `ORDER BY id DESC`. No `created_at`/`updated_at`, no `Clock` dependency. `listing-publish` adds `published_at` when expiry needs it. | `created_at` for a sort the id already gives. |
| D12 | **`ListingStatus` enum with one case, stored** | `Draft` only, column `status varchar(16)` via `enum-type`. The owner agreed "one status, `Draft`", and `listing-publish` needs the column with every existing row already `draft` — storing it now is cheaper than a backfilled column later. | No column until publish: a migration with a default and a backfill next slice, for one saved line now. |
| D13 | **UI: one controller, three routes, server-rendered Twig, everything under `/sell`** | `GET|POST /sell` (create), `GET /sell/drafts` (mine), `GET|POST /sell/drafts/{id}/edit`. One anchored `access_control` rule `^/sell(/|$)` + `#[IsGranted('ROLE_USER')]` (belt and braces, the `/profile` pattern). "Sell Gear" is a copy of the "Profile" link's ADR-0015 shape. The category `<select>` is built from the **existing** `DbalCategoryOutline::rows()` (leaves = rows that are nobody's parent), so no new catalog SQL. Writes are POST with Symfony form CSRF; no GET writes (ADR-0015's permanent rule). | **A Live Component form**: no server state changes while typing yet (ADR-0015 rule of thumb). **`/listings/new`, `/my/listings`**: `/listings/{id}` will be the *public* page in `listing-publish`; keeping the private surface under one prefix keeps one access rule. |

## Domain layer (pure PHP): `src/src/Domain/Listing/` (new) and `src/src/Domain/Catalog/`

### Value objects (`Domain/Listing/ValueObject/`)

- **`ListingId`, `SellerId`, `CategoryId`**: `final readonly`, `fromString()` with the shared UUID
  format check (lower-cased), `equals(self)`, `toString()`. Copies of `CategoryId`'s shape; each
  docblock names ADR-0009's trait proposal and, for `SellerId`/`CategoryId`, says *"Listing's name
  for an id another context owns; never import theirs (ListingIsolationTest)"*.
- **`ListingTitle`** (`MIN_LENGTH = 3`, `MAX_LENGTH = 120`, trimmed, `mb_strlen`),
  **`ListingDescription`** (`MAX_LENGTH = 5000`, trimmed, may be empty).
- **`Currency`**: backed string enum `USD | EUR | SEK | UAH`; `minorUnits(): int` (a `match`, 2 for all);
  `fromCode(string): self` throws `InvalidPrice::unsupportedCurrency()`.
- **`Price`**: `final readonly`; `fromMinorUnits(int, Currency)`, `fromDecimal(string, Currency)`,
  `amountInMinorUnits()`, `currency()`, `toDecimalString()`, `equals()`. `MAX_MINOR_UNITS =
  999_999_999`. Docblock: why minor units, why no float, why the comma, and that cross-currency
  comparison is **not** defined (Phase 2).
- **`ListingStatus`**: backed enum, one case `Draft = 'draft'`.
- **`CategoryStatus`**: enum `Missing | Leaf | Branch` (not backed: never stored). Docblock: *a fact,
  true only inside `Categories::holdSteady()`* (the `Lineage` sentence).

### Aggregate (`Domain/Listing/Entity/Listing.php`)

```
Listing
  static draft(ListingId, SellerId, CategoryId, CategoryStatus, ListingTitle,
               ListingDescription, Price): self
      L-2: Missing → UnknownCategory::for($id); Branch → CategoryIsNotALeaf::for($id)
      status = Draft (L-7)
  revise(SellerId $by, CategoryId, CategoryStatus, ListingTitle, ListingDescription, Price): void
      L-6 first: !isSoldBy($by) → NotTheSeller
      category changed → L-2 on the status; unchanged → no check
      then assign all
  isSoldBy(SellerId): bool
  id(), sellerId(), categoryId(), title(), description(), price(), status()
```

- Docblock: the L-2 contract — *the aggregate decides, the shared lock makes the status true,
  Catalog's C-23/C-24 are the other half*, with ADR-0019. Properties plain `private` (ADR-0007).
- `revise()` takes everything the edit form submits, because the form saves everything at once and
  each part is checked before any is assigned. Separate `retitle()`/`reprice()` methods are additive
  if a caller ever changes one thing.

### Exceptions (`Domain/Listing/Exception/`, all `\DomainException`)

`InvalidListingId`, `InvalidSellerId`, `InvalidCategoryId`, `InvalidListingTitle`,
`InvalidListingDescription`, `InvalidPrice` (`malformed`, `outOfRange`, `unsupportedCurrency`),
`UnknownCategory` (`for`, `withMalformedId`), `CategoryIsNotALeaf`, `NotTheSeller`,
`ListingNotFound` (`withId`, `withMalformedId`).

### Ports

```
Domain/Listing/Port/ListingRepository
  nextIdentity(): ListingId
  get(ListingId): Listing                        @throws ListingNotFound
  save(Listing): void

Domain/Listing/Port/Categories                   Listing's view of the catalog
  holdSteady(\Closure $work): mixed              runs $work in one transaction during which no
                                                 category write can commit; listing writes do not
                                                 block each other. Rethrows after rollback.
  statusOf(CategoryId): CategoryStatus           only true inside holdSteady()

Domain/Catalog/Port/CategoryUsage                Catalog's view of what is filed under it
  hasListings(CategoryId): bool                  any listing, any status; only true inside
                                                 CategoryTreeLock::exclusively()
```

`Categories` lives in the Domain for ADR-0016 d.3's reason: L-2's correctness depends on the status
staying true until commit, and how that is kept is the adapter's business.

### Catalog exception

**`CategoryHasListings`** (`\DomainException`): `cannotDelete(CategoryId)`,
`cannotGainChild(CategoryId)`; exposes the id for the admin message.

### Events

None (D9).

## Application layer

### `Application/Listing/` (new)

- **`DraftListing`** command: `string $sellerId, string $categoryId, string $title, string
  $description, string $price, string $currency`. **`DraftListingHandler`** →
  `ListingId`: entirely inside `categories->holdSteady()`: build VOs (malformed category id →
  `UnknownCategory::withMalformedId`), `statusOf()`, `Listing::draft()`, `save()`.
- **`ReviseListing`** command: `string $listingId` + the same fields. **`ReviseListingHandler`**:
  inside `holdSteady()`: `get()` (malformed id → `ListingNotFound::withMalformedId`), build VOs,
  `statusOf(newCategory)`, `revise()`, `save()`. Status is read even when the category is unchanged
  (one indexed lookup; the aggregate ignores it) so the handler has no branch.
- Idempotency: a repeated draft POST creates a second draft (a user action, not a retry; the PRG
  redirect prevents accidental resubmits). A repeated revise writes the same values.

### `Application/Catalog/` (three guards, no new command)

- **`DeleteCategoryHandler`** gains `CategoryUsage`; after C-13:
  `hasListings($id)` → `CategoryHasListings::cannotDelete()`. Docblock: the accretion list is now
  C-7, C-13, C-23.
- **`CreateCategoryHandler`** gains `CategoryUsage`; when the parent is not null, after
  `lineageOf()` (C-6) and before the C-3 pre-check: `hasListings($parent)` →
  `cannotGainChild()`.
- **`EditCategoryHandler`** gains `CategoryUsage`; inside the existing `if ($parentChanged)`, after
  `moveUnder()` and only when the new parent is not null: `hasListings($newParent)` →
  `cannotGainChild()`. The "SECOND ACCRETION POINT" comment gains: *"C-24 is the Listing slice's
  first guard here; values arrive with `listing-attribute-values`"*.

## Infrastructure layer

### Persistence (`Infrastructure/Listing/Persistence/Doctrine/`)

- **DBAL types** (`Type/`), registered in `doctrine.yaml` under the Listing comment:
  `listing_listing_id`, `listing_seller_id`, `listing_category_id`, `listing_title`,
  `listing_description`. `Currency` and `ListingStatus` use native `enum-type` (the
  `AttributeType` precedent).
- **`mapping/Listing.orm.xml`**: table `listing_listing`, `<generator strategy="NONE"/>`, explicit
  columns, `<embedded name="price" … column-prefix="price_"/>`, two `<indexes>`. Header comment:
  reference by id (no association, no FK) for `seller_id` and `category_id`, and why.
- **`mapping/embeddable/Price.orm.xml`**: `<embeddable>` with `amountInMinorUnits` →
  `amount bigint` and `currency` → `currency varchar(3)` enum-type. Header: ADR-0007 amendment.
- **`doctrine.yaml`**: two mapping blocks, `Listing` (prefix `App\Domain\Listing\Entity`) and
  `ListingValueObjects` (dir `mapping/embeddable`, prefix `App\Domain\Listing\ValueObject`).
- **`DoctrineListingRepository`** implements `ListingRepository` (UUIDv7 via `Uuid::v7()`, as the
  Catalog adapters do; not `ServiceEntityRepository`).
- **`DbalSellerDrafts`** (read model, no port, DBAL): `forSeller(string $sellerId): list<DraftRow>`
  (`ORDER BY id DESC`) and `find(string $id, string $sellerId): ?DraftRow` (malformed id → `null`
  before the query). **The seller filter is in the SQL of both methods.**
- **Migration** `VersionYYYYMMDD…`: `CREATE TABLE listing_listing` + two indexes. Generated by
  `make migration.make`, then read.

### Cross-context adapters (D4)

- **`Infrastructure/Catalog/Integration/CategoriesForListing`** implements
  `App\Domain\Listing\Port\Categories`:
  - `holdSteady()` → `PostgresCategoryTreeLock::sharedly($work)`.
  - `statusOf()`: one DBAL query
    `SELECT EXISTS (SELECT 1 FROM catalog_category c WHERE c.parent_id = :id) FROM catalog_category
    WHERE id = :id` → no row `Missing`, `true` `Branch`, `false` `Leaf`. Served by the primary key
    and the existing `(parent_id, slug)` partial index (leading `parent_id`).
- **`PostgresCategoryTreeLock`** gains a public **`sharedly(\Closure)`** (not on the Catalog port —
  Catalog never needs it): the same `transactional()` + clear-on-throw body, with
  `pg_advisory_xact_lock_shared`. Both methods share one private `run(string $sql, \Closure)`. The
  `KEY` docblock gains: *"held shared by every Listing write (ADR-0019)"*, and the existing
  `ponytail:` ceiling note is extended: *one key now also orders listing writes against admin
  writes; per-subtree keys remain the upgrade path*.
- **`Infrastructure/Listing/Integration/ListingCategoryUsage`** implements
  `App\Domain\Catalog\Port\CategoryUsage`: `SELECT EXISTS (SELECT 1 FROM listing_listing WHERE
  category_id = :id)`; served by `idx_listing_listing_category`.

### HTTP / UI

- **`Infrastructure/Listing/Http/Controller/SellController`** (`#[IsGranted('ROLE_USER')]`):
  - `new` — `GET|POST /sell` (`app_sell`): empty-catalog message (AC-24); on success
    `DraftListingHandler`, flash, 303 → `app_sell_drafts`.
  - `drafts` — `GET /sell/drafts` (`app_sell_drafts`): `DbalSellerDrafts::forSeller()` + one outline
    read for the category paths.
  - `edit` — `GET|POST /sell/drafts/{id}/edit` (`app_sell_draft_edit`): `find()` null → 404;
    `NotTheSeller`/`ListingNotFound` from the handler → the same 404.
  - Refusal mapping: each Domain exception to its field and message (failure contract), 422 —
    `addRefusal()` in the Catalog admin's shape. No logging on refusals.
- **`Infrastructure/Listing/Form/ListingFormType` + `ListingFormData`**: `title`, `description`
  (`TextareaType`), `price` (`TextType`, not `MoneyType` — D7), `currency` (`ChoiceType` from
  `Currency::cases()`), `category` (`ChoiceType` from `LeafCategoryChoices`). Type-level constraints
  only (string, length caps to bound input); the Domain decides validity.
- **`Infrastructure/Listing/Form/LeafCategoryChoices`**: turns `DbalCategoryOutline::rows()` into
  `["Instruments › Guitars › Electric" => id, …]`, leaves only, outline order. Pure PHP over rows;
  unit-testable.
- **Templates** `templates/listing/`: `sell.html.twig`, `drafts.html.twig`, `edit.html.twig`,
  `_form.html.twig`. Price shown as `toDecimalString() ~ ' ' ~ currency`. No seller email anywhere.
- **`base.html.twig`**: inside the `auth_nav` block, "Sell Gear" — signed in: `<a href="{{
  path('app_sell') }}">`; guest: `<a href="{{ path('app_login', {_target_path: path('app_sell')}) }}"
  data-auth-overlay>`. Block comment updated ("the first copy of the shape").
- **`security.yaml`**: `- { path: ^/sell(/|$), roles: ROLE_USER }` with the anchoring comment.
- **Catalog admin**: `CategoryAdminController::delete()` catches `CategoryHasListings` → error flash;
  `new()`/`edit()` map it to the **parent** field (AC-30).

### DI wiring (`services.yaml`)

```
App\Domain\Listing\Port\ListingRepository: '@App\Infrastructure\Listing\Persistence\Doctrine\DoctrineListingRepository'
App\Domain\Listing\Port\Categories:        '@App\Infrastructure\Catalog\Integration\CategoriesForListing'
App\Domain\Catalog\Port\CategoryUsage:     '@App\Infrastructure\Listing\Integration\ListingCategoryUsage'
```

`CategoryUsage`'s alias lands **in the same commit** as the three Catalog handlers that inject it
(CLAUDE.md: Symfony compiles lazily; a missing adapter is silent until a route resolves it).

### Async / schedule / external

None.

## Interface boundary & input contract

| Surface | Accepts | Refuses |
|---|---|---|
| `POST /sell` | `listing_form[title|description|price|currency|category|_token]`, all strings | extra fields (incl. `seller`), bad token → 422; Domain refusals → 422 per field |
| `POST /sell/drafts/{id}/edit` | the same | the same; non-owner / unknown / malformed id → 404 |
| `GET /sell`, `/sell/drafts`, `/sell/drafts/{id}/edit` | — | anonymous → 302 `/login`; never writes |
| `POST /admin/categories/{id}/delete` | (unchanged) | listed-on → error flash |
| `POST /admin/categories/new`, `/admin/categories/{id}/edit` | (unchanged) | parent listed-on → 422 on `parent` |
| `DraftListing` / `ReviseListing` | strings only; `sellerId` from `SecurityUser::id()` | — |

## Data & migrations

```sql
CREATE TABLE listing_listing (
    id             UUID          NOT NULL,
    seller_id      UUID          NOT NULL,   -- Identity user id; no FK (ADR-0009 d.4)
    category_id    UUID          NOT NULL,   -- Catalog category id; no FK; a leaf (L-2, C-23, C-24)
    title          VARCHAR(120)  NOT NULL,
    description    VARCHAR(5000) NOT NULL,   -- '' when empty, never NULL
    price_amount   BIGINT        NOT NULL,   -- minor units; bigint per the ::int lesson
    price_currency VARCHAR(3)    NOT NULL,
    status         VARCHAR(16)   NOT NULL,
    PRIMARY KEY (id)
);
CREATE INDEX idx_listing_listing_seller   ON listing_listing (seller_id);    -- "my drafts"
CREATE INDEX idx_listing_listing_category ON listing_listing (category_id);  -- C-23/C-24; Phase 2 subtree filter
```

- No backfill, no pre-deploy query: no listing exists anywhere yet, so every category is trivially
  consistent with C-23/C-24 at deploy.
- **Deploy window** (cicd.md: `up -d` then `migrate`): for the seconds between them the new Catalog
  guards query a table that does not exist → 500s on admin category create/move/delete and on
  `/sell`. Accepted (admin-only, seconds); recorded in the failure contract, not engineered around.
- `price_amount` is not indexed: no query sorts or filters on it until Phase 2, which must decide
  the cross-currency question first.
- **Search impact:** none now. `idx_listing_listing_category` is the index ADR-0016's Phase 2
  subtree facet (`category_id = ANY(:ids)`) will use.

## Test plan

- **Domain unit (no kernel):** `PriceTest` (AC-1, incl. the float cases and a source scan for
  `float`/`(float)`/`floatval`), `CurrencyTest` (AC-2), `ListingTitleTest`,
  `ListingDescriptionTest` (AC-3), id VO tests (format, case, `equals`), `ListingTest` (AC-4…AC-6),
  `LeafCategoryChoicesTest` (leaves only, path labels, order).
- **Isolation:** `ListingIsolationTest` (AC-7); `CatalogIsolationTest` untouched (AC-8).
- **Integration (DAMA):**
  - `ListingHandlersTest` (AC-9…AC-12) with row snapshots and a **fresh repository** before each
    read-back.
  - `CategoryHandlersTest` additions (AC-13…AC-16) using a new `ListingFactory`.
  - `CategoriesForListingTest`, `ListingCategoryUsageTest` (AC-17).
  - `CategoryTreeLockTest` additions: `ObservedCategoryUsage` and an `ObservedCategories` double
    (records calls and whether `holdSteady()` was active); the real-mode probes from inside the
    closure (AC-18, AC-19).
  - `ListingSchemaTest` (AC-20) reading `information_schema` / `pg_indexes` / `pg_constraint`.
- **`ListingFactory`** (Foundry) goes through `Listing::draft()` with `CategoryStatus::Leaf` — so it
  **bypasses L-2** by design, and its default category does not exist. Docblock says so, the
  `AttributeFactory` warning copied. Build with `->inCategory($leaf)` whenever the category matters.
- **Functional:** `SellTest` (AC-21, AC-23…AC-29, AC-31), `LoginOverlayMarkupTest` updated (AC-22),
  `CategoryAdminTest` additions (AC-30), Sentry silence (AC-32). Uses `ClearsRateLimiters` (login).
- **Bite proof:** AC-33 (a)…(h).
- **Performance:** none required (no search path). `/sell/drafts` uses the seller index; AC-27's
  query count is the guard against an N+1 on category paths.

## Risks / open questions

1. **Q1–Q8 above.** Q1 and Q4 are product calls; the rest have safe defaults.
2. **The lock's scope now crosses a context boundary.** `PostgresCategoryTreeLock::KEY` is taken
   shared by Listing writes. A new Listing write that files a listing (an import, a bulk re-file, the
   Phase 2 wizard) **must** go through `Categories::holdSteady()`, and a new Catalog write that can
   turn a leaf into a branch (a "merge categories" tool) must read `hasListings()` under
   `exclusively()`. AC-18 covers the doors that exist; ADR-0019 names this as the watch item, and
   CLAUDE.md gets the sentence.
3. **Same-session lock modes under DAMA.** A test that takes `holdSteady()` and then `exclusively()`
   in one DAMA connection holds both on one backend; PostgreSQL never makes a session conflict with
   itself, so it will not deadlock — but it also means such a test cannot observe blocking. Only the
   second-session probes (AC-19) prove the modes. Verify the same-session behaviour once in T16
   rather than assume it.
4. **Nine copies of the UUID value object** after this slice (`UserId`,
   `EmailVerificationRequestId`, `PasswordResetRequestId`, `CategoryId`, `AttributeId`,
   `AttributeOptionId`, `ListingId`, `SellerId`, Listing's `CategoryId`). ADR-0009's trait refactor
   was proposed as its own slice; this is a good moment to schedule it (roadmap note), not to do it
   here.
5. **Readonly embeddable hydration.** Doctrine ORM 3.6 hydrates readonly properties by reflection;
   `Price` is `final readonly`. T8 proves a save/fresh-load round trip before anything builds on it.
   Fallback if it misbehaves: D6's first alternative (two private fields), recorded in the ADR.
6. **`clear()` in the lock adapter now runs inside Listing requests too.** It detaches every managed
   entity on a refusal. Safe: no Listing caller holds other managed entities across a handler. The
   adapter's docblock already says so; it gains "and Listing's".
7. **ADR work (T0):**
   - **ADR-0019** *"The Listing context: each context states a cross-context need as its own port;
     the adapter lives with the data's owner; leaf-only is kept by Listing (L-2) and Catalog
     (C-23/C-24) under one lock in two modes; prices are integer minor units with a per-listing
     currency"* — D1–D8, D10, with every rejected alternative above, and the Phase 2 consequence:
     **price facets and sorting across currencies are undecided** (convert at query time with a
     rates table, facet per currency, or restrict a search to one currency).
   - **Dated ADR-0016 amendment:** the lock has a **shared mode** taken by every Listing write; the
     **third accreted delete guard** is C-23; **C-24** joins create and the move (the second
     accretion point's first Listing guard); the lock's scope is no longer only the Catalog.
   - **Dated ADR-0007 amendment:** decision 4 is refined: a **multi-part VO is an embeddable** (its
     rejection was for single-value VOs); embeddables need their own mapping block per context
     (prefix `…\ValueObject`). Single-value VOs stay DBAL types.
   - **No change** to ADR-0009 (applied: ids, no FK; orphans on erasure noted), ADR-0015 (applied:
     overlay shape, no state-changing GET), ADR-0004 (values are next slice).
