# Technical Plan: catalog-category-tree

> The *how*. Disposable. Written after the feature-spec is drafted, approved with it before any code.
> Follows DDD canonical order.

## Decisions needing sign-off

Each row has a recommendation. **Bold** rows should be recorded in an ADR (task T0).

| # | Decision | Recommendation | Alternatives considered |
|---|---|---|---|
| **D1** | **Aggregate boundary** | **One aggregate per category.** `Category` holds `id`, `?parentId` (a `CategoryId`, never an object), `slug` and `name`. | **(a) The whole tree as one aggregate** (`CategoryTree` root, categories as internal entities). It is the textbook answer to "the invariant spans the tree", and the tree is small (hundreds of nodes, one admin). Rejected for three reasons. First, every later reference (an `Attribute` attached to a category, a `Listing` filed under one, a saved search filtering on one) points at **a category**. Evans: nothing outside an aggregate may hold a reference to an entity inside it, so either those references break the rule or `CategoryTree` becomes a global singleton that every write in three contexts has to load. Second, mapping it needs a `<one-to-many>` of child entities, or a `jsonb` document that a listing cannot join against. Third, its one real advantage, a single version row for optimistic locking, is something D3 gets without it. **(b) A `Category` holding a `list<CategoryId>` of its children**: two sources of truth for one edge, and a move then changes three aggregates. |
| **D2** | **Where the cross-instance invariants live** | **The aggregate decides, using facts passed in.** `Category::create(…, Lineage $parentLineage)` and `moveUnder(Lineage $newParentLineage, int $subtreeHeight)` own C-4 and C-5 as pure logic, unit-testable without a database (ADR-0012: *put in the Domain the part that can be wrong*). The handler reads the facts through the repository port. C-3 (sibling slug) follows the **I-6 precedent**: a handler pre-check for the message, plus a unique index that is the real guarantee. C-6/C-7 (parent exists, only delete a leaf) are handler checks over port queries. | **(a) A domain service `CategoryTreePolicy`** that owns C-4/C-5 and calls the port itself. That makes the Domain do I/O, and it is an anemic `Category` with the rules moved next door. Passing facts in keeps the rule on the object whose state it guards. **(b) A Postgres trigger** for cycles and depth. That is the rule written a second time in PL/pgSQL, where no unit test reaches it (the ADR-0012 objection to a SQL "dead" predicate). It also produces SQLSTATE errors, which the adapter would have to map back into domain exceptions. Rejected. **(c) SERIALIZABLE isolation** instead of D3: correct, but it turns a write-skew race into a `40001` that has to be retried. A lock is simpler to reason about at this write rate. |
| **D3** | **Concurrency: what makes those facts true** | **One tree-wide, transaction-scoped Postgres advisory lock** behind a Domain port: `CategoryTreeLock::exclusively(\Closure $work): mixed`. The adapter runs `Connection::transactional()`, then `SELECT pg_advisory_xact_lock(<const key>)`, then `$work()`. On any throwable it rolls back and calls `EntityManager::clear()`. **Every** Catalog write handler (create, edit, delete) reads its facts and saves inside it. The rule is uniform, so nobody has to decide per handler. `ponytail: global lock` is acceptable for one admin writing a few times a day. Per-subtree locking is the upgrade path if Catalog ever gets concurrent writers at volume. | **No lock.** Then two concurrent moves (A under B, B under A) each read a lineage without the other's write. Both pass C-4 and both commit a cycle. That is classic write skew: no single row conflicts, so neither row locks nor optimistic versions see it. Two "create child under P" requests racing a "delete P" would leave an orphan. **A Redis lock** (as the pruning job uses, ADR-0012) cannot be atomic with the Postgres commit: it would be released before or after the commit, never *at* it. **`SELECT … FOR UPDATE` on the parent row** misses the cycle case, because the two moves lock different rows. |
| **D4** | **Tree storage** | **Adjacency list:** `parent_id` on the row, read with `WITH RECURSIVE` CTEs whose recursion is **bounded by `MAX_DEPTH + 1`** (C-4b). Depth, path and lineage are **derived, never stored**. The decisive argument is DDD, not performance. With an adjacency list a move changes **one row = one aggregate**. Every other representation stores something per node that depends on its ancestors, so a move has to rewrite every descendant. With per-node aggregates that is either N aggregates changed in one use case or a bulk `UPDATE` that goes around them (the bypass ADR-0011 decision 4 refused). | **ltree:** excellent for `<@` subtree filters with GiST. But labels allow only `[A-Za-z0-9_]` (UUIDs need re-encoding), it needs an extension, and a move rewrites descendants. **Materialized path** (text with a `text_pattern_ops` prefix index): same move problem. **Nested set:** a move or insert rewrites large parts of the table, the worst write profile there is. **Closure table:** good subtree reads, but a second table of derived pairs to keep in step on every move. **Search impact (Phase 2):** a subtree facet filter is *expand the subtree to ids* (one recursive CTE over a table of a few hundred rows, cache-resident, sub-millisecond, cacheable), then `listing.category_id = ANY(:ids)` on an index of `listing`. The tree is never joined per listing row, so the representation does not affect the 200 ms budget. If it ever does, an ltree/closure **read model** can be derived later without touching the aggregate. |
| **D5** | **Depth ceiling value** | `Category::MAX_DEPTH = 4` (root = 1), for example *Effects > Pedals > Drive > Fuzz*. Structural differences (string count, handedness) are attributes, not subcategories, and a shallow tree keeps that line visible. The ceiling also bounds every recursive read (C-4b) and the ancestor chain that slice 3's schema inheritance will walk. Lowering it later leaves existing deeper nodes in place: they can still be renamed, and only moves and creates are checked. | 3 (too tight for effects/keys), 5+ (invites modelling attributes as categories). **Owner: confirm the number (Q1).** |
| D6 | **No domain events** | `Category` records none and does not use `RecordsEvents`. The handlers call no dispatcher. | `CategoryCreated/Renamed/Moved/Deleted` with no listener, following the "recorded history" precedent of ADR-0009's amendment. Declined: that precedent covered an event whose consumer was one slice away and whose payload was obvious. Here the first consumer (slice 3's "what does a move do to inherited attributes", or a search cache) is two slices away and will need a payload nobody can design yet (old **and** new lineage? the subtree?). |
| D7 | **Delete semantics** | Hard `DELETE` of a **leaf** only (C-7), checked under the lock. ADR-0012: an aggregate governs its transitions, not its own non-existence, so there is no `Category::delete()`. The handler owns the precondition. Later slices that create references (attributes, listings) add their own guard to `DeleteCategoryHandler`, or turn deletion into archiving **when** that happens. Nothing references a category today, so a soft-delete column now would be speculative. | Cascade-delete a subtree (one click destroys a branch, and later its listings: no). Soft delete now (ADR-0004 soft-deletes *attributes* because values reference them; categories have no references yet). |
| **D8** | **`CategoryId` and the ADR-0009 shared-UUID trigger** | ADR-0009's 2026-07-29 amendment says: *"Revisit at the first aggregate id outside `Identity`, and only if the extraction can preserve cross-type comparison as a compile-time error."* `CategoryId` **is** that first id, so the trigger has fired and must be answered on record. **Recommendation: still duplicate** (a ~40-line `CategoryId` copied from `UserId`), and record the answer in the ADR amendment. A **trait** would meet the criterion, because `self` inside a trait resolves to the using class, so `equals(self $other)` keeps its type. But extracting it means editing three Identity VOs, which is out of scope for a Catalog slice. Proposed as its own small refactor. | Extract a trait now (touches Identity; scope creep). A base class (the type hole the amendment describes). |
| **D9** | **Admin UI styling** | **Unstyled semantic HTML** extending `base.html.twig`, like every existing page. **Defer installing `symfonycasts/tailwind-bundle`** (ADR-0006) to its own small devops slice: it adds a binary download to the image build and CI, which is unrelated risk in a modelling slice. ADR-0006 fixes *which* tool, not *when*. | Install Tailwind here (the first admin screen is a natural moment). **Owner's call (Q4).** |
| **D10** | **Who is an admin, and how** | `ROLE_ADMIN` (already in the `Role` enum, ADR-0005). It is granted and revoked **only** from the console: `muzbar:identity:grant-role <email> <ROLE_…> [--revoke]`, a thin adapter over new `GrantRole` / `RevokeRole` Application handlers that call the existing `User::grantRole()` / `revokeRole()`. There is no web UI for roles and no role hierarchy. Firewall: `access_control: { path: ^/admin(/|$), roles: ROLE_ADMIN }` plus `#[IsGranted('ROLE_ADMIN')]` on the controller (the `/account` precedent). Revocation takes effect on the next request, because Symfony's `ContextListener` deauthenticates a refreshed user whose roles changed. AC-32 asserts this rather than assuming it. → **dated ADR-0005 amendment** | An env-var allow-list of admin emails (config that knows about users, and a redeploy to revoke). A role-management screen (an admin who can mint admins needs its own threat model). An admin-only command (`grant-admin`) is the same size as the general one, so the general one is used. |
| D11 | Slug input | Admin-editable, optional on the form. If blank, it is derived at the **Infrastructure** boundary with Symfony's `AsciiSlugger` (Cyrillic is transliterated), lower-cased and cut to 64. The Domain only validates (C-2). Slugs are **mutable**: nothing public links to them yet, and C-8 guarantees that ids, not slugs, are what other things reference. | An immutable slug (a typo is forever). Deriving the slug in the Domain (a vendor transliterator inside the Domain, no). |
| D12 | Sibling order | Name, case-insensitive, id as the tie-break. Computed in the read model only. Nothing stored. | A `position` column with drag-and-drop (Q3) |
| D13 | Locale of names | One name, not translated. | A `jsonb` locale→name map (Q2). Additive later. |

