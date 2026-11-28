# ADR-0028: The search page is enhanced by re-requesting itself; the URL is the state

- **Status:** Accepted
- **Date:** 2026-11-27
- **Established by** the `search-ui` slice ([spec](../specs/search-ui/feature-spec.md) Q1–Q10;
  [plan](../specs/search-ui/technical-plan.md) D1–D4, D7; owner accepted the recommendations,
  2026-11-27) — Phase 2 item 7.
- **Applies** [ADR-0015](./0015-intended-action-redirect-and-login-overlay.md) (plain JS when there
  is no server-held state; no GET writes; a click never does nothing),
  [ADR-0023](./0023-search-is-a-read-only-projection-over-catalog-and-listing-tables.md) (Search
  writes nothing) and [ADR-0026](./0026-search-latency-proven-on-a-seeded-benchmark-database-on-the-box.md)
  (the box is CPU-bound; `/browse` is rate-limited per IP).
- **Distinguishes itself from** [ADR-0027](./0027-symfony-ux-under-assetmapper-live-component-renders-controller-writes.md)
  decision 1 (a Live Component for server-held state that changes as the user edits).
- **Amends** ADR-0026 and ADR-0027 — see the dated 2026-11-27 amendments at the foot of each.

## Context

`/browse` (search-browse, search-range-facets, search-full-text) is a JS-free `GET` form: every
tick, range or text search is a full page load. PRD FR-3 asks that facets "update dynamically based
on the category selected", and §7's narrative is a buyer on a phone narrowing to one instrument in a
few taps. Something has to update the page without a reload.

The repository already has two ways to do that — a Live Component (ADR-0027) and plain JS (the login
overlay, ADR-0015) — and a third is one package away (Turbo). Three facts decide between them:

- **A search has no state but its URL.** Everything it is — category, facets, ranges, price, text,
  page — is in the query string, and `BrowseController` already renders the whole answer for any URL,
  with links that carry only what survived (search-browse AC-24). The listing form is the opposite
  case: a half-filled form is state the server must hold and re-render as it changes.
- **`/browse` has one trust boundary and one limiter.** `BrowseController` normalises hostile query
  shapes (the `InputBag` footguns) and consumes one `browse` token per request before any statement
  (ADR-0026: one 20-selection URL costs ~1.5 s of planning). A second door has to repeat both.
- **The box is CPU-bound** (ADR-0026). Requests per visitor are a cost, not a free variable.

## Decision

1. **A lazy Stimulus controller (`search`) re-requests the page itself.** An applied change fetches
   exactly the URL the JS-free form or link would have navigated to (`form.action` +
   `URLSearchParams(FormData)`, or the link's `href`), parses the HTML and swaps
   `main.search-browse`. No new package; Stimulus is already in prod (ADR-0027).
2. **One route, one response.** No fragment endpoint, no header-dependent rendering, no Live
   Component under `Infrastructure/Search/`. The server is not told and cannot tell which path asked
   (search-ui AC-1: identical bodies for a navigation and the controller's `fetch`; AC-2: the only
   Search route is `app_browse`). So every update consumes exactly one `browse` token, by
   construction.
3. **The URL pushed is the response's, not the request's.** `<main>` carries `data-search-url` — the
   surviving query, built from the same variables the page's links use. After a swap the controller
   `pushState`s it, so the address bar sheds hostile, unknown and empty parameters after one update.
   Back/Forward re-request entries the controller pushed (`state.search`) and nothing else.
4. **Request policy — the client's half of ADR-0026's rate contract.** Checkbox, `<select>` and
   number `change`s are debounced 400 ms (trailing); a submit or a link click goes at once; the text
   box searches on submit only; **no `input` listener anywhere** (no search as you type); a newer
   update aborts the one in flight (`AbortController`) and a superseded response is never applied.
5. **Failure: a click never does nothing.** `429` → the page stays as it is and the status line says
   when to retry (`Retry-After`) — a navigation would also be a 429 and lose the selection. Any
   other non-200, a network error, or a 200 without `main.search-browse` → `location.assign(url)`:
   the visitor gets what the JS-free path gives.
6. **Standing rule for later pages.** A public page whose whole state is its URL is enhanced by
   re-requesting its own URL. A Live Component is for server-held state (ADR-0027 d.1). A fragment
   endpoint or a header-dependent render needs **its own ADR**, because it is a second rendering
   path that drifts from the first and, on `/browse`, a second place to keep the limiter.

## Alternatives

- **A Live Component for the search page.** Morphing keeps focus for free, but: two doors (the page
  for the first render, `/_components/…` for re-renders), the shape normalisation moved or
  duplicated into the component, a live endpoint that **bypasses the `browse` limiter** unless the
  component consumes it itself, and nested `f[<uuid>][]` / `r[<uuid>][min]` / `price[…]` mapped onto
  writable `url: true` props — the URL's state duplicated into checksummed props. Rejected: it buys
  morphing with a second door and a forgettable limiter.
- **Turbo Frames** (`@hotwired/turbo`, likely `symfony/ux-turbo`). One door and history for free,
  but a new importmap package (new image `ls`, new deploy check), and Turbo Drive is global unless
  switched off — every link on the site, the login overlay's included, would change behaviour. A
  Stimulus controller is still needed to submit on `change`. Rejected: a site-wide package for one
  page.
- **Search as you type** (debounced `input` on the text box). Rejected: on a CPU-bound box it
  multiplies the costliest requests (the trigram fallback) for no new answer, and burns the 30/min
  bucket.
- **`replaceState` instead of `pushState`.** Fewer history entries, but Back would leave `/browse`
  at once instead of undoing one filter. Rejected (Q4).

## Consequences

- **Replacing `<main>` re-creates every control**, so focus restoration is the controller's job:
  stable, meaning-derived ids on every control (`search-q`, `search-f-<attr>-<value>`, …), and the
  `<h1>` (`#search-heading`, `tabindex="-1"`) as the fallback and the target after a link. If that
  proves brittle, the upgrade is a morph library — a new dependency, so an amendment here, not a
  silent add.
- **The status line lives outside `<main>`** — a persistent `role="status"` region the controller
  rewrites; a freshly inserted one is often not announced. Moving it inside `<main>` breaks the
  announcement silently.
- **One click is one request** where the JS-free visitor batched several ticks into one "Apply".
  The debounce bounds it; the limit stays 30/min per IP (ADR-0026 amendment, 2026-11-27).
- **Umami** counts one page view per pushed URL (path only, `data-exclude-search`) — the same count a
  JS-free visitor's clicks produce. ADR-0003 is unchanged; the controller never calls Umami.
- **The compiled controller exists in prod only because the image builds it** — the Dockerfile `ls`
  names it and `deploy.yml` fetches it from `/login`'s importmap (ADR-0027 amendment, 2026-11-27).
- **The 0-result state carries an alert slot** (`search/_alert_slot.html.twig`, given only the
  surviving query as `search`) for `search-alert-subscribe` — the query's shape has one definition,
  the one `data-search-url` uses.
