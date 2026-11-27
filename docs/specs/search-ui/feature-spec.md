# Feature Spec: search-ui

> The *what & why*. Disposable: it dies once the feature ships and its behaviour lives in tests +
> code. Make it **executable** (measurable, enumerated), not pretty. Must not contradict the
> [Constitution](../../constitution.md) or an accepted ADR.

- **Feature:** `search-ui`, Phase 2 roadmap item **7 of 15** (Search slice 5 of the Search track).
- **Bounded context(s):** **`Search`** only — and only its Infrastructure (template, styles, one
  Stimulus controller). No Domain, no Application, no SQL change. Catalog and Listing are untouched.
- **Related PRD items:** US-2; FR-3 ("update its facets dynamically based on the category selected …
  within a 200 ms response window"); §6 *Advanced Features* (the 0-result "Subscribe to this Search"
  module — this slice leaves its **slot**, `search-alert-subscribe` fills it); §7 narrative (Max on
  his phone, "within three taps").
- **Governing ADRs:** [0002](../../adr/0002-search-postgres-behind-port.md),
  [0003](../../adr/0003-infra-single-vds-compose-traefik.md) (Umami, query strings excluded),
  [0006](../../adr/0006-dual-styling-admin-public.md) (public SCSS, AssetMapper, Node-free),
  [0015](../../adr/0015-intended-action-redirect-and-login-overlay.md) (no GET writes; plain JS when
  there is no server-held state), [0023](../../adr/0023-search-is-a-read-only-projection-over-catalog-and-listing-tables.md),
  [0024](../../adr/0024-price-is-compared-only-within-one-currency.md),
  [0025](../../adr/0025-full-text-search-trigger-maintained-text-table-parser-built-query.md),
  [0026](../../adr/0026-search-latency-proven-on-a-seeded-benchmark-database-on-the-box.md)
  (budget, rate limit), [0027](../../adr/0027-symfony-ux-under-assetmapper-live-component-renders-controller-writes.md)
  (Stimulus under AssetMapper, image `ls` rule); **ADR-0028** (new, T1).
- **Status:** Approved 2026-11-27 — owner accepted the (R) default for Q1–Q10; Q7 roadmap wording fixed at approval
- **Date:** 2026-11-27
- **Precondition (gating, owner-run):** ADR-0026 AC-10 — the **10 req/s arriving** box re-run — is
  due *before* this slice and has not been run. Task T0. This slice changes no statement, but it
  changes how often a visitor sends one (a click is a request), so the budget it inherits must be a
  measurement, not an estimate.

> **What this slice teaches, beyond the feature.**
>
> 1. **When the state is the URL, the page is the component.** The wizard's Live Component exists
>    because a half-filled listing form is server-side state that changes as the seller edits
>    (ADR-0027). A search has no such state: everything it is lives in the query string, and the
>    server already renders the whole answer for any URL. So the enhancement is a small Stimulus
>    controller that **re-requests the same `/browse` URL** the JS-free form would have navigated
>    to, swaps the page's `<main>`, and pushes the URL. One route, one handler, one limiter, one
>    template — the server cannot tell the two paths apart, and a test proves it.
> 2. **A presentation slice with zero Domain is a correct answer, not a skipped step.** Search has
>    no aggregate (ADR-0023: it writes nothing, so there is no consistency boundary to protect). Its
>    rules — which filters survive, the page clamp, range parsing — already live in the Domain as
>    pure functions. Nothing in "show it without a reload" is a business rule; adding a type to the
>    Domain here would be decoration.
> 3. **Cost is a design input on a CPU-bound box.** ADR-0026 measured the box: the queue is the CPU.
>    "Search as you type" would multiply requests per visitor for no new answer. The policy —
>    debounce clicks, never search per keystroke, abort superseded requests — is part of the spec,
>    with the rate limit (30/min per IP) as the server's half of the same contract.

---

## Ubiquitous language (this slice)