## Domain layer (pure PHP) — `src/src/Domain/Catalog/`

### Value objects (`ValueObject/`)

- **`CategoryId`**: a copy of `UserId`'s shape (private ctor, `fromString()` with the RFC 4122
  regex, lower-cased, `equals(self)`, `toString()`, `__toString()`). Throws `InvalidCategoryId`.
  Its docblock names D8 and the trait alternative.
- **`CategoryName`**: `MAX_LENGTH = 100`. Trim, then `mb_strlen` 1–100, then reject
  `/\p{Cc}/u`. Throws `InvalidCategoryName`. `toString()`, `equals()`.
- **`CategorySlug`**: `MAX_LENGTH = 64`. Trim, then `strtolower`, then
  `^[a-z0-9]+(?:-[a-z0-9]+)*$`, then length. Throws `InvalidCategorySlug`. `toString()`, `equals()`.
- **`Lineage`**: `final readonly`, holding `list<CategoryId>` root-first. `ofRoot(): self` (empty,
  meaning "no parent"). `fromRootDown(list<CategoryId>): self` (non-empty, no duplicate ids, else
  `InvalidLineage`). `depth(): int`, `last(): ?CategoryId`, `contains(CategoryId): bool`. It is a VO
  because it is a *fact handed to the aggregate*, compared by value, never persisted. Its docblock
  says it is only true while the tree lock is held.

