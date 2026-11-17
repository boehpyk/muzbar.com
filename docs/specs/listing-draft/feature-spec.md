# Feature Spec: listing-draft

> The *what & why*. Disposable: it dies once the feature ships and its behaviour lives in tests +
> code. Make it **executable** (measurable, enumerated), not pretty. Must not contradict the
> [Constitution](../../constitution.md) or an accepted ADR.

- **Feature:** `listing-draft`, slice **1 of 4** of the `Listing` skeleton roadmap item (then
  `listing-attribute-values`, `listing-publish`, `listing-schema-drift`; then the `phase1-gate`)
- **Bounded context(s):** **`Listing`** (new: its first aggregate) and **`Catalog`** (three guards).
  The first slice in which two contexts depend on each other. **Neither context's Domain or
  Application layer imports the other** — each states what it needs as a port in its own language,
  and only Infrastructure knows both (plan D3).
- **Related PRD items:** US-1 / §6 (a seller creates a listing), FR-1 (listings sit in the category
  schema), PRD §6 privacy (no seller email shown, nothing public yet), PRD validation #2 (this slice
  lays the row the value layer will hang off)
- **Governing ADRs:** [ADR-0007](../../adr/0007-persistence-conventions-for-domain-aggregates.md)
  (XML, DBAL types, UUIDv7, `listing_listing`), [ADR-0009](../../adr/0009-email-verification-tokens-modelled-in-the-domain.md)
  decision 4 (reference by id, no FK), [ADR-0012](../../adr/0012-challenge-retention-and-recurring-background-work.md)
  (put in the Domain the part that can be wrong), [ADR-0015](../../adr/0015-intended-action-redirect-and-login-overlay.md)
  (overlay-trigger shape; no GET changes state), [ADR-0016](../../adr/0016-category-tree-per-node-aggregates-under-one-tree-lock.md)
  (aggregate decides on facts, the lock makes them true; delete guards accrete; the move is an
  accretion point), [ADR-0018](../../adr/0018-schema-inheritance-derived-along-the-lineage-codes-unique-per-branch.md)
  decision 8 (the four points handed to `Listing`), and **ADR-0019** (new, task T0)
- **Status:** Approved by owner 2026-11-16 (Q1 answered: USD, EUR, SEK, UAH; Q2–Q8 at defaults).
- **Date:** 2026-11-16

> **What this slice teaches, beyond the feature.** It is the first time one context needs a fact
> another context owns, *and* the first time an invariant belongs to one context while the writes
> that could break it belong to another.
>
> **"A listing is filed under a leaf category"** is `Listing`'s rule (L-2). `Listing` can check it
> when a listing is drafted or re-filed. But a listing can stop being on a leaf **without the listing
> being touched at all**: an admin creates a subcategory under it, or moves a category under it. So
> the rule has three doors (draft, re-file, and Catalog's create/move), two owners, and — as with
> C-21 — no index that can see it. The answer is the ADR-0016 pattern stretched across a context
> boundary: each side's aggregate or handler decides on a fact read from the *other* side through
> its own port, and **one lock in two modes** makes those facts true — Catalog writes take it
> exclusively (as today), listing writes take it *shared* so they never queue behind one another.
>
> And the price is the first **two-part value object** in the codebase (an amount and a currency),
> which ADR-0007's "one VO, one column, one DBAL type" never had to face (plan D6).

---

## Ubiquitous language (this slice)

| Term | Means | Not to be confused with |
|---|---|---|
| **Listing** | A seller's advertisement for one piece of gear. In this slice it only ever exists as a draft. Constitution §4: never "product", never "ad" in code. | A catalog category. A published page (`listing-publish`). |
| **Draft** | The only `ListingStatus` this slice has: private to its seller, never shown to anyone else, no expiry. | A form half-filled in the browser (nothing is saved until submit). |
| **Seller** | The signed-in user who drafted the listing. Held by `Listing` as a `SellerId` — the **Listing context's** name for an `Identity` user id. | `User` (Identity's aggregate). Listing never sees it. |
| **Filed under** | The one category a listing belongs to (`CategoryId`, Listing's own VO). | Appearing under every ancestor in search (Phase 2 derives that). |
| **Leaf** | A category with no child categories at the moment of reading. | A root. A "small" category. |
| **Category status** | The fact Listing asks Catalog for: `Missing`, `Leaf` or `Branch`. True only while the catalog is held steady. | Any stored column. Nothing about it is stored. |
| **Held steady** | The window, inside a Listing write's transaction, in which no Catalog write can commit (shared mode of the tree lock). | Exclusive (Catalog's own writes). |
| **Price** | A positive whole number of **minor units** (cents, kopiyky) plus a **currency**. Never a float, never a decimal string once inside the Domain. | The decimal text the seller types ("1 234,50"). |
| **Currency** | An ISO 4217 code from a closed allow-list, each with its number of minor units. | A locale or a country. |
| **Listed-on category** | A category with at least one listing filed under it, of any status. | A category with attributes (C-13). |

