# Feature Spec: listing-wizard

> The *what & why*. Disposable: it dies once the feature ships and its behaviour lives in tests +
> code. Make it **executable** (measurable, enumerated), not pretty. Must not contradict the
> [Constitution](../../constitution.md) or an accepted ADR.

- **Feature:** `listing-wizard`, Phase 2 roadmap item **6 of 15**.
- **Bounded context(s):** **`Listing`** only. Catalog is read through the existing UI crossing
  (`DbalCategoryOutline`, `DbalAttributeSchema::effectiveFor()`/`optionsFor()` — ADR-0019's fourth
  crossing) and the existing port (`Categories::statusOf()`/`schemaOf()`/`holdSteady()`). No Catalog
  code changes. No Search code changes.
- **Related PRD items:** US-1; §6 *Core Experience* ("selecting a base category … morphs the lower
  half of the form"), §6 *UI/UX Highlights* ("non-destructive form transformations … without losing
  previously inputted basic data"); §8 Time-to-Publish < 3 min.
- **Governing ADRs:** [0001](../../adr/0001-framework-symfony.md) (Live Components named for this exact
  job), [0006](../../adr/0006-dual-styling-admin-public.md) (AssetMapper, Node-free),
  [0015](../../adr/0015-intended-action-redirect-and-login-overlay.md) ("a Live Component is for
  server-side state that changes as the user edits"; no GET writes),
  [0019](../../adr/0019-listing-context-cross-context-ports-leaf-only-under-one-lock-in-two-modes.md),
  [0020](../../adr/0020-attribute-values-parts-of-listing-validated-against-catalog-schema-guarded-by-refusal.md),
  [0021](../../adr/0021-listing-lifecycle-in-the-aggregate-completeness-on-public-writes-dispatch-after-commit.md),
  [0022](../../adr/0022-schema-drift-archive-reserved-names-dormant-values.md); **ADR-0027** (new, T0).
- **Status:** Approved by owner 2026-11-26 (Q1–Q6 as recommended)
- **Date:** 2026-11-26

> **What this slice teaches, beyond the feature.**
>
> 1. **A Live Component that renders but never writes.** The component is a *view* of the form
>    that re-derives itself from the server on every category change. Every write is still the
>    ordinary, JS-free `POST` to `SellController`, through the same handlers, under `holdSteady()`,
>    after `schemaOf()`. The live endpoint has no `#[LiveAction]`, so it cannot become a second door
>    to the aggregate — and a scan proves it stays that way.
> 2. **A UI limitation had leaked into a Domain rule.** L-7 said *"a new listing is a draft, with no
>    details yet — the create form has none, because the category is not known until it is
>    submitted."* That justification was about a form, not about gear. Once the form can know the
>    category, the factory takes the details too, and L-9 reads them at birth exactly as on every
>    later write. The honest fix is in the aggregate, not a `draft()`-then-`revise()` workaround in
>    the handler.
> 3. **"Which schema was this form rendered for?" is a fact the POST needs.** With a morphing form
>    the server can no longer assume the Details on a submitted page belong to the stored category.
>    One hidden field — `details_for` — says which category they were rendered for; the POST builds
>    its form for that category, and publishes only when it equals the submitted category. A tampered
>    value can make the server *more* conservative, never less: the Domain still decides L-2, L-9,
>    L-13 on the facts it reads under the lock.

---

## Ubiquitous language (this slice)

| Term | Means | Not to be confused with |
|---|---|---|
| **Listing form** | The one form for `/sell` (create) and `/sell/drafts/{id}/edit` (edit): basics (title, description, price, currency, category) + the **Details** section. | — |
| **Details** | The fieldset of attribute fields for one category's **active effective schema** (C-22, ADR-0022). Rendered from Catalog's admin read model; decided by the Domain on `schemaOf()`. | The schema itself (Catalog's), the values (Listing's). |
| **Morph** (re-render) | A Live Component round-trip triggered by changing the category select: the server rebuilds the form for the newly selected category and returns its HTML; the browser patches the page. **Writes nothing.** | Save (a POST to the controller). |
| **Details-for category** (`details_for`) | A hidden field: the category id the Details on this page were rendered for, or empty when no Details were rendered. The POST builds its form for it. | The submitted `category` (what the seller wants to file under). |
| **Basics** | Title, description, price, currency. Always kept across a morph. | Details. |
| **Shared attribute** | An attribute in force on both the old and the new category — defined on a common ancestor (C-22: one definition, one id). Its value survives a morph. | An attribute merely with the same label (a different id; never carried). |

### Invariants touched

| # | Status | Rule |
|---|---|---|
| **L-7** | **amended** | A new listing is a draft (unchanged). *Was:* "with no details yet". *Now:* `Listing::draft()` takes the category's schema and the seller's details input and reads them by **L-9**, as every later write does. An empty input is still a draft with no details. L-13 does not bite on a draft (L-11, unchanged). |
| L-2, L-6, L-9, L-10, L-11, L-13, L-14, L-15, L-17 | unchanged | Decided where they are today. The wizard adds no rule and relaxes none. |
| C-*n* | unchanged | No Catalog write is added or changed. |

## User story

As an **Individual Gear Seller**, I want **choosing a category to immediately show that category's
specification fields beneath it, without reloading and without losing what I typed**, so that **I can
describe and publish my instrument from one page in under three minutes**.

## In scope

- `symfony/ux-live-component` (+ `stimulus-bundle`, `ux-twig-component`) under AssetMapper; the
  first Stimulus code in the repo; `assets/app.js` boots Stimulus; the login overlay is unchanged.
- One Live Component, `ListingForm`, used by **both** `/sell` and the edit page. On a category
  change it morphs: Details for the new category, basics kept, shared attribute values kept.
- `/sell` gains Details (once a category is chosen) and **"Save and publish"** — create, fill and
  publish in one POST.
- The edit page's "Save and publish" now publishes on a **re-file** too, when the Details on the
  page were rendered for the new category (`details_for` = submitted category).
- `Listing::draft()` reads details at creation (L-7 amended); `DraftListing` carries
  `attributes` and `publish`.
- **The JS-free path keeps working** exactly as today (progressive enhancement): without JS no morph
  happens, `/sell` drafts without Details and redirects to the edit page, a re-file saves and comes
  back to the form.

## Non-goals (explicit — hold the line)

- **Dependent options (D14, `parent_option_id`).** Not in scope (Q1).
- **Remembering unsaved detail input across a round-trip away and back.** Bass → Guitar → Bass keeps
  the *stored* value of an attribute (edit page) but not a value typed into a field that then left
  the page. Basics are always kept.
- **No write through the live endpoint** — no `#[LiveAction]`, no autosave, no "draft on change".
- **No rate limiter on the live endpoint** (Q4): signed-in only, ≤ 5 indexed statements per morph,
  no writes — the same cost profile as the edit page's `GET`, which has none.
- No change to the draft list, the public listing page, withdraw, Search, or the admin.
- No Turbo, no client-side validation, no JS-side schema; no new styles beyond what the morph
  status line and a busy state need.
- No `ListingForm` on admin pages.

## Acceptance criteria (the Definition of Done checklist)

### Morph (Live Component — `InteractsWithLiveComponents` tests unless stated)

- [ ] AC-1: On `/sell`, setting the category to a leaf **C** re-renders the component with a
      `Details` fieldset holding exactly C's active effective schema (one field per attribute, in
      `effectiveFor()` order) and `details_for` = C. Setting it to the placeholder (empty) renders
      **no** Details and `details_for` empty.
- [ ] AC-2: A morph keeps **title, description, price and currency** byte-for-byte as last
      entered, including on the edit page and including values the Domain would refuse (e.g. a
      121-char title is re-rendered, not truncated or errored).
- [ ] AC-3: A morph from leaf **A** to leaf **B** keeps the entered value of every **shared**
      attribute (defined on a common ancestor) and drops every field not in B's schema. The
      re-rendered form holds no `attributes[...]` key outside B's schema (no "extra fields" error).
- [ ] AC-4 (stored fallback, edit page): for a field that was **not** on the previous render, the
      morph pre-fills the listing's **stored** value if it has one; a field that **was** on the
      previous render keeps what was submitted, **including empty** (a deliberate clear is not
      undone). Scenario: stored Strings = 5 on Bass; morph Bass → Guitar (no Strings) → Bass shows 5;
      saving without touching it keeps 5.
- [ ] AC-5: An archived stored option on a choice field still renders selected as
      "… (no longer offered)" after a morph back to the stored category (`keep`, ADR-0022 D10);
      `keep` is built from **choice** attributes' stored values only (an archived option's id stored
      as a text value never reaches `optionsFor()`).