### Aggregate (`Entity/Category.php`)

```
final class Category
  public const int MAX_DEPTH = 4;
  private CategoryId $id; private ?CategoryId $parentId; private CategorySlug $slug; private CategoryName $name;

  static create(CategoryId, CategoryName, CategorySlug, Lineage $parentLineage): self
      C-5: $parentLineage->depth() + 1 > MAX_DEPTH  → CategoryTreeTooDeep
      parentId = $parentLineage->last()
  moveUnder(Lineage $newParentLineage, int $subtreeHeight): void
      $subtreeHeight < 1                                  → \InvalidArgumentException (caller bug)
      $newParentLineage->last() equals current parentId   → return (no-op)
      C-4: $newParentLineage->contains($this->id)          → CategoryMoveWouldCreateCycle
      C-5: depth() + $subtreeHeight > MAX_DEPTH            → CategoryTreeTooDeep
      parentId = $newParentLineage->last()
  rename(CategoryName): void
  changeSlug(CategorySlug): void
  id(), parentId(), slug(), name()
```

- Properties are plain `private`, not `readonly` (ADR-0007 consequence). There is no `delete()` (D7).
- C-4 is checked **before** C-5, so moving a node under its own descendant always reports the cycle
  and never a depth error, which is the more truthful message. The checks happen before any
  assignment, so a refused call mutates nothing (AC-6/AC-7).
