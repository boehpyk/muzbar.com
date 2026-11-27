# Technical Plan: listing-wizard

> The *how*. Disposable. Written after the feature-spec is drafted, approved with it before any code.
> Follows DDD canonical order.

## Pre-planning observations (2026-11-26, read from the repo)

1. **No Symfony UX package is installed.** `composer.json` has no `symfony/ux-*` and no
   `stimulus-bundle`; `importmap.php` holds only `app`. `assets/app.js` is the plain-JS login overlay
   (ADR-0015). This slice is the first Stimulus code and the first Live Component.
2. **`/sell` has no Details today.** `Listing::draft()` takes no values (L-7: "the create form has
   none, because the category is not known until it is submitted"), so create is two steps: draft →
   303 to the edit page → fill Details.
3. **The edit POST builds its form for the *stored* category** (`detailsSchema($draft['categoryId'])`)
   with `allow_extra_fields: false`. A morphed page posting the *new* category's fields would be
   refused as "extra fields". The POST must know which schema the page was rendered for.
4. **A re-file never publishes** (`$publish = $publishClicked && !$refiled`, listing-publish Q2),
   because the new category's details were never on the page. With a morph they are.
5. **Form-building logic lives privately in `SellController`** (`detailsSchema()`, the leaf-choices +
   legacy-non-leaf merge, `keep`). The component needs exactly the same form — or the POST will not
   bind what the morph rendered.
6. `Categories::schemaOf()` of an unknown category is `AttributeSchema::none()`, so the create
   handler may read it before `draft()` refuses L-2 — no ordering trap.
7. Only five call sites of `Listing::draft()` (handler, `ListingFactory`, `ListingTest`).
8. Prod image: `composer install --no-dev` runs auto-scripts (`importmap:install` included), then
   `asset-map:compile` + `ls public-*.css`. `/assets/vendor/` is gitignored, so vendor JS
   (`@hotwired/stimulus`) only exists in the image if `importmap:install` ran — the public-styles
   lesson (prod 404'd every asset while green) applies to JS now.

## Decisions

### Open questions for the owner (the plan is written to the recommendation)

| # | Question | Recommendation |
|---|---|---|
| Q1 | Dependent options (D14, `parent_option_id`: Model depends on Brand) in this slice? | **Out.** It is a Catalog schema change (two partial indexes, a new set-rule, a new L-9 clause) — a slice of its own. The morph mechanism built here is exactly what it will reuse. |
| Q2 | Wizard on create only, or create **and** edit? | **Both** — one component, one form builder. Edit is where re-file happens, and the POST-side `details_for` fix (obs. 3) is needed there anyway. |
| Q3 | `/sell` gets "Save and publish" (one-page create → public)? | **Yes**, shown only once Details are rendered. It is the Time-to-Publish metric, and costs one handler branch the edit path already has. |
| Q4 | Rate-limit the live endpoint? | **No.** Signed-in only, no writes, ≤ 5 indexed statements per morph — the edit `GET`'s profile, which has no limiter. Trigger to add one: abuse seen in logs. |
| Q5 | L-7: amend `Listing::draft()` (Domain) vs. `draft()`-then-`revise()` in the handler (zero Domain)? | **Amend `draft()`** (D3). |
| Q6 | Publish on a re-file when `details_for` = new category (amends listing-publish Q2 / ADR-0021)? | **Yes** (D5). Otherwise "Save and publish" after a morph silently only saves. |

### D1 — The Live Component renders; the controller writes

`ListingForm` is a `#[AsLiveComponent(method: 'post')]` using `ComponentWithFormTrait`. Its `<form>`
keeps `action` = the current page's route and `method="post"`: **submitting is an ordinary full-page
POST to `SellController`**, which is unchanged in shape (handleRequest → handler → 303 / 422).
The component has **no `#[LiveAction]`** and injects only read models (`DbalCategoryOutline`,
`DbalAttributeSchema`, `DbalSellerDrafts`) and the form builder (D2) — never a handler, repository or
the `Categories` port (AC-22 scan). Writes therefore still go through the existing handlers, inside
`holdSteady()`, after `schemaOf()`, with L-2/L-6/L-9/L-10/L-13/L-14/L-15/L-17 exactly where they are.

*Why not submit through a LiveAction:* it would duplicate the controller's refusal mapping, PRG and
flash handling in a second place, and make the live endpoint a writer whose CSRF story differs from
every other form (ux-live-component relies on same-origin headers, not tokens). ADR-0015's rule of
thumb is kept literally: server-side state that changes as the user edits — and nothing more.

`method: 'post'` (explicit, not the default): with `get`, `formValues` — the CSRF token, title,
description — would ride in the query string into nginx/Traefik access logs (AC-21). It also means
an anonymous live request is never a "safe" request the firewall's entry point would stash as a
target path.

### D2 — One form builder, used by the page and the morph

Move `SellController::detailsSchema()`, the choices merge (leaves + a legacy non-leaf current
category) and `nameOf()` into `Infrastructure/Listing/Form/ListingFormBuilder` (final, no interface —
two callers, one implementation). It answers:

- `choices(rows, ?currentCategoryId): array<id, path>` — offered categories;
- `details(?categoryId, stored): {schema, noLongerOffered}` — `[]` without a query unless
  `categoryId` is in the offered choices (AC-6, AC-17); `keep` from **choice** values only (AC-5).

The controller and the component both build `ListingFormType` from it, so the morph and the POST
render and bind **the same fields** (obs. 5). No behaviour moves: the existing `SellTest` cases are
the proof (AC-24).

### D3 — L-7 amended: the factory reads details at birth

`Listing::draft(..., AttributeSchema $schema, AttributeInput $input)`: after `assertFileableUnder()`
(L-2), `$attributeValues = $schema->read($input)` (L-9). No L-10 (nothing to re-file from), no L-17
(nothing stored), no L-13 (a draft, L-11). The docblock drops "the create form has none".
*Rejected:* `draft()` then `revise()` in the handler — zero Domain lines, but it models "create" as
"create empty, then edit", keeps a docblock whose reason is now false, and makes a reader wonder why
creating needs the seller check. Constitution §2: the factory should be able to build the thing the
use case creates. Five call sites change (obs. 7); `ListingFactory` passes `none()`/`fromArray([])`.

### D4 — `details_for`: the POST builds the form the page showed

`ListingFormType` gains an unmapped `HiddenType` `details_for` (= the category the Details were built
for; empty when none). **Before** creating the form, the controller reads it from the raw body
(`$request->request->all()` once, then normalise the shape — never `all('listing_form')` on a
possibly-string value, the `InputBag` footgun) and **validates it against the offered choices**:
invalid → empty on `/sell`, stored category on edit (AC-17). The form is then built for that
category. Consequences, all in the controller:

| Page | `details_for` = submitted category | ≠ (JS-free, race, tamper) |
|---|---|---|
| `/sell` | forward `attributes`; honour `publish` | forward `[]`; ignore `publish`; flash "Add the details below." |
| edit, same category | forward; honour `publish` | `details_for` = another offered category (a failed or in-flight morph): **422**, root error "The details shown are for another category. Reload the page.", nothing written |
| edit, re-file | forward; honour `publish` (D5) | `details_for` = the **stored** category (JS-free): forward (L-10 drops), ignore `publish`. Any other: the 422 above |

**Amended 2026-11-27 (found by `/verify`).** The edit row "same category … equal by construction" was
false: a page morphed to B and switched back to the stored category A can post `details_for` = B if the
second morph failed or was still in flight. Revise replaces the whole value set and blank = clear, so
forwarding B's Details under category A deleted every value A has and B lacks, and said "Draft saved."
The rule is now: on the edit page the Details are accepted **only** when `details_for` is the submitted
category or the stored one (the form showed exactly those fields); any other valid `details_for` is a
422 with nothing written and no handler call. `/sell` keeps its rule — a new listing has nothing to
lose (AC-14).

A tampered `details_for` can only make the server *more* conservative or make it accept Details for
the category the seller is filing under — which the Domain then decides anyway (L-2, L-9, L-13 on
`schemaOf()` under the lock). It is a UI fact, never a Domain input.

### D5 — Re-file + publish is allowed when the page showed the new Details (amends ADR-0021 / listing-publish Q2)

`$publish = $publishClicked && $detailsFor === $data->category && !$wasPublished`. The reason Q2
gave ("the new category's details were never on the page") is exactly what `details_for` now
records. L-13 still decides completeness against the new schema in the same transaction.

### D6 — Morph state rules (the "no data loss" contract)

On every re-render, before the trait submits `formValues` to the rebuilt form (a `#[PreReRender]`
hook with priority above the trait's):

1. Read the selected category from `formValues['category']`; if not offered → no Details.
2. Rebuild the form for it (D2).
3. `formValues['attributes']` := for each field of the **new** schema: the submitted value **if the
   key was present** (even `''` — a deliberate clear, AC-4); else the listing's stored value (edit
   page); else absent. Keys outside the new schema are removed (no extra-fields error, AC-3).
4. `formValues['details_for']` := the category (or `''`).

Basics are never touched (AC-2). Validation errors are not shown on a morph (no field is
`validatedFields`), so a half-typed price does not turn red while the seller is choosing a category.

*Ceiling, stated:* a value typed (not stored) into a field that leaves the page is forgotten when it
comes back (non-goal). Remembering it needs a second writable prop — add it if sellers complain.

### D7 — Only the category morphs; the live endpoint is cheap and private

- The `<form>` carries `data-model="norender|*"` (every field synced, none re-renders); the category
  `<select>` alone carries `data-action="change->live#$render"` (AC-9). One morph per category change.
- Per morph: `/sell` = outline + `effectiveFor` + `optionsFor` (3); edit = + `find(id, seller)` +
  `valuesOf` (5) (AC-8). Stored values are re-read, not carried in props — the ownership `find()` is
  needed every render anyway, and props would put another listing's data in the page state.
- `listingId` is a non-writable LiveProp (checksummed). **Every render** re-runs
  `DbalSellerDrafts::find(listingId, currentUser)` → `NotFoundHttpException` (AC-19). No user →
  `NotFoundHttpException` too, not `AccessDenied` — the firewall's entry point must not run (AC-20).
  No `access_control` rule is added for `/_components`: the component itself is the guard, and a
  rule would turn a stray anonymous request into a login redirect.
- No `url: true` LiveProp: the URL never changes, so Umami sees no extra page view (AC-23).

### D8 — Accessibility and styling (minimal)

One `<p role="status">Details for <path></p>` inside the Details fieldset (AC-10). During a request
Live's loading plugin sets `aria-busy="true"` on the component root during the request, and
`_forms.scss` dims `[aria-busy="true"] .listing-form-details` (*amended 2026-11-27 by `/verify`*: the
first build used `data-loading="addAttribute(aria-busy)"`, which renders `aria-busy=""` — false to
ARIA). Idiomorph keeps focus on the select (AC-27). No other SCSS.

### D9 — ADR-0027 (new) + amendments

**ADR-0027 — "Symfony UX under AssetMapper; the listing form's Live Component renders, the controller
writes."** Records D1, D4, D7, the package set and how it is built (importmap, lazy live controller,
image `ls` guard), and the rule for future Live Components: *a Live Component that needs to write
must justify why the page's POST cannot, in its own ADR.* Amendments: **ADR-0019** (the Details form
is built by one builder for page, POST and morph; the POST builds for `details_for`), **ADR-0020**
(L-7 amended: details at birth), **ADR-0021** (D5, re-file + publish).

