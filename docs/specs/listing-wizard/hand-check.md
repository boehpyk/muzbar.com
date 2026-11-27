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

## 2026-11-27 — recheck after /verify fixes

**Branch:** `feature/listing-wizard` (HEAD `95c6da2` + uncommitted rename of the Details fieldset
class to `listing-form-details`; `make sass` run first) · **Runtime:** dev stack, Chromium via
Playwright (headless) · **Seller:** fresh account `wizard-rc-mwyrjmt3@example.com`, verified with
`muzbar:identity:verify-email`.

| # | Check | Result | Evidence |
|---|---|---|---|
| 1a | `/sell` Details fieldset layout | **PASS** | `fieldset.listing-form-details`: computed `display: block`, `grid-template-columns: none`. Legend, status line and all six field rows at the same x (41) and width (804), stacked in one column, same label-over-control look as the basics. Screenshot: `scratchpad/recheck-sell-details.png`. |
| 1b | Edit page Details fieldset layout | **PASS** | Same: `display: block`, all children x=41 w=804. Screenshot: `scratchpad/recheck-edit-details.png`. |
| 1c | Public `/listings/{id}` `dl.listing-details` | **PASS** | `display: grid`, `grid-template-columns: 256px 512px`; every `dt` at x=24 with its `dd` at x=280 on the same row (5 pairs). Screenshot: `scratchpad/recheck-public-listing.png`. |
| 2 | Dimming during a morph (`/_components/**` delayed 1.2 s) | **PASS** | 400 ms into the request: root `aria-busy="true"`, fieldset opacity `0.5` (status still "Electric"). After the response: `aria-busy` absent, opacity `1`, status "Acoustic". |
| 3a | Aborted morph (`route.abort()`): does the busy state clear? | **FAIL (contract gap)** | Edit page, after a good morph to Acoustic, changed the select back to Electric with the request aborted (`POST /_components/ListingForm net::ERR_FAILED`). Sampled every 500 ms for 5 s: `aria-busy="true"` and opacity `0.5` at **every** sample — it never clears. Live logs two uncaught `TypeError: Failed to fetch` (from `Backend.makeRequest` → `performRequest`), shows no error dialog, and leaves the pending request set. **The component is wedged:** after reloading, aborting one morph and then removing the abort, the next category change sent **0** `_components` requests in 3 s; Details stayed on the old category, still dimmed. No field or button was disabled. |
| 3b | Save in that state (select = stored Electric, Details = Acoustic) | **PASS** | "Save draft" → **422** on `/sell/drafts/{id}/edit`, message "The details shown are for another category. Reload the page." |
| 3c | Reload after the 422 | **PASS** | GET 200: title, price `900.00`, `EUR`, category Electric, Details for Electric with stored Brand=Gibson, Strings=6, Serial number=SN-RC-TELE intact; `aria-busy` absent. |
| 4a | Basics + shared value kept across a morph | **PASS** | Electric → Acoustic: title, description, `1250.50`, `USD` unchanged; Brand=Fender, Has case=Yes, Strings=6 kept; Serial number gone. |
| 4b | Focus after an `ArrowUp` change | **PASS** | `document.activeElement` = `listing_form_category` both during and after the morph. |
| 4c | "Save and publish" from `/sell` | **PASS** | Landed on `/listings/01a21c48-793c-76d8-b58d-b10529713641`, title and five details shown. |
| 4d | No console errors in the normal flow | **PASS** | Register, login, `/sell` (2 morphs), publish, draft save, edit morph: 0 errors / 0 warnings. The only errors logged are the deliberate aborts (`ERR_FAILED` + 2× `TypeError` each), the deliberate 422, and a 422 from `/register` (email already taken). |

**On 3a:** the page "stays as it was" (nothing is lost, every control stays enabled, and a native
submit still works and is caught by the details-for guard) but it does **not** recover by itself:
the Details stay dimmed and further category changes never reach the server until the page is
reloaded. Recovering would take our own handling of a failed Live request (not
investigated here); until then the server-side guard is what keeps a wedged page from saving the
wrong details.

Notes:
- Cosmetic: the "required to publish" hint renders *below* its select, directly above the next
  field's label, so it reads as belonging to the next field (visible in both screenshots).
- The Playwright console log already held earlier entries (an unrelated session's aborts and a 422 on
  draft `01a21c45-aecd…`) before this run; only entries from this run are counted above.

### Data created in the dev DB (this recheck)

- User `wizard-rc-mwyrjmt3@example.com`, verified.
- `01a21c48-793c-76d8-b58d-b10529713641` "RC Strat 1127" — published (Acoustic).
- `01a21c48-99ef-7aa2-867e-2a3ed2644c6b` "RC Draft Tele 1127" — draft (Electric; Brand, Strings,
  Serial number).
- One `ListingPublished` event dispatched (no listener). One verification mail in Mailpit.