| Term | Means | Not to be confused with |
|---|---|---|
| **Search URL** | `/browse?…` with the **surviving** query (category, `f`, `r`, `price`, `q`, `page` > 1) — what every link on the page already carries (search-browse AC-24). "A search is a link." | The URL a visitor typed (may carry dropped, hostile or empty parameters). |
| **Update** | One enhanced round trip: one `GET` of a search URL, the response's `<main>` swapped into the page, the address bar set to the response's search URL. Writes nothing. | A navigation (full page load) — the JS-free path, which yields the same response. |
| **Applied change** | A user action that starts an update: a checkbox or `<select>` change, a number field's `change` (blur/Enter/spinner), the form's submit (Enter in the text box, "Apply filters"), or a click on a same-page `/browse` link. | A keystroke — never an applied change. |
| **Debounce window** | 400 ms after the last checkbox/select/number change before the update starts; another change inside it restarts it. A submit or link click skips it. | Rate limit (the server's 30/min per IP). |
| **Superseded request** | An update still in flight when a newer applied change starts another. It is aborted; its response is never applied. | A failed request. |
| **Status line** | A persistent `role="status"` element **outside** `<main>` that the controller rewrites after each update ("12 listings"), so screen readers hear the new count. | The total in the results (rendered by the server, swapped with `<main>`). |
| **0-result state** | `result.total == 0` — the search matched nothing (any page). | A page past the end (`total > 0`, no hits on this page — "First page" link, unchanged). |
| **Alert slot** | An element inside the 0-result state, rendered only there, that includes an empty partial given the surviving query. `search-alert-subscribe` (roadmap 13) fills it with "Subscribe to this Search". | The alert itself (Notification context, not built). |

### Invariants touched

None. Search has no aggregate and no invariant (ADR-0023). S-1…S-15 (search-browse,
search-range-facets, search-full-text) are unchanged and still decided where they are.

## User story

As a **gear buyer** (Max, on his phone), I want the facets, counts and results to **update as I
tick a box, choose a currency or narrow a range**, and the address bar to always hold my search, so
that I narrow to an exact instrument in a few taps, can go **Back** one step at a time, and can share
or bookmark the result — with exactly the same page working when JavaScript is off.

## In scope

- **Progressive enhancement of `/browse`** by one lazy Stimulus controller (`search`): applied
  changes fetch the search URL the form/link names, swap `<main class="search-browse">`, push the
  response's search URL to history; Back/Forward re-render from the URL.
- **Category-driven facets update with the selection** — category, breadcrumb, child-category and
  pager links update in place too (the facet set changes with the category; FR-3).
- **Text box:** searches on submit only (Enter / "Apply filters"); never per keystroke.
- **Request policy:** 400 ms debounce for checkbox/select/number changes; abort superseded
  requests; at most one update in flight.
- **Failure handling:** 429 → stay, say so in the status line (with `Retry-After`); any other
  failure → full navigation to the same URL (a click never does nothing — the overlay's rule).
- **Accessibility:** stable `id` on every control; focus kept on the control that changed (or moved
  to the `<h1>` after a link); a persistent status line; `aria-busy` on `<main>` while updating;
  "Skip to results" link.
- **0-result state:** "No listings found." with the surviving terms named, "Clear filters" and
  "Search all categories" recovery links, and the **alert slot**.
- **Styling** in the existing public SCSS (`_components.scss`, `tokens.css`): busy state, 0-result
  block, status line (visually hidden), focus visibility — mobile-first, one breakpoint (48rem).
- **Assets in prod:** the compiled controller asserted by the image `ls` and fetched by
  `deploy.yml` (ADR-0027 consequence: "a new Stimulus controller adds its own `ls` line").
- **ADR-0028** + ADR-0026/0027 amendments (T1).

## Non-goals (explicit — hold the line)

- **No Live Component, no Turbo, no new importmap package.** (Q1; trade-offs in the plan, D1.)
- **No search as you type**, no autocomplete, no suggestions, no "did you mean".
- **No change to the query, the SQL, the statement budget, `SearchPort`, or any Domain type.**
- **No fragment endpoint, no header-dependent rendering** — the enhanced and JS-free paths get the
  same response from the same route (AC-1, AC-2).
- **No change to the rate limit's value or key** unless the owner answers Q2 otherwise.
- **No `noindex` removal, no canonical-URL / SEO work** (Q8) — the facet URL space stays out of
  crawlers.
- **No "Subscribe to this Search"** — only the slot (roadmap 13 owns the module and the
  `SearchAlert` aggregate).
- **No sort options, no result-count-per-page option, no infinite scroll, no listing thumbnails**
  (no images exist yet).
- **No homepage search** — `/` stays the coming-soon stub (roadmap Phase 2 preamble).
- **No retry logic, no offline handling, no client-side cache** of responses.

## Acceptance criteria (the Definition of Done checklist)

### Precondition

- [x] AC-0: ADR-0026 AC-10 (10 req/s arriving, open model, per gating scenario, on the box, 0
      dropped arrivals, non-zero sample count per threshold) has been run and logged in
      docs/infrastructure.md's results log **before this branch merges**, with its verdict recorded
      in ADR-0026's amendment. Owner-run (T0); `/verify` checks the log entry exists.
      **Met 2026-11-28: PASS** (B1–B8 p95 46–97 ms, 0 dropped, 300–301 samples each; results log + ADR-0026).

### Server side — the page the controller reads (functional, `KernelBrowser`, real DB)

- [ ] AC-1: **One response for both paths.** A `GET /browse?<query>` sent with the headers the
      controller's `fetch` sends (`Accept: text/html`, `Sec-Fetch-Mode: cors`, `Sec-Fetch-Dest:
      empty`) returns a body identical (after the existing debug/id normalisation) to a navigation
      `GET` of the same URL, for a category page, a 0-result page and a text search. No file under
      `Infrastructure/Search/Http/` reads `$request->headers` or `isXmlHttpRequest()` (scan, ≥ 1
      file scanned).
- [ ] AC-2: **One door.** The only route served from `Infrastructure/Search/` is `app_browse`
      (`GET /browse`), and no class there carries `#[AsLiveComponent]` or `#[AsTwigComponent]`
      (router + source scan). Every update therefore consumes exactly one `browse` limiter token,
      as a page load does.
- [ ] AC-3: **The search URL.** `main.search-browse` carries `data-search-url` = `path('app_browse',
      {category, f, r, price, q, page})` built from the **surviving** query, `page` only when > 1.
      With a hostile or unknown parameter beside a good one (`?f[<good>][]=<opt>&f[x]=1&junk=1&q=`),
      the attribute contains the good selection and none of `x`, `junk` or an empty `q`. On page 2
      it contains `page=2`; on page 1 it contains no `page`.
- [ ] AC-4: **The controller and the status line live outside `<main>`.** The body contains exactly
      one `[data-controller~="search"]`, which is an ancestor of `main.search-browse`, and exactly
      one `[role="status"][aria-live="polite"][data-search-target="status"]` that is **not** a
      descendant of `main` (it survives every swap). It is empty on a full page load.
- [ ] AC-5: **Stable, unique, labelled controls.** Every `input`/`select` in `form.search-facets`
      has an `id`; ids are unique on the page; each is built from what the control means — text box
      `search-q`, checkbox `search-f-<attributeId>-<value>`, range `search-r-<attributeId>-min|max`,
      price `search-price-currency|min|max` — so the same control has the same id on any two pages
      that show it (test: the Fender checkbox's id with and without a second selection). Every
      control has an accessible name (a wrapping `<label>` or `label[for]`).
- [ ] AC-6: **A focus target for link updates.** The `<h1>` has `id="search-heading"` and
      `tabindex="-1"`; `section.search-results` has `id="search-results"` and the page has a "Skip
      to results" link to `#search-results` before the form.
- [ ] AC-7: **The total is one element.** `section.search-results .search-total` holds "N listing(s)"
      exactly once (the status line copies it; a similar-titles page adds the existing
      `.search-similar` sentence).
- [ ] AC-8: **0-result state.** When `result.total == 0`: `section.search-empty` is rendered inside
      `section.search-results` with the heading text "No listings found." (the existing assertion
      in `BrowsePageTest` keeps passing unmodified); when `q` survived, the section names it
      ("… for “<q>”", escaped); when any of `f`/`r`/`price`/`q` survived, a "Clear filters" link =
      `path('app_browse', {category})`; when a category is set, a "Search all categories" link =
      `path('app_browse', {q, price})` (category-bound `f`/`r` dropped). A page past the end
      (`total > 0`, no hits) renders **no** `section.search-empty` and keeps its "First page" link.
- [ ] AC-9: **Alert slot.** `[data-search-alert-slot]` is rendered once on a 0-result page, inside
      `section.search-empty`, and never on a page with `total > 0`. Its contents come from
      `{% include 'search/_alert_slot.html.twig' with {search: <the same array as AC-3>} only %}`
      (source scan of `browse.html.twig`); the partial renders nothing today and its comment names
      `search-alert-subscribe`.
- [ ] AC-10: **Still out of crawlers.** Every `/browse` response (category, 0-result, text, page 2)
      carries `<meta name="robots" content="noindex">` exactly once.
- [ ] AC-11: **JS-free regression.** `BrowsePageTest`, `BrowseRangePageTest`, `BrowseRateLimitTest`
      and the integration Search tests pass **unmodified**; the form is still one `GET` form with
      "Apply filters" and "Clear filters"; statement budgets (≤ 5 + k, + 1 outline read) unchanged.
      Any assertion that must change is listed in the PR with the reason (none expected).

### Client side — source and assets (scans, image)

- [ ] AC-12: **The controller's shape** (scan of `assets/controllers/search_controller.js`):
      declares `stimulusFetch: 'lazy'`; contains exactly one `fetch(`; contains no `/_components`,
      no `XMLHttpRequest`, no `setInterval`, no `localStorage`/`sessionStorage`, no `umami`; binds
      no `input` event (no `input->` in the template's `data-action`s and no `'input'` listener in
      the file).
- [ ] AC-13: **Assets in prod.** The Dockerfile's `ls` names the compiled `controllers/search_controller-*.js`
      (path from `debug:asset-map`); `deploy.yml` reads its URL from a page's importmap and fetches
      it (200, `text/javascript`), like the live controller. Proven once by removing the `ls`
      target and watching the image build fail (mutation (l)).

### Client side — behaviour (hand-check in a real Chromium, logged in `hand-check.md` at `/verify`)

Fixture for every hand-check: the `search-benchmark` seeder is **not** used; a dev tree with ≥ 2
levels, a choice facet with ≥ 3 options, a boolean, a number attribute, prices in two currencies,
and ≥ 25 published listings in one leaf (so a pager exists).

- [ ] AC-14: **One request per applied change.** Ticking a checkbox, changing the currency select,
      or committing a number field (`change`) sends exactly **one** `GET /browse?…` (DevTools
      Network, Fetch/XHR) and updates facets, counts, the total and the hits without a page load.
      Three checkboxes ticked within 400 ms send **one** request. Typing in the text box or a number
      box sends **nothing** until `change`/Enter.
- [ ] AC-15: **Text searches on submit only.** Enter in the text box or "Apply filters" sends one
      request immediately (no debounce); leaving the text box without Enter sends none.
- [ ] AC-16: **A search is a link.** After each update the address bar equals the new `<main>`'s
      `data-search-url` (a hostile parameter typed into the original URL is gone after the first
      update); reloading, or opening it in a new tab, renders the same page; the document `<title>`
      matches the category.
- [ ] AC-17: **History.** Each update adds one history entry; Back undoes the last update and
      Forward redoes it (each one request, no new entry); Back past the first `/browse` entry leaves
      the page normally. The "Skip to results" link (a hash change) sends no request on Back.
- [ ] AC-18: **Links update in place.** Child-category, breadcrumb, pager, "Clear filters" and
      "Search all categories" links update in place (one request, one entry), focus moves to the
      `<h1>`, and the page scrolls to the top of `<main>`. A listing link, and any `/browse` link
      clicked with Ctrl/Cmd/Shift/Alt or the middle button, navigates normally.
- [ ] AC-19: **Focus is not lost.** After a form-driven update, focus is on the control with the
      same `id` as before (checkbox, select, number box), keyboard-only (Space on a checkbox, Tab
      onward continues from there). If that control is gone (its facet left with the category),
      focus is on the `<h1>`. Text typed into the text box **while** an update is in flight is still
      there, caret at the same place, after the swap.
- [ ] AC-20: **Announced and visible.** After each update the status line reads the new total
      (e.g. "12 listings"; on a similar-titles page also the similar sentence) — checked in the
      Chromium accessibility tree; `<main>` has `aria-busy="true"` while the request is in flight
      and loses it after; the results are visibly dimmed meanwhile.
- [ ] AC-21: **Superseded requests never win.** With DevTools throttling at "Slow 3G", tick A, then
      B after A's request has started: A's request is cancelled (Network shows it), and the page
      ends showing A+B; it never flashes A-only after A+B.
- [ ] AC-22: **429.** With the limit exhausted (`BROWSE_RATE_LIMIT=3` in `.env.local`), the next
      applied change leaves the page as it is (the user's tick stays ticked), the status line reads
      "Too many searches from this address. Try again in N seconds." with N from `Retry-After`, and
      nothing navigates. "Apply filters" after the window passes works.
- [ ] AC-23: **Other failures navigate.** Server stopped (network error), a 500, and a 404 (the
      category deleted in another tab, then a child link clicked) each end in a full navigation to
      the request's URL — the visitor sees what the JS-free path shows. A click never does nothing.
- [ ] AC-24: **JS off and mobile.** With JavaScript disabled every flow above works by full page
      loads (the JS-free form, unchanged). At 375 px width: no horizontal scroll, facets above
      results, the "Skip to results" link visible on focus, tap targets ≥ 44 px tall for checkboxes'
      labels.

### Mutations that must bite (each one, applied alone, fails at least one check)

Server-side and scan mutations must fail `make test`; client-side ones are proved by hand during
`/verify` with the mutation applied (listing-wizard mutation (r) precedent), and the results logged
in `mutation-results.md`.

| | Mutation | Caught by |
|---|---|---|
| (a) | `BrowseController` renders only `<main>` when `Sec-Fetch-Mode: cors` (a "fragment" optimisation) | AC-1 |
| (b) | Add a Live Component or a second route (`/browse/fragment`) for the updates | AC-2 |
| (c) | Build `data-search-url` from `app.request.queryString` instead of the surviving query | AC-3 |
| (d) | Move the status line inside `<main>` | AC-4 |
| (e) | Checkbox ids from `loop.index` instead of attribute id + value | AC-5 |
| (f) | Render `section.search-empty` when `result.hits is empty` (catches the page past the end) | AC-8 |
| (g) | "Clear filters" keeps `q` (or "Search all categories" keeps `f`) | AC-8 |
| (h) | Render the alert slot when `total > 0` | AC-9 |
| (i) | Drop the `head` block's `noindex` | AC-10 |
| (j) | Remove the lazy marker from the controller | AC-12 |
| (k) | Bind `input->search#change` on the text box | AC-12 (scan), AC-14 (hand) |
| (l) | Remove the Dockerfile `ls` line for the controller | AC-13 (image build, by hand) |
| (m) | Drop the debounce | AC-14 (hand: three ticks, three requests) |
| (n) | Drop the `AbortController` | AC-21 (hand) |
| (o) | Handle every `popstate`, not only entries the controller pushed | AC-17 (hand: Back after "Skip to results" sends a request) |
| (p) | On 429, navigate like other failures | AC-22 (hand) |
| (q) | `pushState` the request URL instead of the response's `data-search-url` | AC-16 (hand: the hostile parameter stays) |
| (r) | Skip focus restoration | AC-19 (hand) |

## Failure contract

| Condition | Expected behaviour |
|---|---|
| JavaScript disabled, or the controller fails to load | The JS-free page exactly as today: one `GET` form, links navigate (AC-11, AC-24). |
| `429` from `/browse` (30/min per IP) | Page left as it is, selection kept, status line: "Too many searches from this address. Try again in N seconds." (N from `Retry-After`, ≥ 1); no navigation, no automatic retry (AC-22). |
| Network error, `5xx`, `404`, or a `200` without `main.search-browse` | `location.assign(<request URL>)` — the visitor gets what the JS-free path gives (AC-23). |
| A newer applied change while a request is in flight | The older request is aborted and its response never applied (AC-21). |
| Hostile, unknown or empty parameters in the URL | Unchanged server behaviour (S-5, S-10, S-13, S-15: a 200 with fewer filters); the address bar is replaced by the surviving search URL after the first update (AC-3, AC-16). |
| Category deleted between page load and a click | 404 → full navigation → the existing one 404 (AC-23). |
| Back/Forward to an entry the controller pushed | One request for that URL, swapped in, no new entry. To any other entry (hash, earlier page): the browser's default (AC-17). |
| The focused control no longer exists after a swap | Focus moves to the `<h1>` (AC-19). |
| Text typed during an in-flight update | Preserved across the swap, caret kept (AC-19). |
| Umami configured | One page view per pushed URL, path `/browse` only (`data-exclude-search`) — the same count a JS-free visitor produces with the same clicks; the controller never calls Umami (AC-12). |

## Open questions for the owner — resolved 2026-11-27: all (R) defaults accepted

Written to the **recommendation** (R); the plan and task list follow R until answered.

- **Q1 — How to enhance.** (A) a lazy **Stimulus controller that re-requests the same `/browse`
  URL** and swaps `<main>` (R); (B) a **Live Component** for the search page; (C) **Turbo Frames**
  (new `@hotwired/turbo` package). Trade-offs: plan D1. A is the only one with no second door to the
  search, no new package, and unchanged limiter semantics.
- **Q2 — The 30/min per IP limit** (rate_limiter.yaml says "revisit at search-ui"). Each applied
  change is now one request where the JS-free visitor batched several ticks into one "Apply".
  (R) **keep 30/min**: the 400 ms debounce and submit-only text box bound a person well below it,
  a 429 keeps the page intact (AC-22), and it is the only guard against the 1.5 s planning-bound
  URL (ADR-0026 B9). Revisit with logs if 429s appear for real users (CGNAT/office IPs). Alternative:
  60/min.
- **Q3 — Request policy.** (R) 400 ms debounce on checkbox/select/number `change`; text box on
  submit only. Alternative: debounced search-as-you-type on the text box (rejected in R: on a
  CPU-bound box it multiplies the costliest requests — trigram fallback — for no new answer).
- **Q4 — History.** (R) `pushState` per update (Back undoes one filter). Alternative: `replaceState`
  (Back leaves `/browse` at once; fewer entries, but Back no longer means "undo").
- **Q5 — Mobile layout.** (R) facets stacked above results with a "Skip to results" link (no JS,
  no new pattern). Alternative: a collapsible `<details>` "Filters" panel (shorter page; but a
  `<details>` cannot be open on desktop and closed on phones without JS).
- **Q6 — ADR.** (R) a **new ADR-0028** ("the search page is enhanced by re-requesting itself; the
  URL is the state") + dated amendments to ADR-0026 (one update = one request = one token; the
  client's half of the rate contract) and ADR-0027 (when the state is the URL, no Live Component;
  the image `ls` rule applies to app controllers). Alternative: amendments only.
- **Q7 — Roadmap numbering.** The roadmap says the slot is "for slice 12"; after the contact-channel
  slices were inserted, 12 is `listing-renewal-reminder` and the consumer is **13,
  `search-alert-subscribe`**. (R) fix the roadmap text in T15.
- **Q8 — `noindex`.** (R) keep it (search-browse Q13): canonical URLs belong to a launch/SEO slice.
- **Q9 — Analytics.** (R) accept one Umami page view per pushed URL — identical to the JS-free count.
  Alternative: suppress (would need a tracker option and makes the two paths report differently).
- **Q10 — "Apply filters" with JS on.** (R) keep it visible: it is the text box's and the range
  boxes' explicit commit on mobile, where `change` fires only on blur. Alternative: hide it with JS
  (then the text box needs Enter, which mobile keyboards show as "Go" — workable, less obvious).