### Invariants

`Listing` starts its own series (`L-n`). Catalog continues its `C-n` series after C-22.

| # | Invariant | Who decides | Facts / backstop |
|---|---|---|---|
| **L-1** | A listing has exactly one seller, fixed when drafted and never changed. | `Listing::draft()` (constructor parameter; no setter). | `seller_id NOT NULL`. No FK (ADR-0009 d.4). |
| **L-2** | **A listing is filed under a category that exists and is a leaf** — when drafted and whenever its category changes. | `Listing::draft()` / `revise()`, on the `CategoryStatus` read through `Categories` while held steady. Re-saving with an **unchanged** category checks nothing (the `moveUnder()`-to-current-parent shape). | No index can see it. The **shared lock** is the guarantee on Listing's side; **C-23/C-24** are Catalog's half. |
| **L-3** | Title: 3–120 characters after trimming; no control characters, valid UTF-8. | `ListingTitle` | `varchar(120)` |
| **L-4** | Description: 0–5000 characters after trimming; no control characters but line breaks and tabs, valid UTF-8. | `ListingDescription` | `varchar(5000)` |
| **L-5** | Price: an integer of minor units, 1 ≤ amount ≤ 999 999 999 (9 999 999.99 at two decimals), in an allowed currency; decimal input may not carry more fraction digits than that currency has minor units. | `Price`, `Currency` | `bigint`, `varchar(3)` |
| **L-6** | **Only the seller may revise a listing.** | `Listing::revise(SellerId $by, …)`, checked **first**, before any assignment. | Read side: the draft read model filters by seller. |
| **L-7** | A new listing is a `Draft`; this slice has no transition. | `Listing::draft()` | `status varchar(16)` |
| **C-23** | **A listed-on category cannot be deleted.** Third guard in `DeleteCategoryHandler`, after C-7 and C-13. | `DeleteCategoryHandler`, under `CategoryTreeLock`. | `CategoryUsage::hasListings()`. |
| **C-24** | **A listed-on category cannot gain a child** — neither by *create under it* nor by *move under it*. Together with L-2 this keeps every listing on a leaf. | `CreateCategoryHandler` (parent not null) and `EditCategoryHandler` (only when the parent changes, after C-4, C-5, C-21), under `CategoryTreeLock`. | `CategoryUsage::hasListings()`. |

Moving a **listed-on** category somewhere else is allowed: it stays a leaf, its listings go with it,
and no value exists yet whose meaning could change (roadmap: "moves stay harmless").

---

## User story

As a **signed-in seller**, I want to **click "Sell Gear", fill in a title, description, price and
currency, pick the exact category, and save it as a draft I can come back to and edit**, so that I
can start a listing now and finish it later.

As **muzbar**, I want **every listing to sit on a leaf category, whatever admins do to the tree**, so
that the listing form (next slice) and search (Phase 2) always have exactly one, most specific
schema to work with.

