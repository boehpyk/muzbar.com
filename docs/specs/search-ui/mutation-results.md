# search-ui: mutation results

Server-side and scan mutations (a)-(j), each applied **alone** to production code, then reverted
(`git checkout -- <file>`; `git diff` over `src/templates`, `src/assets`, `src/src` empty after each).
Run against `BrowseUiTest`, `SearchRouteTest` and `SearchUiScanTest`, 2026-11-27. All ten bit on the
first try; none needed a strengthened test.

| | Mutation | How applied | Failing test(s) |
|---|---|---|---|
| (a) | Fragment for `Sec-Fetch-Mode: cors` | `BrowseController::browse()` returns a bare `<main>` when the header is `cors` | `BrowseUiTest::testTheControllersFetchGetsTheSameBodyAsANavigation` (all 3 URLs), `SearchUiScanTest::testNoSearchHttpClassBranchesOnRequestHeaders` |
| (b) | Second route | `#[Route('/browse/fragment')]` method on `BrowseController` | `SearchRouteTest::testTheOnlySearchRouteIsGetBrowse` |
| (c) | `data-search-url` from the raw query string | `path('app_browse')?{{ app.request.queryString }}` | `BrowseUiTest::testTheSearchUrlHoldsTheSurvivingQueryAndNothingHostile`, `SearchUiScanTest::testTheBrowseTemplateFillsTheAlertSlot...` |
| (d) | Status line inside `<main>` | `<p role="status">` moved into `main.search-browse` | `BrowseUiTest::testTheControllerWrapsMainAndTheStatusLineIsOutsideItAndEmpty` |
| (e) | Checkbox ids from `loop.index` | `id="search-f-{{ loop.index }}"` | `testACheckboxHasTheSameIdWithAndWithoutASecondSelection`, `testEveryControlHasAUniqueIdAndAnAccessibleName` |
| (f) | Empty state on `hits is empty` | `{% if result.hits is empty %}` replaces `result.total == 0` | `BrowseUiTest::testAPagePastTheEndIsNotAZeroResultState` |
| (g1) | "Clear filters" keeps `q` | `{category: categoryId, q: q}` in the empty block | `BrowseUiTest::testAZeroResultTextSearchNamesTheEscapedTermsAndClearFiltersDropsThem` |
| (g2) | "Search all categories" keeps `f` | `f: f` added to its `path()` | `BrowseUiTest::testAZeroResultSearchInACategoryOffersClearFiltersAndAllCategoriesWithoutCategoryBoundFilters` |
| (h) | Alert slot when `total > 0` | `<div data-search-alert-slot>` in the hits branch | `BrowseUiTest::testTheAlertSlotIsOnAZeroResultPageOnceInsideTheEmptyStateAndOnlyThere` |
| (i) | `noindex` dropped | `head` block removed | `BrowseUiTest::testEveryBrowsePageIsNoindexExactlyOnce` |
| (j) | Lazy marker removed | first line of `search_controller.js` deleted | `SearchUiScanTest::testTheControllerIsLazyHasOneFetchAndTouchesNothingForbidden` |

(k)-(r) are client-side and proved by hand at `/verify` (T13); (k) is also covered by the AC-12 scan
(`testTheTemplateBindsNoKeystrokeEvent`, `testTheControllerIsLazy...`'s `'input'` listener check),
which was not separately mutated here.

Client-side (k)-(r) and (l): see [hand-check.md](./hand-check.md). All bit at `/verify`, 2026-11-27.