- The class docblock states D2's contract plainly: *this aggregate decides C-4/C-5, it cannot
  verify that the lineage it was given is current, and `CategoryTreeLock` is what makes it current.*
  This is a design choice, and the docblock should say so as one.
- `create()` takes a lineage rather than a `?CategoryId $parentId`, so a category cannot be built
  under a parent whose depth the caller did not look up.

### Exceptions (`Exception/`)

`InvalidCategoryId`, `InvalidCategoryName`, `InvalidCategorySlug`, `InvalidLineage`,
`CategoryNotFound`, `CategorySlugAlreadyTaken`, `CategoryTreeTooDeep`,
`CategoryMoveWouldCreateCycle` and `CategoryHasChildren` all extend `\DomainException` with named
constructors (the Identity style). `CorruptCategoryTree` extends **`\LogicException`**: it is not
a refusal, it is data that must not exist.

### Ports (`Port/`)

```
interface CategoryRepository
  nextIdentity(): CategoryId
  get(CategoryId): Category                                        // throws CategoryNotFound
  lineageOf(CategoryId): Lineage                                   // root-first incl. the id; CategoryNotFound; CorruptCategoryTree (C-4b)
  subtreeHeightOf(CategoryId): int                                 // ≥ 1; CorruptCategoryTree (C-4b)
  hasChildren(CategoryId): bool
  isSlugTakenAmongSiblings(?CategoryId $parentId, CategorySlug, ?CategoryId $except): bool
  save(Category): void                                             // 23505 on either slug index → CategorySlugAlreadyTaken
  remove(Category): void

interface CategoryTreeLock
  /** Runs $work exclusively against every other category write, in one transaction:
      all of it commits, or none of it does. @template T @param \Closure(): T $work @return T */
  exclusively(\Closure $work): mixed
```

`CategoryTreeLock` is in the Domain because "the tree is changed one write at a time, atomically"
is the guarantee the aggregate's correctness depends on (D2/D3). Advisory locks and transactions
are how the adapter keeps it. **No `Clock`, no `DomainEventDispatcher`** is needed (no timestamps,
D6).

## Application layer — `src/src/Application/Catalog/`

**Commands** (primitives in, as in `RegisterUser`):

- `CreateCategory(string $name, string $slug, ?string $parentId)`
- `EditCategory(string $id, string $name, string $slug, ?string $parentId)`
- `DeleteCategory(string $id)`

**Handlers.** Each one wraps all of its work in `$this->lock->exclusively(fn () => …)`:

- **`CreateCategoryHandler`** → build the VOs (they throw on bad input before the lock is even
  needed, but stay inside it so there is one code shape). Then
  `$lineage = $parentId ? repo->lineageOf(CategoryId::fromString($parentId)) : Lineage::ofRoot()`
  (C-6; a malformed id becomes `CategoryNotFound`). Then the C-3 pre-check
  `isSlugTakenAmongSiblings($lineage->last(), $slug, null)` → `CategorySlugAlreadyTaken`. Then
  `Category::create(repo->nextIdentity(), …, $lineage)` and `repo->save()`. Returns the new
  `CategoryId` (the controller needs it for the flash).
- **`EditCategoryHandler`** → `get(id)`. If the parent changed: `lineage` and `subtreeHeightOf(id)`,
  then `moveUnder()`. Then `rename()` and `changeSlug()` when they differ. If the slug **or** parent
  changed: the pre-check against the (new) siblings with `except: id`. Then `save()`. All of it is
  inside one `exclusively()`, so a refusal at any step rolls the whole edit back, and the adapter's
  `clear()` drops the half-mutated object (AC-16). The order is move first, then rename, so that
  the checks that consult the tree run before the cheap ones. The comment at the call site says so.