Secondary and ranked (Constitution §2): as the **developer**, I want this slice to show how two
bounded contexts depend on each other **without either Domain importing the other**, and how an
invariant owned by one context is protected against writes made by another.

---

## In scope

**Domain (`Listing`, new):** `Listing` aggregate (`draft()`, `revise()`, readers); VOs `ListingId`,
`SellerId`, `CategoryId`, `ListingTitle`, `ListingDescription`, `Price`; enums `Currency`,
`ListingStatus`, `CategoryStatus`; exceptions; ports `ListingRepository` and `Categories`.

**Domain (`Catalog`):** port `CategoryUsage`; exception `CategoryHasListings`.

**Application:** `DraftListing` / `DraftListingHandler`, `ReviseListing` / `ReviseListingHandler`
(both inside `Categories::holdSteady()`). `DeleteCategoryHandler`, `CreateCategoryHandler`,
`EditCategoryHandler` gain the C-23/C-24 guards.

**Infrastructure:** `listing_listing` table + migration; XML mapping, DBAL types, `Price` as an
embeddable; `DoctrineListingRepository`; `CategoriesForListing` (in `Infrastructure/Catalog`,
reads catalog tables, takes the lock in shared mode); `ListingCategoryUsage` (in
`Infrastructure/Listing`, reads the listing table); `DbalSellerDrafts` read model; `SellController`
with `/sell`, `/sell/drafts`, `/sell/drafts/{id}/edit`; Twig templates; "Sell Gear" in the layout;
`access_control` for `^/sell(/|$)`; refusal messages in the Catalog admin.

**Tests:** `ListingIsolationTest` (copy of `CatalogIsolationTest`, plus a data-ownership scan).

---

## Non-goals (explicit, hold the line)

| Deliberately excluded | Belongs to / why |
|---|---|
| Attribute values, `listing_attribute_value`, the `EffectiveSchema` port, an attribute section on the form | `listing-attribute-values` (ADR-0018 d.8.4). |
| Guards on **attribute** delete / option remove / move-with-values | `listing-attribute-values`: no value exists that they could strand. |
| Publishing, `Published`/`Withdrawn`, any public listing page, `/listings/{id}` | `listing-publish`. |
| 30-day expiry, Scheduler | Phase 2. |
| Images, contact channels, location | Later slices / Phase 2. |
| Hot-column promotion | Dropped to the Phase 2 latency gate (roadmap). |
| **Deleting or withdrawing a draft** | Not asked for. Additive later; note it is the first time *Listing* would delete a row a Catalog guard counts. |
| Domain events (`ListingDrafted`) | Plan D9: no consumer, and the first real consumer wants `ListingPublished`. |
| Cross-currency price **facets / sorting / conversion** | Phase 2 must decide (owner, 2026-11-16). This slice only stores amount + code. |
| An admin view of listings; moving listings in bulk when the tree changes | Not asked for; the C-23/C-24 refusals mean nothing ever needs moving. |
| A Live Component wizard / morphing form | Phase 2 (ADR-0015: a Live Component is for server-side state that changes as the user edits). |
| The SCSS pipeline (`symfonycasts/sass-bundle`, `public.css`) | Not installed yet (ADR-0006 decided it; nothing set it up). Q7. Pages ship as unstyled semantic markup like every public page so far. |
| Pagination of "my drafts", per-seller draft caps, a rate limiter on `/sell` | Q6. Verified accounts only; additive. |
| A shared UUID trait for the (now nine) id VOs | ADR-0009's proposed refactor, its own slice (plan Risk 4). |
| Search, facets, the 200 ms budget | Phase 2. Nothing here is on the search path. |

---

## Acceptance criteria (the Definition of Done checklist)

Fixture tree used below (`CategoryFactory`):

```
Instruments                (branch)
├── Guitars                (branch)
│   └── Electric           (leaf)
└── Bass                   (leaf)
```