## Domain layer (pure PHP)

- **Aggregate:** `Listing::draft()` gains `AttributeSchema $schema, AttributeInput $input`; reads
  `$schema->read($input)` after L-2 (D3). Docblock: L-7 amended. Nothing else in the Domain changes.
- **Value objects:** none new. `AttributeSchema`, `AttributeInput`, `AttributeValues` reused.
- **Domain events:** none new. `ListingPublished` is now also released by a create-and-publish.
- **Ports:** none new, none changed.

## Application layer

- **Command:** `DraftListing` gains `array $attributes` and `bool $publish` — **no defaults**, for
  `ReviseListing`'s reason (an omission must not be a silent "no details" or "don't publish").
- **Handler:** `DraftListingHandler` (now also `Clock`, `DomainEventDispatcher`), inside
  `holdSteady()`: `statusOf()` → `schemaOf()` → `Listing::draft(..., $schema,
  AttributeInput::fromArray($attributes))` → `publish($seller, $status, $schema, $clock->now())` if
  asked → `save()` → return `[$id, $listing->releaseEvents()]`; **dispatch after `holdSteady()`
  returns** (ADR-0021 d.5). One transaction: a refused publish leaves no row (AC-13).
- **Idempotency:** unchanged — a double-submit drafts twice; PRG prevents the accidental one.
- `ReviseListingHandler`: **unchanged.**