- **`DeleteCategoryHandler`** → `get(id)`, then `hasChildren(id)` → `CategoryHasChildren` (C-7),
  then `remove()`.

`src/src/Application/Identity/`: `GrantRole(string $email, string $role)` and `RevokeRole(…)`, each
with a handler. `Email::fromString`, then `findByEmail` (or `UserNotFound`), then `Role::from()`
(a `\ValueError` for an unknown role, caught by the console adapter), then `grantRole()` or
`revokeRole()`, then `save()`. No event. Matches today's `grantRole()`, which records none.

**Idempotency:** create is **not** idempotent. A double submit produces a C-3 refusal on the
second POST, because the slug is the same. That is the natural guard and needs no token. Edit is
idempotent. Delete of an already-deleted id answers 404.

## Infrastructure layer — `src/src/Infrastructure/Catalog/`

### Persistence (`Persistence/Doctrine/`)

- **`mapping/Category.orm.xml`**: table `catalog_category`. `<id name="id" type="catalog_category_id"
  column="id"><generator strategy="NONE"/></id>`, `parentId → parent_id catalog_category_id
  nullable="true"` (**a plain typed column, never `<many-to-one>`**, with a header comment naming
  ADR-0009 the way `PasswordResetRequest.orm.xml` does), `slug → slug catalog_category_slug
  length=64`, `name → name catalog_category_name length=100`. Two `<unique-constraint>`s with
  `<options><option name="where">…</option></options>` (AC-19). They carry their names explicitly
  because the AC, the adapter's 23505 mapping and the migration all refer to them by name.
- **`Type/CategoryIdType`** (extends `GuidType`, the `UserIdType` shape), and
  **`Type/CategorySlugType`** and **`Type/CategoryNameType`** (extend `StringType`, the
  `EmailType` shape). They are registered in `doctrine.yaml` as `catalog_category_id`,
  `catalog_category_slug` and `catalog_category_name`. `doctrine.yaml` also gets a new `Catalog:`
  mappings block (`prefix: App\Domain\Catalog\Entity`).
- **`DoctrineCategoryRepository`**: does not extend `ServiceEntityRepository`. `nextIdentity()`
  uses `Uuid::v7()`. `get()` is `find()` or `CategoryNotFound`. `save()` does persist and flush and
  catches `UniqueConstraintViolationException` → `CategorySlugAlreadyTaken` (DBAL exception kept as
  the cause). `remove()` does remove and flush. The tree reads use **DBAL on the same connection**,
  so they run inside the lock's transaction:
  - `lineageOf`: `WITH RECURSIVE up(id, parent_id, n) AS (SELECT … WHERE id = :id UNION ALL SELECT
    c.id, c.parent_id, up.n + 1 FROM catalog_category c JOIN up ON c.id = up.parent_id WHERE up.n <=
    :bound)`, ordered `n DESC`. Zero rows → `CategoryNotFound`. More than `MAX_DEPTH + 1` rows, or
    a top row whose `parent_id` is not null → `CorruptCategoryTree`. `:bound = MAX_DEPTH + 1` comes
    from the Domain constant, never a literal.
  - `subtreeHeightOf`: the downward twin, `max(n)`, with the same bound and the same corruption
    rule.
  - `hasChildren`: `SELECT EXISTS(… WHERE parent_id = :id)`.
  - `isSlugTakenAmongSiblings`: `parent_id IS NOT DISTINCT FROM :parent AND slug = :slug AND
    (:except IS NULL OR id <> :except)`.
