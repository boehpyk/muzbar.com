# ADR-0027: Symfony UX under AssetMapper; the listing form's Live Component renders, the controller writes

- **Status:** Accepted
- **Date:** 2026-11-26
- **Established by** the `listing-wizard` slice ([spec](../specs/listing-wizard/feature-spec.md)
  Q1–Q6; [plan](../specs/listing-wizard/technical-plan.md) D1, D4–D7, D9; owner approved the
  recommendations, 2026-11-26) — Phase 2 item 6, the repository's first Stimulus code and first Live
  Component.
- **Applies** [ADR-0001](./0001-framework-symfony.md) (Symfony UX, Live Components named for this
  job), [ADR-0006](./0006-dual-styling-admin-public.md) (AssetMapper, Node-free; the image compiles
  assets and proves it did) and [ADR-0015](./0015-intended-action-redirect-and-login-overlay.md)
  ("a Live Component is for server-side state that changes as the user edits"; no GET changes
  state).
- **Keeps** [ADR-0019](./0019-listing-context-cross-context-ports-leaf-only-under-one-lock-in-two-modes.md)
  decision 5 (every Listing write runs inside `holdSteady()`, after the facts it needs) and
  [ADR-0021](./0021-listing-lifecycle-in-the-aggregate-completeness-on-public-writes-dispatch-after-commit.md)
  decision 5 (dispatch after the transaction commits) — without exception.
- **Amends** ADR-0019, ADR-0020 and ADR-0021 — see the dated 2026-11-26 amendments at the foot of
  each.

## Context

