# Task List: catalog-category-tree

> Ordered, small tasks in DDD canonical order. Each should be reviewable in < 5 minutes and is one
> commit on `feature/catalog-category-tree`. Run `make check` before each commit. Check off as you go.

**Approved by the owner 2026-11-14** — technical plan D1–D13 and Q1 (`MAX_DEPTH = 4`), Q2 (no
localised names), Q3 (alphabetical sibling order) and Q4 (no Tailwind yet).

**Rules for the whole slice:**
- Nothing under `src/src/Domain/Catalog/` or `src/src/Application/Catalog/` imports
  `App\Domain\Identity\…` (AC-9).
- No `<many-to-one>`, no FK, no `RecordsEvents` in Catalog.

## Decisions & prerequisites
- [x] **T0:** `/adr` → **ADR-0016** *"Category tree: per-node aggregates on an adjacency list;
      cross-instance invariants decided by the aggregate on facts read under one tree lock"* (plan
      D1–D4, D7, with the rejected whole-tree aggregate, trigger, SERIALIZABLE, Redis lock, ltree,
      materialized path, nested set and closure table). Also a dated **ADR-0005 amendment** (D10:
      `ROLE_ADMIN` granted and revoked only from the console; revocation bites on the next request)
      and a dated **ADR-0009 amendment** (D8: the shared-UUID trigger fired at `CategoryId`; still
      duplicated; the trait route meets the criterion and is proposed as its own refactor). No code.

## Domain (`src/src/Domain/Catalog/`)
- [x] **T1:** VOs `CategoryId` (the `UserId` shape, docblock naming D8), `CategoryName` (C-1) and
      `CategorySlug` (C-2), plus `InvalidCategoryId`, `InvalidCategoryName` and
      `InvalidCategorySlug`. (AC-1…AC-3)
- [x] **T2:** VO `Lineage` (`ofRoot`, `fromRootDown`, `depth`, `last`, `contains`) plus
      `InvalidLineage`. The docblock says it is only true while the tree lock is held. (AC-4)
- [x] **T3:** Aggregate `Category` with `MAX_DEPTH = 4`, `create()`, `moveUnder()` (C-4 checked
      before C-5, no-op on the same parent), `rename()`, `changeSlug()` and the readers. Plus
      `CategoryTreeTooDeep` and `CategoryMoveWouldCreateCycle`. The class docblock states the D2
      contract. (AC-5…AC-9)
- [x] **T4:** Ports `CategoryRepository` and `CategoryTreeLock` (generic `@template T` docblock on
      `exclusively`). Plus `CategoryNotFound`, `CategorySlugAlreadyTaken`, `CategoryHasChildren`
      and `CorruptCategoryTree` (the last one extends `\LogicException`).

## Application
- [x] **T5:** `Application/Catalog`: `CreateCategory` + handler (returns `CategoryId`) and
      `DeleteCategory` + handler, each fully inside `exclusively()`. (C-3 pre-check, C-6, C-7)
- [x] **T6:** `EditCategory` + handler: move, then rename, then reslug, then the sibling pre-check
      with `except`, then save, all in one `exclusively()`. Includes the call-site comment on the
      order.
- [x] **T7:** `Application/Identity`: `GrantRole` / `RevokeRole` + handlers over the existing
      `User::grantRole()` / `revokeRole()`.

## Infrastructure
- [x] **T8:** DBAL types `CategoryIdType`, `CategorySlugType` and `CategoryNameType`. Register them
      in `doctrine.yaml` together with a new `Catalog:` mappings block. Add `Category.orm.xml`
      (plain `parent_id` column, the two named partial unique constraints, and a header comment
      citing ADR-0007/0009 and plan D4). (AC-19, AC-21)
- [x] **T9:** Migration (generated, then reviewed by hand). Run `make migrate`, then
      `make migration.make` and confirm **it generates nothing**. If it does generate something,
      apply the normalised-predicate fallback (plan *Data & migrations*). (AC-19, AC-20)
- [x] **T10:** `DoctrineCategoryRepository`: `nextIdentity` (v7), `get`, `save` with 23505 →
      `CategorySlugAlreadyTaken`, `remove`, and the bounded recursive-CTE `lineageOf` and
      `subtreeHeightOf` (bound = `Category::MAX_DEPTH + 1`, corruption → `CorruptCategoryTree`).
      Also `hasChildren` and `isSlugTakenAmongSiblings` (`IS NOT DISTINCT FROM`). (AC-12, AC-18)