- [ ] AC-6: A category value that is **not among the offered choices** (unknown, malformed,
      non-uuid, a branch, an array) renders no Details, `details_for` empty, and issues **no**
      `effectiveFor()`/`optionsFor()` statement (no 500).
- [ ] AC-7: A published listing's edit page renders the category disabled (L-15) and a forced morph
      changes nothing: Details stay those of the stored category.
- [ ] AC-8: Statement budget per morph (recorded with `QueryRecordingStatement`): **`/sell` ≤ 3**
      (outline, effective schema, options), **edit ≤ 5** (+ ownership find, stored values) —
      independent of the number of attributes and options. The edit page's full `GET` stays at its
      listing-attribute-values AC-25 budget.
- [ ] AC-9: Only the category select triggers a morph: the component's template binds every other
      field with `norender`, and the category select is the only element carrying the render action
      (template assertion).
- [ ] AC-10: The morph shows one `role="status"` line naming the category the Details are for
      ("Details for Instruments › Bass") — or none when no Details are shown.

### Writes (functional, `KernelBrowser`, real DB)

- [ ] AC-11: `/sell` POST with `details_for` = category, valid basics and details, **Save draft**:
      one draft with those values persisted (rows in `listing_attribute_value`), 303 to its edit page.
- [ ] AC-12: `/sell` POST, **Save and publish**, every required detail filled: one listing,
      `status = published`, `published_at` set, 303 to `/listings/{id}`; exactly one
      `ListingPublished` dispatched, **after** the transaction commits (`CategoryTreeLockTest`
      records the lock as released at dispatch).