Two verified users: **Ann** (seller) and **Bob**. Currencies per Q1 (owner): `USD`, `EUR`, `SEK`, `UAH`.

### Values and aggregate (Domain unit tests, no kernel)

- [ ] **AC-1 (`Price`):**
      - `Price::fromDecimal("1234.5", EUR)` and `("1234,50", EUR)` → amount `123450`; `("7", UAH)`
        → `700`; `(" 12.00 ", USD)` → `1200` (trimmed); `("0.29", EUR)` → `29` and
        `("1.15", EUR)` → `115` (both come out one short through a `float`).
      - Each of `""`, `"0"`, `"0.00"`, `"-1"`, `"1.234"`, `"1e3"`, `"1 000"`, `"12."`, `".5"`,
        `"١٢"` (Arabic-Indic digits), `"10000000.00"` throws `InvalidPrice`.
      - `"9999999.99"` is accepted (`999999999`).
      - `Price::fromMinorUnits(0, EUR)` and `(1_000_000_000, EUR)` throw; `(1, EUR)` succeeds.
      - `toDecimalString()` of `123450 EUR` is `"1234.50"`; of `700 UAH` is `"7.00"`.
      - `equals()` compares amount **and** currency (`100 EUR` ≠ `100 USD`).
      - No `float` appears anywhere in `Price` (asserted by a source scan in the test, so the claim
        is checked, not stated).
- [ ] **AC-2 (`Currency`):** the cases are exactly `USD`, `EUR`, `SEK`, `UAH` (backing values equal to the
      names); `minorUnits()` is `2` for each; `Currency::fromCode("GBP")`, `("eur")`, `("")` throw
      `InvalidPrice` (unsupported currency).
- [ ] **AC-3 (`ListingTitle`, `ListingDescription`):** values are trimmed; a title of 2 or 121
      characters (counted in characters, so 120 × "ї" is accepted) throws `InvalidListingTitle`; a
      description of `""` is accepted and one of 5001 characters throws
      `InvalidListingDescription`.
- [ ] **AC-4 (`Listing::draft()`, L-1, L-2, L-7):** with `CategoryStatus::Leaf` it returns a listing
      whose readers return exactly what was passed, and `status()` is `Draft`. With `Missing` it
      throws `UnknownCategory`; with `Branch`, `CategoryIsNotALeaf`.
- [ ] **AC-5 (`Listing::revise()`, L-2, L-6):**
      - By a `SellerId` other than the seller: throws `NotTheSeller`, and **every** reader is
        unchanged — even when the new category's status is also bad (L-6 is checked first).
      - By the seller, with an **unchanged** category and status `Branch` (a legacy row): succeeds
        and changes title/description/price (unchanged category checks nothing).
      - By the seller, to a **new** category: `Leaf` succeeds; `Missing` → `UnknownCategory`,
        `Branch` → `CategoryIsNotALeaf`, and in both refusals title, description and price are
        also unchanged (all checks before any assignment).
- [ ] **AC-6:** `Listing` does not use `RecordsEvents`; its public methods are exactly `draft`,
      `revise`, `id`, `sellerId`, `categoryId`, `title`, `description`, `price`, `status`,
      `isSoldBy` (allow-list test, the Catalog shape).
