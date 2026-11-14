# Feature Spec: catalog-category-tree

> The *what & why*. Disposable: it dies once the feature ships and its behaviour lives in tests +
> code. Make it **executable** (measurable, enumerated), not pretty. Must not contradict the
> [Constitution](../../constitution.md) or an accepted ADR.

- **Feature:** `catalog-category-tree`, slice **1 of 3** of the `Catalog` roadmap item (then
  `catalog-attribute-schema`, then `catalog-schema-inheritance`)
- **Bounded context(s):** `Catalog` (new). It touches `Identity` in exactly one place: an operator
  console command to grant and revoke `ROLE_ADMIN`, built on `User::grantRole()` / `revokeRole()`,
  which already exist and have had no caller until now.
- **Related PRD items:** FR-1 ("admins … dynamically create, edit, and delete instrument
  categories"), PRD §3 *Admin* role, PRD §9 *Schema Drift*
- **Governing ADRs:** [ADR-0004](../../adr/0004-dynamic-category-attribute-schema.md) (categories are
  data, admins never run DDL), [ADR-0005](../../adr/0005-auth-oauth2-google-plus-api-keys.md) (the
  `ROLE_ADMIN` role), [ADR-0006](../../adr/0006-dual-styling-admin-public.md) (admin styling, see
  plan decision D9), [ADR-0007](../../adr/0007-persistence-conventions-for-domain-aggregates.md),
  [ADR-0009](../../adr/0009-email-verification-tokens-modelled-in-the-domain.md) (reference by id, no
  FK, and its 2026-07-29 shared-UUID trigger, which this slice fires),
  [ADR-0012](../../adr/0012-challenge-retention-and-recurring-background-work.md) ("put in the Domain
  the part that can be wrong")
- **Status:** **Approved** by the owner 2026-11-14 as written — all open questions accepted at the
  planner's defaults (depth 4; one untranslated name; siblings sorted by name; unstyled admin pages,
  Tailwind deferred to its own slice; hard delete of childless categories only; generic `grant-role`
  command). See [technical plan](./technical-plan.md) §*Decisions needing sign-off*
- **Date:** 2026-11-14

> **What this slice teaches, beyond the feature.** Identity's aggregates each owned their own rules.
> The one exception, email uniqueness (I-6), was handled with a pre-check plus a unique index. A
> **tree** is where that stops being an exception. Of its interesting invariants, *no cycles*,
> *sibling-unique slug* and *a depth ceiling* each span several `Category` instances. *Only a leaf
> may be deleted* spans instances too. No single per-node aggregate can see enough of the tree to
> enforce any of them on its own. The slice has to choose where each rule lives and say why. The
> answer it gives (plan decisions D1 to D3) is:
>
> - **The aggregate decides.** `Category` gets the facts it needs as arguments: the new parent's
>   *lineage* and its own subtree *height*. Its methods refuse a cycle or an over-deep tree. The rule
>   can be unit-tested without a database.
> - **A tree-wide lock makes those facts true.** Every write reads its facts and commits while
>   holding one Postgres transaction-scoped advisory lock. Without the lock, two concurrent moves
>   (A under B, B under A) would each pass their own check and together create a cycle (write skew).
> - **The database is the backstop where it can be.** Sibling-unique slugs are also guaranteed by
>   partial unique indexes, which follows the I-6 precedent exactly. Cycles and depth have no
>   declarative constraint, and the plan argues against putting them in a trigger.
>
> The plan rejects the obvious "honest" alternative, the whole tree as one aggregate, and argues it
> through. In short: every later reference (`Attribute`, `Listing`, saved searches) points at *one
> category*, and the model has to make that a root.

---

## Ubiquitous language (this slice)

| Term | Means | Not to be confused with |
|---|---|---|
| **Category** | An admin-defined node in the instrument taxonomy ("Bass Guitars", "Effects Pedals"). Aggregate root, identified by `CategoryId`. In later slices it is where attributes attach and what a listing is filed under. | An **attribute** (a structural property such as "String count", slice 2). A listing "type". A product. |
| **Root category** | A category with no parent. Depth 1. | "the root", meaning a single tree root. There is no single root; the roots together form one sibling group. |
| **Parent / child** | The relationship a category holds **by id** (`parentId`). A category knows its parent, not its children. | A Doctrine association. There is none. |
| **Siblings** | Categories with the same parent. All roots are siblings of each other. | |
| **Leaf** | A category with no children. Only a leaf can be deleted (C-7). | |
| **Lineage** | Value object: the ordered list of `CategoryId`s from a root down to **and including** one category. The lineage of a root is `[root]`. **The empty lineage stands for "no parent"**, meaning a placement at root level. | A slug *path*. A lineage is ids only and is never stored. |
| **Depth** | Number of categories in a category's lineage. A root has depth 1. **Never stored**, because storing it would make every move rewrite every descendant. | Height. |
| **Subtree height** | The number of levels in the subtree rooted at a category, counting the category itself. A leaf has height 1. | Depth. |
| **Depth ceiling** | `Category::MAX_DEPTH = 4`. No category is ever deeper. | |
| **Slug** | The URL-safe, lower-case, ASCII key of a category, **unique among its siblings** (`electric`, `5-string-basses`). Admin-editable. | The name. The id. A slug is not stable and nothing may reference a category by slug. |
| **Category path** | The slugs of a lineage joined with `/` (`/guitars/electric`). Derived, not stored, and not used in this slice. Public URLs will use it later. | Lineage. |
| **Move** | Changing a category's parent, taking its whole subtree with it. Only the moved category's row changes. | Re-ordering. There is no manual order in this slice. |
| **Tree lock** | The one exclusive, transaction-scoped lock every category write holds while it reads facts and commits (`CategoryTreeLock` port). | A row lock, or the Redis lock of the pruning job (ADR-0012). |
| **Admin** | A signed-in user holding `ROLE_ADMIN`. Granted only by the operator from the console. | A "business user" (`ROLE_BUSINESS`). |

### Invariants (numbered; Catalog uses its own `C-n` series, Identity's `I-n` is unaffected)

| # | Invariant | Who enforces it | Backstop |
|---|---|---|---|
| **C-1** | A name is 1–100 characters after trimming and contains no control characters. | `CategoryName` VO | `varchar(100)` |
| **C-2** | A slug matches `^[a-z0-9]+(?:-[a-z0-9]+)*$` and is 1–64 characters long. It is stored lower-cased and trimmed. | `CategorySlug` VO | `varchar(64)` |
| **C-3** | **Sibling-unique slug:** no two categories with the same parent share a slug. Roots count as one sibling group. | Application pre-check under the tree lock, which produces the error message | **Two partial unique indexes.** This is the real guarantee. The adapter translates SQLSTATE 23505 into `CategorySlugAlreadyTaken` (the I-6 pattern). |
| **C-4** | **No cycles:** a category is never in its own parent's lineage. That covers moving it under itself or under any of its descendants. | `Category::moveUnder()`, using the lineage the handler reads under the tree lock | None declarative. Readers bound their recursion (C-4b). |
| **C-4b** | **A corrupted tree is never trusted.** A lineage or height walk that exceeds `MAX_DEPTH + 1` steps throws. It does not loop. | Repository adapter | None |
| **C-5** | **Depth ceiling:** no category is deeper than `MAX_DEPTH = 4`. On create, `parentLineage.depth() + 1 ≤ 4`. On move, `newParentLineage.depth() + subtreeHeight ≤ 4`. | `Category::create()` / `moveUnder()`, using facts read under the tree lock | None |
| **C-6** | A category's parent exists. | Handler under the tree lock (`lineageOf()` throws `CategoryNotFound`). C-7 holds under the same lock. | None. No FK (ADR-0009 decision 4). |
| **C-7** | Only a leaf may be deleted. | Handler under the tree lock (`hasChildren()`) | None |
| **C-8** | A category's id never changes. Rename, slug change and move all keep it, so references by id from later slices survive every edit. | `Category` has no id setter. `CategoryId` is a VO. | PK |

---

## User story

As an **admin**, I want to **create, rename, re-slug, move and delete instrument categories in a
tree from an admin screen**, so that **the taxonomy that listings and facets will hang off can be
shaped at runtime without a developer or a migration** (FR-1, ADR-0004).

As **muzbar**, I want **the tree to be impossible to corrupt**: no cycles, no duplicate URL segment
under one parent, no unbounded depth, no orphans. That has to hold even with two admin tabs open,
because every later slice (attributes, inheritance, search subtree filters) assumes a sound tree.

As the **operator**, I want **one console command to make an account an admin and to take that
away again**.

Secondary and ranked (Constitution §2): as the **developer**, I want this slice to show honestly
what to do with invariants that span instances of one aggregate type.

---

## In scope

**Domain (`Catalog`, new):** the `Category` aggregate (`create`, `rename`, `changeSlug`,
`moveUnder`) and the VOs `CategoryId`, `CategoryName`, `CategorySlug` and `Lineage`. Domain
exceptions. Two ports: `CategoryRepository` and `CategoryTreeLock`.

**Application:** commands `CreateCategory`, `EditCategory`, `DeleteCategory` with their handlers.
`Identity` gets `GrantRole` and `RevokeRole` with their handlers.

**Infrastructure:** the XML mapping, three DBAL types, `DoctrineCategoryRepository`, the
`PostgresCategoryTreeLock` adapter (advisory lock + transaction), a read model `DbalCategoryOutline`
(recursive CTE) for the admin screens, one additive migration, `CategoryAdminController` + one form
type + three Twig pages under `/admin/categories`, `access_control` for `^/admin(/|$)`, and the
console command `muzbar:identity:grant-role`.

---

## Non-goals (explicit, hold the line)

| Deliberately excluded | Belongs to / why |
|---|---|
| Attributes, options, attaching a schema to a category | `catalog-attribute-schema` |
| A child inheriting its ancestors' attributes, and what a **move** does to the attributes a subtree inherits | `catalog-schema-inheritance`. That slice decides what a move means and adds the event it needs (see "no domain events" below). |
| **Domain events** (`CategoryCreated`, `CategoryMoved`, …) | No consumer exists in this slice or in slice 2. The first plausible consumer is inheritance (a move changes inherited attributes) or a search cache. It should define the payload it needs, for example old *and* new lineage for a move. Designing that payload now, with no reader, is the speculative part. Plan D6. |
| **Public** category pages, navigation, breadcrumbs, category URLs | Phase 2 (public faceted search UI) |
| Redirects from an old slug or path after a rename or move | Lands with public category URLs. Until then nothing public links to a slug. **Rule for every later slice: persist `CategoryId`, never a slug or path.** |
| **Manual ordering** of siblings (drag and drop, `position`) | Later, if wanted (open question Q3). Siblings are ordered by name, case-insensitively. A `position` column would be additive. |
| **Localised** category names (en/ru/uk …) | Later, if wanted (open question Q2). The PRD does not require it. One name per category. A translation map would be an additive `jsonb` value collection. |
| Soft delete, archive, "category in use" checks against listings or attributes | Slice 2 and the `Listing` slice add their own guards when references exist. Today nothing references a category, so deleting a leaf is a hard `DELETE`. Plan D7. |
| Sibling-unique **names** | Not an invariant. Slugs disambiguate. |
| Tailwind / the admin stylesheet pipeline (ADR-0006) | Plan D9 / open question Q4. Admin pages are unstyled semantic HTML, like every existing page. |
| Live Components, drag-and-drop tree editing, JavaScript | Plain server-rendered forms are enough for one admin |
| A seeded starter taxonomy, import/export | Entered by hand through the admin UI |
| An audit log of admin actions or role grants | Later, if needed |
| A shared `Uuid` base for ids | Plan D8. `CategoryId` is written out, like the three Identity ids. |

---

## Acceptance criteria (the Definition of Done checklist)

### Values and the aggregate (Domain unit tests, no kernel)

- [x] **AC-1 (C-1):** `CategoryName` trims surrounding whitespace and accepts 1–100 characters,
      counted with `mb_strlen`, so `str_repeat('я', 100)` is accepted. It rejects an empty or
      whitespace-only string, 101 characters, and any string containing a character in `\p{Cc}`
      (e.g. `"Bass\nGuitars"`). Every rejection throws `InvalidCategoryName`.
- [x] **AC-2 (C-2):** `CategorySlug` trims and lower-cases, so `" Bass-Guitars "` becomes
      `bass-guitars`. It then accepts `5-string`, `a` and 64 characters. It rejects `""`, 65
      characters, `-bass`, `bass-`, `bass--guitars`, `bass_guitars`, `bass guitars`, `basś` and `/`.
      Every rejection throws `InvalidCategorySlug`.
- [x] **AC-3:** `CategoryId` accepts an RFC 4122 string, normalises it to lower case, and rejects
      anything else with `InvalidCategoryId`. `equals()` is typed to `CategoryId`, so passing a
      `UserId` is a PHPStan error and a `TypeError` at runtime.
- [x] **AC-4:** `Lineage::ofRoot()` has depth 0 and no last element. `Lineage::fromRootDown([a, b,
      c])` has depth 3, `last() == c`, `contains(b) === true`, and `contains(x) === false` for an id
      not in the list. A list containing a duplicate id is rejected with `InvalidLineage` (a
      duplicate would mean a cycle).
- [x] **AC-5 (C-5, create):** `Category::create()` with `Lineage::ofRoot()` gives `parentId() ===
      null`. With a lineage of depth 3 it gives `parentId()` equal to the lineage's last id. With a
      lineage of depth 4 it throws `CategoryTreeTooDeep`.
- [x] **AC-6 (C-4):** `moveUnder()` throws `CategoryMoveWouldCreateCycle` when the new parent's
      lineage contains the category's own id. That covers moving under itself, under a child and
      under a grandchild. `parentId()` is unchanged afterwards.
- [x] **AC-7 (C-5, move):** `moveUnder(lineage of depth d, height h)` succeeds when `d + h == 4`
      and throws `CategoryTreeTooDeep` when `d + h == 5`, leaving `parentId()` unchanged.
      `moveUnder(Lineage::ofRoot(), h)` succeeds for every `h ≤ 4`. A height below 1 is a
      programming error (`\InvalidArgumentException`).
- [x] **AC-8:** `rename()` and `changeSlug()` replace the value and leave `id()` and `parentId()`
      untouched (C-8). Moving under the current parent is a no-op that does not throw.
- [x] **AC-9:** `Category` records no domain events. It does not use the `RecordsEvents` trait.
      Nothing in `src/src/Domain/Catalog/` or `src/src/Application/Catalog/` imports anything from
      `App\Domain\Identity` (checked by `grep` in `/verify`).

### Use cases against a real database (Application/Integration, DAMA)

- [x] **AC-10 (create):** `CreateCategory("Guitars", "guitars", parent: null)` persists one row in
      `catalog_category` with `parent_id IS NULL`. A child created under it has
      `parent_id = <guitars id>`. A great-great-grandchild (depth 5) is refused with
      `CategoryTreeTooDeep`, and the row count is unchanged.
- [x] **AC-11 (C-3):** A second root with slug `guitars` is refused with `CategorySlugAlreadyTaken`.
      So is a second `electric` under `guitars`. `electric` under `basses` is **accepted**. The same
      rule applies when the slug comes from `EditCategory` (slug change) or from a **move** into a
      parent that already has a child with that slug.
- [x] **AC-12 (C-3 backstop):** Inserting a duplicate `(parent_id, slug)` with raw DBAL fails with
      a unique violation, and so does inserting a duplicate root slug. When the pre-check is
      bypassed, `DoctrineCategoryRepository::save()` raises `CategorySlugAlreadyTaken`, not a DBAL
      exception.
- [x] **AC-13 (C-4, C-5 through the handler):** On the tree `guitars > electric > solid-body`,
      `EditCategory` moving `guitars` under `solid-body` throws `CategoryMoveWouldCreateCycle`. Moving
      `electric` (height 2) under a depth-3 node throws `CategoryTreeTooDeep`. Moving `electric` to
      the root succeeds and leaves `solid-body.parent_id` unchanged, so **exactly one row is
      updated**. After every refusal the table is byte-for-byte unchanged.
- [x] **AC-14 (C-6):** `CreateCategory` or `EditCategory` naming a parent id that does not exist
      (including a malformed id) throws `CategoryNotFound`, and no row is written. `EditCategory` or
      `DeleteCategory` on an unknown id throws `CategoryNotFound`.
- [x] **AC-15 (C-7):** `DeleteCategory` on a leaf removes its row. On a category with a child it
      throws `CategoryHasChildren`, and both rows remain.
- [x] **AC-16 (EditCategory is all-or-nothing):** An edit that changes the name **and** makes an
      invalid move (cycle or depth) persists **neither** change. Re-reading the row from a fresh
      repository shows the old name. A follow-up `flush()` in the same test does not write the
      half-applied rename either, because the lock adapter clears the EntityManager on failure.
- [x] **AC-17 (the tree lock is real):** While a handler's work is running inside
      `CategoryTreeLock::exclusively()`, a **second** database session's
      `pg_try_advisory_xact_lock(<tree key>)` returns `false`. Every one of the three handlers runs
      its reads and its save inside `exclusively()`. This is proven by a test that fails when the
      `exclusively()` call is removed from any one of them. Release at commit is PostgreSQL's
      `_xact_` semantics, not our code. It is not asserted, because DAMA's outer transaction holds
      the lock until the test ends (plan, *Test plan*).
- [x] **AC-18 (C-4b):** With a cycle planted by raw SQL (`a.parent = b`, `b.parent = a`),
      `lineageOf(a)` and `heightOf(a)` each throw `CorruptCategoryTree` (a `\LogicException`
      naming the id) after at most `MAX_DEPTH + 1` recursion steps. Neither hangs. The SQL bounds
      the recursion; PHP does not detect the cycle after the fact.

### Persistence conventions (ADR-0007 / ADR-0009)

- [x] **AC-19:** Table `catalog_category` has `id uuid PK`, `parent_id uuid NULL`,
      `slug varchar(64) NOT NULL` and `name varchar(100) NOT NULL`. It has **no foreign key** and
      **no `<many-to-one>`**. It has the two partial unique indexes
      `uniq_catalog_category_sibling_slug (parent_id, slug) WHERE parent_id IS NOT NULL` and
      `uniq_catalog_category_root_slug (slug) WHERE parent_id IS NULL`. Both are declared in the XML
      **and** in the migration.
- [x] **AC-20:** After `make migrate`, `doctrine:schema:validate` passes and `make migration.make`
      generates **no** migration. This is the proof that the partial-index predicates do not show up
      as diff noise.
- [x] **AC-21:** Ids are UUIDv7 minted by `CategoryRepository::nextIdentity()`, using
      `<generator strategy="NONE"/>`. The DBAL types are registered as `catalog_category_id`,
      `catalog_category_name` and `catalog_category_slug`. The mapping lives at
      `src/Infrastructure/Catalog/Persistence/Doctrine/mapping/Category.orm.xml` in a `Catalog`
      mapping block of its own.

### Admin HTTP surface (Functional)

- [x] **AC-22 (authz):** For every route under `/admin` (`GET /admin/categories`,
      `GET|POST /admin/categories/new`, `GET|POST /admin/categories/{id}/edit` and
      `POST /admin/categories/{id}/delete`):
      - an **anonymous** request is redirected to `/login`, and no row changes;
      - a verified user with only `ROLE_USER` gets **403**, and no row changes;
      - a `ROLE_ADMIN` user gets 200 on the GET routes (or 303 after a successful POST).

      The `access_control` rule is anchored as `^/admin(/|$)`, and a request to `/administrator`
      is not affected by it. The controller also carries `#[IsGranted('ROLE_ADMIN')]`.
- [x] **AC-23 (index):** `GET /admin/categories` lists every category depth-first. Each row is
      indented by its depth and shows name and slug. Siblings are ordered by name,
      case-insensitively, with id as the tie-break. Each row has "Edit", "Delete" and "Add child".
      **"Add child" is absent on depth-4 rows.** There is one "Add root category" link. The page
      issues a **single** SQL query for the tree.
- [x] **AC-24 (create via form):** Submitting the new-category form with name, an optional slug and
      a parent chosen from a select ("— none (root) —" plus every category, indented) creates the
      category. The response is a 303 to `/admin/categories` with a flash naming it.
      `/admin/categories/new?parent=<id>` preselects that parent.
- [x] **AC-25 (slug derivation):** A blank slug is derived from the name with Symfony's
      `AsciiSlugger`, lower-cased and cut to 64 characters with no trailing hyphen.
      `"Bass & Guitars"` gives `bass-guitars` and `"Электрогитары"` gives `elektrogitary`. If
      derivation gives an empty string (e.g. name `"!!!"`), the form shows *"Enter a slug: one could
      not be derived from this name."* on the slug field, and nothing is persisted.
- [x] **AC-26 (errors render on the right field, status 422):** Each domain refusal re-renders the
      form with HTTP **422**, nothing persisted, and the exact message on the field given in the
      failure contract below. Every other field keeps the value that was submitted.
- [x] **AC-27 (edit):** The edit form is pre-filled. One submit can rename, change the slug and move.
      Its parent select lists every category except the edited one. A move into a descendant is
      still possible by tampering, and the domain refuses it (AC-26).
- [x] **AC-28 (delete):** `POST /admin/categories/{id}/delete` with a valid CSRF token deletes a
      leaf and redirects 303 with a flash. For a non-leaf it redirects 303 with the error flash
      *"“<name>” has subcategories. Move or delete them first."* and deletes nothing. There is **no
      GET route that deletes** (ADR-0015's accepted risk: no GET may change state).
- [x] **AC-29 (CSRF):** A create, edit or delete POST with a missing or wrong CSRF token mutates
      nothing. Create and edit re-render with 422. Delete answers **403**.
- [x] **AC-30 (unknown ids):** `GET /admin/categories/<valid but unknown uuid>/edit` and the delete
      POST for such an id return **404**. A malformed `{id}` also returns 404 (route requirement
      `Requirement::UUID`). Neither case writes anything.

### Admin role (Identity, console)

- [x] **AC-31:** `muzbar:identity:grant-role <email> ROLE_ADMIN` grants the role and exits 0, and
      running it again is still exit 0 (idempotent, I-3). `--revoke` removes the role and exits 0.
      An unknown email exits 1 with *`No user found with email "<email>"`*. An unknown role exits 1
      and lists the valid values. `--revoke ROLE_USER` exits 1 with the `CannotRevokeBaseRole`
      message and changes nothing.
- [x] **AC-32 (revocation bites immediately):** An admin who is signed in and then has `ROLE_ADMIN`
      revoked from the console does **not** get a 200 from `/admin/categories` on their next request.
      Symfony's user refresh sees the role change. The test asserts this; it does not assume it.

### Cross-cutting

- [x] **AC-33:** Domain refusals (every row of the failure contract marked *user error*) are **not**
      logged at `error`, so they never reach Sentry (ADR-0013). C-4b corruption **is**: it surfaces
      as an uncaught `\LogicException` and a 500.
- [x] **AC-34 (tests bite):** Each of these mutations makes a named test fail, and the commit
      message records which:
      (a) delete the cycle guard in `moveUnder()`;
      (b) change `+ subtreeHeight` to `+ 1`;
      (c) drop one partial unique index from the migration;
      (d) remove `exclusively()` from `EditCategoryHandler`;
      (e) remove `access_control`'s `/admin` line **and** the `#[IsGranted]`;
      (f) remove the recursion bound in `lineageOf()`.
- [x] **AC-35:** `make check` is green, and `make test` passes **twice in a row**. Deptrac reports
      0 violations with `--fail-on-uncovered`.

---

## Failure contract

| Condition | Expected behaviour | Kind |
|---|---|---|
| Name empty, too long or containing control characters | `InvalidCategoryName`. 422. **name** field: *"Name must be 1–100 characters, without line breaks."* | user error |
| Slug malformed or too long | `InvalidCategorySlug`. 422. **slug** field: *"Use lower-case letters, digits and single hyphens (max 64)."* | user error |
| Slug blank and not derivable | 422. **slug** field: *"Enter a slug: one could not be derived from this name."* No command is sent. | user error |
| Slug taken among the target siblings (pre-check **or** unique-index race) | `CategorySlugAlreadyTaken`. 422. **slug** field: *"“<slug>” is already used by another category at this level."* | user error |
| Parent would make the tree deeper than 4 | `CategoryTreeTooDeep`. 422. **parent** field: *"Categories can be at most 4 levels deep."* | user error |
| Move under itself or a descendant | `CategoryMoveWouldCreateCycle`. 422. **parent** field: *"A category cannot be moved inside itself."* | user error |
| Parent id unknown or malformed (tampered form) | Symfony's choice validation rejects it first. If it gets through, `CategoryNotFound` gives 422 on the **parent** field: *"That parent no longer exists."* | user error |
| Edit or delete of an unknown or malformed id | 404 | user error |
| Delete of a non-leaf | `CategoryHasChildren`. 303 to the index with the error flash (AC-28) | user error |
| Missing or invalid CSRF token | No mutation. 422 on create/edit, 403 on delete | user error |
| Anonymous on `/admin…` | Redirect to `/login` (the firewall entry point stashes the target, ADR-0015) | authz |
| Signed in without `ROLE_ADMIN` | 403 | authz |
| Second admin writes while a write is in progress | Waits on the advisory lock (milliseconds), then runs against the committed tree. It never sees half a write. | concurrency |
| Two edits of the same category in two tabs | Last write wins. Both are serialized and both pass the invariants. Accepted: there is one admin (plan Risk 4). | concurrency |
| Cycle or chain longer than `MAX_DEPTH + 1` found in stored data (C-4b) | `\LogicException` naming the id. 500. Logged at `error` and reported to Sentry. No write. | corruption |
| Postgres down | The usual 500, logged at error. No partial write is possible, because each command is one transaction. | dependency |
| `grant-role` with an unknown email, unknown role, or revoking `ROLE_USER` | Exit 1 with the message in AC-31. Nothing changes. | operator error |
