# listing-wizard: mutation sweep (a)-(r)

Each mutation applied **alone** to production code, then the eight related test files run
(`ListingFormComponentTest`, `SellWizardTest`, `SellTest`, `SellPublishTest`, `ListingFormIsolationTest`,
`CategoryTreeLockTest`, `ListingPublishHandlersTest`, `ListingTest`; 274-275 tests), then restored with
`git checkout -- <file>`. Test names are abbreviated; the class is given once per row.

| | Change applied | Caught by | Message excerpt |
|---|---|---|---|
| (a) | `ListingForm`: add a `#[LiveAction] save(ReviseListingHandler $h)` | `ListingFormIsolationTest::testTheComponentDeclaresNoLiveActionSoTheLiveEndpointCannotWrite` (+ `testTheComponentReferencesNoHandlerRepositoryOrCategoriesPort`) | "A #[LiveAction] is a second door to the aggregate (ADR-0027)." |
| (b) | `SellController::edit`: `$detailsFor` always the stored category, ignoring posted `details_for` | `SellWizardTest::testRefilingToBassWithBassDetailsBuildsTheFormForBassAndStoresTheValues` (+2 more AC-15 tests) | expected 303 to `/sell/drafts/{id}/edit`, got a 422 page (extra fields) |
| (c) | `edit`: `$publish = $publishClicked` (drops `$detailsFor === $data->category`) | `SellWizardTest::testRefilingWithTheOldCategorysDetailsKeepsTodaysBehaviourAndDoesNotPublish`, `SellPublishTest::testSaveAndPublishWithAChangedCategorySavesTheRefileDoesNotPublishAndSaysSo` | redirect to `/sell/drafts/{id}/edit` expected, listing published instead |
| (d) | `ListingFormBuilder::details`: drop the `!isset($offered[$categoryId])` check | `ListingFormComponentTest::testACategoryNotAmongTheOfferedChoicesRendersNoDetailsAndReadsNoSchema` (5 data sets, plus the other morph tests) | `DriverException ... invalid input syntax for type uuid: ""` (a schema query runs for a category never offered) |
| (e) | `ListingForm::morph`: replace the ownership `find()` by a stub row | `ListingFormComponentTest::testAnotherUsersReplayOfAListingsPropsIsTheSame404AsAnUnknownIdAndLeaksNothing`, `testAPublishedListingsCategoryIsDisabledAndAForcedMorphChangesNothing` | "Failed asserting that the Response status code is 404. HTTP/1.1 200 OK" |
| (f) | `morph`: seed `$attributes = $submitted` (no stripping of non-schema keys) | **SURVIVED, then strengthened:** `ListingFormComponentTest::testMorphLeavesNoAttributeKeyOutsideTheNewSchemaInTheStateItHandsOn` | "Only the new schema's keys survive." (extra attribute id in `formValues`) |
| (g) | `morph`: remove the stored-value fallback (`elseif (false)`) | `ListingFormComponentTest::testBassToElectricAndBackShowsTheStoredValueAgainAndSavingKeepsIt` (+3) | "Control: the stored value pre-fills." |
| (h) | `morph`: a submitted `''` no longer wins over the stored value | `ListingFormComponentTest::testADeliberateClearOfAStoredValueIsNotUndoneByTheNextRender` | "The field was on the previous render, so its empty value wins over the stored one." |
| (i) | `ListingFormBuilder::details`: `$keep = array_values($stored)` (all stored values) | `ListingFormComponentTest::testAnArchivedOptionsIdStoredAsATextValueNeverReachesTheOptionsQueryOrAnotherSelect` (+ ~30 others) | `invalid input syntax for type uuid: "5"` (a text value reached the options query) |
| (j) | `DraftListingHandler`: `AttributeInput::fromArray([])` | `ListingPublishHandlersTest::testDraftingWithPublishPublishesStampsTheClockAndDispatchesExactlyOneEvent`, `SellWizardTest::testSellPostWithDetailsAndSaveDraftPersistsTheDraftWithItsValuesAndRedirectsToItsEditPage` (+5) | `ListingIncomplete: Required attributes without a value: ...` |
| (k) | `DraftListingHandler`: dispatch events inside the `holdSteady()` closure | `CategoryTreeLockTest::testDraftAndPublishSavesInsideHoldSteadyAndDispatchesOutsideIt` | "ListingPublished was dispatched inside holdSteady(): a rollback would have un-published it after a listener saw it." |
| (l) | `DraftListingHandler`: when publishing, first draft-save in its own `holdSteady()` | `SellWizardTest::testSaveAndPublishWithARequiredDetailEmptyIs422OnThatFieldAndWritesNothingAtAll`, `ListingPublishHandlersTest::testDraftingWithPublishAndARequiredDetailEmptyLeavesNoRowAndDispatchesNothing` (+2) | "No listing row may change." (row left behind) |
| (m) | `ListingForm`: `#[AsLiveComponent(method: 'get')]` | `SellWizardTest::testTheLiveComponentPostsItsStateSoNothingSensitiveRidesInAUrl`, `testTheLiveEndpointRefusesAGetSoStateCanNeverBeSentInAQueryString` | "a morph is a POST; "get" would put the CSRF token and text in the URL." / expected 405, got 200 |
| (n) | `SellController::detailsFor`: accept any string without the `isset($offered[$value])` check | `SellWizardTest::testATamperedDetailsForOnTheEditPageIsTreatedAsTheStoredCategory` (4 data sets) | expected redirect to `/sell/drafts` (303) |
| (o) | `SellController::new`: `$withDetails = null !== $detailsFor` | `SellWizardTest::testDetailsRenderedForAnotherCategoryAreNotForwardedAndNothingIsPublished` | "Failed asserting that actual size 0 matches expected size 1." |
| (p) | `ListingForm::getDataModelValue`: `'on(change)\|*'` | `ListingFormComponentTest::testOnlyTheCategorySelectAsksForAMorphAndEveryFieldSyncsWithoutRendering`, `ListingFormIsolationTest::testEveryFieldSyncsWithoutRenderingBecauseTheFormWideBindingIsNorender` | "Every field syncs; none renders." |
| (q) | `ListingForm::morph`: throw `AccessDeniedException` instead of 404 for no user | `ListingFormComponentTest::testAnAnonymousLiveRequestIs404AndLeavesTheIntendedActionUntouched` | "Failed asserting that the Response status code is 404. HTTP/1.1 302 Found" |
| (r) | Remove the Dockerfile `ls` that asserts the live controller is compiled | Not re-run here: proved by hand in T7 (image build fails on the `ls`) | n/a |
| (s) | `SellController::edit`: drop the `details_for` mismatch guard (`addError(DETAILS_FOR_ANOTHER_CATEGORY)`) | `SellWizardTest::testAnEditPostWhoseDetailsWereForAnotherCategoryThanTheSubmittedAndStoredOneIs422AndClearsNothing`, `…::testAnEditPostRefilingToAThirdCategoryWithDetailsForAnotherIs422AndChangesNothing` | "Failed asserting that the Response status code is 422." (the POST redirected 303 and cleared the stored values) |

## Survivor: (f)

With the original tests (f) survived. Through the Live round trip it is **unobservable**:
`ComponentWithFormTrait::submitForm()` re-extracts `formValues` from the form view after
submitting (so a stripped key never survives either way), and it clears the extra-fields error for
fields nobody asked to validate. The spec's "AC-3 (extra-fields error)" cannot be seen through a
render. Fix (test only): `testMorphLeavesNoAttributeKeyOutsideTheNewSchemaInTheStateItHandsOn`
calls `ListingForm::morph()` directly (signed in via the token storage) and asserts on the
component's own `formValues`. Re-run with (f) applied: it fails; without it: green.