- [ ] **AC-7 (`ListingIsolationTest`):**
      - Nothing under `src/Domain/Listing` or `src/Application/Listing` references
        `App\<Layer>\<OtherContext>\` (the `CatalogIsolationTest` regex), and the scan counted > 0
        files.
      - **Data ownership:** no PHP or XML file under `src/Infrastructure/Listing` contains a
        `catalog_` table name, and none under `src/Infrastructure/Catalog` contains `listing_`
        (each context's tables are read only by its own Infrastructure; plan D4). Also > 0 files.
- [ ] **AC-8:** `CatalogIsolationTest` passes unchanged (Catalog's Domain/Application import nothing
      from Listing — `CategoryUsage` is Catalog's own port).

### Use cases against a real database (Integration, DAMA)

- [ ] **AC-9 (draft):** `DraftListing(seller: Ann, category: Electric, title: "Fender Precision V",
      description: "", price: "1234.50", currency: "EUR")` returns an id, and `listing_listing` holds
      exactly one row: that id (a UUIDv7), `seller_id` = Ann, `category_id` = Electric,
      `title` = "Fender Precision V", `description` = "", `price_amount` = `123450`,
      `price_currency` = `'EUR'`, `status` = `'draft'`.
- [ ] **AC-10 (L-2 on draft):** drafting on `Guitars` throws `CategoryIsNotALeaf`; on a random
      well-formed UUID and on `"not-a-uuid"`, `UnknownCategory`. `listing_listing` stays empty.
- [ ] **AC-11 (revise):** Ann revising her draft (new title, price "99", currency "UAH", category
      Bass) succeeds; a **fresh** repository shows all four changes.
- [ ] **AC-12 (L-6, L-2 on revise):** Bob revising Ann's draft throws `NotTheSeller`; Ann moving it to
      `Instruments` throws `CategoryIsNotALeaf`; an unknown or malformed listing id throws
      `ListingNotFound`. After each, a snapshot of the row (all columns) is identical to before.
- [ ] **AC-13 (C-23):** with a draft on `Electric`, `DeleteCategory(Electric)` throws
      `CategoryHasListings` and both rows remain. Order: `Guitars` still reports
      `CategoryHasChildren`; `Electric` with an attribute **and** a draft reports
      `CategoryHasAttributes` (C-7 → C-13 → C-23). `Bass` (no listing) is deleted.
- [ ] **AC-14 (C-24, create):** `CreateCategory(parent: Electric)` with a draft on Electric throws
      `CategoryHasListings`, and `catalog_category` has no new row; `CreateCategory(parent: Bass)`
      succeeds; a root (`parent: null`) never reads listings.
- [ ] **AC-15 (C-24, move):** with a draft on `Electric`, `EditCategory(Bass, parent: Electric)`
      throws `CategoryHasListings` and Bass's `parent_id` is unchanged. A move that is also a cycle
      reports the cycle (C-4 → C-5 → C-21 → C-24).
- [ ] **AC-16 (listed-on categories may still change otherwise):** with a draft on `Electric`:
      renaming and re-slugging Electric succeed; **moving Electric under Bass succeeds** (Bass had no
      listings) and the draft's `category_id` is unchanged; saving Electric with its current parent
      reads no listings.
- [ ] **AC-17 (the two cross-context adapters):**
      - `Categories::statusOf()`: `Electric` → `Leaf`, `Guitars` → `Branch`, an unknown id →
        `Missing`.
      - `CategoryUsage::hasListings()`: true for `Electric` with a draft, false for `Bass` and for an
        unknown id; a **draft of any status** counts.
- [ ] **AC-18 (the lock, recording doubles):**
      - `DraftListingHandler` and `ReviseListingHandler` call `Categories::statusOf()` and
        `ListingRepository::save()` **inside** `Categories::holdSteady()`.
      - `DeleteCategoryHandler`, `CreateCategoryHandler` (when the parent is not null) and
        `EditCategoryHandler` (when the parent changes) call `CategoryUsage::hasListings()` **inside**
        `CategoryTreeLock::exclusively()`.
      - Removing `holdSteady()` from either Listing handler, or moving the `hasListings()` call out of
        `exclusively()` in any Catalog handler, fails a named test.
- [ ] **AC-19 (the lock, real modes):** a second, non-DAMA session, probing **from inside** the work
      closure:
      - inside `holdSteady()`: `pg_try_advisory_xact_lock(KEY)` (exclusive) returns **false** and
        `pg_try_advisory_xact_lock_shared(KEY)` returns **true** (listing writes do not block each
        other);
      - inside `exclusively()`: `pg_try_advisory_xact_lock_shared(KEY)` returns **false**;
      - control: before either, both probes return true.
      `KEY` is `PostgresCategoryTreeLock::KEY` in every case (one lock, two modes).
- [ ] **AC-20 (schema):** after the migration, `listing_listing` has exactly the columns of plan
      *Data & migrations* with those types and nullability, the primary key on `id`, the two indexes
      `idx_listing_listing_seller (seller_id)` and `idx_listing_listing_category (category_id)`, and
      **no foreign key**. `make migration.make` afterwards generates **nothing**.

### HTTP surface (Functional)

- [ ] **AC-21 (authz, anonymous):** `GET /sell`, `GET /sell/drafts` and
      `GET /sell/drafts/{any}/edit` redirect (302) to `/login`; `POST /sell` does too and writes
      nothing. `GET /sellers` and `GET /selling` are **404**, not a redirect (the rule is anchored:
      `^/sell(/|$)`).
- [ ] **AC-22 ("Sell Gear" in the layout):** a guest on `/forgot-password` sees exactly one
      `a[data-auth-overlay]` whose text is "Sell Gear" and whose `href` is `/login` with
      `_target_path=/sell`, plus the existing "Profile" trigger. A signed-in user sees a plain
      `a[href="/sell"]` and no trigger. `/login` and `/register` show neither. The existing
      `LoginOverlayMarkupTest` assertion "exactly one trigger" is **updated on purpose** to select
      by text, not deleted.
- [ ] **AC-23 (intended action):** a guest following the "Sell Gear" `href`, signing in on that
      page, lands on `/sell` (303/302 chain ends at `/sell`, 200).
- [ ] **AC-24 (the form):** `GET /sell` signed in shows fields `title`, `description`, `price`,
      `currency` (a select of exactly the allowed currencies, `UAH` preselected per Q2) and `category`
      (a select of **leaves only**, each labelled with its full path, e.g. "Instruments › Guitars ›
      Electric", in the admin outline's depth-first order); `Instruments` and `Guitars` are not
      options. With no categories at all, the page says *"Listings can't be created yet: there are
      no categories."* and shows no form.
- [ ] **AC-25 (create):** a valid POST responds **303** to `/sell/drafts`, which shows the flash
      *"Draft saved."*; the row's `seller_id` is the signed-in user's id. The form has no seller
      field; posting an extra `seller` field is rejected as an extra field (422) and writes
      nothing.
- [ ] **AC-26 (refusals on the form):** each row of the failure contract marked *user error* re-renders
      the form with **422**, the stated message on the stated field, every other field keeping its
      value, and no row written.
- [ ] **AC-27 (my drafts):** `GET /sell/drafts` lists **only** the signed-in seller's drafts, newest
      first (id order), each with its title, price as `"1234.50 EUR"`, the category path and an
      "Edit" link. Bob's drafts never appear on Ann's page. With none: *"You have no drafts yet."*
      and a link to `/sell`. The page issues **two** SQL queries in total for its data (drafts +
      category outline), asserted with `QueryRecordingStatement`.
- [ ] **AC-28 (edit):** `GET /sell/drafts/{id}/edit` shows the form prefilled (price as
      `toDecimalString()`); a valid POST responds 303 to `/sell/drafts` with *"Draft saved."* and the
      row changes.
- [ ] **AC-29 (someone else's draft):** Bob's `GET` and `POST` on Ann's draft's edit URL, and anyone's
      on an unknown or malformed id, all respond **404** with identical bodies; Ann's row is unchanged
      after Bob's POST. (A 403 would confirm that the draft exists.)
- [ ] **AC-30 (admin refusals):** in the Catalog admin, with a draft on `Electric`:
      - deleting Electric redirects back with the error flash *"“Electric” has listings filed under
        it, so it cannot be deleted."*;
      - creating a category with parent Electric, and moving Bass under Electric, each re-render with
        **422** and on the **parent** field *"“Electric” has listings filed under it, so it cannot
        have subcategories."*
- [ ] **AC-31 (no GET changes state, ADR-0015):** `GET /sell`, `GET /sell/drafts` and the edit `GET`
      leave every table's row count and content unchanged.

### Cross-cutting

- [ ] **AC-32:** every refusal in the failure contract marked *user error* is not logged at `error`
      and produces no Sentry event (`RecordingSentryTransport` stays empty).
- [ ] **AC-33 (tests bite):** each mutation fails a named test (recorded in the commit message):
      - (a) drop the `Branch` check from `Listing::draft()` → AC-4 / AC-10
      - (b) check L-6 after assigning in `revise()` → AC-5
      - (c) remove the C-23 guard → AC-13
      - (d) remove the C-24 guard from `CreateCategoryHandler` → AC-14; from `EditCategoryHandler` →
        AC-15
      - (e) make `holdSteady()` take the **exclusive** lock → AC-19 (shared probe)
      - (f) make `holdSteady()` take **no** lock → AC-19
      - (g) drop the seller filter from `DbalSellerDrafts` → AC-27 / AC-29
      - (h) parse price through `(float)` → AC-1 (`"0.29"`-style rounding case and the source scan)
- [ ] **AC-34:** `make check` green, `make test` passes **twice in a row**, Deptrac 0 violations with
      `--fail-on-uncovered`, and `make migration.make` is empty after the slice's migration.

---

## Failure contract

| Condition | Expected behaviour | Kind |
|---|---|---|
| Title blank / under 3 / over 120 chars / control characters or invalid UTF-8 | `InvalidListingTitle`. 422. **title**: *"Give the listing a title of 3 to 120 characters, on one line."* | user error |
| Description over 5000 chars / control characters other than line breaks and tabs, or invalid UTF-8 | `InvalidListingDescription`. 422. **description**: *"Keep the description under 5000 characters, in plain text."* | user error |
| Price malformed, zero, negative, too many decimals, over the maximum | `InvalidPrice`. 422. **price**: *"Enter a price like 1250 or 1250.50, up to 9 999 999.99."* | user error |
| Currency not allowed (tampered select) | `InvalidPrice` (unsupported currency). 422. **currency**: *"Choose one of the listed currencies."* | user error |
| Category unknown, malformed or deleted since the form loaded | `UnknownCategory`. 422. **category**: *"Choose a category from the list."* | user error |
| Category gained a subcategory since the form loaded | `CategoryIsNotALeaf`. 422. **category**: *"That category now has subcategories. Choose a more specific one."* | user error |
| Extra field posted (e.g. `seller`) / bad CSRF token | Symfony form error, 422, nothing written | user error |
| Edit of someone else's draft, or unknown / malformed id | `NotTheSeller` / `ListingNotFound` → **404**, identical body, nothing written | authz |
| Anonymous request to `/sell…` | Firewall entry point → 302 `/login` (target stashed) | authz |
| Admin deletes a listed-on category | `CategoryHasListings`. Error flash (AC-30). Nothing changes. | user error |
| Admin creates under / moves under a listed-on category | `CategoryHasListings`. 422 on **parent** (AC-30). Nothing changes. | user error |
| Listing write and Catalog write at the same moment | Serialised by the one lock: listing writes hold it shared, Catalog writes exclusive. The second to acquire decides on committed facts and refuses if needed. Listing writes never wait for each other. | concurrency |
| A listing found on a non-leaf (raw SQL; impossible through the app) | Shown normally; editing it without changing the category succeeds (AC-5); changing to another category is checked. | legacy data |
| Seller account erased later | Orphan rows (no FK, ADR-0009 d.4). The GDPR-erasure design owns it; nothing reads a seller's email here. | known gap |
| Deploy window: new code up before the migration has run | `/sell` and the Catalog admin's create/move/delete 500 until `doctrine:migrations:migrate` completes (seconds; cicd.md order). Logged at `error` → Sentry. Accepted. | deploy |
| Postgres down | The usual 500; each command is one transaction. | dependency |