- [ ] AC-13: `/sell` **Save and publish** with a required detail empty: 422, "Fill this in to
      publish." on that field, **no row** written (draft and publish are one transaction).
- [ ] AC-14: `/sell` POST with `details_for` ≠ category (JS-free first submit: empty; or a race):
      the draft is created **without** details and **not** published even if "Save and publish" was
      clicked; 303 to the edit page with "Draft saved. Add the details below."
- [ ] AC-15: Edit page, **re-file** (draft, category A → B) with `details_for` = B and B's details:
      the POST's form is built for **B** (B's fields bind; no extra-fields error), the values are
      stored, and **Save and publish** publishes (L-13 against B).
- [ ] AC-16: Edit page, re-file with `details_for` = A (the JS-free path): today's behaviour,
      unchanged — the form is built for A, L-10 drops what B does not ask, "Save and publish" does
      **not** publish, flash "Saved, not published yet. The details now follow “B” …".
- [ ] AC-17: A `details_for` that is not an offered category (tampered, unknown, malformed, a
      branch) is treated as **empty** on `/sell` and as the **stored category** on the edit page; no
      500, no statement with the raw value.
- [ ] AC-18: Every existing Domain refusal keeps its field and wording on both pages (L-2 stale
      leaf, L-3/4/5, L-9 incl. "no longer offered", L-13, L-15), now including on `/sell` for L-9
      and L-13; a drift between morph and POST only refuses (fail closed, ADR-0019).

### Security, privacy, regressions

- [ ] AC-19: Someone else's listing id given to the component (or a valid prop set replayed by
      another user) → **404**, identical to an unknown id; no listing data in the response.
