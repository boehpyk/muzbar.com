# Technical Plan: listing-publish

> The *how*. Disposable. Written after the feature-spec is drafted, approved with it before any code.
> Follows DDD canonical order.

## Decisions

**Bold** rows go into **ADR-0021** or a dated amendment (task T0).

### Open questions for the owner (the plan is written to the recommendation)

| # | Question | Recommendation |
|---|---|---|
| Q1 | **Can a published listing be edited, and re-filed?** | **Edited: yes, and it stays published** — with L-13 (complete against the *current* schema) and L-9 (current bounds) on every save: D11.2's "at every later edit". Flipping it back to draft on edit would unpublish a listing for fixing a typo. **Re-filed: no (L-15)** — withdraw, re-file, publish again. A re-file is the one edit where the form was rendered for the *old* category, so a new category's required attributes cannot have been filled: allowing it would refuse almost every such save anyway, with errors on fields the seller cannot see. The form shows the category disabled with the reason. |
| Q2 | **How does a seller publish?** A separate "Publish" POST of the stored draft, or a button on the edit form? | **A second submit button on the edit form, "Save and publish", one command, one transaction** (`ReviseListing::$publish`). Either both the edits and the publish commit, or neither does and the form re-renders 422 with the seller's input intact — no "saved but not published" half-state to explain, and one schema read serves both checks. A standalone publish endpoint has no user today (YAGNI). "Save and publish" with a changed category saves the re-file and does not publish (AC-18): the new category's fields were never shown. |
| Q3 | **`ListingPublished` with no listener — ship it?** listing-draft D9 said "the first consumer designs its payload"; the roadmap says ship it now. | **Ship it** (roadmap), with the smallest honest payload: `ListingId`, `SellerId`, `CategoryId`, `publishedAt`. That is ADR-0008's own `UserRegistered` precedent (an event designed before its listener so it stays a fact, not a listener's shape). **Not** title, price or values: Notification (Phase 2) will read the committed row; a snapshot in the payload is a second copy that can disagree with it. Recorded on publish-again too (it is "became public", and Phase 2's alert matching wants exactly that). Cost: `RecordsEvents` on `Listing`, one dispatcher call. If you'd rather hold to D9, drop D5 and T3; nothing else changes. |
| Q4 | **The public page shows no way to contact the seller.** | **Accept for this slice.** PRD §6 wants "explicitly preferred external channels"; there are none yet, and the site is pre-launch (`/` is the coming-soon stub). The page shows nothing about the seller — not a name, not "contact". A contact slice (mail relay / phone / Telegram) belongs on the roadmap before public launch; I'll add it as a roadmap line, not design it. |
| Q5 | **Seller's view of their own unpublished listing at `/listings/{id}`?** | **404 like everyone else.** One rule on the public route, no auth branch on it, nothing to get wrong. The seller's view is the edit page, which now shows status. A "preview" is a later nicety. |
| Q6 | **Transitions out of `Withdrawn`.** | **`Withdrawn → Published` only ("Save and publish again").** Never back to `Draft` — "was public once" is a fact (`published_at` stays set), and Phase 2's lifecycle will want to tell an expired/withdrawn listing from a never-published draft. Withdraw a draft: refused (L-12). Publish a published / withdraw a withdrawn: no-op (ADR-0008 d.7: idempotency in the aggregate). Deleting drafts: out of scope. |
| Q7 | **The two roadmap candidates (public SCSS pipeline; UUID-id trait).** | **Neither inside this slice; neither before it.** *UUID trait:* ADR-0020 asked for it "before `listing-publish`" to stop the copy count growing — this slice adds **no** id copy (still eleven), so the reason does not apply; do it **immediately after** this merges, as its own `refactor/uuid-id-trait` (Identity, Catalog, Listing — a mechanical, all-green diff that would drown this slice's review). *SCSS pipeline:* the public page is the first page anyone else *could* see, but nobody will until launch (no links, `/` is coming-soon), and ADR-0006's real customer is Phase 2's search UI, where the design is the product. Ship this page as unstyled semantic markup with stable class names (styling is then purely additive) and schedule `public-styles` (sass-bundle, `public.css`, tokens) **before Phase 2's public search UI**. The roadmap line is re-ordered accordingly (T21). |
| Q8 | **Rename `/sell/drafts…` and `DbalSellerDrafts`?** They now hold published and withdrawn listings. | **No (owner, 2026-11-20).** URLs, route names, `DbalSellerDrafts` and `drafts.html.twig` stay; only the page heading becomes "Your listings". A rename is mechanical churn that would swamp this review; it can happen any time. |

### Design decisions

| # | Decision | Recommendation | Alternatives considered |
|---|---|---|---|
| **D1** | **The lifecycle is a state machine inside `Listing`; three statuses, three transitions** | `ListingStatus` gains `Published = 'published'`, `Withdrawn = 'withdrawn'` (column is `varchar(16)` with native `enum-type`: **no DDL**, listing-draft D12 paid for that). `publish()` from `Draft`/`Withdrawn`, `withdraw()` from `Published`; no-ops recorded nothing; `withdraw()` on a draft throws `ListingNotPublished`. L-6 first in both. | **Withdrawn = back to Draft** (no third status): loses "was public once", which Phase 2's expiry and the L-16 null rule need. **A separate `PublishedListing` aggregate / table:** one listing is one consistency boundary through its life; two roots would need a cross-aggregate move on every publish. **Symfony Workflow:** a vendor in the Domain (Deptrac) for three transitions. |
| **D2** | **`required` bites when a listing is, or becomes, public — on that write only (L-13). Never retroactive.** | Checked in `publish()` and in `revise()` when `status === Published`, against the schema read **at that write**. A Draft/Withdrawn revise never checks it (L-11, narrowed). Nothing reads published listings to re-check them, so an admin's change makes a listing incomplete only when its owner next touches it — D11.2 falls out of "the rule is checked on writes" rather than being a separate mechanism. **All missing ids at once** (`ListingIncomplete::attributeIds()`), so the seller sees every field to fill in one round trip. | **Retroactive:** unpublish (or refuse the admin's change) when a published listing becomes incomplete — the owner rejected it (roadmap D11.2); it also lets one admin edit silently take down thousands of listings. **Check required on drafts too:** L-11, and it makes saving a half-finished draft impossible. **First missing only (the `InvalidAttributeValue` shape):** one field per round trip for a form with dozens. |
| **D3** | **`publish()` re-checks the category and the stored values unconditionally (L-14)** | `publish(SellerId $by, CategoryStatus $status, AttributeSchema $schema, \DateTimeImmutable $now)`: **L-6 → no-op if `Published` → L-2 on the current category (always) → L-9 on the stored values → L-13 → assign + record**. Re-reading stored values reuses the one parser: `$schema->read(AttributeInput::fromArray(raw of each stored value))`, with `AttributeValue::toRaw()` (option id / int as string / `yes`/`no` / text) — so a refusal is the same `InvalidAttributeValue` with the same field message as on edit. In the form flow `revise()` has just run L-9 on the same values; publish does not trust that (ADR-0012 d.2: the Domain owns the part that can be wrong — the next caller may be an import). `revise()` keeps skipping L-2 on an unchanged category, so legacy data stays editable; it simply cannot go public. | **An `AttributeRule::admits(AttributeValue)`:** a second definition of "fits" beside `read()`, which can drift. **Trust revise:** a public method with a precondition nobody enforces. |
| **D4** | **`AttributeRule` carries `required`; `AttributeSchema::missingFrom(AttributeValues): list<AttributeId>`** | The schema fact gains the one bit it was missing (ADR-0020 d.3 kept it label-free; `required` is a rule, not presentation). Each named constructor takes `bool $required` **with no default** — a forgotten flag fails to compile instead of silently never biting. `CategoriesForListing::schemaOf()` selects `a.is_required`. | **A separate `requiredOf(CategoryId)` on the port:** a second read of the same lineage under the same lock. **Default `false`:** fail-open; mutation (j) would be the only thing that noticed. |
| **D5** | **`ListingPublished(ListingId, SellerId, CategoryId, \DateTimeImmutable $publishedAt)`, released inside the transaction, dispatched after it commits** | `Listing` uses `RecordsEvents`; `publish()` records it (not on a no-op). `ReviseListingHandler`: `$events = holdSteady(function () { …; save; return $listing->releaseEvents(); }); $this->events->dispatch(...$events);`. ADR-0008 d.2 says "after a successful save" — here the save is inside `holdSteady()`'s transaction, so dispatching inside the closure would publish a fact a later rollback could undo. Comment at the call site; ADR-0008 amendment: *"after save" means after the transaction that holds it commits*. No event on withdraw or revise (Q3). | **Dispatch inside the closure:** a listener would see a publish that rolled back. **Outbox:** ADR-0008/0010's trigger is a load-bearing listener; there is none. **`ListingWithdrawn` now:** no consumer; Search is Postgres itself and reads `status` (ADR-0002). |
| **D6** | **Publish is a flag on `ReviseListing`, not its own command** | `ReviseListing` gains `public bool $publish` (**no default**, the `$attributes` precedent: a caller must say). Handler, inside `holdSteady()`: `get → statusOf → schemaOf → revise → if publish: publish(…, $this->clock->now()) → save → releaseEvents`. Same status and schema objects serve both — they describe the command's (target) category, which after `revise()` is the listing's. The controller sends `publish: $clicked && !$categoryChanged` (Q2). | **`PublishListing` command + handler, controller calls revise then publish:** two transactions, a "saved but not published" state, two schema reads. **`ReviseAndPublishListing` duplicating every field:** two commands to keep in step. |
| **D7** | **`withdraw()` is its own command; it runs inside `holdSteady()` anyway** | `WithdrawListing(listingId, sellerId)`, `WithdrawListingHandler`: `holdSteady(get → withdraw → save)`. It reads no catalog fact, but ADR-0019 d.5's rule is uniform — every Listing write — so nobody decides per handler whether "this one is safe". | Skip the lock: saves nothing measurable (shared mode never waits on another seller) and breaks a rule that is easy to state. |
| **D8** | **`published_at timestamptz(0) NULL` (L-16)** | Needed now: the public page shows "Listed on …" (a buyer's freshness signal), and it is the only durable record that a withdrawn listing was ever public. Set from the `Clock` port (whole seconds, ADR-0007), moves forward on publish-again, kept on withdraw. One nullable column, no backfill (every row is a draft). **No CHECK** tying it to status: the aggregate is the only writer, and a hand-written CHECK on a mapped table is a re-diff risk for a rule a unit test already covers. | **No column; derive "listed on" from the event:** nothing stores events. **`first_published_at` + `last_published_at`:** no reader for the first. **A CHECK `(status = 'draft') = (published_at IS NULL)`:** see left. |
| **D9** | **Catalog's guards stay status-blind — confirmed, no change** | C-23/C-24 (`hasListings`), C-25/C-26 (`AttributeUsage`), C-27 (`hasAttributeValues`) count listings of **every** status. That is right: a withdrawn listing can be published again and must still be on a leaf with values that mean something; a draft too. ADR-0019's watch item ("the slice that adds `Published` must add `status = 'draft'` to my drafts and decide which statuses `revise()` accepts") is answered: **"Your listings" shows every status; `revise()` accepts every status** (with D2/L-15 deciding what each may do). AC-14 + mutation (m) pin it. | **Ignore withdrawn listings in the guards:** an admin could then turn a withdrawn listing's leaf into a branch, and "publish again" would fail on L-14 for a reason the seller cannot fix. |
| **D10** | **The public page is a DBAL read with a status filter, and its SQL never selects `seller_id`** | `DbalSellerDrafts::findPublished(string $id): ?PublicRow` — `SELECT id, category_id, title, description, price_amount, price_currency, published_at FROM listing_listing WHERE id = :id AND status = 'published'`. Malformed id → null with no query. The template therefore *cannot* render seller data (AC-22 asserts the SQL, not only the HTML). One 404 for draft/withdrawn/unknown/malformed, for everyone (Q5). Route has **no** `requirements` (a route-level 404 and a controller 404 must be the same 404; CLAUDE.md's `{token}` lesson). | **Hydrate the aggregate (`ListingRepository::get()`):** an N+1 shape for the later list pages, a postLoad values query, and `sellerId` in hand. **Filter status in PHP:** the `DbalSellerDrafts` "never filter in PHP" rule. |
| **D11** | **The public page labels values with Catalog's read model, loading only the labels it shows** | Data: `findPublished()`, `DbalCategoryOutline::rows()` (path via `LeafCategoryChoices::paths()`), `DbalAttributeSchema::effectiveFor()` (labels, units, order), `DbalSellerDrafts::valuesOf()` (raw stored values), and a new **`DbalAttributeSchema::optionLabels(list<string> $optionIds): array<string, string>`** (one query by primary key; `[]` without a query). Not `optionsFor()`: on the page buyers hit most it would load a 5 000-entry Brand list to label one value. A stored value whose attribute is no longer in the effective schema is not shown (legacy only). This is the **fifth** cross-context crossing (a UI read, ADR-0019's consequences list). | **`optionsFor()`:** see left. **A joined SQL in Listing's read model:** `catalog_*` under `Infrastructure/Listing`, which the data-ownership scan forbids. |
| D12 | **"Your listings" and the edit page show status; the form adapts by status** | `DbalSellerDrafts` rows gain `status` and `publishedAt`. `ListingFormType` gains option `published` (bool): when true, `category` is `disabled` (Symfony then ignores the submitted value and keeps the model's — the L-15 UX; the Domain refusal is the backstop) with the contract's help text. Submit buttons live in the template (`<button name="action" value="save|publish">`), read from the request — the form type does not change per status. Withdraw is a separate small POST form with token id `withdraw-listing-<id>`. | **Hide the category field when published:** the seller should see where it is filed. **`SubmitType` children:** two more form children for what one request parameter says. |
| D13 | **Withdraw's CSRF failure is a 403, checked before ownership** | `CategoryAdminController::delete()`'s precedent; because it runs before the lookup, it is the same for every id and leaks nothing. | A flash + redirect: friendlier, and different from every other POST-only action in the app. |

## Domain layer (pure PHP)

### `Domain/Listing/ValueObject/`

- **`ListingStatus`** — gains `Published = 'published'`, `Withdrawn = 'withdrawn'`; docblock: the
  three transitions (L-12), "nothing returns to Draft".
- **`AttributeRule`** — gains `private bool $required`; each named constructor takes `bool $required`
  (no default, D4); `isRequired(): bool`.
- **`AttributeSchema`** — gains `missingFrom(AttributeValues $values): list<AttributeId>` (required
  rules with no value, id order).
- **`AttributeValue`** — gains `toRaw(): string` (option id / whole number / `yes`|`no` / text —
  exactly what the edit form posts and `AttributeRule::read()` accepts). Docblock: the round trip
  is the contract (AC-5).

### `Domain/Listing/Event/` (new directory)

- **`ListingPublished`** (`final readonly`, implements `DomainEvent`): `listingId(): ListingId`,
  `sellerId(): SellerId`, `categoryId(): CategoryId`, `publishedAt(): \DateTimeImmutable`. Docblock:
  why no title/price/values (Q3), recorded on publish-again, **never a secret**, dispatched after
  commit (D5).

### `Domain/Listing/Exception/`

- **`ListingIncomplete`** (`\DomainException`): `missing(list<AttributeId>)`; `attributeIds()`.
- **`ListingNotPublished`** (`\DomainException`): `cannotWithdraw(ListingId)`.
- **`PublishedListingCannotBeRefiled`** (`\DomainException`): `for(ListingId)`.

### Aggregate (`Listing`)

```
Listing  (uses RecordsEvents)
  private ?\DateTimeImmutable $publishedAt          mapped; null for a draft (L-16)

  revise(…unchanged signature…)
      L-6
      if Published and category changes → PublishedListingCannotBeRefiled        (L-15)
      category changes → L-2; input = input->onlyFor(schema)                      (L-10)
      values = schema->read(input)                                                (L-9)
      if Published and schema->missingFrom(values) ≠ [] → ListingIncomplete      (L-13)
      assign

  publish(SellerId $by, CategoryStatus $status, AttributeSchema $schema, \DateTimeImmutable $now): void
      L-6
      if Published → return                                     (no-op, records nothing)
      L-2 on (this->categoryId, status), always                 (L-14)
      schema->read(stored values as input)                      (L-14 / L-9)
      schema->missingFrom(this->attributeValues) ≠ [] → ListingIncomplete   (L-13)
      status = Published; publishedAt = now; recordThat(ListingPublished)    (L-12, L-16)

  withdraw(SellerId $by): void
      L-6
      Withdrawn → return; Draft → ListingNotPublished
      status = Withdrawn                                          (publishedAt kept)

  publishedAt(): ?\DateTimeImmutable
```

Docblock: replace "in this slice only ever a private draft" and "No domain events (plan D9)" with
the lifecycle, the L-13 contract (*checked on writes that are or make the listing public, so never
retroactive — that is the design, D11.2*) and the dispatch-after-commit note. `draft()` is unchanged
apart from passing `publishedAt: null`.

### Ports

**No change.** `Categories::schemaOf()`'s docblock gains "with each attribute's `required` flag".
`ListingRepository` unchanged. Clock and `DomainEventDispatcher` are the existing shared ports.

## Application layer

- **`ReviseListing`** gains `public bool $publish` (no default; docblock: *"also publish, in the
  same transaction; the controller sends false on a re-file, Q2"*).
- **`ReviseListingHandler`** gains `Clock $clock`, `DomainEventDispatcher $events`. Flow per D6:
  inside `holdSteady()` — `get → categoryId → statusOf → schemaOf → revise → (publish) → save →
  return releaseEvents()`; after it returns, `dispatch(...$events)`. Comment: why outside (D5), and
  that a crash between commit and dispatch loses the event knowingly. `@throws` gains
  `ListingIncomplete`, `PublishedListingCannotBeRefiled`.
- **`WithdrawListing`** (`listingId`, `sellerId`) + **`WithdrawListingHandler`**: `holdSteady(get →
  withdraw → save)`. Malformed listing id → `ListingNotFound` (the `ReviseListingHandler::listingId()`
  shape).
- `DraftListingHandler` unchanged (a draft records nothing).
- Idempotency: publish-on-published and withdraw-on-withdrawn are aggregate no-ops (ADR-0008 d.7), so
  a double-submit needs no handler branch.

## Infrastructure layer

### Persistence

- **`Listing.orm.xml`**: `<field name="publishedAt" column="published_at" type="datetimetz_immutable"
  nullable="true"/>`; header comment gains the lifecycle line.
- **Migration**: generated by `make migration.make` (`ALTER TABLE listing_listing ADD published_at
  TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL` + the column comment Doctrine adds for immutable types).
  No status DDL, no backfill, no index (the public read is by PK).
- **`DbalSellerDrafts`** (name kept, Q8). Rows gain `status` and `publishedAt`
  (`?string`, ISO date). New **`findPublished(string $id): ?PublicRow`** (D10). `valuesOf()`
  unchanged; its "call only after a filter passed" docblock now names both `find()` and
  `findPublished()`. `@phpstan-type PublicRow` is its own shape (phpstan cannot intersect — CLAUDE.md).

### Cross-context adapter

- **`CategoriesForListing::schemaOf()`**: select `a.is_required`, pass it to each `AttributeRule`
  constructor. (No other change — `GROUP BY a.id` already covers it, `id` being the PK.)

### Catalog read model

- **`DbalAttributeSchema::optionLabels(list<string> $optionIds): array<string, string>`** — `SELECT
  id, label FROM catalog_attribute_option WHERE id = ANY(:ids)`; `[]` without a query (D11).

### HTTP / UI

- **`SellController`** (routes unchanged, Q8):
  - `drafts()`: `GET /sell/drafts`, title "Your listings", status per row, a
    "View" link for published ones.
  - `edit()`: `GET|POST /sell/drafts/{id}/edit`. Builds the form with `published` = status is
    published. Reads `action` (`save` | `publish`) from the request; `publish` is honoured only for a
    Draft/Withdrawn listing whose category did not change. On success: published now → 303
    `app_listing_show`, *"Published. This is what buyers see."*; re-file with publish clicked → 303
    edit page with the "Saved, not published yet …" notice; published listing saved → 303
    `app_sell_drafts`, *"Saved. Your listing is updated."*; otherwise as today. New catches:
    `ListingIncomplete` → *"Fill this in to publish."* on each `attributes[<id>]` field, or *"A new
    detail is now required. Reload the page."* on the form for an id without a field;
    `PublishedListingCannotBeRefiled` → 422 on `category`.
  - **`withdraw()`**: `POST /sell/drafts/{id}/withdraw` (`methods: ['POST']`). CSRF (`withdraw-
    listing-<id>`) → 403; then `find($id, $user)` → 404; `WithdrawListingHandler`; `ListingNotPublished`
    → error flash; 303 `app_sell_drafts`. `NotTheSeller|ListingNotFound` → 404.
- **`ListingController`** (new, public, no `#[IsGranted]`): `GET /listings/{id}`, name
  `app_listing_show`. `findPublished()` or 404; then outline, `effectiveFor()`, `valuesOf()`,
  `optionLabels()` of the stored choice values; builds a `details` list (label, display value) in
  `effectiveFor()` order. No `access_control` line (public by default — the anchored `^/sell(/|$)`
  rule does not match `/listings`).
- **`ListingFormType`**: option `published` (D12). `ListingFormData` unchanged.
- **Templates**: `listing/drafts.html.twig` (name kept; heading "Your listings"), `edit.html.twig` (status,
  buttons per status, withdraw form, public link), `_form.html.twig` (buttons moved to the caller),
  **`listing/show.html.twig`** (new; `{% extends 'base.html.twig' %}`; `<article class="listing">`,
  `<h1>`, `.listing-price`, `.listing-category`, `<time datetime>` "Listed on", description via
  `|nl2br` — Twig escapes before inserting `<br>` — and `<dl class="listing-details">`). A comment
  in `show.html.twig`: *no seller data reaches this template, on purpose (PRD §6, Constitution §8);
  contact channels are their own slice (Q4)*.

### DI wiring

Nothing new: handlers are autowired; `Clock` and `DomainEventDispatcher` are already aliased.

### Async / schedule / external

None. (Expiry is Phase 2.)

## Interface boundary & input contract

| Surface | Accepts | Refuses |
|---|---|---|
| `GET /listings/{id}` (public) | any string | not published / unknown / malformed → the one 404 |
| `GET|POST /sell/drafts/{id}/edit` | listing-attribute-values' fields + `action=save|publish`; `category` ignored when published (disabled) | non-owner/unknown → 404; refusals → 422 on their fields; unknown `action` → treated as `save` |
| `POST /sell/drafts/{id}/withdraw` | `_token` | bad token → 403; non-owner/unknown → 404; draft → error flash |
| `GET /sell/drafts` | — | anonymous → login (unchanged) |
| `ReviseListing::$publish` | `bool`, required | — |
| `WithdrawListing` | listing id, seller id (from the authenticated user) | malformed id → `ListingNotFound` |
| `Listing::publish()` | `SellerId`, `CategoryStatus`, `AttributeSchema`, `\DateTimeImmutable` | L-6, L-14, L-13 |
| `Categories::schemaOf()` | unchanged; now also reports `required` | — |
| `ListingPublished` | — | carries ids + time only |

## Data & migrations

```sql
ALTER TABLE listing_listing ADD published_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL;
-- status: varchar(16) already; 'published' and 'withdrawn' need no DDL (listing-draft D12).
```

- **No backfill, no pre-deploy query:** every existing row is a draft with no `published_at`, which
  is L-16's draft state. **Prod is consistent after this deploy on its own.**
- **No new index.** The public read is by PK; "Your listings" keeps `idx_listing_listing_seller`.
  Phase 2's search decides its own `(status, …)` indexes against its 200 ms benchmark; nothing here is
  on a search path.
- **Deploy window** (as the two previous Listing slices): between `up -d` and `migrate`, Doctrine
  loads of a listing select a missing `published_at` → the edit page, every seller POST and the
  public page 500 for seconds. Accepted; `docs/infrastructure.md` note extended.

## Test plan

- **Domain unit (no kernel):** `ListingTest` additions (AC-1…AC-4, AC-6: every refusal asserts
  status, `publishedAt` and an empty `releaseEvents()`), `AttributeSchemaTest::missingFrom`,
  `AttributeValueTest::toRaw` round trip (AC-5), `AttributeRuleTest` constructor call sites updated
  for `required`.
- **Integration (DAMA):**
  - `ListingPublishSchemaTest` or the existing schema test extended (AC-8).
  - `ListingHandlersTest` additions (AC-9, AC-10, AC-12, AC-13) — **fresh EntityManager** before each
    read-back; a recording `DomainEventDispatcher`; a fixed whole-second Clock.
  - `CategoriesForListingTest::schemaOf` asserts `isRequired()` (AC-11).
  - Catalog handler tests: one status-blind case per guard (AC-14).
  - `CategoryTreeLockTest`: `ObservedCategories` + a dispatcher double recording "lock held?"
    (AC-15); `WithdrawListingHandler` case.
- **`ListingFactory`**: `->published()` state — publishes through the aggregate with a schema built
  from the category's attributes, saves through `ListingRepository::save()`, and **releases events**
  (ADR-0008's Foundry hole); `->withdrawn()`. A test Clock with whole seconds.
- **Functional:** AC-16…AC-20, AC-26; new `PublicListingTest`
  (AC-21…AC-25, AC-27) with `QueryRecordingStatement` for AC-22/AC-24 and the normalised-body
  comparison for AC-23.
- **Bite proof:** AC-28 (a)…(o).
- **Performance:** no budget applies (not a search path). AC-24's query count guards the public page
  against an N+1 and against loading whole option lists.

## Risks / open questions

1. **Q1–Q8 above.** Q1 (no re-file while published), Q3 (ship the event) and Q4 (no contact yet) are
   product calls; the rest have safe defaults.
2. **The first Listing event lands outside the transaction.** The handler shape (release inside,
   dispatch outside) is the one to copy; `CategoryTreeLockTest`'s dispatch case (AC-15) and mutation
   (i) are what stop a refactor moving it back inside. ADR-0008's "crash between commit and dispatch
   loses the event" window is re-opened knowingly — acceptable while nothing listens; the first
   listener (Phase 2 Notification) must revisit it, as ADR-0010 did for mail.
3. **Foundry and events.** A factory state that publishes must release events in `afterPersist`, or a
   later test's dispatcher count sees a phantom `ListingPublished` (ADR-0008's consequence, seen once
   already with `UserRegistered`).
4. **A published listing can show stored values outside today's rules** (narrowed bounds) and lack a
   newly required detail. That is D11.2 by design; FORboehpyk and ADR-0021 say so plainly so nobody
   "fixes" it with a sweep.
5. **No URL rename (Q8, owner).** `/sell/drafts` now lists published and withdrawn listings too; the
   heading says "Your listings". Rename later as its own mechanical change if it grates.
6. **The site is publicly reachable on the box.** After deploy, a seller can publish a page that has
   no way to contact them (Q4). Pre-launch only; the contact slice must ship before launch.
7. **ADR work (T0):**
   - **ADR-0021** *"The listing lifecycle: Draft → Published ⇄ Withdrawn inside the aggregate;
     completeness bites on writes that are or make a listing public, never retroactively; the public
     read selects published rows and no seller data; `ListingPublished` is released inside the
     transaction and dispatched after it"* — D1–D11 with rejected alternatives; consequences (risks
     2–4, the fifth crossing, the deferred contact slice).
   - **Dated ADR-0008 amendment:** decision 2's "after a successful save" means after the transaction
     that contains the save commits; where a handler's work runs in a closure-owned transaction
     (`holdSteady()`), release inside and dispatch outside.
   - **Dated ADR-0018 amendment:** decision 8 point 2 (required) answered — L-13, never retroactive.
   - **Dated ADR-0019 amendment:** the "status is not yet a filter" watch item discharged (D9: guards
     status-blind on purpose; "Your listings" shows every status; `revise()` accepts every status);
     the fifth crossing (`optionLabels()` for the public page).
   - **Dated ADR-0020 amendment:** L-11 narrowed to "not public"; `AttributeRule` carries `required`.