- **`PostgresCategoryTreeLock`** implements `CategoryTreeLock`. It uses
  `$connection->transactional(function () use ($work) { $connection->executeStatement('SELECT
  pg_advisory_xact_lock(:k)', ['k' => self::KEY]); return $work(); })`, wrapped in
  `try { … } catch (\Throwable $e) { $this->entityManager->clear(); throw $e; }`.
  - It uses **DBAL `transactional()`, not `EntityManager::wrapInTransaction()`**. The latter
    **closes** the EntityManager on any throwable, so a plain domain refusal (say, a cycle) would
    leave the request with a dead EM. Clearing is the narrower fix: it forgets the half-mutated
    aggregate without disabling the EM.
  - `KEY` is a named `bigint` constant with a docblock (the one key for "the category tree"; the
    pruning job uses Redis and shares nothing with it).
  - A unique violation during `flush()` still closes the EM (Doctrine behaviour). That is why the
    controller re-renders forms from the **DBAL** read model, never from the EM (Risk 2).
- **`DbalCategoryOutline`** is a read model with no port. It is consumed only by Infrastructure
  controllers, CQRS-lite, and reads bypass the Domain the way `ComingSoonController` already does.
  `rows(): list<array{id: string, parentId: ?string, name: string, slug: string, depth: int}>` is
  one recursive CTE from the roots down, with a sort key `array[lower(name), id::text]` accumulated
  per level and `ORDER BY` that path, bounded by `MAX_DEPTH`. It serves the index page and both
  parent `<select>`s (AC-23).

### HTTP / UI

- **`Http/Controller/CategoryAdminController`** carries `#[IsGranted('ROLE_ADMIN')]` at class
  level and has `#[Route('/admin/categories')]` actions:
  - `index` GET → `catalog/admin/index.html.twig` (outline rows; `MAX_DEPTH` passed to the
    template to suppress "Add child")
  - `new` GET|POST (`?parent=` preselects)
  - `edit` GET|POST `{id}`, requirement `Requirement::UUID`. GET reads through the outline;
    an unknown id is a 404.
  - `delete` POST only, `{id}` with `Requirement::UUID`. It checks the token with
    `isCsrfTokenValid('delete-category-'.$id, …)` and answers 403 on failure.

  On a valid submit the controller sends the command, catches each domain exception, adds a
  `FormError` to the field from the failure contract, and re-renders with 422. Success redirects
  303 to the index with a flash. It **does not log** domain refusals (AC-33).
- **`Form/CategoryFormType` + `CategoryFormData`**: `name` (TextType, `required`), `slug` (TextType,
  optional, help text), `parent` (ChoiceType, choices from the outline with labels indented by depth,
  plus `'' => '— none (root) —'`. In edit the category itself is excluded). There are **no business
  constraints** on the form. The VOs are the single arbiter (the `VerifyUserEmailCommand` precedent).
  Slug derivation (D11) lives in the controller or a tiny `CategorySlugSuggester` before the command
  is built, using the injected `SluggerInterface`.
- **Templates** `templates/catalog/admin/{index,new,edit}.html.twig` extend `base.html.twig`.
  They contain no CSS (D9). The index is a `<table>` whose name cell is prefixed with
  one `— ` per level below the root (a `{% for %}` over `1..depth-1`). Each row's delete is a `<form method="post">` with a token, so there is
  no GET delete.
- **`config/packages/security.yaml`**: add `- { path: ^/admin(/|$), roles: ROLE_ADMIN }` with a
  comment on the anchoring, as for `^/profile(/|$)`.

### Identity console

- **`Infrastructure/Identity/Console/GrantRoleCommand`** (`muzbar:identity:grant-role`). Arguments
  are `email` and `role`, plus a `--revoke` flag. It calls `GrantRoleHandler` or `RevokeRoleHandler`
  and maps `UserNotFound`, `InvalidEmail`, `\ValueError` (role) and `CannotRevokeBaseRole` to
  exit 1 with the AC-31 messages. Its output names the email (the operator typed it), and nothing
  is logged.

### DI wiring (`config/services.yaml`)

```
App\Domain\Catalog\Port\CategoryRepository: '@App\Infrastructure\Catalog\Persistence\Doctrine\DoctrineCategoryRepository'
App\Domain\Catalog\Port\CategoryTreeLock:   '@App\Infrastructure\Catalog\Persistence\Doctrine\PostgresCategoryTreeLock'
```

