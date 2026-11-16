# Task List: catalog-schema-inheritance

> Ordered, small tasks in DDD canonical order. Each should be reviewable in < 5 minutes and is one
> commit on `feature/catalog-schema-inheritance`. Run `make check` before each commit. Check off as
> you go.

**Status:** Approved 2026-11-16 (owner accepted defaults for Q1, Q2 and Q4; Q3 is answered by running the D10 query before deploy).

**Rules for the whole slice:**
- Nothing under `src/src/Domain/Catalog/` or `src/src/Application/Catalog/` imports another context.
- **Nothing derived is stored** (C-22): no table, column, index or migration. `make migration.make`
  must stay empty.
- Every fact read (`lineageOf`, `descendantsOf`, `codesDefinedOn`) happens **inside**
  `CategoryTreeLock::exclusively()`, next to the save it justifies.
- A changed port lands **with** its adapter and its `Observed*`/`Racing*` test fixtures in the same
  commit (CLAUDE.md: Symfony compiles lazily, so a missing adapter is silent until a route hits it).
- Signature changes keep every commit green: each task updates the existing call sites it breaks
  (factories, unit tests) in the same commit, appending `AttributeCodes::none()` and nothing more.

## Decisions & prerequisites
- [ ] **T0:** `/adr` → **ADR-0018** (plan D1–D6, D10, D11, with the rejected alternatives: override,
      exclusion, global codes, materialised schema, handler pre-check, domain service, trigger) and
      the dated **ADR-0017** and **ADR-0016** amendments (plan Risk 5). No code.

## Domain (`src/src/Domain/Catalog/`)
- [ ] **T1:** VO `AttributeCodes` (sorted, de-duplicated; `of`, `none`, `contains`,
      `firstSharedWith`, `toList`) and `Lineage::ids()`. (AC-1, AC-2)
- [ ] **T2:** Ports + adapters, additive: `CategoryRepository::descendantsOf()` (bounded walk,
      `CorruptCategoryTree`) and `AttributeRepository::codesDefinedOn()` (`[]` → no query), in
      `DoctrineCategoryRepository` / `DoctrineAttributeRepository`, plus the
      `ObservedCategoryRepository`, `ObservedAttributeRepository`, `RacingCategoryRepository` and
      `RacingAttributeRepository` fixtures. (AC-19)
- [ ] **T3:** Exception `AttributeCodeAlreadyTaken::inBranch()`. `Attribute::define…()` and
      `recode()` take `AttributeCodes $codesInBranch` and decide C-21 first (recode to own code is a
      no-op). In the same commit: `DefineAttributeHandler` and `EditAttributeHandler` read the branch
      codes under the lock and pass them in, dropping their C-11 pre-checks; `AttributeFactory` and
      existing `AttributeTest` call sites pass `AttributeCodes::none()`; docblocks per the plan.
      (AC-3, AC-4, AC-9…AC-13)
- [ ] **T4:** Delete `AttributeRepository::isCodeTaken()`: port, adapter, both fixtures, and its
      repository tests. The commit message says the tests went with the method on purpose and that
      AC-12 keeps the index backstop covered.
- [ ] **T5:** Exception `CategoryMoveWouldDuplicateAttributeCode`. `Category::moveUnder()` takes
      `$codesAlongNewParent` and `$codesInSubtree` and checks C-21 after C-4 and C-5. In the same
      commit: `EditCategoryHandler` (gains `AttributeRepository`) reads both code sets under the
      lock only when the parent changes, and its order comment gains C-21 and the D11
      "Listing's move guard goes here" note; existing `CategoryTest` call sites pass `none(), none()`.
      (AC-5, AC-6, AC-14, AC-15)

## Application
*(Folded into T3 and T5: there is no new command, and splitting a signature change from its only
callers would leave a red commit.)*

## Infrastructure
- [ ] **T6:** `DbalAttributeSchema::effectiveFor()` replaces `forCategory()` (one bounded recursive
      query; `definedOnId`, `definedOnName`, `inherited`; root-first order). Existing `forCategory()`
      tests move over. (AC-8, AC-24)
- [ ] **T7:** `attributes.html.twig`: "Defined here" + "Inherited" (read-only, *"from <link>"*, no
      tokens) + the empty state; `AttributeAdminController::index()` uses `effectiveFor()`.
      `attribute_edit.html.twig`: the AC-28 sentence. (AC-23, AC-28)
- [ ] **T8:** Refusal mapping: `AttributeAdminController` shows the AC-25 message for both
      `AttributeCodeAlreadyTaken` constructors; `CategoryAdminController::edit()` maps
      `CategoryMoveWouldDuplicateAttributeCode` to the parent field. No logging. (AC-25…AC-27, AC-29)

## Tests (qa)
- [ ] **T9:** Domain unit: `AttributeCodesTest`, `LineageTest::ids`, `AttributeTest` (C-21 on each
      constructor and recode, check order, own-code no-op), `CategoryTest` (C-21 on move, order vs
      C-4/C-5, current-parent no-op). (AC-1…AC-7)
- [ ] **T10:** Integration: the five-category fixture tree; `AttributeHandlersTest` and
      `CategoryHandlersTest` additions with table snapshots and fresh-repository read-backs;
      repository tests for `descendantsOf` (incl. planted cycle), `codesDefinedOn`, `effectiveFor`;
      the planted legacy duplicate. (AC-8…AC-20)
- [ ] **T11:** `CategoryTreeLockTest` asserts the new fact reads happen inside the lock in the three
      handlers; `NoDdlTest` gains a refused define and a refused move. (AC-21, AC-22)
- [ ] **T12:** Functional: `AttributeAdminTest` (both sections, inherited rows have no controls, one
      query, define/recode refusal on the code field; the slice-2 duplicate-code message assertion
      updated to the AC-25 text, not deleted) and `CategoryAdminTest` (move refusal on the parent
      field). (AC-23…AC-29)
- [ ] **T13:** The D10 detection query as an integration test: it returns exactly the planted
      ancestor/descendant pair and nothing for a sibling-branch reuse.
- [ ] **T14:** Bite proof: apply AC-30 mutations (a)…(g) one at a time; each must fail a named test.
      Revert, record results in the commit message, run `make test` twice. (AC-30, AC-31)

## Docs
- [ ] **T15:** Update the docs:
      - **`CLAUDE.md`:** status line (Catalog 3 of 3); under *Invariants that span many instances*:
        *"C-21 has three doors (define, recode, move): a new write that changes a code or a parent
        must read the branch codes under the lock"*; *"the effective schema is derived, never
        stored"*; `AttributeFactory` bypasses C-21.
      - **`docs/roadmap.md`:** tick the item; hand D11's four points to the `Listing` skeleton item.
      - **`docs/infrastructure.md`:** the D10 detection query, as a one-off pre-deploy step.
      - **`FORboehpyk.md`:** the chapter: "Why a move is the second door", the C-5 parallel, and why
        the override that looked natural would have split every Brand facet in two.

## Verify
- [ ] **T16:** `/verify` → reviewer PASS, AC-1…AC-31 checked, empty `make migration.make`, the
      cross-context `grep` empty. Then by hand on the dev stack:
      1. Build Instruments > Guitars > Electric; define Condition on Instruments.
      2. Confirm Electric's page lists it under "Inherited", with a working "from" link and no
         Delete button.
      3. Try to define `condition` on Electric; try to move a category with a clashing code.
      4. Relabel Condition on Instruments and see it change on Electric.
      5. **Before deploy:** run the D10 query on prod (Q3); zero rows, or recode first.

      If a hand-check contradicts the green suite, suspect the runtime (opcache) before the code.