- [x] **T11:** `PostgresCategoryTreeLock`: DBAL `transactional()`, then
      `pg_advisory_xact_lock(KEY)`, then `$work()`. Calls `EntityManager::clear()` on a throwable.
      The comment explains why this is not `wrapInTransaction()`. Wire both ports in
      `services.yaml`. (AC-16, AC-17)
- [x] **T12:** `DbalCategoryOutline`: one depth-first recursive CTE ordered by
      `lower(name), id` per level. It returns the rows, including `depth`. (AC-23)
- [x] **T13:** `CategoryFormType` + `CategoryFormData` (no business constraints) and slug derivation
      with `SluggerInterface` (lower-cased, cut to 64, trailing hyphen trimmed, empty → the slug
      field error). (AC-25)
- [x] **T14:** `CategoryAdminController` (`#[IsGranted('ROLE_ADMIN')]`, `Requirement::UUID`) with
      index, new (`?parent=`), edit and POST-only delete (token `delete-category-{id}`, 403 on a bad
      token). Maps domain exceptions to fields per the failure contract with 422, and logs nothing.
      Templates `templates/catalog/admin/{index,new,edit}.html.twig`, unstyled, with "Add child"
      suppressed at depth 4. In `security.yaml`, add `^/admin(/|$)` → `ROLE_ADMIN` with the
      anchoring comment. (AC-22…AC-30, AC-33)
- [x] **T15:** `GrantRoleCommand` (`muzbar:identity:grant-role <email> <role> [--revoke]`), with exit
      codes and messages per AC-31.

## Tests (qa)
- [x] **T16:** Domain unit tests: `CategoryNameTest`, `CategorySlugTest`, `CategoryIdTest`,
      `LineageTest` and `CategoryTest`. No kernel. (AC-1…AC-9)
- [x] **T17:** `CategoryFactory` (Foundry, `instantiateWith()` → `Category::create()`, plus a
      `childOf()` state using the real repository's lineage). Integration tests for the handlers and
      the adapter: create/edit/delete, C-3 including moves and the raw-SQL backstop, C-4/C-5 through
      `EditCategory`, one-row moves, all-or-nothing edit plus the follow-up `flush()`, C-6/C-7, and
      the planted-cycle C-4b. Read through a fresh repository before every assertion that follows a
      write. (AC-10…AC-16, AC-18, AC-21)
- [x] **T18:** Lock tests: the second-connection `pg_try_advisory_xact_lock` probe from inside the
      closure, and the "every handler takes the lock" recording double (concrete service id +
      `disableReboot()`). The docblock explains why release is not asserted under DAMA. (AC-17)
- [x] **T19:** Functional `CategoryAdminTest`: authz matrix over all routes, index order,
      indentation and suppression, create with `?parent=`, Cyrillic slug derivation, every
      failure-contract row (exact message, field, 422, other fields keep their values), edit and
      move, delete leaf/non-leaf, no GET delete, CSRF, and the 404s. Also the C-3 race re-render
      reading only through DBAL (plan Risk 2). Uses `ClearsRateLimiters`. (AC-22…AC-30, AC-33)
- [x] **T20:** `GrantRoleCommandTest` (`CommandTester`) and `AdminRevocationTest` (signed-in admin,
      revoked, next request not 200). (AC-31, AC-32)
- [x] **T21:** Prove the tests bite with AC-34's mutations (a)–(f): watch each one fail, revert,
      and record the results in the commit message. Then run `make test` twice. (AC-34, AC-35)

## Docs
- [x] **T22:** CLAUDE.md: status line (Catalog slice 1 shipped); the cross-instance-invariant
      rule (*aggregate decides on facts, the tree lock makes them true, an index is the backstop
      where one exists*); `CategoryTreeLock` + DAMA (the lock is held until the test ends); the EM
      after a failed flush (use the DBAL outline to re-render); the `grant-role` command in
      *Commands*; *"persist `CategoryId`, never a slug or path"*. `docs/roadmap.md`: tick the
      `catalog-category-tree` sub-item. `FORboehpyk.md`: the chapter (the tree as the first rule an
      aggregate cannot hold alone; write skew explained with two admins; why adjacency won for
      DDD reasons, not speed).

## Verify
- [ ] **T23:** `/verify` → reviewer PASS, AC-1…AC-35 checked. `grep -rn 'Domain\\Identity'
      src/src/Domain/Catalog src/src/Application/Catalog` is empty. `make migration.make` produces
      no diff. Manually walk through the admin screens on the dev stack (create a 4-level branch,
      attempt a cycle, attempt depth 5, delete a non-leaf), and if a hand-check contradicts the
      green suite, suspect the runtime before the code (CLAUDE.md, opcache).