### Async / schedule / external

None.

## Interface boundary & input contract

| Surface | Accepts | Rejects |
|---|---|---|
| `GET /admin/categories` | ROLE_ADMIN | anonymous → `/login`; other → 403 |
| `GET /admin/categories/new?parent=<uuid>` | `parent` optional. An unknown or malformed value is ignored (no preselect). | — |
| `POST /admin/categories/new` | `category_form[name]`, `[slug]` (may be blank), `[parent]` (`''` or an outline id), `[_token]` | per the failure contract, 422 |
| `GET\|POST /admin/categories/{id}/edit` | `{id}` matching `Requirement::UUID`. Same fields as above. | malformed or unknown id → 404 |
| `POST /admin/categories/{id}/delete` | `_token` for `delete-category-{id}` | bad token → 403; unknown → 404; non-leaf → 303 + error flash |
| `muzbar:identity:grant-role <email> <role> [--revoke]` | `role` ∈ `ROLE_USER`, `ROLE_BUSINESS`, `ROLE_ADMIN` | exit 1 (AC-31) |

The Application commands take raw strings. **All parsing happens in the VOs**, and the controller
only maps their exceptions to fields.

## Data & migrations

One additive migration `VersionYYYYMMDDHHMMSS` (`make migration.make`, then reviewed by hand):

```
CREATE TABLE catalog_category (id UUID NOT NULL, parent_id UUID DEFAULT NULL,
  slug VARCHAR(64) NOT NULL, name VARCHAR(100) NOT NULL, PRIMARY KEY (id));
CREATE UNIQUE INDEX uniq_catalog_category_sibling_slug ON catalog_category (parent_id, slug) WHERE (parent_id IS NOT NULL);
CREATE UNIQUE INDEX uniq_catalog_category_root_slug    ON catalog_category (slug)            WHERE (parent_id IS NULL);
```

