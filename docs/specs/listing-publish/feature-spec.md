# Feature Spec: listing-publish

> The *what & why*. Disposable: it dies once the feature ships and its behaviour lives in tests +
> code. Make it **executable** (measurable, enumerated), not pretty. Must not contradict the
> [Constitution](../../constitution.md) or an accepted ADR.

- **Feature:** `listing-publish`, slice **3 of 4** of the `Listing` skeleton roadmap item (after
  `listing-draft`, `listing-attribute-values`; then `listing-schema-drift`; then the `phase1-gate`)
- **Bounded context(s):** **`Listing`** (the lifecycle, owned by the `Listing` aggregate; the public
  page). **`Catalog`** changes by one column in one cross-context adapter's query
  (`CategoriesForListing::schemaOf()` now reports `required`) and one read-model method
  (`DbalAttributeSchema::optionLabels()`); **no Catalog Domain or Application change**. Neither
  context's Domain or Application imports the other (ADR-0019 decision 3).
- **Related PRD items:** US-1 (a seller goes from draft to a schema-accurate public listing), §6
  (*"Public listings hide raw user email addresses"*), Constitution §8 (email isolation), PRD §6
  lifecycle (30-day expiry is **not** this slice — Phase 2).
- **Governing ADRs:** [ADR-0008](../../adr/0008-domain-events-recorded-on-the-aggregate.md) (events
  recorded on the aggregate, released after a successful save — first event in `Listing`),
  [ADR-0015](../../adr/0015-intended-action-redirect-and-login-overlay.md) (no GET changes state),
  [ADR-0016](../../adr/0016-category-tree-per-node-aggregates-under-one-tree-lock.md) (aggregate
  decides on facts, the lock makes them true), [ADR-0018](../../adr/0018-schema-inheritance-derived-along-the-lineage-codes-unique-per-branch.md)
  decision 8 point 2 (**answered here**), [ADR-0019](../../adr/0019-listing-context-cross-context-ports-leaf-only-under-one-lock-in-two-modes.md)
  (its "status is not yet a filter" watch item is **discharged here**),
  [ADR-0020](../../adr/0020-attribute-values-parts-of-listing-validated-against-catalog-schema-guarded-by-refusal.md)
  (L-9, L-11; the schema fact), and **ADR-0021** (new, task T0)
- **Status:** Approved by owner 2026-11-20 (Q8: no rename)
- **Date:** 2026-11-20

> **What this slice teaches, beyond the feature.**
>
> 1. **A state machine inside an aggregate, and an invariant whose strength depends on the state.**
>    `required` is *not* a rule on a draft (L-11) and *is* a rule on a public listing (L-13). The
>    same `revise()` enforces it or not depending on `status` — and "never retroactive" falls out of
>    that for free: a rule checked only on a transition or a write cannot fire on a row nobody
>    touches.
> 2. **A domain event with a transaction around it.** ADR-0008 says "dispatch after a successful
>    save". In Listing the save runs inside `holdSteady()`'s transaction, so "after save" is not
>    "after commit": the handler must release the events *inside* and dispatch them *outside* the
>    closure. The first Listing event is the first place that distinction is real.
> 3. **The read side of privacy.** The public page's SQL never selects `seller_id`. Not "selects it
>    and doesn't render it" — the column a page cannot leak is the one it never read.

---

## Ubiquitous language (this slice)

