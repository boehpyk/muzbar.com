# Technical Plan: search-ui

> The *how*. Disposable. Written after the feature-spec is drafted, approved with it before any code.
> Follows DDD canonical order. Written to the **recommendation** of every open question (Q1–Q10 in
> the feature spec).

## Decisions

| # | Decision | Why |
|---|---|---|
| D1 | **A lazy Stimulus controller that re-requests the page itself** (Q1-A) | See the trade-off table below. |
| D2 | **Same route, same response.** The controller `fetch`es the exact URL the JS-free form/link would navigate to (`form.action` + `URLSearchParams(new FormData(form))`, or the link's `href`), parses the HTML, and swaps `main.search-browse` (`replaceWith`). The server is not told and cannot tell (AC-1). | One handler, one limiter, one template, one set of tests. A fragment endpoint or a header branch is a second rendering path that drifts. |
| D3 | **The URL pushed is the response's, not the request's.** `<main>` carries `data-search-url` (the surviving query, built in Twig from the variables links already use). After a swap the controller `pushState`s it. | "A search is a link" means the *canonical* link: the address bar sheds hostile, unknown and empty parameters after one update (AC-3, AC-16). |
| D4 | **Request policy** (Q3): checkbox/`<select>`/number `change` → 400 ms trailing debounce; `submit` and link clicks → immediately (cancelling a pending debounce); the text box's own `change` is ignored (submit only); **no `input` listener anywhere**. Each new update aborts the in-flight one (`AbortController`). | The box is CPU-bound (ADR-0026); clicks batch, keystrokes never search, superseded work is not applied. |
| D5 | **History** (Q4): `history.replaceState({search: true}, '')` on connect; `pushState({search: true}, '', url)` per update; `popstate` handled **only** when `event.state?.search` — fetch `location.href`, swap, no push. | Back = undo one update; a hash entry ("Skip to results", the layout's "Skip to content") is left to the browser (mutation (o)). |
| D6 | **Focus and announcement.** Before a form-driven update remember `document.activeElement.id` (and, for a text input, its value and selection); after the swap focus the element with that id (`preventScroll: true`), restoring value + selection for a text input that the user was typing in; else focus `#search-heading`. Link-driven updates focus `#search-heading` and `scrollIntoView` the main's top. The status line (outside `<main>`) gets the new `.search-total` text (+ `.search-similar` if present). `aria-busy="true"` on `<main>` while in flight. | WCAG 2.4.3 / 4.1.3 basics; a persistent live region is the reliable way to announce (a freshly inserted one often is not read). |
| D7 | **Failure** (failure contract): `429` → status line from `Retry-After`, page untouched; any other non-`200`, network error, or a `200` without `main.search-browse` → `location.assign(url)`; `AbortError` → ignored. | The overlay's rule (ADR-0015): a click never does nothing. A 429 navigation would also be a 429 and lose the visitor's selection. |
| D8 | **Link interception** is narrow: left click, no modifier, `a[href]` inside `<main>` whose `URL.pathname === '/browse'` and origin is the page's. Everything else (listing links, the overlay trigger, new-tab clicks) is untouched. | Same guard list as `app.js`'s overlay. |
| D9 | **0-result state + alert slot** in Twig only; the slot is `<div data-search-alert-slot>` + `{% include 'search/_alert_slot.html.twig' with {search: searchParams} only %}`; the partial is a comment today. | `search-alert-subscribe` (roadmap 13) gets the surviving query in the exact shape links use, so "the facet params" have one definition. |
| D10 | **ADR-0028** + ADR-0026 and ADR-0027 amendments (Q6). | See *Interface boundary* and T1. |

### D1 — the trade-off

| | **A. Stimulus + same-URL fetch (R)** | B. Live Component | C. Turbo Frame |
|---|---|---|---|
| New dependency | none (Stimulus 3.2.2 already in prod) | none (ux-live-component 3.8.1 in prod) | `@hotwired/turbo` (+ likely `symfony/ux-turbo`): new importmap entry, new Dockerfile `ls`, new `deploy.yml` check; Turbo Drive is global unless switched off, so every link on the site — including the login overlay's — changes behaviour |
| Doors to the search | **one** (`GET /browse`) | two: the page's controller for the first render, `/_components/…` for re-renders — the shape-normalisation trust boundary (the `InputBag` footguns in `BrowseController`) is duplicated or moved into the component | one (frame requests hit `/browse`) |
| Rate limit (ADR-0026) | unchanged: one update = one token, by construction | the live endpoint **bypasses** the `browse` limiter unless the component consumes it itself — a second place to keep the limiter, easy to forget | unchanged |
| URL / history | `pushState` of the server's canonical URL; Back = undo | `url: true` LiveProps would have to map nested `f[<uuid>][]`, `r[<uuid>][min]`, `price[…]` onto writable props; history behaviour is the library's | `data-turbo-action="advance"` gives push + restore |
| Debounce / no per-keystroke | in the controller (~10 lines) | `data-model="debounce(400)|…"` per field; the text box needs `norender` + a submit hook | needs a Stimulus controller anyway to `requestSubmit()` on change |
| Server-held state | none — the URL is the state | state would be duplicated into props (checksummed JSON in the page) | none |
| Focus after swap | by hand (D6) | idiomorph keeps it | by hand (frame replace) |
| Code | ~120 lines of JS, 0 PHP | a component class + template + prop mapping + the limiter + moving `BrowseController`'s parsing | a package + a controller + frame markup |

ADR-0027 decision 1 adopted Live Components for **server-side state that changes as the user
edits** (ADR-0015's rule of thumb) and its Alternatives rejected a hand-rolled fetch *for the
wizard* because it would have re-implemented form state. Here the reason inverts: the search has no
state but its URL, and the server already renders the full answer for any URL. B buys morphing
(focus for free) at the cost of a second door, a duplicated trust boundary and a bypassed limiter;
C buys history for free at the cost of a site-wide package. A is the shortest path that keeps one
door — and teaches the honest lesson (feature spec, "what this slice teaches", 1).

## Domain layer (pure PHP)

**No change — by design.**

- **Aggregate / entity changes:** none. `Search` has **no aggregate** (Constitution §4; ADR-0023):
  it writes nothing, so it has no consistency boundary and no invariant spanning a transaction. Its
  rules that *can be wrong* — which filters survive (S-5), the page clamp (S-8), range parsing
  (S-12), currency (ADR-0024), search terms (S-15) — are already pure functions on
  `FilterRequest` → `FacetedQuery::resolve()`. "Render it without a reload" adds no business rule.
- **Value objects:** none new, none changed. The surviving query that `data-search-url` and the alert
  slot carry is built in Twig from the variables `BrowseController` already passes (`categoryId`,
  `f`, `r`, `price`, `q`, `query.page`) — a presentation shape, not a Domain concept. (When
  `search-alert-subscribe` stores it, *that* slice defines Notification's own VO in its own words —
  ADR-0019 — not this one.)
- **Domain events:** none. Nothing is written.
- **Ports (interfaces):** none. `SearchPort` unchanged.

## Application layer

**No change.** `BrowseListings` / `BrowseListingsHandler` are called exactly as today, by exactly one
caller.

- **Command/Query:** `BrowseListings` (unchanged).
- **Handler:** unchanged; no transaction, no lock (ADR-0023 D6).
- **Idempotency:** a `GET` that writes nothing; an aborted or repeated update is harmless (the
  limiter token is spent either way — that is the server's half of D4).

## Infrastructure layer

- **Persistence:** none. No migration, no SQL change, no new statement.
- **HTTP / UI:**
  - `BrowseController`: **at most one addition** — pass `searchParams` (the array AC-3 and the slot
    both use: `{category, f, r, price, q, page}` with `page` only when > 1 and nulls dropped) so Twig
    builds it once. If the implementer finds Twig can build it as cleanly, the controller stays
    byte-identical; either way it reads no header (AC-1 scan).
  - `templates/search/browse.html.twig`:
    - wrap `<main>` in `<div class="search-page" data-controller="search" data-action="change->search#change submit->search#submit click->search#follow popstate@window->search#restore">`
      (exact action names the implementer's; **no `input->`**);
    - the status line inside the wrapper, before `<main>`:
      `<p class="visually-hidden" role="status" aria-live="polite" data-search-target="status"></p>`;
    - `<main class="search-browse" data-search-url="{{ path('app_browse', searchParams) }}">`;
    - `<h1 id="search-heading" tabindex="-1">`; "Skip to results" link (`#search-results`) before
      the form; `section.search-results` gets `id="search-results"`;
    - ids on every control per AC-5 (`for`/`id` or keep the wrapping labels and add ids);
    - the total becomes `<p class="search-total">`; `.search-similar` unchanged;
    - the 0-result branch `{% if result.total == 0 %}` → `section.search-empty` (AC-8) with the alert
      slot (AC-9); the existing `result.hits is empty` "No listings found." line is replaced by it
      for `total == 0` only — the page-past-the-end branch keeps its "First page" link.
  - `templates/search/_alert_slot.html.twig` — new, a comment only: "Filled by
    `search-alert-subscribe` (roadmap 13). Receives `search`: the surviving query, the same array
    as `data-search-url`."
  - `assets/controllers/search_controller.js` — new, `/* stimulusFetch: 'lazy' */`, behaviour D2–D8.
    Targets: `status`. No values (the URL comes from the swapped `<main>`). Plain DOM APIs only:
    `fetch`, `AbortController`, `DOMParser`, `FormData`, `URLSearchParams`, `history`.
  - Styles (`assets/styles/_components.scss`, `_base.scss` for the utility): `.visually-hidden`
    (the standard clip pattern; check none exists — none does today); `.search-browse[aria-busy="true"]
    .search-results { opacity: .5 }` (+ `prefers-reduced-motion` respected, no transition needed);
    `.search-empty` block (tokens only: `--surface`, `--faint` border, `--space-*`); checkbox label
    rows `min-height: 44px` under 48rem; the `<h1>` keeps the global `:focus-visible` ring (no
    `outline: none` on programmatic focus — a sighted keyboard user must see where focus went). No new colours; every
    colour from `tokens.css` (contrast notes there).
- **Async / schedule:** none.
- **External:** none. Umami unchanged (`data-exclude-search`); its tracker records a `pushState` as a
  page view of `/browse` — the same count the JS-free path produces (Q9).
- **DI wiring:** none. Stimulus bundle auto-registers controllers from `assets/controllers/`; no
  `controllers.json` change (that file lists package controllers only).
- **Image and deploy:** `docker/php/Dockerfile` — add the compiled
  `public/assets/controllers/search_controller-*.js` to the existing `ls` (confirm the path with
  `debug:asset-map` / a local image build); `.github/workflows/deploy.yml` — after the live
  controller's check, extract the controller's URL from `/login`'s importmap (the lazy controller is
  listed in every page's importmap, never modulepreloaded) and fetch it (200, `text/javascript`),
  with the existing "headers first, no `curl | grep -q` under pipefail" care. Using `/login`, not
  `/browse`, keeps the smoke check off the rate-limited route.

## Interface boundary & input contract

- **The only server surface is unchanged:** `GET /browse` with `category`, `f[<attr>][]`,
  `r[<attr>][min|max]`, `price[currency|min|max]`, `q`, `page` — parsed by `BrowseController`'s
  shape normalisation and the Domain, exactly as in search-browse / search-range-facets /
  search-full-text. The controller adds no parameter and no header.
- **The page → controller contract** (what the JS may rely on, and AC-3…AC-7 pin):
  `[data-controller~=search]` ⊃ `[data-search-target=status]` + `main.search-browse[data-search-url]`
  ⊃ `#search-heading`, `form.search-facets` (controls with AC-5 ids), `#search-results`
  ⊃ `.search-total`, optional `.search-similar`.
- **The controller → server contract:** `GET` only, `credentials: 'same-origin'`, `Accept:
  text/html`, no body, no custom header, no `/_components` URL; one request per update.
- **The slot contract** (for roadmap 13): `search/_alert_slot.html.twig` receives `search` and
  nothing else (`only`), rendered only when `result.total == 0`.

## ADR work (T1)

- **ADR-0028 — "The search page is enhanced by re-requesting itself; the URL is the state."**
  Decisions: D1 (with the trade-off table, B and C as Alternatives), D2 (one route, no header
  branch — AC-1/AC-2 as its tests), D3 (push the server's canonical URL), D4 (request policy as the
  client half of ADR-0026's rate contract), D7 (failure rule). **Standing rule for later pages:** a
  public page whose whole state is its URL is enhanced by re-requesting its own URL; a Live
  Component is for server-held state (ADR-0027 d.1); a fragment endpoint or header-dependent render
  needs its own ADR.
- **ADR-0026 amendment (dated):** with search-ui, one applied change = one `/browse` request = one
  limiter token; the limit stays 30/min per IP (Q2) because the client debounces and never searches
  per keystroke; a 429 keeps the page. Record AC-0's re-run result here (owner, T0).
- **ADR-0027 amendment (dated):** the image `ls` + deploy fetch rule applies to app controllers
  under `assets/controllers/` too (first one: `search`); cross-reference ADR-0028 as the
  "state is the URL" case its decision 1 does not cover.
- Not amended: ADR-0023 (no SQL, no table), ADR-0003 (Umami behaviour unchanged — noted in
  ADR-0028's Consequences), ADR-0015 (the controller writes nothing; its plain-JS rule is cited).

## Data & migrations

None. No table, column, index, function or trigger. Statement budgets unchanged (≤ 5 + k, k ≤ 20,
+ 1 outline read; ≤ 3 with no category).

## Test plan

- **Domain unit:** none new (no Domain change). `SearchIsolationTest` unchanged and green.
- **Functional** (`tests/Functional/Search/BrowseUiTest.php`, `KernelBrowser`, real DB, DAMA,
  `ClearsRateLimiters`; reuse `BrowsePageTest`'s fixture helpers — extract a trait only if copying
  more than one helper):
  - AC-1 three URLs × two header sets, bodies compared after `ReadsListingPages::normalised()`-style
    normalisation (the debug pages embed traces; compare `main.search-browse` outer HTML **and**
    full bodies with the trace stripped — compare like with like);
  - AC-3 hostile/unknown/empty params, page 1 vs 2;
  - AC-4, AC-5 (uniqueness across all controls; the Fender id with/without a second selection;
    each control's accessible name via wrapping label or `label[for]`), AC-6, AC-7;
  - AC-8 four cases: no-category 0-result, category 0-result with filters, text 0-result (q named,
    escaped — use `<b>` in `q`), page past the end (no `search-empty`);
  - AC-9 slot present only when total 0; AC-10 `noindex` on four pages.
- **Scans** (`tests/Unit/Infrastructure/Search/SearchUiScanTest.php` or alongside
  `SearchIsolationTest`): AC-1's header scan (≥ 1 file), AC-2 router + attribute scan, AC-9's
  include line, AC-12's controller-source scan. Strip comments first (the isolation-scan footgun).
- **Regression:** AC-11 — run the Search suites unmodified; `make test` twice (Redis limiter state).
- **Performance:** none new — no statement changed. AC-0 is the owner's box re-run.
- **Hand-check:** AC-14…AC-24 in Chromium (DevTools Network + Accessibility panes), JS on and off,
  375 px and desktop; mutations (k)–(r) proved by hand; logged in `hand-check.md` and
  `mutation-results.md` at `/verify`.

## Risks / open questions

1. **Replacing `<main>` re-creates every control**, so focus restoration (D6) is the price of not
   morphing. If hand-checks find it brittle (screen readers re-reading the region), the upgrade is a
   morph library — a new dependency, so an ADR-0028 amendment, not a silent add.
2. **A request per click raises per-visitor request rate** versus the JS-free batch submit. That is
   why AC-0 must be a measurement and why Q2 is asked; the debounce is the mitigation.
3. **CGNAT / office IPs share one 30/min bucket** (rate_limiter.yaml's own note). Accepted (Q2);
   the signal is 429s in nginx logs for `/browse`.
4. **Umami's history hook** counts a page view per `pushState`. Accepted (Q9); hand-check only if a
   local Umami is configured — it usually is not.
5. **`stimulusFetch: 'lazy'` + AssetMapper path.** The compiled file name/directory must be read
   from `debug:asset-map`, not guessed; the image `ls` is what proves it (the public-styles lesson —
   no in-process test fetches an asset).
6. **The test-env debug 404 echoes the request**, so AC-1 compares only 200 pages (category,
   0-result, text) — never a 404 body.
7. Owner questions Q1–Q10: feature spec, last section.