PRD §6 asks that choosing a base category "morphs the lower half of the form" — the Details for that
category appear beneath it without a reload, and nothing the seller already typed is lost. Until now
the listing form could not know its category before it was submitted, so `/sell` drafted without
Details and redirected to the edit page, and a re-file on the edit page could not publish (the new
category's fields had never been on the page — listing-publish Q2).

Symfony UX Live Components are the tool ADR-0001 named for exactly this. Three things make adopting
them a decision rather than an install:

- **A Live Component can write.** `#[LiveAction]` turns the component's endpoint into a second door
  to the aggregate — with its own refusal mapping, its own redirect and flash handling, and a CSRF
  story (same-origin headers) that differs from every other form in the repository. Every Listing
  write today goes through one handler path under one lock; a second door is a second place to get
  L-2/L-9/L-13 ordering wrong.
- **A morphing form breaks an assumption the POST relied on.** The edit POST built its form for the
  *stored* category with `allow_extra_fields: false`. A morphed page posts the *new* category's
  fields. The server can no longer assume which schema the submitted Details belong to.
- **JavaScript is now load-bearing in prod.** Until `public-styles` the image never ran
  `asset-map:compile`, and prod 404'd every asset — the coming-soon CSS and the login overlay's
  `app.js` — for months while every test was green, because an in-process test renders a `<link>`
  and nobody fetches it. Stimulus and the live controller are vendor files that exist in the image
  only if `importmap:install` ran; the same blindness applies.

## Decision

1. **The Live Component renders; the controller writes** (D1). `ListingForm` is
   `#[AsLiveComponent(method: 'post')]` with `ComponentWithFormTrait`. Its `<form>` keeps
   `action` = the page's own route and `method="post"`, so **submitting is an ordinary full-page POST
   to `SellController`**, through the existing handlers, inside `holdSteady()`, after `schemaOf()`,
   with every Domain rule where it already was. The component declares **no `#[LiveAction]`** and
   injects only read models (`DbalCategoryOutline`, `DbalAttributeSchema`, `DbalSellerDrafts`) and
   the form builder — never a handler, a repository or the `Categories` port. A source scan (AC-22,
   ≥ 1 file scanned) keeps it that way. ADR-0015's rule of thumb is taken literally: server-side
   state that changes as the user edits, and nothing more. The JS-free path is the same form posting
   to the same route — progressive enhancement, not a fallback.

2. **`method: 'post'`, explicitly** (D1). With the default `get`, the component's `formValues` —
   the CSRF token, title, description — would ride in the query string into nginx and Traefik access
   logs (AC-21). A POST is also never a "safe" request, so an anonymous live request cannot make the
   firewall's entry point stash a target path (ADR-0015).

3. **`details_for`: the POST builds the form the page showed** (D4). `ListingFormType` carries an
   unmapped hidden `details_for` — the category the Details on this page were rendered for, empty
   when none. The controller reads it from the raw body **before** building the form (once, through
   `$request->request->all()`, normalising shapes — never `InputBag::all('listing_form')` on a value
   that may be a string), **validates it against the offered category choices** (invalid → empty on
   `/sell`, the stored category on edit — AC-17), and builds the form for that category. Details are
   forwarded, and "Save and publish" is honoured, only when `details_for` equals the submitted
   category; otherwise `/sell` drafts without Details and does not publish, and the edit page
   re-files as before (L-10 drops). `details_for` is a **UI fact, never a Domain input**: a tampered
   value can only make the server more conservative, or offer Details for the category the seller is
   filing under — which the Domain then decides on `schemaOf()` under the lock (L-2, L-9, L-13).

4. **Re-file + publish is allowed when the page showed the new Details** (D5, cross-reference). The
   reason listing-publish Q2 refused it — "the new category's details were never on the page" — is
   exactly what `details_for` now records. Recorded as the 2026-11-26 amendment to ADR-0021.

5. **The morph's state rules, and their ceiling** (D6). On every re-render the component rebuilds
   the form for the selected category (no Details if it is not an offered choice — no schema
   statement runs) and rewrites `formValues['attributes']` for the new schema only: the submitted
   value **if its key was present** (even `''` — a deliberate clear), else the listing's stored value
   (edit page), else absent; keys outside the new schema are removed. Basics are never touched.
   **Ceiling, stated:** a value *typed* (not stored) into a field that then leaves the page is
   forgotten if the field comes back (Bass → Guitar → Bass). Remembering it needs a second writable
   prop; add it if sellers complain, not before.

6. **Only the category morphs; the live endpoint is cheap and private** (D7).
   - The `<form>` carries `data-model="norender|*"`; the category `<select>` alone carries
     `data-action="change->live#$render"` (AC-9) — one morph per category change.
   - Per morph: `/sell` ≤ 3 statements (outline, effective schema, options), edit ≤ 5 (+ ownership
     find, stored values), independent of the number of attributes and options (AC-8). Stored values
     are re-read, not carried in props: the ownership check runs on every render anyway, and props
     would put a listing's data in client-held state.
   - `listingId` is a **non-writable** LiveProp (checksummed), and **every render** re-runs
     `DbalSellerDrafts::find(listingId, currentUser)`: someone else's listing → 404, identical to an
     unknown id (AC-19). No user → **404**, not `AccessDenied`, so the firewall's entry point never
     runs and the overlay's intended action survives (AC-20). No `access_control` rule for
     `/_components`: the component is the guard, and a rule would turn a stray anonymous request
     into a login redirect.
   - No `url: true` LiveProp: the URL never changes, so Umami records no extra page view (AC-23).
   - **No rate limiter** (Q4): signed-in only, no writes, the edit `GET`'s cost profile, which has
     none. Trigger to add one: abuse seen in the logs.

7. **The package set and how it is built.**
   - **Packages:** `symfony/ux-live-component`, which pulls `symfony/ux-twig-component` and
     `symfony/stimulus-bundle`. Version: `symfony/ux-live-component` **v3.8.1** (constraint `^3.8`), with
     `ux-twig-component` v3.8.0 and `stimulus-bundle` v3.8.0; `@hotwired/stimulus` 3.2.2. No Turbo.
   - **Under AssetMapper, Node-free** (ADR-0006): `@hotwired/stimulus` and
     `@symfony/ux-live-component` (and any JS dependency its `package.json` names) are importmap
     entries; `assets/app.js` imports the Stimulus bootstrap and leaves the plain-JS login overlay
     untouched.
   - **The live controller is lazy:** `assets/controllers.json` declares it `"fetch": "lazy"`, so a
     page without a Live Component never downloads it.
   - **The image proves the files exist.** The Dockerfile's `asset-map:compile` step `ls`-asserts the
     compiled Stimulus and live-controller files, as it already does `public-*.css`, so a missing
     `importmap:install` fails the build. `deploy.yml`'s smoke check reads the live controller's URL
     from `/login`'s importmap and fetches it (200, `text/javascript`) (AC-26). These two guards are
     the only things that can see a missing asset — the public-styles lesson: an in-process test
     never fetches what it renders.
   - Components live in `App\Infrastructure\<Context>\Http\Component\`, templates in
     `templates/<context>/components/` (`twig_component.yaml`).

8. **The rule for every future Live Component: a Live Component that needs to write must justify
   why the page's POST cannot, in its own ADR.** Until such an ADR exists, a `#[LiveAction]` that
   reaches a handler, a repository or a port is a review failure, and the AC-22 scan is the model for
   catching it in a new component's directory.

## Alternatives

- **Submit through a `#[LiveAction]`.** One fewer round trip on save. Rejected: it duplicates the
  controller's refusal mapping, PRG and flash handling in a second place, makes the live endpoint a
  writer with a different CSRF story, and gives the aggregate a second door that would need its own
  `CategoryTreeLockTest` cases forever.
- **`method: 'get'` (the default).** Leaks form state, including the CSRF token, into access logs,
  and makes an anonymous live request a "safe" request the entry point may stash as a target path.
- **Build the POST's form for the stored category (today's rule) or for the submitted one.** The
  first refuses every morphed page as "extra fields"; the second binds and publishes Details the
  seller never saw after a JS-free submit or a race. Only the page itself knows which schema it
  showed.