- **Why two partial indexes, not one `UNIQUE NULLS NOT DISTINCT (parent_id, slug)`** (available
  since PG 15): DBAL's schema tooling neither models nor introspects `NULLS NOT DISTINCT`, so the
  mapping could not declare it and every `make migration.make` would diff it as unwanted. That is
  the ADR-0009 foreign-key argument again. Partial indexes are modelled (`where` option). AC-20
  proves it with an empty diff. **If** the comparator re-diffs the predicate over spacing or
  parentheses, write the `where` option in exactly PostgreSQL's normalised form (`(parent_id IS
  NOT NULL)`) and re-check.
- **Lookup indexes:** none extra. `parent_id = :id` (children, `hasChildren`, the downward CTE)
  is served by the leading column of `uniq_catalog_category_sibling_slug`. Root lookups match the
  root index's predicate. The table holds hundreds of rows. A partial index cannot serve
  `IS NOT DISTINCT FROM`, but at this size a sequential scan is the correct plan anyway.
- **No FK** on `parent_id` (ADR-0009 decision 4: no diff noise, and the integrity comes from D3).
  **No CHECK** constraints: `parent_id <> id` alone catches only a cycle of length 1 and would give
  false comfort.
- Backward compatibility: a new table, and nothing reads it yet. Rollback is `DROP TABLE`.

## Test plan

- **Domain unit (no kernel)**: `CategoryNameTest`, `CategorySlugTest`, `CategoryIdTest` and
  `LineageTest` (AC-1…AC-4). `CategoryTest` covers create depth bounds, cycle via self/child/
  grandchild, the `d + h` boundary at 4/5, root moves, the no-op move, rename and slug change
  keeping id and parent, a refused call leaving state unchanged, and no `releaseEvents` method
  (AC-5…AC-9).
- **Integration (DAMA)**: handlers + `DoctrineCategoryRepository` + `PostgresCategoryTreeLock` on
  the real `muzbar_test` DB (AC-10…AC-21). The `CategoryFactory` (Foundry) builds through
  `Category::create()` via `instantiateWith()`. A `childOf($parent)` state obtains the parent's
  lineage from the real repository.
  - **The lock test (AC-17) and DAMA.** DAMA keeps one outer transaction open per test, so a
    `pg_advisory_xact_lock` taken inside it is held until the **test** ends, not until
    `exclusively()` returns. Test from a **second, non-DAMA connection** (`DriverManager` with the
    same params) using `pg_try_advisory_xact_lock`, called **from inside the work closure**, where
    it must be `false`. The "released afterwards" half is not asserted, and the test docblock says
    why. **Do not write a test that passes only because DAMA holds the lock.** A probe made
    *after* `exclusively()` returns would read `false` under DAMA even if the adapter took no lock
    inside the transaction, so it proves nothing.
  - "Every handler takes the lock" (AC-17, AC-34d): either a recording `CategoryTreeLock` test
    double swapped in by the concrete service id with `disableReboot()` (CLAUDE.md's two-step rule),
    or the second-session probe run from inside a repository double. The recording double is simpler.
  - C-4b (AC-18): plant the cycle with raw SQL, then call the adapter methods directly.
- **Functional**: `CategoryAdminTest` covers authz matrix × all routes (AC-22), index ordering,
  indentation and "Add child" suppression (AC-23), create, `?parent=`, slug derivation incl.
  Cyrillic (AC-24/25), every failure-contract row compared field by field with 422 (AC-26), edit
  and move (AC-27), delete leaf/non-leaf/no GET route (AC-28), CSRF (AC-29) and 404s (AC-30).
  Admins come from `UserFactory::new()->verified()` + `grantRole(Role::Admin)`. Uses
  `ClearsRateLimiters` (login).
- **Console**: `GrantRoleCommandTest` with `CommandTester` (AC-31). `AdminRevocationTest`: sign in
  as admin, revoke through the handler, then the next request is not 200 (AC-32).
- **Bite proof** (AC-34): apply each mutation, watch a named test fail, revert, and record it in the
  commit message.
- **Performance**: none needed. The admin index is one query over hundreds of rows. Phase 2's
  benchmark owns the subtree-expansion cost (D4).

## Risks / open questions

1. **(Q1) `MAX_DEPTH = 4`?** Changing it is a one-constant edit plus tests, as long as no data is
   deeper than the new value.
2. **Doctrine EM state after a failed flush.** A C-3 race lost at the index closes the EM, so the
   re-render must read only through `DbalCategoryOutline` (DBAL). A template or controller that
   touches the EM after a failed save will throw "EntityManager is closed". The functional test for
   the index race (simulate with a raw insert between pre-check and save via a repository double)
   should render the form successfully.
3. **Partial-index predicate diff noise** (AC-20). It is mitigated by the normalised-form fallback
   above. If DBAL still cannot round-trip it, the fallback is `schema_filter`-style exclusion of the
   two index names, *documented in `doctrine.yaml` like `prelaunch_signup`*.
4. **Lost update on simultaneous edits of the same category** (two tabs). Both writes are valid,
   and the second overwrites the first. Accepted for one admin. An optimistic `version` column is
   the upgrade, and the reason not to add it now is that there is no second admin.
5. **Deleting will need guards from contexts Catalog must not depend on** (listings). When
   `Listing` lands, "a category with listings cannot be deleted" needs either a Catalog-owned port
   implemented by Listing's Infrastructure, or archiving. That is decided then, not here (D7).
6. **(Q2) Localised names, (Q3) manual sibling order, (Q4) Tailwind now or later.** All three are
   additive. The defaults are recorded in D13, D12 and D9.
7. **ADR work (T0):** a new **ADR-0016** *"Category tree: per-node aggregates, adjacency list, and
   cross-instance invariants decided by the aggregate under one tree lock"* (D1–D4, D7). A dated
   **ADR-0005 amendment** (D10: how `ROLE_ADMIN` is granted and revoked). A dated **ADR-0009
   amendment** (D8: the shared-UUID trigger fired; still duplicated, and the trait route recorded
   as the answer to its criterion).
