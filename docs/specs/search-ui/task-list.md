# Task List: search-ui

> Ordered, small tasks in DDD canonical order. Each should be reviewable in < 5 minutes and is one
> commit on `feature/search-ui`. Run `make check` before each commit. Check off as you go.
> **Owner answers:** pending (Q1–Q10 in the feature spec; written to the recommendation).

## Gate (owner-run, on the box — not an implementer task)
- [ ] T0: **ADR-0026 AC-10** — the 10 req/s arriving box re-run (runbook: docs/infrastructure.md
      §*Search: latency benchmark*; k6 `constant-arrival-rate`, per gating scenario, 0 dropped
      arrivals, every `{bn:Bn,phase:measure}` threshold with a non-zero count). Log it in the results
      log and record the verdict in ADR-0026's amendment. **The branch does not merge until this is
      logged** (AC-0). A FAIL is a stop-and-decide for the owner (ADR-0026 d.4/d.5), not a reason to
      change this slice.

## Decision record
- [x] T1: `/adr` → **ADR-0028** (the search page is enhanced by re-requesting itself; the URL is
      the state — plan D1–D4, D7, the trade-off table). Dated amendments: ADR-0026 (one update = one
      request = one token; 30/min kept, Q2), ADR-0027 (app controllers get the image `ls` + deploy
      fetch; "state is the URL" cross-reference).

## Domain
- (none) Search has no aggregate (ADR-0023) and this slice adds no rule — plan §Domain says why.

## Application
- (none) `BrowseListings` / its handler unchanged, one caller.

## Infrastructure — markup the controller relies on (JS-free behaviour unchanged)
- [x] T2: `browse.html.twig`: `searchParams` (controller variable or Twig — plan §HTTP),
      `data-search-url` on `<main>` (AC-3), wrapper `div[data-controller=search]` + status line
      outside `<main>` (AC-4), `#search-heading[tabindex=-1]`, `#search-results`, "Skip to results"
      (AC-6), `.search-total` (AC-7). No `data-action`s yet. Existing Search suites green unmodified.
- [ ] T3: Control ids per AC-5 (`search-q`, `search-f-<attr>-<value>`, `search-r-<attr>-min|max`,
      `search-price-currency|min|max`), labels kept. Suites green unmodified.
- [ ] T4: 0-result state (`section.search-empty`, heading "No listings found.", q named, "Clear
      filters", "Search all categories" — AC-8) + `<div data-search-alert-slot>` including the new
      empty `search/_alert_slot.html.twig` with `{search: searchParams} only` (AC-9). The page past
      the end keeps its "First page" branch. `noindex` untouched (AC-10).

## Infrastructure — styles
- [ ] T5: `_base.scss` `.visually-hidden`; `_components.scss`: busy dimming on
      `.search-browse[aria-busy="true"] .search-results`, `.search-empty` block, 44 px checkbox rows
      under 48rem, skip-to-results visible on focus (reuse `.skip-link`'s pattern). Tokens only.
      `make sass`; check 375 px for horizontal scroll.

## Infrastructure — the controller
- [ ] T6: `assets/controllers/search_controller.js` (`stimulusFetch: 'lazy'`): `submit` (immediate),
      `change` (400 ms debounce; ignore the text box's own `change`), `follow` (link interception per
      D8), one `fetch` with `AbortController`, swap `main.search-browse`, `pushState` the response's
      `data-search-url`, update `document.title`, status line, `aria-busy` (D2–D6).
- [ ] T7: `restore` on `popstate` (only `state.search`; `replaceState` on connect — D5); failure
      handling: 429 → status line from `Retry-After`, else `location.assign` (D7); focus restore
      incl. text value + selection (D6). Wire the `data-action`s on the wrapper (no `input->`).
- [ ] T8: Dockerfile `ls` for the compiled controller (path from `debug:asset-map`); `deploy.yml`
      fetch of its URL from `/login`'s importmap. Build the image locally once; prove mutation (l)
      by removing the `ls` target (AC-13).

## Tests (qa)
- [ ] T9: `tests/Functional/Search/BrowseUiTest.php` — AC-1, AC-3…AC-10 (fixtures as
      `BrowsePageTest`; `ClearsRateLimiters`). AC-1 compares 200 pages only.
- [ ] T10: Scans — AC-1 header scan, AC-2 (router: one Search route; no `AsLiveComponent`/
      `AsTwigComponent` under `Infrastructure/Search`), AC-9 include line, AC-12 controller source.
      Strip comments first; assert ≥ 1 file scanned.
- [ ] T11: AC-11 regression — every existing Search suite unmodified; `make test` twice in a row.
- [ ] T12: Mutation sweep (a)–(j) against the suite, each applied alone, each bites; results in
      `mutation-results.md`.

## Hand-check (at `/verify`)
- [ ] T13: Chromium, JS on/off, 375 px + desktop: AC-14…AC-24; mutations (k)–(r) proved by hand.
      Log in `docs/specs/search-ui/hand-check.md`. If a hand-check contradicts a green suite,
      suspect the runtime first (opcache, stale `var/cache`, `public/assets/` shadowing).

## Docs
- [ ] T14: CLAUDE.md — status line for `search-ui` + footguns (the page is the component: never a
      fragment endpoint or header branch without an ADR; the status line lives outside `<main>`;
      the controller's `ls` line; no `input` listener on `/browse`).
- [ ] T15: roadmap item 7 ticked with a "Shipped" note (item 7 wording "slice 12" → 13 already fixed at approval)
      `search-alert-subscribe` (Q7); FORboehpyk.md chapter (the URL is the state; why not Live/Turbo;
      cost as a design input).

## Verify
- [ ] T16: `/verify` → reviewer PASS, AC-0…AC-24 checked (AC-0 = the logged box run), docs updated.