| Term | Means | Not to be confused with |
|---|---|---|
| **Status** | Where a listing is in its life: `Draft`, `Published`, `Withdrawn`. | Category status (`Leaf`/`Branch`/`Missing`, ADR-0019). |
| **Draft** | Never yet public. Private to its seller. Editable, re-fileable; `required` not enforced. | Withdrawn (was public once). |
| **Publish** | The seller makes the listing public. Allowed from `Draft` and from `Withdrawn` ("publish again"). Re-checks everything the listing claims about its category (L-14) and that it is **complete** (L-13). | Save (a revise). |
| **Published** | Public: visible to anyone at `/listings/{id}`. Editable by its seller; stays published through edits, which must keep it complete. Cannot be re-filed (L-15). | "Active"/"live" (not used in code). Featured (Billing, Phase 3). |
| **Withdraw** | The seller takes a published listing down. It is no longer public; its data is kept. | Delete (not in this slice). Expire (Phase 2's 30-day lifecycle — a different status, later). |
| **Withdrawn** | Was public, is not now. Private to its seller, editable and re-fileable like a draft; can be published again. | Draft. |
| **Complete** | Every attribute marked `required` in the listing's category's **current** effective schema has a value. | Valid (L-9: every value *given* fits its rule). A listing can be valid and incomplete. |
| **Published at** | The moment the listing last became public (Clock, whole seconds). Null for a listing that was never published; kept on withdraw. | The listing's creation (its UUIDv7 id carries that). |
| **Public page** | `GET /listings/{id}` — the first page of muzbar anyone other than its author can see. | The seller's edit page (`/sell/…`). |

### Invariants

Listing continues its `L-n` series after L-11. **No new Catalog invariant**: C-23…C-27 already count
listings of every status (confirmed in plan D9).

| # | Invariant | Who decides | Facts / backstop |
|---|---|---|---|
| **L-11** *(narrowed)* | `required` is not enforced while a listing is **not public** (Draft, Withdrawn). A draft with every required attribute empty saves. | `Listing::revise()` (by checking L-13 only when `Published`). | — |
| **L-12** | **The lifecycle:** `Draft → Published` and `Withdrawn → Published` by `publish()`; `Published → Withdrawn` by `withdraw()`. Nothing returns to `Draft`. `publish()` on a published listing and `withdraw()` on a withdrawn one change nothing and record nothing; `withdraw()` on a draft is **refused**. Only the seller may do either (L-6, first). | `Listing::publish()`, `Listing::withdraw()`. | `status varchar(16)`, native `enum-type`. |
| **L-13** | **A listing is complete when it becomes public and whenever it is revised while public:** every attribute marked `required` in its category's effective schema **current at that write** has a value. Checked at `publish()` and at every `revise()` of a `Published` listing — **never retroactively**: a listing that an admin's later change makes incomplete stays published, untouched, until its seller's next revise. | `Listing::publish()`, `Listing::revise()`, on the `AttributeSchema` read through `Categories::schemaOf()` inside `holdSteady()`. | No index can see it; the shared lock makes the schema true until commit. |
| **L-14** | **Publishing re-checks what the listing claims about its category, unconditionally:** the category exists and is a leaf (L-2), and every **stored** value fits the current schema (L-9) — even when nothing changed since the last save. | `Listing::publish()`. | Shared lock. (Revise keeps skipping L-2 on an unchanged category — legacy data stays editable; it just cannot go public.) |
| **L-15** | **A published listing cannot be re-filed.** Withdraw it, re-file, publish again. | `Listing::revise()`, before L-2. | The edit form renders the category disabled when published. |
| **L-16** | `publishedAt` is set by `publish()` to the Clock's now, **is null exactly when the listing was never published**, is kept on withdraw and moves forward on publish-again. | `Listing::publish()`. | `published_at timestamptz(0) NULL`. |

**What these keep true together:** every listing anyone but its seller can see is on a leaf, holds
only values that fitted the rules current when it was last written, and had every required detail
filled when it was last written. **What is deliberately not kept true:** that a public listing is
complete *against today's schema* — that is D11.2's "never retroactive", and it is the owner's call
(roadmap), not an accident.

**Read-side rule (not an aggregate invariant, owned by `DbalSellerDrafts`):** a listing is visible
outside its seller's surface **only while `Published`**. Draft, withdrawn, unknown and malformed ids
are the same 404, for everyone including the seller (no preview — Q5).

---

## User story

As a **seller with a finished draft**, I want **to publish it, keep it up to date while it is
public, and take it down when the gear is sold**, so that buyers can find it — and so that it says
everything its category asks of it.

As a **buyer** (anyone, signed in or not), I want **one page per listing showing what the gear is,
what it costs, where it sits in the catalog and every detail the seller filled in** — and never the
seller's email address.

As **muzbar**, I want **`required` to mean something for the first time, without an admin's schema
edit silently unpublishing or vetoing anyone's listing.**

Secondary and ranked (Constitution §2): as the **developer**, I want to see a state machine inside an
aggregate where one invariant's force depends on the state, and the first Listing domain event
released across a transaction boundary correctly.

---

## In scope

**Domain (`Listing`):** `ListingStatus` gains `Published`, `Withdrawn`. `Listing` gains
`publish()`, `withdraw()`, `publishedAt()`, uses `RecordsEvents`; `revise()` gains L-13 (when
published) and L-15. `AttributeRule` gains `required`; `AttributeSchema` gains `missingFrom()`;
`AttributeValue` gains `toRaw()` (L-14 re-reads stored values through the one existing parser).
Event `ListingPublished`. Exceptions `ListingIncomplete`, `ListingNotPublished`,
`PublishedListingCannotBeRefiled`.

**Application:** `ReviseListing` gains `bool $publish` (no default); `ReviseListingHandler` publishes
in the same transaction when asked and dispatches released events **after** `holdSteady()` returns.
New `WithdrawListing` + `WithdrawListingHandler`.

**Infrastructure:** `published_at` column + migration; `CategoriesForListing::schemaOf()` reads
`is_required`; `DbalSellerDrafts` gains `status`/`publishedAt` on its rows
and `findPublished()`; `DbalAttributeSchema::optionLabels()`; new public `ListingController`
(`GET /listings/{id}`) + `listing/show.html.twig`; `SellController`: "Save and publish", "Withdraw",
status on "Your listings" and the edit page, disabled category when published; seller routes unchanged
(Q8).

**Docs:** ADR-0021 + amendments (T0), roadmap (tick; re-order the two candidates — plan Q7),
CLAUDE.md, `docs/infrastructure.md` (deploy window), `FORboehpyk.md`.

---

## Non-goals (explicit, hold the line)

| Deliberately excluded | Belongs to / why |
|---|---|
| Expiry, renewal, the 3-day warning mail, an `Expired` status, `expires_at` | Phase 2 30-day lifecycle (roadmap; Constitution §3 Scheduler row). |
| **Any way to contact the seller** (mail-relay form, phone, Telegram) | Its own slice (PRD §6 "explicitly preferred external channels"). The public page shows **no** seller data at all until then — Q4. |
| A listener for `ListingPublished`; `ListingWithdrawn` / `ListingRevised` events | Notification / Search (Phase 2). The event exists so its payload is designed before a listener bends it (ADR-0008's `UserRegistered` precedent) — Q3. |
| Deleting a draft or a listing | Not asked for; PRD's "delete" is a later seller-surface item. GDPR erasure owns the rest. |
| A seller preview of an unpublished listing at its public URL | Q5. The edit page is the seller's view. |
| Admin moderation (unpublish someone else's listing) | Phase-later; L-6 would gain a named exception. |
| Public listing index / search / category browse pages; links to the public page from anywhere but "Your listings" | Phase 2 search UI. |
| Styling of the public page; the SCSS pipeline | Q7: its own slice. Unstyled semantic markup with stable class names, as every page today. |
| The UUID-trait refactor | Q7: its own slice. This slice adds **no** id copy (still eleven). |
| Photos | Not on the roadmap yet. |
| Enforcing `required` / current bounds on listings nobody touches; a "your listing is now incomplete" notice | D11.2: never retroactive. |
| A standalone "publish without saving" endpoint | Q2: publish is a button on the edit form; one POST, one transaction. |
| Re-filing a published listing | L-15 (Q1). |
| Caching / `noindex` / SEO metadata on the public page | Pre-launch; `/` is still the coming-soon stub. |

---

## Acceptance criteria (the Definition of Done checklist)

Fixture tree and schema — listing-attribute-values' tree, **unchanged**, built in C-21-respecting
order with `->inCategory()`:

```
Instruments                       condition  (choice: New, Used)          required
├── Guitars                       brand      (choice: Fender, Gibson)     required
│   └── Electric (leaf)           strings    (number, 4…12)
│                                 has_case   (boolean)
│                                 serial     (text)
└── Bass (leaf)                   bass_strings (number, no bounds)
```

Two verified users, **Ann** (seller) and **Bob**. Ann has a draft **D** on Electric. "Complete D" =
D with condition = Used, brand = Fender. The Clock in tests is a fixed whole-second instant **T**.

### Aggregate (Domain unit tests, no kernel)

- [x] **AC-1 (publish, happy path, L-12/L-16)** — complete D, `publish(Ann, Leaf, schema, T)`:
      `status()` = `Published`, `publishedAt()` = T, `releaseEvents()` = exactly one
      `ListingPublished` carrying D's `ListingId`, Ann's `SellerId`, Electric's `CategoryId` and T —
      and nothing else (no title, price or values). A fresh draft has `publishedAt()` = null and
      records no event.
- [x] **AC-2 (publish refusals, order, check-before-assign)** — each refusal leaves `status()`,
      `publishedAt()` unchanged and records no event:
      - by Bob → `NotTheSeller`, even when D is also incomplete (L-6 first);
      - status `Branch` → `CategoryIsNotALeaf`; `Missing` → `UnknownCategory` (L-14, even though D's
        category is unchanged since its last save);
      - a stored `strings` = 6 against a schema whose strings rule is now 7…12 →
        `InvalidAttributeValue` (out of range, bounds 7 and 12) (L-14);
      - condition and brand both empty → `ListingIncomplete` naming **both** attribute ids, in one
        exception (L-13);
      - order: L-6 → L-14 (L-2) → L-14 (L-9) → L-13 — a D on a `Branch` with an empty brand reports
        `CategoryIsNotALeaf`.
- [x] **AC-3 (no-ops and withdraw, L-12)** — `publish()` on a published D (by Ann) changes nothing,
      keeps `publishedAt()` and records nothing. `withdraw(Ann)` on a published D → `Withdrawn`,
      `publishedAt()` kept, no event; again → no-op. `withdraw(Ann)` on a draft →
      `ListingNotPublished`; `withdraw(Bob)` → `NotTheSeller`. `publish()` on a withdrawn D at T+60s
      → `Published`, `publishedAt()` = T+60s, one `ListingPublished`.
- [x] **AC-4 (revise by status, L-11/L-13/L-15)** —
      - Draft and Withdrawn: revise with required attributes empty succeeds (L-11).
      - Published: revise keeping condition and brand succeeds and stays `Published`; revise
        clearing brand → `ListingIncomplete` naming brand, nothing changed; revise against a schema
        with a **new** required attribute (absent from the input) → `ListingIncomplete` naming it.
      - Published: revise to Bass → `PublishedListingCannotBeRefiled`, before L-2 (also with Bass
        `Missing`), nothing changed. Withdrawn: re-file to Bass succeeds (L-10 applies).
- [x] **AC-5 (schema fact)** — `AttributeSchema::missingFrom(AttributeValues)` returns exactly the
      required rules' ids with no value, in id order; `AttributeValue::toRaw()` read back through its
      own rule returns an equal value for each of the four kinds.
- [x] **AC-6 (shape)** — `Listing`'s public methods are exactly the listing-attribute-values AC-4
      list plus `publish`, `withdraw`, `publishedAt`, `releaseEvents` (allow-list test updated **on
      purpose**; listing-draft AC-6's "does not use `RecordsEvents`" assertion **inverted on
      purpose**). `ListingPublished` holds only value objects and a `\DateTimeImmutable`.
- [x] **AC-7 (isolation)** — `ListingIsolationTest` and `CatalogIsolationTest` pass unchanged.
      **Amended at `/verify`:** "unchanged" was wrong in one respect — the first Listing event uses
      the shared kernel (`RecordsEvents`, `DomainEvent`, `Clock`), so `ListingIsolationTest` now
      admits `App\Domain\Shared` and nothing else. The shared kernel is not a bounded context; the
      test won, on purpose. `CatalogIsolationTest` is unchanged.

### Use cases against a real database (Integration, DAMA)

- [x] **AC-8 (schema)** — after the migration `listing_listing.published_at` is `timestamp(0) with
      time zone`, nullable; every pre-existing row is `status = 'draft'`, `published_at IS NULL`.
      `make migration.make` afterwards generates **nothing**.
- [x] **AC-9 (save and publish)** — `ReviseListing(publish: true)` on D with condition = Used, brand
      = Fender: one transaction; a **fresh EntityManager** load shows `Published`, `publishedAt` = T,
      the values stored. The recording dispatcher received exactly one `ListingPublished`.
      `ReviseListing(publish: false)` dispatches nothing.
- [x] **AC-10 (refused publish writes nothing)** — `ReviseListing(publish: true)` on D with a new
      title and brand empty → `ListingIncomplete`; `listing_listing` and `listing_attribute_value`
      equal their pre-command snapshot (the title edit is **not** saved either), nothing dispatched.
- [x] **AC-11 (`schemaOf()` reports required)** — for Electric, condition and brand are required,
      the other three are not; toggling `required` on strings through `EditAttribute` flips it on
      the next read.
- [x] **AC-12 (never retroactive — the D11.2 test)** — with D published and strings = 6:
      - an admin marks `has_case` required, defines a new required attribute on Instruments, and
        narrows strings to 7…12: all succeed; D's `listing_listing` and `listing_attribute_value`
        rows are byte-identical; D is still `published` and its public page is still 200;
      - Ann's next revise with only the old fields → refused (strings out of range first, L-9);
        with strings = 8 → `ListingIncomplete` naming `has_case` and the new attribute; with all
        filled → saved, still `Published`, `published_at` unchanged, nothing dispatched.
      - The same three admin changes against a **withdrawn** D: Ann's revise without the new fields
        succeeds (L-11); her publish without them is refused (L-13).
- [x] **AC-13 (withdraw)** — `WithdrawListing` on published D → `withdrawn`, `published_at`
      unchanged, values unchanged; on a draft → `ListingNotPublished`, row unchanged; by Bob →
      `NotTheSeller`, row unchanged.
- [x] **AC-14 (Catalog guards are status-blind — ADR-0019's watch item)** — with **only** a
      published listing on Electric (no drafts anywhere): deleting Electric (C-23), creating a child
      under it (C-24), deleting brand (C-25), removing Fender (C-26), moving Electric (C-27) are each
      refused. With only a **withdrawn** listing: deleting Electric and creating a child under it
      are refused.
- [x] **AC-15 (the lock and the commit, recording doubles)** — `ReviseListingHandler` calls
      `statusOf()`, `schemaOf()` and `save()` inside `holdSteady()`, and calls
      `DomainEventDispatcher::dispatch()` **outside** it (the double records whether the lock was
      held at dispatch time). `WithdrawListingHandler` calls `get()` and `save()` inside
      `holdSteady()`. Each removal fails a named test.

### HTTP surface (Functional)

- [x] **AC-16 (your listings)** — `GET /sell/drafts` lists **only** Ann's listings, newest first,
      each with its status as a word ("Draft", "Published", "Withdrawn"); a published one also links
      to its public page. Title "Your listings". URLs unchanged (Q8).
- [x] **AC-17 (edit page by status)** — `GET /sell/drafts/{D}/edit`:
      - Draft: heading "Edit listing", status "Draft", buttons "Save draft" and "Save and publish";
        no withdraw form.
      - Published: status "Published" with a link to the public page; the category `<select>` is
        `disabled` with help *"Withdraw the listing to move it to another category."*; one button
        "Save changes"; a separate POST form "Withdraw" with its own CSRF token.
      - Withdrawn: status "Withdrawn"; buttons "Save" and "Save and publish again"; category enabled.
- [x] **AC-18 (publish from the form)** — "Save and publish" on complete D → 303 to
      `/listings/{D}` with flash *"Published. This is what buyers see."*. Incomplete → **422**, the
      edit form with *"Fill this in to publish."* on every missing required field, every field
      keeping its submitted value, flash-free, nothing written. "Save and publish" with a **changed
      category** saves the re-file as before (L-10), does **not** publish, and lands on the edit page
      with *"Saved, not published yet. The details now follow “Bass”: check them, then publish."*
- [x] **AC-19 (edit while published)** — "Save changes" on published D: valid and complete → 303 to
      `/sell/drafts` with *"Saved. Your listing is updated."*, still published; clearing brand →
      422 *"Fill this in to publish."* on brand, nothing written; a tampered `category` field on
      the POST is ignored (disabled field) and the category is unchanged.
- [x] **AC-20 (withdraw from the form)** — `POST /sell/drafts/{D}/withdraw` with a valid token on
      published D → 303 `/sell/drafts`, flash *"Withdrawn. It is no longer public."*; the public
      page is now 404. Bad/missing token → **403**, checked **before** the ownership lookup so it is
      the same for every id (the `CategoryAdminController::delete()` precedent), nothing changed. On a draft → 303, error flash *"Only a published
      listing can be withdrawn."* `GET` on the withdraw URL → 405.
- [x] **AC-21 (public page content)** — anonymous `GET /listings/{D}` on published D → 200, showing:
      the title as `<h1>`; the price as "1234.50 EUR"; the category path "Instruments › Guitars ›
      Electric"; "Listed on 20 Nov 2026" (from `published_at`); the description with line breaks
      preserved and HTML escaped (`<script>` in a description renders as text); a "Details" `<dl>`
      with one entry per stored value in `effectiveFor()` order — choice → its option's label,
      number → value with unit ("6 pcs"), boolean → "Yes"/"No", text → as stored. Attributes with
      no value are omitted; no Details section when there are none. Signed in as Bob: identical body.
- [x] **AC-22 (privacy — Constitution §8)** — the public page's HTML contains **neither** Ann's
      email, **nor** her user id, **nor** any string from `identity_user`; and the read model's SQL
      for it (captured by `QueryRecordingStatement`) **never names `seller_id`**.
- [x] **AC-23 (404 parity)** — `GET /listings/{x}` is 404 for x = a draft, a withdrawn listing, an
      unknown UUID, a malformed id — for anonymous, Bob, **and Ann** — and the four bodies are equal
      after normalising ids and dump ids (the `SellTest` technique). A malformed id issues no query.
- [x] **AC-24 (public page query budget)** — the public page issues at most **five** queries
      (listing, outline, effective schema, values, option labels), the same with one choice
      attribute as with four, and the option-label query names only the stored option ids (a
      50-option attribute fetches one label row).
- [x] **AC-25 (no GET writes)** — the public page, "Your listings" and the edit page GETs leave every
      table unchanged (ADR-0015).
- [x] **AC-26 (someone else's listing)** — Bob's GET/POST on D's edit URL and POST on its withdraw
      URL (with a valid token minted for his session), for D in each status, are the listing-draft
      AC-29 404; D unchanged.
- [x] **AC-27 (silence)** — every *user error* refusal (incomplete, not-a-leaf, out of range,
      withdraw a draft) is not logged at `error`; `RecordingSentryTransport` stays
      empty.

### Cross-cutting

- [x] **AC-28 (tests bite)** — each mutation fails a named test (recorded in the commit message):
      - (a) `publish()` without L-13 → AC-2 / AC-10
      - (b) `revise()` without L-13 while published → AC-4 / AC-19
      - (c) L-13 on every revise regardless of status → AC-4 / AC-12 (draft and withdrawn)
      - (d) `publish()` without the unconditional L-2 → AC-2
      - (e) `publish()` without re-reading stored values → AC-2 / AC-12
      - (f) `publish()` assigns status before checking → AC-2 (check-before-assign)
      - (g) record `ListingPublished` on a no-op publish → AC-3
      - (h) allow re-filing a published listing → AC-4
      - (i) dispatch inside `holdSteady()` → AC-15
      - (j) `schemaOf()` hard-codes `required = false` → AC-11 / AC-12
      - (k) drop `status = 'published'` from `findPublished()` → AC-23
      - (l) select `seller_id` in the public read → AC-22
      - (m) filter `status = 'draft'` in `ListingCategoryUsage::hasListings()` → AC-14
      - (n) `withdraw()` keeps status `Published` → AC-3 / AC-20
      - (o) `publishedAt` not set from the Clock (e.g. `new \DateTimeImmutable()`) → AC-1 / AC-9
- [x] **AC-29** — `make check` green, `make test` passes **twice in a row**, Deptrac 0 violations
      with `--fail-on-uncovered`, `make migration.make` empty, CI-mode run
      (`php -d zend.assertions=-1 vendor/bin/phpunit`) green.

---

## Failure contract

| Condition | Expected behaviour | Kind |
|---|---|---|
| Publish (or save while published) with a required detail empty | `ListingIncomplete` (all missing ids at once). 422; *"Fill this in to publish."* on each such field; nothing written (the whole save is refused, not half-applied). | user error |
| A required attribute was defined after the form was rendered (no field for it) | Same exception; the id has no field → error on the form itself: *"A new detail is now required. Reload the page."* | user error |
| Publish with a stored value outside narrowed bounds | `InvalidAttributeValue` → the existing out-of-range message on that field, 422. | user error |
| Publish while the category is no longer a leaf / is gone (legacy data) | `CategoryIsNotALeaf` / `UnknownCategory` → 422 on `category`, existing messages. | user error |
| "Save and publish" with a changed category | Saved as a re-file, **not** published; edit page with the "Saved, not published yet" notice (AC-18). | by design |
| Re-file a published listing (tampered: the field is disabled) | Ignored by the disabled field; if it ever reaches the Domain, `PublishedListingCannotBeRefiled` → 422 on `category`: *"Withdraw the listing to move it to another category."* | user error |
| Withdraw a draft (stale tab / tampered) | `ListingNotPublished` → 303, error flash *"Only a published listing can be withdrawn."* | user error |
| Publish an already-published listing; withdraw an already-withdrawn one (double-submit) | No-op, no event, normal success redirect. | idempotent |
| Withdraw with a missing/invalid CSRF token | 403 (the admin delete precedent), checked before the ownership lookup so it reveals nothing about the id; nothing changed. | tampering |
| An admin marks an attribute required, defines a required one, or narrows bounds while listings are published | Allowed. Published listings stay published and public, rows untouched; the seller's next save must satisfy the current rules (AC-12). | by design |
| Someone else's / unknown / malformed listing id on the seller surface | 404, identical body (unchanged). | authz |
| Draft / withdrawn / unknown / malformed id on `/listings/{id}` | 404, identical body, for everyone including the seller. | privacy |
| Seller save and admin schema write at the same moment | Serialised by the tree lock (shared vs exclusive), as before; publish decides on committed facts. | concurrency |
| Crash between commit and `dispatch()` | The listing is published; `ListingPublished` is lost. Accepted: nothing listens yet (ADR-0008's original window, re-opened knowingly for this event — ADR-0021). | accepted |
| Deploy window: code up before migration | Every Doctrine load of a listing (edit page, all seller POSTs) 500s until `migrate` adds `published_at` (seconds). The public page 500s too. Logged at `error` → Sentry. Accepted, as the two previous Listing slices. | deploy |
| Postgres down | The usual 500; each command is one transaction. | dependency |