## Infrastructure layer

- **Persistence:** none. No migration, no mapping change.
- **Packages:** `composer require symfony/ux-live-component` (pulls `ux-twig-component`,
  `stimulus-bundle`); recipe files: `assets/controllers.json` (live controller `fetch: "lazy"`, so
  pages without a component don't download it), `assets/stimulus_bootstrap.js`, `config/routes/
  ux_live_component.yaml` (prefix `/_components`), importmap entries for `@hotwired/stimulus` and
  `@symfony/ux-live-component`. `app.js` imports the bootstrap; overlay code untouched.
  `twig_component.yaml`: `App\Infrastructure\Listing\Http\Component\` → `listing/components/`.
- **Form:** `ListingFormBuilder` (D2); `ListingFormType` + unmapped `details_for` (D4).
- **Component:** `Infrastructure/Listing/Http/Component/ListingForm` + `templates/listing/components/
  ListingForm.html.twig` (replaces `listing/_form.html.twig`; `buttons` stays a prop). Props:
  `?string listingId` (not writable), `bool published`, `list buttons`, and the trait's `form`
  (so a 422 page mounts it from the refused form, errors and all).
- **Controller:** `SellController` uses the builder; reads/validates `details_for`; D4's table; `new()`
  passes `attributes` + `publish` to `DraftListing` and maps `InvalidAttributeValue` /
  `ListingIncomplete` exactly as `edit()` does (share the existing private mappers); success
  flashes: publish → `PUBLISHED` + 303 to the public page; draft with Details → "Draft saved." + 303
  to edit; draft without → `ADD_DETAILS` (today).
- **Templates:** `sell.html.twig` / `edit.html.twig` render `<twig:ListingForm …/>`; `/sell` buttons
  "Save draft" + "Save and publish" (the latter only when Details are rendered).
- **Image / deploy:** Dockerfile `RUN` adds `ls public/assets/vendor/@hotwired/stimulus/*.js
  public/assets/@symfony/ux-live-component/live_controller-*.js` (exact paths confirmed with
  `debug:asset-map` in T7); `deploy.yml` smoke step extracts the live controller URL from `/login`'s
  importmap and fetches it (200, `text/javascript`) (AC-26).
- **DI:** autowired; no bindings.
- **Async / external:** none.

## Interface boundary & input contract

- **Real POST** (`/sell`, `/sell/drafts/{id}/edit`): `listing_form[title|description|price|currency|
  category|details_for|attributes[<attribute id>]|_token]` + top-level `action=save|publish`.
  `details_for` ∈ offered categories, else normalised (D4). Everything else exactly as today.
- **Live endpoint** (`POST /_components/ListingForm`): accepts the component's props + `formValues`;
  writes nothing; 404 for anonymous or non-owner; renders the component fragment only.
- **`DraftListing`**: `sellerId, categoryId, title, description, price, currency, attributes: array<string,string>, publish: bool` — strings decided by the Domain.

## Data & migrations

None.

## Test plan

- **Domain unit** (`ListingTest`): AC-25; existing `draft()` cases updated to pass `none()`.
- **Application** (`ListingHandlersTest`, `ListingPublishHandlersTest`): draft with values; draft +
  publish; incomplete publish leaves no row; `CategoryTreeLockTest` gains the `DraftListingHandler`
  dispatch-outside-lock case (AC-12, mutation k).
- **Live Component** (`tests/Functional/Listing/ListingFormComponentTest`, `InteractsWithLiveComponents`,
  `actingAs()`): AC-1…AC-10, AC-19, AC-20, AC-23; statement counts via `QueryRecordingStatement`.
- **Functional** (`SellTest`, `SellPublishTest`, new `SellWizardTest`): AC-11…AC-18, AC-21, AC-24.
- **Scans**: AC-22 (no `LiveAction`, no handler/repository/`Categories` import in the component dir),
  AC-9 (template bindings).
- **Hand-check**: AC-27 in Chromium; AC-26 mutation (r) once by hand.
- **Performance:** no search path touched; AC-8's statement budget is the guard.

## Risks / open questions

1. **`ComponentWithFormTrait` internals** (D6 relies on a pre-submit hook on `formValues`). If the
   trait's hook order fights the rule, drop the trait for a plain component with a writable
   `array $values` prop that builds the form itself — same ACs, same tests. Decide in T8, not later.
2. **`allow_extra_fields: false`** on a morph: guarded by D6 step 3 and AC-3; keep it false.
3. **Package versions:** pin `symfony/ux-live-component` to a 2.x release supporting Symfony 7.4 and
   record it in ADR-0027. Check whether it needs an `idiomorph` importmap entry (`importmap:require`
   per its `package.json`) — a missing one is exactly the prod-only 404 AC-26 exists for.
4. **Two dev containers sharing `var/cache`** already handled; nothing new. CI builds fresh.
5. **Search is unaffected** (no write path change), but `ListingPublished` now also comes from
   create — `search-alert-match` (14) must not assume it follows a revise.
