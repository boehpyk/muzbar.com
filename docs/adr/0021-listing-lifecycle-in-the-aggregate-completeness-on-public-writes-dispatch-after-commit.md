# ADR-0021: The listing lifecycle — Draft → Published ⇄ Withdrawn inside the aggregate; completeness bites on writes that are or make a listing public, never retroactively; the public read selects published rows and no seller data; `ListingPublished` is released inside the transaction and dispatched after it

- **Status:** Accepted
- **Date:** 2026-11-20
- **Established by** the `listing-publish` slice ([spec](../specs/listing-publish/feature-spec.md),
  [plan](../specs/listing-publish/technical-plan.md) D1–D13, Q1–Q8) — Listing slice 3 of 4, the
  first slice with a **state machine inside an aggregate**, the first **Listing domain event**, and
  the first page of muzbar that anyone other than its author can see.
- **Applies** [ADR-0008](./0008-domain-events-recorded-on-the-aggregate.md) (events recorded on the
  aggregate; idempotency in the aggregate, d.7), [ADR-0012](./0012-challenge-retention-and-recurring-background-work.md)
  decision 2 (put in the Domain the part that can be wrong), [ADR-0015](./0015-intended-action-redirect-and-login-overlay.md)
  (no GET changes state) and [ADR-0019](./0019-listing-context-cross-context-ports-leaf-only-under-one-lock-in-two-modes.md)
  decision 5 (every Listing write runs inside `holdSteady()`) and decision 8 (L-6 first; someone
  else's listing is a 404).
- **Answers** [ADR-0018](./0018-schema-inheritance-derived-along-the-lineage-codes-unique-per-branch.md)
  decision 8 point 2 (when `required` bites) and **discharges** ADR-0019's "status is not yet a
  filter" watch item.
- **Amends** ADR-0008, ADR-0018, ADR-0019 and ADR-0020 — see the dated 2026-11-20 amendments at the
  foot of each.

## Context

A listing so far is a private draft: a title, a price, a leaf category and the details its schema
asks for. Nobody but its seller can see it. This slice makes it public — and the moment "public"
exists, four questions arrive together:

- **What is the lifecycle, and where does it live?** A seller publishes, takes the listing down when
  the gear sells, perhaps puts it back up. Phase 2 will add expiry. Something has to say which moves
  are legal and refuse the others.
- **When does `required` mean something?** ADR-0020 deliberately did not enforce it on drafts (L-11)
  — a half-written draft must save. But a public listing missing its Brand is exactly what the
  schema was built to prevent. And the owner already ruled (roadmap D11.2) that an admin marking an
  attribute required, or narrowing a number's bounds, must **not** silently unpublish or veto
  thousands of existing listings.
- **What may the public page read?** PRD §6 and Constitution §8: public listings never expose a
  seller's email. The safest column is the one a page never selects.
- **The first Listing event sits inside a transaction.** ADR-0008 says "dispatch after a successful
  save". Every Listing save runs inside `holdSteady()`'s transaction (ADR-0019 d.5), so *after the
  save* is not *after the commit*. Until now no handler that dispatched events ran inside a
  closure-owned transaction, so the difference never mattered.

## Decision

1. **The lifecycle is a state machine inside `Listing`: three statuses, three transitions** (D1).
   `ListingStatus` gains `Published` and `Withdrawn` (`varchar(16)` with a native `enum-type` — no
   DDL, listing-draft paid for that). `publish()` moves `Draft → Published` and `Withdrawn →
   Published`; `withdraw()` moves `Published → Withdrawn`. **Nothing returns to `Draft`**: "was
   public once" is a fact (`published_at` stays set), and Phase 2's expiry will want to tell a
   withdrawn listing from a never-published one (L-12). L-6 (only the seller) is checked first in
   both. Publishing a published listing and withdrawing a withdrawn one are **no-ops that record
   nothing** (ADR-0008 d.7 — a double-submit needs no handler branch); withdrawing a draft is
   **refused** (`ListingNotPublished`).

2. **`required` bites when a listing is, or becomes, public — on that write only. Never
   retroactive** (D2, L-13). It is checked in `publish()`, and in `revise()` when the status is
   `Published`, against the schema read **at that write** under the shared lock. A `Draft` or
   `Withdrawn` revise never checks it — L-11 narrowed from "drafts" to "not public". Nothing ever
   reads published listings to re-check them, so an admin's schema change makes a listing incomplete
   only when its seller next touches it. D11.2 is therefore **not a mechanism**: it falls out of "the
   rule is checked on writes" — a rule checked only on a write cannot fire on a row nobody writes.
   `ListingIncomplete::attributeIds()` reports **every** missing attribute at once, so the seller
   fills them all in one round trip.

   A published listing **can be edited and stays published** (Q1), carrying L-13 and current-bounds
   L-9 on every save. It **cannot be re-filed** (L-15, checked in `revise()` before L-2): the edit
   form was rendered for the old category, so a new category's required fields could never have been
   shown. Withdraw, re-file, publish again.

3. **`publish()` re-checks the category and the stored values unconditionally** (D3, L-14).
   `publish(SellerId $by, CategoryStatus $status, AttributeSchema $schema, \DateTimeImmutable $now)`
   decides **L-6 → no-op if already `Published` → L-2 on the current category (always) → L-9 on the
   stored values → L-13 → assign + record**, every check before any assignment. Stored values are
   re-read through the **one existing parser**: each `AttributeValue::toRaw()` (option id / whole
   number / `yes`|`no` / text) goes back through `AttributeSchema::read()`, so a refusal is the same
   `InvalidAttributeValue` with the same field message as on edit. In the form flow `revise()` has
   just run L-9 on the same values; `publish()` does not trust that — the next caller may be an
   import (ADR-0012 d.2). `revise()` keeps skipping L-2 on an unchanged category, so legacy data
   stays *editable*; it simply cannot go *public*.

4. **The schema fact carries `required`** (D4). `AttributeRule` gains `isRequired()`; every named
   constructor takes `bool $required` **with no default**, so a forgotten flag fails to compile
   instead of silently never biting. `AttributeSchema::missingFrom(AttributeValues): list<AttributeId>`
   is the one definition of "complete". `CategoriesForListing::schemaOf()` selects `a.is_required`.
   ADR-0020 d.3 kept the fact label-free; `required` is a rule, not presentation, so it belongs.

5. **`ListingPublished(ListingId, SellerId, CategoryId, publishedAt)` is released inside the
   transaction and dispatched after it commits** (D5, Q3). `Listing` uses `RecordsEvents`;
   `publish()` records the event on every real transition, publish-again included ("became public"
   is what Phase 2's alert matching wants), never on a no-op. The handler shape is:

   ```php
   $events = $this->categories->holdSteady(function () use (…): array {
       // get → statusOf → schemaOf → revise → (publish) → save
       return $listing->releaseEvents();
   });
   $this->events->dispatch(...$events);
   ```

   Dispatching inside the closure would publish a fact that a later failure in the same transaction
   could roll back — exactly the case ADR-0008 rejected "dispatch before save" for. The event ships
   **with no listener**, ADR-0008's `UserRegistered` precedent: designed as a fact before a listener
   bends it. Its payload is **ids and a time only** — no title, price or values. Notification (Phase
   2) will read the committed row; a snapshot in the payload is a second copy that can disagree with
   it. No `ListingWithdrawn` or `ListingRevised`: no consumer, and Search is Postgres reading
   `status` directly (ADR-0002).

6. **Publish is a flag on `ReviseListing`, not its own command** (D6, Q2). `ReviseListing` gains
   `public bool $publish` (**no default**, the `$attributes` precedent: the caller must say). The
   edit form gets a second button, "Save and publish": one command, one transaction, one schema
   read serving L-9 and L-13. Either both the edits and the publish commit or neither does, and the
   form re-renders 422 with the seller's input intact — there is no "saved but not published"
   half-state to explain. The controller sends `publish: false` when the category changed (the new
   category's fields were never shown).

7. **`withdraw()` is its own command, and it runs inside `holdSteady()` anyway** (D7).
   `WithdrawListing(listingId, sellerId)` reads no catalog fact, but ADR-0019 d.5's rule is uniform —
   *every* Listing write — so nobody decides per handler whether "this one is safe". The shared mode
   never waits on another seller, so the cost is nil.

8. **`published_at timestamptz(0) NULL`** (D8, L-16). Set from the `Clock` port (whole seconds,
   ADR-0007), moves forward on publish-again, kept on withdraw; **null exactly when the listing was
   never published**. The public page shows it ("Listed on …", a buyer's freshness signal), and it is
   the only durable record that a withdrawn listing was ever public. **No CHECK** tying it to status:
   the aggregate is the only writer, a unit test covers the rule, and a hand-written CHECK on a mapped
   table is a re-diff risk. No backfill: every existing row is a draft.

9. **Catalog's guards stay status-blind — on purpose** (D9). C-23/C-24 (`hasListings`), C-25/C-26
   (`AttributeUsage`) and C-27 (`hasAttributeValues`) count listings of **every** status. A
   withdrawn listing can be published again and must still be on a leaf with values that mean
   something; a draft likewise. ADR-0019's watch item is answered: **"Your listings" shows every
   status, and `revise()` accepts every status**, with L-13 and L-15 deciding what each may do.
   Mutation (m) — filtering `status = 'draft'` in `hasListings()` — fails AC-14.

10. **The public page is a DBAL read with a status filter in SQL, and that SQL never selects
    `seller_id`** (D10). `DbalSellerDrafts::findPublished(string $id): ?PublicRow` selects `id,
    category_id, title, description, price_amount, price_currency, published_at` **`WHERE id = :id
    AND status = 'published'`**. A malformed id returns null with no query. The template therefore
    *cannot* render seller data — AC-22 asserts the SQL, not only the HTML. Draft, withdrawn, unknown
    and malformed ids are **one 404, for everyone, the seller included** (Q5: no preview, no auth
    branch on a public route). The route has **no** `requirements`, so a route-level 404 and a
    controller 404 cannot differ (CLAUDE.md's `{token}` lesson).

11. **The public page labels values from Catalog's read model, loading only the labels it shows**
    (D11). It reads `DbalCategoryOutline` (the category path), `DbalAttributeSchema::effectiveFor()`
    (labels, units, order), `DbalSellerDrafts::valuesOf()` (the stored raw values) and a new
    **`DbalAttributeSchema::optionLabels(list<string> $optionIds): array<string, string>`** — one
    query by primary key, `[]` without a query. A stored value whose attribute is no longer in the
    effective schema is not shown. This is the **fifth cross-context crossing**, a UI read like the
    other two (ADR-0019, ADR-0020 consequences).

12. **"Your listings" and the edit page show status; the form adapts by status** (D12). When the
    listing is published, `ListingFormType` renders `category` **disabled** (Symfony then ignores a
    submitted value; L-15 in the Domain is the backstop) with the reason as help text. The submit
    buttons are `<button name="action" value="save|publish">` in the template; an unknown `action` is
    `save`. Withdraw is a separate small POST form with its own CSRF token id
    `withdraw-listing-<id>`. URLs, route names and `DbalSellerDrafts` keep their names (Q8, owner).

13. **Withdraw's CSRF failure is a 403, checked before ownership** (D13) —
    `CategoryAdminController::delete()`'s precedent. Because it runs before the lookup it is the same
    for every id and leaks nothing.

## Alternatives

**The lifecycle (D1)**

- **Withdrawn = back to `Draft`** (two statuses). Loses "was public once", which Phase 2's expiry and
  L-16's null rule both need.
- **A separate `PublishedListing` aggregate or table.** One listing is one consistency boundary
  through its life; two roots would need a cross-aggregate move on every publish and withdraw.
- **Symfony Workflow.** A vendor in the Domain (Deptrac rejects it) for three transitions that fit in
  two methods.

**When `required` bites (D2)**

- **Retroactive** — unpublish, or refuse the admin's change, when a published listing becomes
  incomplete. Rejected by the owner (roadmap D11.2): one admin edit would silently take down, or be
  vetoed by, thousands of listings.
- **Check `required` on drafts too.** Breaks L-11: a half-finished draft could not be saved.
- **Report only the first missing attribute** (the `InvalidAttributeValue` shape). One field per
  round trip, on a form with dozens.
- **Flip a published listing back to draft on edit.** Unpublishes a listing for fixing a typo.
- **Allow re-filing a published listing.** Almost every such save would be refused anyway, with
  errors on fields the seller was never shown.

**Re-checking at publish (D3)**

- **An `AttributeRule::admits(AttributeValue)`.** A second definition of "fits" beside `read()`,
  free to drift from it.
- **Trust `revise()` to have checked.** A public method with a precondition nobody enforces.

**Carrying `required` (D4)**

- **A separate `Categories::requiredOf(CategoryId)`.** A second read of the same lineage under the
  same lock.
- **`$required = false` by default.** Fail-open; mutation (j) would be the only thing that noticed.

**The event (D5)**

- **Dispatch inside the closure.** A listener would observe a publish that then rolled back.
- **A transactional outbox now.** ADR-0008/0010's trigger is a *load-bearing* listener; there is
  none.
- **Drop the event until its listener exists** (listing-draft D9). Risks a listener-shaped payload;
  the cost of shipping it now is one trait and one dispatcher call.
- **A snapshot payload** (title, price, values). A second copy of the row that can disagree with it.

**Publish as a command (D6)**

- **`PublishListing` + its own handler, the controller calling revise then publish.** Two
  transactions, a "saved but not published" state, two schema reads.
- **`ReviseAndPublishListing` duplicating every field.** Two commands to keep in step.

**Withdraw outside the lock (D7)**

- Saves nothing measurable and breaks a rule that is easy to state precisely because it has no
  exceptions.

**`published_at` (D8)**

- **No column; derive "listed on" from the event.** Nothing stores events.
- **`first_published_at` and `last_published_at`.** No reader for the first.
- **A CHECK `(status = 'draft') = (published_at IS NULL)`.** See decision 8.

**Status-aware Catalog guards (D9)**

- **Ignore withdrawn listings.** An admin could then turn a withdrawn listing's leaf into a branch,
  and "publish again" would fail on L-14 for a reason the seller cannot fix.

**The public read (D10)**

- **Hydrate the aggregate** (`ListingRepository::get()`). The N+1 shape for the later list pages, a
  `postLoad` values query, and `sellerId` in hand on a public page.
- **Filter status in PHP.** `DbalSellerDrafts`'s "never filter in PHP" rule — and mutation (k) would
  then be a template bug, not a query bug.
- **A seller preview at the public URL.** An auth branch on the one route whose rule should be
  trivially stateable.
- **Route `requirements` on `{id}`.** A second, different 404.

**Labelling values (D11)**

- **`optionsFor()`.** On the page buyers hit most, it would load a 5 000-entry Brand list to label one
  value.
- **A joined SQL in Listing's read model.** `catalog_*` under `Infrastructure/Listing`, which the
  data-ownership scan forbids (ADR-0019 d.4).

**The form (D12)**

- **Hide the category when published.** The seller should still see where the listing is filed.
- **`SubmitType` children.** Two form children for what one request parameter says.

## Consequences

- **Easy:** the whole lifecycle and L-11…L-16 are unit-tested on `Listing` without a database —
  every refusal asserts the status, `publishedAt` and an empty `releaseEvents()`. A double-submit of
  publish or withdraw is harmless by construction. Phase 2's expiry adds a status and a transition,
  not a redesign.
- **A published listing can be out of date with today's schema — by design.** It may lack a newly
  required detail, or hold a number outside narrowed bounds. Every listing anyone else can see was
  complete and valid **when it was last written**; nothing promises it is against **today's** rules.
  That is D11.2, the owner's call. **Do not "fix" it with a sweep** — a job that re-checks published
  listings is precisely the retroactive rule that was rejected. The seller meets the new rule at their
  next save, in the same form, possibly on a field they did not change.
- **The first Listing event is dispatched outside the transaction, and that is the shape to copy.**
  Release inside `holdSteady()`, dispatch after it returns. `CategoryTreeLockTest`'s dispatch case
  (AC-15) and mutation (i) stop a refactor moving it back inside. ADR-0008's window — a crash between
  commit and dispatch loses the event — is **re-opened knowingly**, acceptable only while nothing
  listens. **The first listener (Phase 2 Notification) must revisit it**, as ADR-0010 did for mail.
- **Foundry and events, again.** `ListingFactory::published()` publishes through the aggregate and
  must **release** the event, or a later test's dispatcher count sees a phantom `ListingPublished` —
  ADR-0008's consequence, seen once already with `UserRegistered`. Any code that publishes a listing
  without going through the handler has the same hole.
- **Five crossings now.** Besides the cross-context adapters, the public `ListingController` reads
  Catalog's `DbalCategoryOutline` and `DbalAttributeSchema` (now including `optionLabels()`). A change
  to either read model is a change to `/listings/{id}`; `PublicListingTest` is what notices. AC-24's
  five-query budget guards the page against an N+1 and against loading whole option lists.
- **The public page has no way to contact the seller** (Q4). It shows no seller data at all — not a
  name, not a "contact" button. A contact slice (mail relay, phone, Telegram — PRD §6's "explicitly
  preferred external channels") **must ship before public launch**; it is a roadmap line, not designed
  here. Until launch nobody reaches the page (`/` is the coming-soon stub, nothing links to it but
  "Your listings"), but the box is publicly reachable, so a seller *can* publish a page nobody can
  answer.
- **Privacy is a property of the query.** The template cannot leak what `findPublished()` never
  selected; AC-22 asserts the SQL. Any later public read (search results, category pages) must hold
  the same line: select the public columns by name, never `*`, never `seller_id`.
- **The seller-surface names now under-describe.** `/sell/drafts`, `DbalSellerDrafts` and
  `drafts.html.twig` hold published and withdrawn listings too (Q8). A rename is mechanical churn,
  safe to do as its own change any time.
- **Deploy window,** as for the two previous Listing slices: between `up -d` and `migrate`, every
  Doctrine load of a listing selects the missing `published_at`, so the edit page, every seller POST
  and the public page 500 for seconds. Accepted; `docs/infrastructure.md` records it. No backfill and
  no pre-deploy query: prod is consistent after this deploy on its own.
- **Still eleven copies of the UUID id shape** — this slice adds none. ADR-0020's "before
  `listing-publish`" no longer applies; `refactor/uuid-id-trait` follows this slice (Q7).
