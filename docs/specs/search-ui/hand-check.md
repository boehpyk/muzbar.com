# search-ui: hand-check log (AC-14 … AC-24, mutations (k)–(r))

`/verify`, 2026-11-27. Headless Chromium (Playwright) driving the dev stack (`localhost:8080`,
asset mapper dev server, so every mutation was served on the next load). Fixture: the dev tree
(Instruments › Guitars › Acoustic: 4 choice facets, a boolean, a number range, prices in two
currencies). For the run, Acoustic was topped up to 30 published listings by cloning its 5 published
rows 5× in SQL (titles `… (handcheck n)`), so the page had a pager. The clones were deleted afterwards.
Requests were counted from the browser's own request log: `fetch` = an enhanced update,
`document` = a navigation.

## Iteration 1 found a failure: AC-17 (reviewer MAJOR 1, reproduced)

Pressing "Skip to results" and then Back sent **1 request** and moved focus to the `<h1>`. Cause:
`connect()` marks the loaded entry `{search: true}`, so the `popstate` back into it passed the
`state.search` guard. **Fix:** `restore()` also returns early when the popped URL without its hash
equals what `<main>` already shows (`this.shown`). Reviewer MAJOR 2 (focus lost to `<body>` after
"Apply filters", which has no id) was fixed in the same pass. An id-less focused control inside
`<main>` now sends focus to the heading. Every row below is from the fixed code.

## Results

| AC | Check | Result |
|---|---|---|
| 14 | Space on a checkbox | 1 fetch, 0 navigations, no reload; facets, total and hits updated ("6 listings") ✅ |
| 14 | 3 checkboxes ticked 100 ms apart | **1** fetch carrying all three ✅ |
| 14 | Currency `<select>` change | 1 fetch, no reload ✅ |
| 14 | Typing in the text box and a number box | 0 fetches; Tab out of the number box → 1 ✅ |
| 15 | Enter in the text box | fetch started after 14 ms (no debounce), 1 fetch ✅; text box left without Enter → 0 ✅ |
| 16 | `?…&junk=1&f[x]=1&q=` + one tick | address bar = `data-search-url` (hostile and empty params gone); title "Acoustic"; reload → same URL, same box ticked ✅ |
| 17 | Two ticks, Back, Forward | 2 entries; Back → 1 fetch, previous state; Forward → 1 fetch, both ticked; no new entries ✅ |
| 17 | "Skip to results", then Back | **0** fetches (1 before the fix); a further Back → 1 fetch to the previous search ✅ |
| 18 | Pager "Next" / breadcrumb "Guitars" / 0-result "Clear filters" | 1 fetch each, no reload, focus `#search-heading`, `<main>` scrolled to top (0 px); title "Guitars"; `q` dropped ✅ |
| 18 | Listing link; Ctrl+click on "Next" | listing link navigates (0 fetches); Ctrl+click opens page 2 in a new tab, 0 fetches, page stays ✅ |
| 19 | Keyboard Space on a checkbox | focus back on the same id; Tab continues to the next checkbox ✅ |
| 19 | "Apply filters" by keyboard | focus `#search-heading` (was `<body>` before the fix) ✅ |
| 19 | Typing "acou" + ArrowLeft into the text box while a tick's request is in flight (delayed 1.5 s) | after the swap: value "acou", caret 3, focus on `search-q` ✅ |
| 20 | In flight / after | `aria-busy="true"` on `<main>` and results at opacity 0.5; afterwards no `aria-busy`, status "6 listings" ✅ |
| 21 | Tick A (response delayed 3 s), then tick C | A's request `net::ERR_ABORTED`; page ends at A+C (2 filters in the URL) ✅ |
| 22 | 429 with `Retry-After: 17` (stubbed response) | no navigation, tick kept, status "Too many searches from this address. Try again in 17 seconds.", `aria-busy` cleared ✅. Also seen against the **real** dev limiter (30/min), which the run exhausted: "Clear filters" stayed put on a genuine 429. |
| 23 | Stubbed 500 / 404 / connection refused | each ends in a full navigation (1 `document` request) to the request URL ✅ |
| 24 | JavaScript disabled | tick + "Apply filters" → navigation, box ticked, "6 listings"; breadcrumb link navigates ✅ |
| 24 | 375 px | no horizontal scroll; facets above results; checkbox labels ≥ 44 px tall; "Skip to results" on focus at top 8 px, 42 px tall, topmost element ✅ |

## Client-side mutations: each applied alone, then reverted

| | Mutation | Observed | Bit? |
|---|---|---|---|
| (k) | `data-action="input->search#change"` on the text box | `SearchUiScanTest::testTheTemplateBindsNoKeystrokeEvent` fails. **By hand it would not bite:** `change()` ignores `#search-q`, so the scan is its only catcher. | ✅ (scan) |
| (l) | Controller moved out of the build context (the `ls` target is missing) | `docker build --target production` fails: `ls: cannot access 'public/assets/controllers/search_controller-*.js'`. Positive control: the same build with the controller passes, and the image holds `search_controller-ikIfCqg.js` with the fix in it. | ✅ |
| (m) | Debounce 0 | 3 ticks → **3** fetches | ✅ |
| (n) | No abort in `supersede()` | page showed A+C, then A's late response overwrote it: final A only, 1 filter in the URL | ✅ |
| (o) | `restore()` handles every `popstate` | Skip + Back → 2 fetches, Forward → 1 (fixed code: 0) | ✅ |
| (p) | 429 not special-cased | the page navigated away | ✅ |
| (q) | `pushState(url)` (request URL) | address bar ≠ `data-search-url`; empty `q=`, `r[…]`, `price[…]` kept | ✅ |
| (r) | No `refocus()` | focus `<body>` after Space on a checkbox | ✅ |

## Known ceilings (reviewer MINORs, not fixed; reported to the owner)

- A 429 during a Back/Forward restore leaves the address bar on the popped URL while `<main>` still
  shows the previous search. A reload fixes it.
- A second 429 with the same `Retry-After` writes identical text into the status line, which screen
  readers may not announce again.
- Back pressed *while* a form update is in flight, to an entry showing the current search, is
  skipped (nothing to re-render). The update then lands and pushes its URL, clearing Forward. This
  only happens within the request window (reviewer, iteration 2).
