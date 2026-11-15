# Task List: catalog-attribute-schema

> Ordered, small tasks in DDD canonical order. Each should be reviewable in < 5 minutes and is one
> commit on `feature/catalog-attribute-schema`. Run `make check` before each commit. Check off as you go.
**Status:** Approved 2026-11-15 (D1–D14; Q1–Q10 answered — Q10: unpaginated option list is fine for now).

**Rules for the whole slice:**
- Nothing under `src/src/Domain/Catalog/` or `src/src/Application/Catalog/` imports another
  bounded context (AC-11).
- No `<many-to-one>`/`<one-to-many>`, no FK, no `RecordsEvents`, no expression index, no
  `position`, no `parent_option_id`.
- **Every** new Catalog write handler runs entirely inside `CategoryTreeLock::exclusively()` (D5).
- Admin controllers read through DBAL read models, never the EntityManager (D11).

## Decisions & prerequisites
- [x] **T0:** `/adr` → write the decision records first. No code in this task.
      - **ADR-0017** *"Attribute schema: attributes and options as separate aggregates referencing
        by id; set-rules on options as cross-instance invariants under the tree lock; definitions
        stored once on the defining category"* (D1–D4, D6, D13, D14). It includes the rejected
        jsonb-inside-`Attribute` design (unbounded sets, dependent options, per-parent uniqueness,
        direct references from listings), the rejected expression index (DBAL re-diff), and the
        `@internal` `create()` convention.
      - Dated **ADR-0004 amendment:** hard delete until values exist; `is_facet`/`is_searchable`
        deferred. Its table sketch stands.
      - Dated **ADR-0016 amendment:** the lock covers attribute writes and the cross-aggregate
        option invariants C-17/C-18; C-13 is the first accreted delete guard.

## Domain (`src/src/Domain/Catalog/`)
- [x] **T1:** VOs `AttributeId`, `AttributeOptionId` + exceptions. (AC-3)
- [x] **T2:** VOs `AttributeLabel` (with `key()`) and `AttributeCode` + exceptions. (AC-1, AC-2)
- [x] **T3:** VOs `Unit`, `NumberRules` + exceptions; enum `AttributeType`. (AC-4)
- [x] **T4:** Aggregate `Attribute`: the four `define…` constructors (`defineChoice` checks the
      initial labels: non-empty, distinct `key()`), `relabel`, `recode`, `changeRequired`,
      `changeNumberRules`, readers. Exceptions: `InvalidAttributeOptions`,
      `DuplicateAttributeOption`, `AttributeRuleNotApplicable`. Docblock: D1, D2, D4, the
      facts-under-the-lock contract. (AC-5, AC-6, AC-10)
- [x] **T5:** Aggregate `AttributeOption` (`@internal create()`, `relabel()` sets label and
      labelKey together) plus `Attribute::newOption()` and `Attribute::allowOptionRemoval()`.
      Exceptions: `AttributeNeedsAnOption`, `AttributeOptionNotFound`. (AC-7…AC-9)
- [ ] **T6:** Ports `AttributeRepository` and `AttributeOptionRepository`. Exceptions:
      `AttributeNotFound`, `AttributeCodeAlreadyTaken`, `CategoryHasAttributes`. Add one sentence to
      `CategoryTreeLock`'s docblock (D5).

## Application (`src/src/Application/Catalog/`)
- [ ] **T7:** `DefineAttribute` + handler: C-12, C-11 pre-check, options built through
      `newOption()`, **options saved before the attribute**, with a call-site comment naming D13
      and D7's opposite order. (AC-12…AC-14, AC-19)
- [ ] **T8:** `EditAttribute` + handler. Number rules are applied for a number attribute; for any
      other type the aggregate refuses them. C-11 pre-check with `except`. All-or-nothing.
      (AC-15, AC-22)
- [ ] **T9:** `AddAttributeOption`, `RelabelAttributeOption`, `RemoveAttributeOption` + handlers:
      the C-17 pre-check, the ownership check, and `allowOptionRemoval(countForAttribute())`.
      (AC-17, AC-20, AC-21)
- [ ] **T10:** `DeleteAttribute` + handler: the attribute is removed first, then
      `removeAllForAttribute()`, with a call-site comment naming D7. Also add the C-13 guard in
      `DeleteCategoryHandler`, after C-7. (AC-23, AC-24)

