# Task List: listing-wizard

> Ordered, small tasks in DDD canonical order. Each should be reviewable in < 5 minutes and is one
> commit on `feature/listing-wizard`. Run `make check` before each commit. Check off as you go.
> **Owner answers:** pending (Q1–Q6 in the technical plan; written to the recommendation).

## Decision record
- [x] T0: `/adr` → **ADR-0027** (Symfony UX under AssetMapper; the listing form's Live Component
      renders, the controller writes — plan D1, D4, D7, D9). Amendments: ADR-0019 (one form builder;
      POST builds for `details_for`), ADR-0020 (L-7 amended), ADR-0021 (re-file + publish, D5).

## Domain
- [x] T1: `Listing::draft()` takes `AttributeSchema $schema, AttributeInput $input`; reads
      `$schema->read($input)` after L-2; docblock L-7 amended (D3). Update `ListingFactory` and
      existing `ListingTest` call sites (`none()` / `fromArray([])`). New unit cases for AC-25.

## Application
- [x] T2: `DraftListing` + `array $attributes`, `bool $publish` (no defaults).
      `DraftListingHandler`: `schemaOf()` → `draft()` → optional `publish()` → save → release inside,
      dispatch after `holdSteady()` (inject `Clock`, `DomainEventDispatcher`). Controller passes
      `[]`/`false` for now, so behaviour is unchanged at this commit. Handler tests: values, publish,
      incomplete → no row; `CategoryTreeLockTest` dispatch-outside-lock case.

## Infrastructure — refactor first (no behaviour change)
- [x] T3: Extract `ListingFormBuilder` from `SellController` (`choices()`, `details()` with the
      offered-choices guard and choice-only `keep`, `nameOf()`). Controller uses it. Existing suite
      green **unmodified**.
- [x] T4: `ListingFormType` + unmapped hidden `details_for`; controller sets it to the category the
      Details are built for. Suite still green unmodified.

## Infrastructure — POST side
- [x] T5: Controller reads + validates `details_for` from the raw body before building the form
      (D4, `InputBag` footgun), builds for it; edit: publish rule D5; `/sell`: forward `attributes`
      / `publish` per D4's table, map `InvalidAttributeValue` / `ListingIncomplete` as `edit()` does,
      new success flashes. `/sell` renders Details on a 422 when a category is chosen.
      Edit the two intentionally changed assertions and list them (AC-24).

## Infrastructure — packages and assets
- [x] T6: `composer require symfony/ux-live-component` (pin, record version for ADR-0027); recipe
      files; `controllers.json` live controller `fetch: lazy`; `app.js` imports the Stimulus
      bootstrap (overlay untouched); `twig_component.yaml` namespace → `listing/components/`. Check
      `importmap.php` against the package's `package.json` (idiomorph?).
- [x] T7: Dockerfile `ls` for the compiled Stimulus + live controller (paths from
      `debug:asset-map`); `deploy.yml` smoke fetch of the live controller URL from `/login`'s
      importmap. Build the image locally once; prove mutation (r) by removing the `ls` target.

## Infrastructure — the component
- [x] T8: `ListingForm` Live Component (`method: 'post'`, `ComponentWithFormTrait`, non-writable
      `listingId`, `published`, `buttons`) + `listing/components/ListingForm.html.twig` replacing
      `_form.html.twig`; per-render ownership `find()` → 404; anonymous → 404 (D7). Decide the trait
      vs. plain-props question here (plan Risk 1).
- [x] T9: Morph rules (D6): rebuild for the selected category, attributes merge (submitted-if-present
      → stored → absent), strip non-schema keys, `details_for`. Template bindings: form
      `norender|*`, category `change->live#$render` (D7).
- [ ] T10: `sell.html.twig` / `edit.html.twig` mount `<twig:ListingForm>`; `/sell` "Save and publish"
      only when Details are rendered; `role="status"` line + `aria-busy` + one `_forms.scss` rule (D8).

## Tests (qa)
- [ ] T11: `ListingFormComponentTest` — AC-1…AC-10, AC-19, AC-20, AC-23 (statement counts with
      `QueryRecordingStatement`; target-path assertion reads the session, not a probe GET).
- [ ] T12: `SellWizardTest` — AC-11…AC-18, AC-21; run `make test` twice (rate limiters, Redis).
- [ ] T13: Scans — AC-22 (component dir: no `LiveAction`, no handler/repository/`Categories`;
      ≥ 1 file scanned) and AC-9 (template bindings).
- [ ] T14: Mutation sweep (a)–(r), each applied alone, each bites; record results in the PR.
- [ ] T15: Hand-check in Chromium (AC-27), including JS disabled.

## Docs
- [ ] T16: CLAUDE.md status + footguns (live endpoint writes nothing; `details_for`; Stimulus
      assets need the image `ls`; `ListingPublished` now also from create), roadmap item 6 ticked,
      FORboehpyk.md chapter.

## Verify
- [ ] T17: `/verify` → reviewer PASS, all 27 acceptance criteria checked, docs updated.