- [ ] AC-20: An anonymous live request → **404**, and the session's `_security.main.target_path`
      is **unchanged** (the overlay's intended action survives).
- [ ] AC-21: The live component posts its state (`method: 'post'`): the CSRF token, title and
      description never appear in a request URL. The listing form's own CSRF token is still checked
      on the real POST (a bad token → 422 with the root error, nothing written).
- [ ] AC-22: `ListingForm` declares **no** `#[LiveAction]` and injects no handler, repository or
      `Categories` port (a source scan, ≥ 1 file scanned).
- [ ] AC-23: A live re-render response contains no `<html>`, no layout and no analytics script
      (Umami); no LiveProp is URL-bound, so a morph records no page view.
- [ ] AC-24: **JS-free regression**: every existing `SellTest`/`SellPublishTest` case passes; the
      only edited assertions are the ones this spec changes on purpose (AC-14's flash on `/sell`,
      AC-15's publish-on-re-file), each listed in the PR.
- [ ] AC-25: `Listing::draft()` reads details by L-9 (unit): a not-an-option, a non-number, a key
      not in the schema → `InvalidAttributeValue`; a required detail left empty is **accepted** (L-11);
      an empty input yields `AttributeValues::none()`.
- [ ] AC-26: **Assets in prod**: the image build asserts the compiled Stimulus and live-controller
      files exist (`ls`, like `public-*.css`), and `deploy.yml` fetches the live controller's URL from
      `/login`'s importmap → 200 `text/javascript`.
- [ ] AC-27: Hand-check in a real Chromium (logged in `/verify`): morph on `/sell` and edit, basics
      kept, shared value kept, publish from `/sell`, JS disabled path, keyboard focus stays on the
      category select after a morph.

### Mutations that must bite (each one, applied alone, fails at least one test)

| | Mutation | Caught by |
|---|---|---|
| (a) | Add a `#[LiveAction]` that calls `ReviseListingHandler` | AC-22 scan |
| (b) | Build the POST form for the stored category instead of `details_for` | AC-15 (extra fields → 422) |
| (c) | Publish when `details_for` ≠ category | AC-14, AC-16 |
| (d) | Drop the "offered choices" check before `effectiveFor()` in the morph | AC-6 (500 / statement recorded) |
| (e) | Drop the per-render ownership `find()` | AC-19 |
| (f) | Don't strip non-schema `attributes` keys before re-submitting | AC-3 (extra-fields error) |
| (g) | Remove the stored-value fallback | AC-4 (Bass→Guitar→Bass clears Strings) |
| (h) | Fallback overrides a submitted empty value | AC-4 (deliberate clear undone) |
| (i) | Build `keep` from all stored values | AC-5 (text-held uuid) |
| (j) | `draft()` ignores the input | AC-11, AC-25 |
| (k) | Dispatch `ListingPublished` inside `holdSteady()` in `DraftListingHandler` | AC-12 |
| (l) | Save the draft in a separate transaction before publish | AC-13 (row left behind) |
| (m) | `method: 'get'` on the component | AC-21 |
| (n) | Accept a tampered `details_for` unvalidated | AC-17 |
| (o) | `/sell` forwards attributes when `details_for` ≠ category | AC-14 |
| (p) | Morph on every field (`on(change)\|*`) | AC-9 |
| (q) | Throw `AccessDeniedException` (not 404) for anonymous | AC-20 (target path overwritten / redirect) |
| (r) | Remove the Dockerfile `ls` for the live controller | AC-26 (proved by hand once) |

## Failure contract

| Condition | Expected behaviour |
|---|---|
| JavaScript disabled / Stimulus fails to load | Plain form, JS-free flow exactly as today (AC-16, AC-24). |
| A morph request fails (network, 5xx, 404) | The page stays as it was and stays submittable; `details_for` still names the category the visible Details are for, so the POST builds the right form and never publishes Details the seller did not see. Live's default error handling only; no custom retry. |
| Category tampered in the live state | No Details, no schema statement (AC-6). |
| Someone else's listing / no session | 404 (AC-19, AC-20). |
| Schema changes between morph and POST (attribute archived, option archived, made required, bounds narrowed) | The POST's Domain refusal, in today's wording: "This detail no longer applies. Reload the page." / "… is no longer offered. Choose another." / "A new detail is now required. Reload the page." / range message. Nothing written. |
| Category gains a subcategory between morph and POST | "That category now has subcategories. Choose a more specific one." (unchanged). |
| `details_for` tampered | Treated as empty (`/sell`) or stored category (edit) — AC-17. |
| Category select changed, then submitted before the morph returns | `details_for` still names the old category: `/sell` drafts without details, not published (AC-14); edit re-files with L-10 dropping, not published (AC-16). |
| Required detail empty on "Save and publish" from `/sell` | 422, field error, nothing written (AC-13). |
| Bad CSRF token on the real POST | 422, root error, nothing written (AC-21). |