- **Carry stored values in LiveProps.** Saves two statements per morph on the edit page, at the price
  of putting listing data in client-held state while the ownership `find()` runs anyway.
- **A plain-JS fetch of a Details fragment** (the login overlay's approach, ADR-0015). Smaller, but
  re-implements what Live Components do — form state, morphing, focus — by hand, and ADR-0001 chose
  UX for exactly this. The overlay stays plain JS because it holds no server-side state.
- **Turbo Frames.** Would bring Turbo Drive site-wide for one select; not needed for a morph.
- **Dependent options (Model depends on Brand) in this slice** (Q1). A Catalog schema change of its
  own; the morph built here is what it will reuse.

## Consequences

- **Easy:** the Domain does not know the wizard exists. Every write is still one handler path under
  one lock, so the existing refusal tests cover `/sell` and the edit page alike; the JS-free flow is
  the same code path and keeps its tests (AC-24).
- **JavaScript is now in prod's critical UI path** for the first time beyond the overlay. A missing
  vendor file fails the image build or the deploy smoke check, never silently — but only for the
  files those guards name. A new Stimulus controller or importmap package must add its own `ls` line.
- **The live endpoint is a public URL that renders seller data.** Its guard is the component's
  per-render `find()`, not the firewall. A new Live Component that renders anything owned must do the
  same, and return 404 for anonymous — never `AccessDenied` (ADR-0015's target path).
- **`details_for` is one more hidden field the POST trusts only after validation.** Any future form
  whose fields depend on another field's value needs the same "which schema was this rendered for"
  fact; copy this shape rather than inferring it from the stored row.
- **`ListingPublished` is now also released by a create-and-publish** from `/sell`, dispatched after
  `holdSteady()` returns, as on revise. A listener must not assume a publish follows a revise.
- **Decision 8 is a standing review rule.** It costs nothing to keep and is easy to forget: a
  `#[LiveAction]` reads like a convenience, not like a new write path.
