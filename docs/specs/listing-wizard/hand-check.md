# listing-wizard — hand-check (T15 / AC-27)

**Date:** 2026-11-27 · **Branch:** `feature/listing-wizard` (HEAD `31edfc9` + uncommitted docs) ·
**Runtime:** dev stack, `http://localhost:8080`, Chromium via Playwright (headless) ·
**Seller:** fresh account `wizard-hc-1127@example.com`, registered at `/register`, verified with
`muzbar:identity:verify-email`.

Dev tree used: `Instruments › Guitars › {Electric, Acoustic}` and `Vintage` (leaves). Schema:
Condition, Fretboard wood (both required, on Instruments), Brand, Has case, Strings (on Guitars),
Serial number (Electric only). So Electric → Acoustic keeps five shared fields and drops Serial number.

## Results

| # | Check | Result | Evidence |
|---|---|---|---|
| 1 | `/sell`: basics typed, leaf chosen → Details, status line, basics kept, "Save and publish" appears; morph is a POST with no query string | **PASS** | Chose Electric: exactly one request, `POST http://localhost:8080/_components/ListingForm` (no query string, 1039 B body), 200. `role="status"`: "Details for Instruments › Guitars › Electric"; 6 detail fields; `details_for` = Electric's id. Title "HC Strat 1127", description, price `1250.50`, currency `USD` unchanged after the morph. Buttons before: "Save draft"; after: "Save draft", "Save and publish". |
| 1b | AC-9: only the category morphs | **PASS** | Filling Brand, Strings, Serial number, Has case issued **0** `_components` requests; the next category change issued exactly 1. |
| 2 | Electric → Acoustic: shared values kept, non-shared gone | **PASS** | Before: Brand=Fender, Has case=Yes, Strings=6, Serial number=SN-123. After the morph: Brand=Fender, Has case=Yes, Strings=6; Serial number field absent; status "Details for Instruments › Guitars › Acoustic"; basics unchanged. |
| 3 | Keyboard: focus stays on the category select after a morph | **PASS** | Focused the select, pressed `ArrowUp` (Electric → Acoustic) on `/sell` and again on the edit page: after the re-render `document.activeElement` = `SELECT#listing_form_category` both times. |
| 4 | Fill required details, "Save and publish" from `/sell` → `/listings/{id}`, public | **PASS** | Condition=Used, Fretboard wood=Rosewood → redirected to `/listings/01a21a23-9a6c-79f1-b951-e90308ebd185`; page shows title, `1250.50 USD`, Acoustic, all five details. The same URL in a fresh **anonymous** context: 200, same content. |
| 5a | Edit page of a draft: morph away and back → stored value re-appears | **PASS** | Draft "HC Draft Tele 1127" (Electric, Serial number=SN-TELE-9, Strings=6). On its edit page: title edited, morph → Acoustic (Serial number gone, Strings=6, edited title kept), morph → Electric: Serial number=**SN-TELE-9** again (stored fallback, AC-4), edited title still kept. |
| 5b | Edit page re-file + "Save and publish" with the new category's details publishes | **PASS** | Re-filed Electric → Acoustic (by keyboard), Condition=New, Fretboard wood=Maple, "Save and publish" → `/listings/01a21a23-b526-71d6-a682-18d809f5a28e`, published under Acoustic with Condition, Fretboard wood, Strings; Serial number dropped (not in Acoustic's schema, L-10). |
| 6 | JS disabled (`newContext({javaScriptEnabled: false})`) | **PASS** | `/sell`: 0 fieldsets, only "Save draft"; choosing Electric rendered no Details. "Save draft" → `/sell/drafts/{id}/edit` with flash **"Draft saved. Add the details below."** (verified on a second draft, `01a21a24-57f5…`). Edit page JS-free: Details for Electric + "Save and publish"; filled Condition/Fretboard wood/Brand/Serial number → published at `/listings/01a21a24-3868-7f55-9d7f-85cdf1612002`. Zero `_components` requests in the whole context. |
| 7 | No console errors on `/sell`, edit, `/login`; overlay still opens on `/browse` | **PASS** | Across register, login, `/sell` (4 morphs), edit (3 morphs), publish: 34 `log` + 2 `verbose` entries, **0 errors, 0 warnings, 0 page errors**. The `log` lines are Stimulus's dev debug output (`application #starting` / `#start`, `details: {application}`); the verbose ones are Chromium's autocomplete hint on `/register`. Anonymous context: `/login` and `/browse` no errors; clicking the `data-auth-overlay` trigger ("Sell Gear") on `/browse` opened a `<dialog open>` with the password field, URL still `/browse`. |

**Verdict: AC-27 met — 7/7 (plus 1b).**

Notes:
- Playwright's `selectOption()` does not move focus, so focus was checked with a real keypress
  (`ArrowUp` on the focused select), which is how a keyboard user changes it.
- "Listed on 26 Nov 2026": the box clock is UTC (23:1x UTC on 26 Nov) — not a defect.

## Data created in the dev DB

- User `wizard-hc-1127@example.com`, verified.
- Listings, all by that user:
  - `01a21a23-9a6c-79f1-b951-e90308ebd185` "HC Strat 1127" — published (Acoustic)
  - `01a21a23-b526-71d6-a682-18d809f5a28e` "HC Draft Tele 1127 (edited)" — published (Acoustic)
  - `01a21a24-3868-7f55-9d7f-85cdf1612002` "HC NoJS Les Paul 1127" — published (Electric)
  - `01a21a24-57f5-7b51-a2e2-811036136cfd` "HC NoJS flash 1127" — draft (Acoustic)
- Three `ListingPublished` events dispatched (no listener). One verification mail in Mailpit.