## Infrastructure
- [ ] **T11:** DBAL types (`AttributeIdType`, `AttributeOptionIdType`, `AttributeCodeType`,
      `AttributeLabelType`, `NumberRulesType extends JsonbType`), registered in `doctrine.yaml`.
      `Attribute.orm.xml` and `AttributeOption.orm.xml`: plain id columns, `enum-type`, the two
      named unique constraints, and header comments covering D3/D14. (AC-28, AC-29, AC-31)
- [ ] **T12:** Migration, generated and reviewed by hand. Run `make migrate`, then
      `make migration.make`: it must generate **nothing**. (AC-29, AC-30)
- [ ] **T13:** `DoctrineAttributeRepository` and `DoctrineAttributeOptionRepository`: v7 ids;
      23505 → domain exception; DBAL fact reads on the same connection; variadic `save()` with one
      flush; set-based `removeAllForAttribute()`. Add the `services.yaml` port aliases.
      (AC-16, AC-18)
- [ ] **T14:** `DbalAttributeSchema`: `forCategory()` (one query including the option counts),
      `find()` and `optionsOf()` (`ORDER BY id`). (AC-13, AC-33)
- [ ] **T15:** `AttributeFormType` + `AttributeFormData`, with fields per type. The options textarea
      appears at define only.
- [ ] **T16:** `AttributeAdminController`: list, define per `{type}`, edit. Failure-contract mapping
      with 422, re-render from DBAL only, code derivation, no logging. Templates. Add the
      "Attributes" link to the category index. (AC-32…AC-37, AC-41, AC-42)
- [ ] **T17:** Delete action plus the three option POST actions (303 + flash, 403 on a bad token).
      `CategoryAdminController::delete()` catches `CategoryHasAttributes`. (AC-38…AC-40)

## Tests (qa)
- [ ] **T18:** Domain unit tests for every VO plus `AttributeTest` and `AttributeOptionTest`.
      (AC-1…AC-11)
- [ ] **T19:** Foundry factories that go through `define…` / `newOption()`. Add
      `AttributeHandlersTest`, both repository tests (backstops, `ORDER BY id`, the sweep, corrupt
      `number_rules`), and the `CategoryHandlersTest` additions. Use the racing double for AC-19.
      (AC-12…AC-25, AC-28)
- [ ] **T20:** `NoDdlTest` (AC-27), and extend the lock recording-double test to the six handlers
      (AC-26).
- [ ] **T21:** Functional `AttributeAdminTest`, including the C-11/C-17 index-race re-render, plus
      the `CategoryAdminTest` delete-with-attributes case. (AC-32…AC-42)
- [ ] **T22:** Bite proof: apply AC-43 mutations (a)–(h); each must fail a named test. Revert them,
      record the results in the commit message, then run `make test` twice. (AC-43, AC-44)

## Docs
- [ ] **T23:** Update the docs:
      - **`CLAUDE.md`:**
        - the status line (Catalog slice 2)
        - *"a part with its own references or an unbounded count is its own aggregate; its set-rules
          are cross-instance invariants (aggregate decides on facts, lock, index)"*
        - the `label_key` rule (*no expression indexes: DBAL re-diffs them; store the normalised
          form*)
        - multi-aggregate save order: the attribute row is the commit point, written last and
          removed first
        - *"every Catalog write, attributes and options included, runs under `CategoryTreeLock`"*
        - delete guards accrete in `DeleteCategoryHandler`
        - `NoDdlTest`
      - **`docs/roadmap.md`:** tick the item; record what slice 3 inherits (D4, C-11 to widen) and
        what dependent options inherit (D14).
      - **`FORboehpyk.md`:** the chapter. "Why the options left the attribute": the 500-cap
        conversation, "Standard" under two brands, and the expression index that would have
        re-diffed forever.

## Verify
- [ ] **T24:** `/verify` → reviewer PASS and AC-1…AC-44 checked. The cross-context `grep` comes back
      empty and `make migration.make` produces an empty diff. Then walk through the dev stack by
      hand:
      1. Define one attribute of each type on a non-leaf category, including a choice with a few
         hundred pasted options.
      2. Relabel an option and confirm its id in the row is unchanged.
      3. Try to remove the last option.
      4. Try to delete the category.
      5. Delete the attribute and confirm its option rows are gone.
      6. Delete the category.

      If a hand-check contradicts the green suite, suspect the runtime (opcache) before the code.
