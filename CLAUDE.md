# CLAUDE.md

Guidance for Claude Code working in this repository. Read the [Constitution](./docs/constitution.md)
before planning any feature — it is the source of truth for stack, architecture, and quality gates.

## Project

**muzbar.com** — a hyper-granular musical-instrument marketplace + local music-services directory.
Structured faceted search over structured data, on a single VDS, run by one person. Full product
intent: [docs/muzbar-PRD.md](./docs/muzbar-PRD.md).

Secondary goal (ranked, not incidental): **learn DDD + Symfony deeply.** Prefer the implementation
that teaches DDD honestly.

**Stack:** PHP 8.4 · Symfony 7 · Twig + Symfony UX (Live Components) · AssetMapper (Node-free) ·
Tailwind (admin UI) + hand-authored SCSS (public UI) · Doctrine · PostgreSQL 16 · Redis 7 ·
Docker Compose · Traefik · Nginx.

> Status: **Phase 0 complete** (2026-07-23 → 07-24) · **Phase 1 `Identity` complete (7 of 7)** · **`Catalog` complete (3 of 3)**.
> `identity-user-password-auth` (2026-07-26) shipped the repo's first Domain code — the `User`
> aggregate, value objects, ports, two domain events, the first migration, and
> register/login/logout/`/account`. `identity-email-verification` (2026-07-28) added the **second
> aggregate**, `EmailVerificationRequest`, the first cross-aggregate reference by identity, async
> mail over Messenger, and the `VerifiedAccountUserChecker` that finally enforces `User::isUsable()`
> at login — without touching `User` at all.
> Slice 2 passed `/verify` on 2026-07-28 after three fix iterations; it also configured
> `framework.trusted_proxies`, which retroactively makes slice 1's `login_throttling` limiter work.
> `identity-password-reset` (2026-07-29) added the **third aggregate**, `PasswordResetRequest` —
> the same *shape* as slice 2's with **four rules deliberately inverted** (a replay is refused, a
> reissue invalidates outstanding links, the GET mutates nothing, and the two saves go in the
> opposite order). Established [ADR-0011](./docs/adr/0011-password-reset-challenges-modelled-in-the-domain.md).
> It is the first slice to touch `User` since slice 1, by exactly one property, one method, one
> reader and one import — and the first where a successful use case in one context (reset) also
> discharges a fact owned by another use case (email verification).
> `identity-challenge-pruning` (2026-08-01) shipped the repository's **first `DELETE`** — a
> `muzbar:identity:prune-challenges` console command designed for **host cron** (*the cron line is
> deferred and not yet installed* — see the roadmap's deferred-activations section), sweeping overdue
> rows from both challenge tables in bounded batches behind two per-aggregate port methods. It added **no new
> type of any kind**: the Domain diff is one constant and one static per aggregate and nothing else.
> It established [ADR-0012](./docs/adr/0012-challenge-retention-and-recurring-background-work.md) and
> discharged ADR-0009 decision 4's orphan clause (*answered*, not reversed — still no FK) and slice
> 3's `expires_at`-index note. It is also the first slice whose only failure symptom is silence, so
> it carries its own signal: a backlog count that cannot be faked by a job that runs and does
> nothing, a Redis heartbeat, and one log line per run including the all-zero ones.
> `identity-password-changed-notification` (2026-11-13) gave `UserPasswordChanged` its first
> listener, `NotifyUserOnPasswordChanged`, with **zero Domain and zero Application code**: a complete
> event payload made a new consequence Infrastructure-only, and it is the **first mail without a
> Domain port** — a port exists so the inside can reach the outside, and nothing inside calls this
> one. Its catch has **no compensating action** (unlike the verification listener's resend form), so
> its `error` log is the only signal; ADR-0010 gained an amendment recording that its outbox trigger
> was met and declined.
> `observability-sentry` (2026-11-13) closed the Sentry carry-over: **one capture path** — every
> Monolog record at `error`+ becomes a scrubbed Sentry event — with zero Domain/Application code.
> **Live in prod since 2026-11-13** (events verified from both `app` and `messenger-worker`). Runbook:
> [docs/infrastructure.md](./docs/infrastructure.md) §*Sentry (runbook)*; [ADR-0013](./docs/adr/0013-error-tracking-hosted-sentry-one-capture-path.md).
> `identity-google-oauth` (2026-11-14) put **a collection of value objects inside an aggregate** —
> `User` holds `list<OAuthIdentity>` (jsonb) — and an invariant that exists only because of it:
> **I-29, linking Google to an *unverified* account discards its password** (the pre-hijack defence;
> deliberately not `changePassword()`, so no event and no mail). Passwords became optional
> (`?HashedPassword`); a returning user is recognised by **`sub`, never the email**; I-5 was
> **not** widened, because the predicted clause could never decide. Google never crosses into the
> Domain: no port, the authenticator hands plain claims to `SignInWithOAuth`.
> [ADR-0014](./docs/adr/0014-google-sign-in-linked-identities-on-the-user-aggregate.md) + an ADR-0005 amendment.
> **Not yet live:** needs the Google Console client and `GOOGLE_CLIENT_ID`/`_SECRET` in the box `.env`
> (runbook: docs/infrastructure.md §*Google sign-in*); the deploy smoke check fails until then.
> `identity-login-overlay` (2026-11-14) closed `Identity` with **no Domain or Application code, because
> there was no domain**: "where was this visitor going?" is navigation state of one session. One
> carrier (Symfony's session target path), **one writer** (`GET /login?_target_path=`), **one
> validator** (`IntendedActionPath`), and every reader redirects through `HttpUtils`. The overlay is
> `/login`'s `[data-auth-panel]` lifted into a native `<dialog>` by plain JS in `assets/app.js` — not
> a Live Component (login must go through the firewall). `/profile` is a **placeholder** proving
> target. [ADR-0015](./docs/adr/0015-intended-action-redirect-and-login-overlay.md) + an ADR-0005 amendment.
> Passed `/verify` on 2026-11-14 in two iterations; the one MAJOR finding was **two unkeepable ACs**
> (AC-15, AC-19), amended in place with "the test won, on purpose". The JS overlay (AC-25) was checked
> in a real Chromium, 8/8. ADR-0015 now records the accepted risk: **no GET route may change state on
> behalf of the signed-in user** — any site can choose where a victim lands after signing in.
> `catalog-category-tree` (2026-11-14, **passed `/verify` 2026-11-15 first time**) — Catalog slice 1 of 3: one
> `Category` aggregate **per node** on an adjacency list, and the repo's **first cross-instance
> invariants** (no cycles, depth ≤ `MAX_DEPTH = 4`), decided by the aggregate on facts read under one
> tree-wide advisory lock. Admin CRUD under `/admin` (`ROLE_ADMIN`, granted only from the console).
> [ADR-0016](./docs/adr/0016-category-tree-per-node-aggregates-under-one-tree-lock.md) + ADR-0005/0009 amendments.
> `catalog-attribute-schema` (2026-11-15, **passed `/verify` 2026-11-15 in two iterations** — fixed an
> int4 cast on 64-bit bounds and `mb_strtolower` lengthening "İ" past `label_key`) — Catalog slice 2 of 3: the first
> aggregate with **parts**, and the parts left. `Attribute` (four types: choice/number/boolean/text) and
> `AttributeOption` are **separate aggregates** referencing by id, because a Brand list is unbounded
> and options will be referenced from outside (listings, dependent Model options). Options are minted
> by `Attribute::newOption()`; their set-rules (unique label C-17, never the last C-18) are
> cross-instance invariants under the **same tree lock**. Definitions live once, on the defining
> category. [ADR-0017](./docs/adr/0017-attribute-schema-attributes-and-options-as-separate-aggregates.md)
> + ADR-0004/0016 amendments.
> `catalog-schema-inheritance` (2026-11-16, **passed `/verify` 2026-11-16 in two iterations** — the
> MAJOR was AC-7's context-isolation scan, claimed in a commit message but committed as an empty file) — Catalog slice 3 of 3, and the
> first invariant spanning **two aggregate types and the tree at once**. A category's effective
> schema is its own attributes plus every ancestor's, **derived over the lineage, never stored**
> (C-22). **No overrides:** a code appears at most once along any branch (C-21) — an override would
> be a second `Attribute` with its own id, and every Brand facet on an ancestor would split in two.
> C-21 has **three doors** (define and recode on `Attribute`, move on `Category` — C-5's shape) and
> no index; C-11 became its same-category case and `isCodeTaken()` was deleted. No migration, but a
> **one-off pre-deploy query** (docs/infrastructure.md §*Catalog*). The bite proof's mutation (d)
> was caught **only** by AC-14's descendant case.
> [ADR-0018](./docs/adr/0018-schema-inheritance-derived-along-the-lineage-codes-unique-per-branch.md)
> + ADR-0016/0017 amendments.
> `listing-draft` (2026-11-17, **passed `/verify` 2026-11-17 in two iterations** — the MAJOR was title/description
> accepting NUL and invalid UTF-8) — Listing slice 1 of 4, and the first slice where
> **two bounded contexts depend on each other**. One `Listing` aggregate (seller, leaf category, title,
> description, `Price`; status `Draft`) in `listing_listing`; `/sell`, "my drafts" and edit behind the
> "Sell Gear" overlay trigger. **Leaf-only has two owners and three doors:** Listing decides L-2 on
> draft/re-file; Catalog refuses delete (C-23) and gaining a child by create or move (C-24) of a
> listed-on category. One tree lock, **two modes** — sellers shared, admins exclusive. Money is
> **integer minor units** parsed in the Domain (`(int)(1.15 * 100)` is 114). Someone else's draft is
> the same 404 as a missing one. All eight AC-33 mutations bit; 1436 tests.
> [ADR-0019](./docs/adr/0019-listing-context-cross-context-ports-leaf-only-under-one-lock-in-two-modes.md)
> + ADR-0007/0016 amendments.
> `listing-attribute-values` (2026-11-18, **passed `/verify` 2026-11-20 first time** — 7 MINORs fixed
> anyway, chiefly an explicit row lock under two concurrent saves of one draft) — Listing slice 2 of 4: a seller's
> data now refers to Catalog's **schema**, not only its tree. Values are **parts** of `Listing`
> (ADR-0017's test run again, opposite answer: nothing points at a value, a listing has dozens at
> most), held as an immutable `AttributeValues` — **not** a Doctrine collection. The repository is
> their only writer (delete-and-reinsert into the unmapped `listing_attribute_value`, one typed
> column per kind, a `num_nonnulls` CHECK); a `postLoad` listener hydrates by reflection.
> **Parsing that needs a fact:** `revise()` reads raw strings against `Categories::schemaOf()`
> (L-6 → L-2 → L-10 drop-on-re-file → L-9 → assign; refuse, not drop, on an unchanged category).
> Catalog refuses the three writes that would strand a value — C-25/C-26/C-27 — **by refusal now so
> soft delete can come later** (`listing-schema-drift`). Blank = clear. All fourteen AC-28 mutations
> bit; 1637 tests. [ADR-0020](./docs/adr/0020-attribute-values-parts-of-listing-validated-against-catalog-schema-guarded-by-refusal.md)
> + ADR-0004/0007/0016/0018/0019 amendments.
> `listing-publish` (2026-11-21, **passed `/verify` 2026-11-21 first time** — the one fix was
> `StatusBlindGuardsTest` covering withdrawn listings for C-25/C-26/C-27, not only C-23/C-24) — Listing slice 3 of 4: the first page
> anyone but its author can see, and the first **state machine inside an aggregate whose invariant's
> force depends on the state**. `Draft → Published ⇄ Withdrawn` (L-12; nothing returns to `Draft`).
> `required` bites (L-13) at `publish()` and at every `revise()` **while published**, against the
> schema current **at that write** — so **never retroactive** needs no mechanism: a rule checked only
> on writes cannot fire on a row nobody touches. Publish re-checks leaf and stored values
> unconditionally (L-14); a published listing cannot be re-filed (L-15); `published_at` (L-16).
> "Save and publish" is `ReviseListing::$publish` (no default) — one transaction. The first Listing
> event, `ListingPublished` (ids + time, no listener), is released **inside** `holdSteady()` and
> **dispatched after it returns**: "after save" there is not "after commit". The public page
> `/listings/{id}` filters `status = 'published'` in SQL and **never selects `seller_id`** — privacy
> as a column never read; one 404 for draft/withdrawn/unknown/malformed, for everyone. All fifteen
> AC-28 mutations bit; 1746 tests.
> [ADR-0021](./docs/adr/0021-listing-lifecycle-in-the-aggregate-completeness-on-public-writes-dispatch-after-commit.md)
> + ADR-0008/0018/0019/0020 amendments.
> `uuid-id-trait` (2026-11-21) — a refactor: the eleven copies of the UUID id shape became **one trait**,
> `Shared\ValueObject\UuidIdShape`, **shared code without a shared type** — `self` in a trait is
> the using class, so a cross-type `equals()` is still a `TypeError` (`UuidIdShapeTest`'s ring).
> Each id keeps its own exception via a `malformed()` hook. One deliberate behaviour change: the two
> challenge ids had drifted (no `D`) and now refuse `"<uuid>\n"`. The nine DBAL id types are deferred
> (trigger: a fix made nine times). ADR-0009 amendment.
> `listing-schema-drift` (2026-11-21, **passed `/verify` 2026-11-22 first time** — 3 MINORs fixed anyway, chiefly `keep` built from choice values only; six hand-checks in Chromium green) — Listing slice 4 of 4, and the
> first slice that **weakens an invariant on purpose**: "every stored value is in force" is no longer
> kept by refusal. `Attribute`/`AttributeOption` gain **archive/restore** (C-28; nullable
> `archived_at`, a transition — hard delete stays non-existence, for unused rows only). An archived
> name **stays reserved** (C-29), so restore takes no facts and is never refused. A value whose
> attribute is not in the active schema is **dormant** (L-17): kept by a revise, dropped by a re-file,
> read by no rule, shown nowhere, never stored as a flag. A value naming an archived option is
> **stale**, not dormant: shown, refused at the next write and at publish (L-14 narrowed to in-force
> values). C-18 counts **active** options. **C-27 is retired** — a move with values is allowed; what
> leaves the lineage goes dormant and wakes if the move is undone. All fifteen AC-23 mutations bit;
> 1883 tests. [ADR-0022](./docs/adr/0022-schema-drift-archive-reserved-names-dormant-values.md)
> + ADR-0004/0016/0017/0018/0020/0021 amendments.
> `phase1-gate` (2026-11-22, **gate MET 2026-11-22** — restore rehearsed on the box, logged in
> docs/infrastructure.md §*Rehearsal log*) — a **proof slice with zero production code**. `SchemaMutationGateTest` drives ADR-0004's bet through the real admin and seller pages: a
> runtime-defined attribute reaches a pre-existing draft's form and a facet count, with no DDL and
> every pre-existing row byte-identical. The gate query (`FACET_COUNT`, test-owned — Phase 2 lifts
> it **with its fixture**) speaks "active" in four clauses, each with a fixture row only it excludes.
> The `EXPLAIN` proves **usability, not choice**, and needed an `Index Cond` check: with seq scans off,
> a full index scan "uses the index" while looking nothing up (mutation (f) survived until then).
> API-key issuance moved to Phase 3. ADR-0004 amendment. 1892 tests. Next: `/verify phase1-gate`;
> `public-styles` before Phase 2's public search UI; seller contact channels before launch.
> One Phase 0 item still carries over — the Claude Code hooks from
> [docs/tooling.md](./docs/tooling.md). Not covered by Sentry, by design: crash loops and pre-boot
> failures (owned by the deploy image check and the Phase 2 queue-age readiness item).

## Architecture — non-negotiable

DDD + Hexagonal (Ports & Adapters). App code lives under `src/src/` (Symfony root is `src/`) in three
layers with strictly ordered dependencies:

| Layer | May import | Must NOT import |
|---|---|---|
| `Domain` | nothing external | Symfony, Doctrine, any framework/vendor |
| `Application` | `Domain` | `Infrastructure`, Doctrine, controllers |
| `Infrastructure` | `Domain` + `Application` + Symfony/Doctrine | — |

Enforced by **Deptrac** in CI — via a catch-all `Vendor` layer (anything not under `App\`, minus real
PHP/SPL built-ins) granted only to `Infrastructure`, so *every* vendor is caught rather than a list of
named ones, plus the `use` emitter so a bare unused import counts. Repository/`*Port` interfaces live in `Domain/<Context>/Port/`;
adapters live in `Infrastructure/`. Bounded contexts: `Catalog`, `Listing`, `Directory`, `Identity`,
`Billing`, `Notification`, `Search` — see Constitution §4.

Canonical order to add a feature: **Domain** (entity/aggregate, value objects, domain events, port) →
**Application** (command/query + handler) → **Infrastructure** (Doctrine adapter, controller/Live
Component, DI wiring).

**Persistence conventions** ([ADR-0007](./docs/adr/0007-persistence-conventions-for-domain-aggregates.md)) —
established by the first slice, inherited by every context:

- Doctrine mapping is **XML, one file per aggregate**, at
  `src/Infrastructure/<Context>/Persistence/Doctrine/mapping/<Aggregate>.orm.xml`, with one
  `doctrine.orm.mappings` block per context. **Never ORM attributes on a Domain class** — that is a
  `use Doctrine\...` in `Domain/` and Deptrac fails the build. There is no `src/Entity/`.
- Value objects map through **custom DBAL types** (`Infrastructure/<Context>/Persistence/Doctrine/Type/`,
  registered under `doctrine.dbal.types` as `<context>_<concept>`), not embeddables.
- **Tables are `<context>_<aggregate>`, singular** (`identity_user`), with every table and column name
  spelled out explicitly in the XML rather than derived by the naming strategy.
- **Column types are chosen, not inherited from driver defaults.** JSON columns are **`jsonb`** — extend
  `Doctrine\DBAL\Types\JsonbType`, never `JsonType` (whose PostgreSQL default is textual `json`) and
  never the deprecated `jsonb` column option. Timestamps are **whole-second**: `datetimetz_immutable`
  discards microseconds at the *type*, so `Clock` mandates whole seconds and `SystemClock` truncates at
  the source. Any hand-written `Clock` test double must honour that too.
- Identity is **application-assigned UUIDv7** minted by `Repository::nextIdentity()`, mapped with
  `<generator strategy="NONE"/>`. Adapters implement the port and do **not** extend
  `ServiceEntityRepository`.

**References between aggregates** ([ADR-0009](./docs/adr/0009-email-verification-tokens-modelled-in-the-domain.md)) —
established by the second slice, inherited by every context:

- An aggregate holds another's **id value object, never the object** (`UserId`, not `User`). The
  mapping is a plain typed column, **never a `<many-to-one>`**: an association hands anyone holding
  one root the power to mutate another, and lets Doctrine's cascades decide transactional semantics.
- **No database foreign key between aggregates.** Referential integrity across roots is the
  application's job, and an FK the mapping does not know about is diffed as unwanted on every
  `make migration.make` — deleted and re-added forever. The cost is possible orphan rows; the
  pruning job and the GDPR-erasure design own that.
- Two aggregates changing in one use case means **two saves in a deliberate order**. Pick the order
  whose crash window is benign, and write down why at the call site. **This rule has now produced two
  opposite answers, which is the evidence that it is a rule and not a habit**: verification saves
  user-first (a surviving token is inert once the account is verified), reset saves *request-first*
  (a surviving token is another chance to set a password —
  [ADR-0011](./docs/adr/0011-password-reset-challenges-modelled-in-the-domain.md) decision 5). Both
  call sites name the other one, so a reader who spots the contradiction finds the reason instead of
  "aligning" them. A third answer, for **many** aggregates in one transaction (ADR-0017 decision 8):
  **the attribute row is the commit point** — written **last** on define (options first), removed
  **first** on delete (then the options swept). The only leftover a split transaction could leave is
  an orphan option, which is inert; the reverse could leave a choice with zero options (C-18).

**Deleting rows** ([ADR-0012](./docs/adr/0012-challenge-retention-and-recurring-background-work.md)) —
established by the fourth slice, which shipped the repository's **first `DELETE`**:

- **An aggregate governs its state transitions, not its own non-existence.** That is what licences a
  set-based `DELETE` behind a port method here while ADR-0011 decision 4 refused a bulk `UPDATE` next
  door: `invalidate()` is a transition with an invariant to protect, so a bulk update bypasses a
  guard; deletion has no post-condition, no invariant a non-existent object can violate and no method
  it could refuse, so it bypasses nothing. The general rule: **put in the Domain the part that can be
  wrong.** Here that is the *selection* — a constant and a pure static on each aggregate — and it is
  exactly what stayed there. A bulk operation is illegitimate when it bypasses a rule and legitimate
  when there is no rule to bypass.
- **The prune predicate is `expires_at` and nothing else** — never `redeemed_at`, `invalidated_at`,
  `email_verified_at` or `password_changed_at`, in any layer. The two challenge tables *disagree
  about what "dead" means*, deliberately, so a "dead" predicate would be the rejected `Challenge`
  base class re-derived in SQL where no unit test can reach it. `expires_at` is the one column both
  define identically and a ceiling on every other reason a row is finished.
- **The two retention windows are inverted relative to the lifetimes and that is the design**:
  verification keeps rows **7 days** after a **24-hour** link, reset keeps them **30 days** after a
  **one-hour** link. Retention measures the question a row answers *after* it stops working. Each
  constant's docblock names its twin and gives the reason, and a unit test fails loudly if anyone
  "aligns" them.
- **A dedicated Monolog channel is not enough on its own.** `prod` and `test` route through a
  `fingers_crossed` handler that buffers anything below ERROR and discards it, so an INFO line on a
  new channel is silently dropped unless the channel gets its **own** handler *and* is excluded from
  `main`. The `pruning` channel does both. Verified empirically: without them a green run wrote zero
  bytes to stderr.
- **Two more Redis keys survive DAMA** — `identity:challenge_pruning:lock` and `:last_run`. Use the
  `ClearsPruningState` trait; `ClearsRateLimiters` cannot help, because these are raw Predis keys
  rather than entries in the `cache.rate_limiter` pool. A leftover **lock** is the dangerous one: the
  command then does nothing, logs `skipped: lock_held` and **exits 0**, so a test asserting a
  successful run passes against a run that never happened.
- An **elapsed interval is not an instant**: the `Clock` port is whole-second by contract, so a
  duration is measured with `hrtime(true)`. `Clock` remains the only source of "now" for anything the
  Domain sees.

**Invariants that span many instances** ([ADR-0016](./docs/adr/0016-category-tree-per-node-aggregates-under-one-tree-lock.md)) —
established by `catalog-category-tree`, inherited by every context:

- **The aggregate decides, on facts passed in; the lock makes the facts true; a unique index is the
  backstop where one exists.** `Category::moveUnder(Lineage, int $subtreeHeight)` owns C-4/C-5 as pure
  logic; the handler reads the facts through the port; `CategoryTreeLock::exclusively()` wraps the
  reads *and* the save so no concurrent write can make them stale (write skew). **Every** Catalog
  write — **attributes and options included** — runs under `CategoryTreeLock`; a new handler needs it
  too, and the recording-double test (`CategoryTreeLockTest`) extended to prove it. C-18 ("never the
  last option") has **no index at all**: the lock is the whole guarantee.
- **A part with its own references or an unbounded count is its own aggregate** (ADR-0017). Options
  were a `jsonb` list inside `Attribute` in the first draft (the `User`/`OAuthIdentity` shape); they
  left because a Brand list has thousands of entries and listings will point at options by id. Its
  set-rules then become cross-instance invariants: **aggregate decides on facts, lock, index**. The
  parent stays the factory (`Attribute::newOption()`); `AttributeOption::create()` is public only
  because PHP has no package visibility — it is `@internal`, and a reviewer must catch a direct call.
- **Delete guards accrete in `DeleteCategoryHandler`**, under the lock, in order: C-7 (children),
  then C-13 (defines attributes → `CategoryHasAttributes`). Every future thing that hangs off a
  category (listings, saved searches) adds its guard there, never a cascade.
- **Persist `CategoryId`, never a slug or path.** Slugs and paths change on rename and move; the id
  never does. Depth, path and lineage are derived, never stored.
- **C-21 has three doors — define, recode, move — and no index** (ADR-0018). A code is unique along
  every branch (ancestors + descendants; siblings may share). **Any new write that changes an
  attribute code or a category's parent** (an import, a "merge categories" tool) must read the branch
  codes (`lineageOf()->ids()` + `descendantsOf()` → `codesDefinedOn()`) **under the lock** and hand
  them to the aggregate, and get a `CategoryTreeLockTest` case. Nothing else will catch a fourth door.
  The move is also the **second accretion point** (after `DeleteCategoryHandler`): Listing's move
  guard (C-24) sits in `EditCategoryHandler`, beside C-21. **Restore is not a fourth door only because
  archived names stay reserved** (C-29) — see the archive footguns.
- **The effective schema is derived, never stored** (C-22). Its only computation today is
  `DbalAttributeSchema::effectiveFor()` (one bounded recursive query). No schema cache, no
  `category_attribute` table, no `jsonb` copy: a define on a parent and a move each stay one row.
- **`AttributeFactory` bypasses C-21** — it hands every constructor `AttributeCodes::none()`. A test
  that needs a lawful tree must build it in an order that respects C-21, or the factory hides the
  refusal it is testing. Its **default category does not exist** (no FK): any recode now reads that
  category's lineage and throws `CategoryNotFound`, and the edit page's "Changes apply to …" sentence
  silently disappears (it looks the name up tolerantly). Build with `->inCategory($category)` whenever
  the category matters.

**Cross-context dependencies** ([ADR-0019](./docs/adr/0019-listing-context-cross-context-ports-leaf-only-under-one-lock-in-two-modes.md)) —
established by `listing-draft`, inherited by every context:

- **A context states a need of another as its own port, in its own words**: Listing's
  `Port/Categories` (`statusOf()`, `holdSteady()`), Catalog's `Port/CategoryUsage` (`hasListings()`).
  Foreign ids are local copies (`SellerId`, Listing's `CategoryId`) — never import another context's
  VO. Neither Domain nor Application crosses; `ListingIsolationTest`/`CatalogIsolationTest` prove it.
- **The adapter lives with the data's owner**: `CategoriesForListing` in
  `Infrastructure/Catalog/Integration/` (reads `catalog_category`), `ListingCategoryUsage` in
  `Infrastructure/Listing/Integration/` (reads `listing_listing`). A table name appears only in its
  own context's Infrastructure, so a schema change stays in one directory.
- **The tree lock has a shared mode**: `PostgresCategoryTreeLock::sharedly()`, reached by Listing as
  `Categories::holdSteady()`. Shared conflicts with exclusive only, so sellers never queue behind
  each other. **Watch item:** every new write that files a listing (an import, a bulk re-file, the
  wizard) goes through `holdSteady()`; every new Catalog write that can turn a leaf into a branch
  reads `CategoryUsage::hasListings()` under `exclusively()`, and gets a `CategoryTreeLockTest` case.
- **`Categories::schemaOf()` is the effective schema in Listing's words** (ids, kinds, option ids,
  bounds — no labels): a **fact, true only inside `holdSteady()`**, never stored. The edit *form* is
  built from Catalog's admin read model (`effectiveFor()` + `optionsFor()`) instead — a UI crossing
  like `DbalCategoryOutline`; the POST re-reads the fact under the lock and a drift only refuses.
- **`AttributeUsage` is Catalog's second port into Listing** (`isInUse()`, `isOptionInUse()`, adapter
  `ListingAttributeUsage`). It backs C-25 (attribute delete) and C-26 (option remove, after C-18) —
  which now guard **hard** delete only (Archive is the in-use path, ADR-0022) and count **every**
  stored value, dormant included: the rule is "every value names a row that exists". **C-27 is
  retired** with `CategoryUsage::hasAttributeValues()` (deleted): a move with values is allowed, and
  the move order is C-4 → C-5 → C-21 → C-24. **Watch items:** values are written only inside
  `holdSteady()`, after `schemaOf()`; any new Catalog write that hard-deletes an attribute or option
  reads the matching usage under `exclusively()` and gets a `CategoryTreeLockTest` case.
- **`ListingFactory` bypasses L-2** — it hands `draft()` `CategoryStatus::Leaf`, and its default
  category does not exist. Build with `->inCategory($leaf)`, in an order that keeps it on a leaf.
  **`withValues()` also skips L-9's "in force" half** — it builds the schema from the attributes it
  is given, not the category's lineage, so a fixture can hold a value no seller could enter.
  **`ListingFactory::published()` / `->withdrawn()` go through the aggregate** (`publish()` against
  a schema built from the category's real lineage, `required` included, then `withdraw()`), save
  through the repository **and release the events** — nobody dispatches them, so a leftover would
  surface later as a phantom. L-13 applies: give `withValues()` every required attribute, or the
  fixture throws. L-2 is still bypassed (`publish()` is handed `Leaf`).
- **Catalog's guards are status-blind, on purpose** (ADR-0021 d.9): C-23…C-26 count listings of
  **every** status. A withdrawn listing can be published again and a draft can be published, so both
  must stay on a leaf with values that mean something. **Never add a `status` filter to
  `ListingCategoryUsage`/`ListingAttributeUsage`** — mutation (m) and `StatusBlindGuardsTest` bite.
- **The public listing page is the fifth crossing** — a UI read of Catalog's read model
  (`DbalCategoryOutline`, `effectiveFor()`, and `DbalAttributeSchema::optionLabels()`, which loads
  only the stored option ids — never `optionsFor()`, a 5 000-entry Brand list to label one value).
  3 queries without values, ≤ 5 with (AC-24).
- **`ListingIsolationTest` admits `App\Domain\Shared`** (`RecordsEvents`, `DomainEvent`, `Clock`,
  `DomainEventDispatcher`) — the shared kernel is not a bounded context. Widened by the first Listing
  event; listing-publish's AC-7 ("unchanged") was wrong in that one respect. Admit nothing else.
- **Money is integer minor units, never a float** — `Price::fromDecimal(string, Currency)` parses
  with string and integer arithmetic; `PriceTest` scans the source for `float`. Never `MoneyType`.

**Structurally identical aggregates still do not share a base class.** `PasswordResetRequest` is ~80%
the same file as `EmailVerificationRequest` and shares no supertype with it. They share a *shape*,
not *behaviour*, and they differ on **four** rules — replay refused vs absorbed, reissue invalidating
vs not, the GET mutating vs burning, and the save order. Any guess a base class made on those would
be right for one subclass and a latent security bug in the other. **Every one of the four carries a
comment at its call site saying *why* it is inverted**, and that rule generalises: when a new file
deliberately contradicts an existing one, the contradiction is the thing that needs the comment.

**Shared code is a trait; a shared type is a decision** (ADR-0009, 2026-11-21 amendment). A new id
value object is `final readonly class XId { use UuidIdShape; private static function
malformed(string $value): InvalidXId { return InvalidXId::malformed($value); } }`, with its own
exception in its own context — **never a copy, never a base class** (a base's `self` is the base, so
`XId::equals(UserId)` would quietly return `false`). Add it to `UuidIdShapeTest`'s ring.

**Domain events** ([ADR-0008](./docs/adr/0008-domain-events-recorded-on-the-aggregate.md)): the
aggregate `recordThat()`s via the `RecordsEvents` trait and never dispatches; the Application handler
calls `$this->events->dispatch(...$aggregate->releaseEvents())` **after** a successful `save()`.
`releaseEvents()` empties the buffer. Events carry value objects, never the aggregate — and **never a
secret**: a token inside an event is a token in every listener, every log line and every queue row.

## Commands

All application commands run inside the Docker `app` container. (Makefile targets wrap these — run
`make help`.)

```bash
# Start the full stack (dev: Xdebug, live mount, Mailpit, 127.0.0.1-bound ports)
make up.dev            # docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d

# Production mode (no override file — never auto-loaded)
make up.prod

# Symfony console
make console cmd="..."         # docker compose exec app php bin/console ...

# Doctrine
make migrate                   # doctrine:migrations:migrate
make migration.make            # make:migration
make db.dump                   # pg_dump -Fc to backups/

# Quality gates (also run in CI)
make cs                        # php-cs-fixer
make stan                      # phpstan max
make deptrac                   # layer boundary check (--fail-on-uncovered: an unclassified
                               #   dependency fails the build instead of being silently allowed)
make test.db                   # create + migrate muzbar_test (run automatically by `make test`)
make test                      # phpunit  (opts: filter=, file=)
make check                     # cs + stan + deptrac + test — run before every commit

# Identity operations
make console cmd="muzbar:identity:verify-email <email>"   # break-glass: mark an email verified,
                                                          #   bypassing the token flow entirely
make console cmd="muzbar:identity:grant-role <email> ROLE_ADMIN"           # the only way to make an admin
make console cmd="muzbar:identity:grant-role <email> ROLE_ADMIN --revoke"  # bites on their next request
# Challenge pruning — cron line (`17 * * * *`) is documented but NOT YET INSTALLED: deferred to the
#   end of the roadmap (2026-08-02). Run by hand until then. See docs/infrastructure.md's runbook.
make console cmd="muzbar:identity:prune-challenges --dry-run"    # report only; deletes nothing
make console cmd="muzbar:identity:prune-challenges --limit=100"  # a small, explicit bite
make console cmd="muzbar:identity:prune-challenges"              # a full run; exit 0 even if truncated
curl -s localhost:8080/health/ready | jq .jobs.challenge_pruning  # the backlog — the signal to trust
# Pre-launch "notify me" list (coming-soon stub at `/`, temporary — unmapped raw-DBAL table)
make console cmd="dbal:run-sql 'SELECT email, locale, created_at FROM prelaunch_signup ORDER BY created_at'"
# Mail & queue (dev)
open http://localhost:8025                                # Mailpit — every dev mail lands here
make console cmd="messenger:failed:show"                  # the failure transport nobody looks at
make console cmd="messenger:failed:retry"
# Google sign-in — GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET (box .env; dev: src/.env.local). Empty = off:
#   /connect/google logs one error and bounces to /login. Runbook: docs/infrastructure.md §Google sign-in
curl -sI localhost:8080/connect/google | grep -i ^location   # expect code_challenge_method=S256, scope=openid email
# Error tracking — run in BOTH containers after setting SENTRY_DSN; exits 1 when the DSN is empty
make console cmd="muzbar:ops:sentry-check"                # then the same via `docker compose exec messenger-worker`
```

**Async mail** ([ADR-0010](./docs/adr/0010-event-delivery-and-transactional-mail.md)): `SendEmailMessage`
is routed to a Doctrine-backed `async` transport drained by the **`messenger-worker`** Compose
service. Nothing is delivered while that container is down — and **a stopped worker looks exactly
like a healthy system**, because `/health/ready` probes Postgres and Redis, not queue depth. Under
`APP_ENV=test` the same message is routed to `sync` so `MailerAssertionsTrait` works without a
worker.

## Conventions

- **PHP:** constructor property promotion; explicit return types; typed params everywhere; enums with
  TitleCase cases (one deliberate exception: `Currency`'s cases are ISO 4217 codes, `EUR` not `Eur`); curly braces on all control structures; no dead code; no secrets in source.
- **Domain purity:** zero `use Symfony\...` / `use Doctrine\...` in `Domain/`. Doctrine mapping is an
  infrastructure concern. Model the business, not the table.
- **Config:** never read env vars outside `config/` / DI parameters; inject configured values.
- **Validation:** untrusted input is validated at the Infrastructure boundary before reaching the
  Domain. The search-query sanitizer is security-critical.
- **Tests:** Domain gets pure unit tests (no kernel boot); Application/Infrastructure get integration
  tests against a real database (DAMA transactional rollback). Tests run with `APP_ENV=test` against a
  **dedicated test database** (`muzbar_test`) — **never** the dev DB. Descriptive test names. No
  assertions that cannot fail. **Never put work inside `assert()`** — CI's PHP has
  `zend.assertions=-1`, which skips the whole expression, so `\assert(1 === preg_match(…, $m))` never
  fills `$m` there while the dev container (assertions on) stays green. It reached a PR once
  (`SellTest::detailFieldIds()`). Use `if (…) { self::fail(…); }`, which also narrows for PHPStan
  (`assertSame` does not). To reproduce CI locally: `php -d zend.assertions=-1 vendor/bin/phpunit`.
  Build aggregates via a Foundry factory that goes through the aggregate's
  own named constructor (`instantiateWith()`), never around it.
  **DAMA rolls back Postgres, not Redis** — anything cached survives between tests and must be
  cleared in `setUp()` via the `ClearsRateLimiters` trait. The `cache.rate_limiter` pool now backs
  **four** limiters (`login_throttling`, `verification_email_resend`, `password_reset_request` and
  `password_reset_submit`) — plus `launch_signup` on the coming-soon stub — so a test driving `/login`, `/verify-email/resend`, `/forgot-password`
  or either reset route needs it. The cheap proof that you got it right is to **run `make test`
  twice in a row** — a second run that fails is the classic symptom.
  **Redis state is no longer only rate limiters.** `identity-challenge-pruning` added two keys —
  `identity:challenge_pruning:lock` and `identity:challenge_pruning:last_run` — written straight
  through `Predis\ClientInterface` with no pool and no namespace, so `ClearsRateLimiters`'
  `$pool->clear()` **cannot see them**. Use the separate `ClearsPruningState` trait in any test that
  runs the prune command or reads `/health/ready`'s `jobs` section. A leftover heartbeat makes a
  staleness assertion depend on file order; a leftover **lock** is worse, because the command then
  does nothing, logs `skipped: lock_held` and **exits 0** — a test asserting a successful run passes
  against a run that never happened.
  **The session is load-bearing in a functional test now**, because the reset flow stashes its token
  there. Test sessions use `session.storage.factory.mock_file`, which persists across requests within
  **one** `KernelBrowser` — so a test that creates a second client, or reboots the kernel, silently
  loses the flow. Keep a reset scenario inside one client.
  **A repository fetched once in `setUp()` serves entities from Doctrine's identity map**, so a read
  taken after an HTTP-driven mutation can hand back the pre-request object rather than the committed
  row. Fetch a fresh repository from the container immediately before any assertion that must reflect
  a request that just finished.
  **`CategoryTreeLock` under DAMA: the xact advisory lock is held until the *test* ends**, because
  DAMA's outer transaction never commits. Probe it from a second, non-DAMA connection **inside** the
  work closure — a probe after `exclusively()` returns reads "held" even if the adapter took no lock.
  **`NoDdlTest` is ADR-0004's invariant as a test** — it drives every attribute handler and compares
  `public`'s columns and indexes before and after (PostgreSQL DDL is transactional, so a stray
  `ALTER TABLE` is visible inside DAMA). A new schema-writing handler belongs in it.
  **Changing a signed-in user's roles logs them out on their next request** (Symfony compares the
  refreshed user's roles) — grant `ROLE_ADMIN` *before* logging the test client in.
- **Swapping an adapter inside a test needs two things, and each fails silently alone.** Target the
  **concrete class's** service id, never the port alias — Symfony's `ResolveReferencesToAliasesPass`
  rewrites alias references to their target at *compile* time, so a handler's constructor is already
  wired to the adapter's id before the test runs. And call `$client->disableReboot()` first, because
  `KernelBrowser` reboots the kernel before every request and discards container overrides.
- **A test encodes what the code *should* do — never what it was observed doing.** A test written by
  running the code and recording the answer has no source of truth independent of the code, so it can
  never disagree with it. Slice 2 shipped one: it asserted a 404 that directly contradicted an
  approved AC, with a confident message documenting the bug as the design, and stayed green for two
  commits. **When an AC and the implementation disagree, fix one of them on purpose and say which
  won** — do not write the test that ratifies the accident. Where two responses must be
  indistinguishable, assert them against *each other* live, not against two copies of a literal that
  can drift apart while both stay green.
- **A comment or docblock claiming coverage the assertion cannot deliver is the same defect one level
  up.** If a test's docblock names a regression class, verify it actually fails on that regression —
  twice this slice a docblock named a failure mode that was structurally invisible to its assertion.
  The same goes for a commit message or a ticked task: `catalog-schema-inheritance` claimed a
  context-isolation scan that was a 0-byte file. **Deptrac enforces layers, not contexts** —
  cross-context isolation is `CatalogIsolationTest` (copy it for a new context), which also asserts it
  scanned more than zero files so a wrong path cannot pass vacuously.

## Infrastructure footguns (baked-in guards)

These are documented failure modes we design against (see [docs/infrastructure.md](./docs/infrastructure.md)):

- Traefik must be pinned to its network: `--providers.docker.network=traefik`.
- Never expose Redis/Postgres with a public `ports:` mapping — Docker bypasses UFW. Bind admin/data
  ports to `127.0.0.1` only. A pre-commit hook flags violations.
- Use **named** volumes only; anonymous Postgres volumes remember stale passwords.
- Give Redis a password and a stable network alias.
- Health checks probe Postgres + Redis — never `return 'ok'`.
- **`env_file:` outranks the image's `ENV`, so the root `.env` decides `APP_ENV` on a real box** — the
  Dockerfile's `ENV APP_ENV=prod` cannot protect you. `.env.example` therefore defaults to
  `APP_ENV=prod` (the safe value), and `docker-compose.dev.yml` pins `APP_ENV: dev` in `environment:`
  (which outranks `env_file:`) so dev-ness follows the override file you load rather than a value
  someone remembered to change. Verified empirically, not assumed.
  **It already bit:** until 2026-11-13 the box `.env` said `APP_ENV=production`. Symfony treats that
  as an unknown env, so it turned **debug on** (public `x-debug-exception` headers and debug error
  pages) and applied **no `when@prod` block** — no `trusted_proxies`, so rate limiters were one
  global bucket and `isSecure()` was false. Every test, gate and health check stayed green; it
  surfaced only as Sentry tagging events `production`. `deploy.yml`'s smoke check now fails if a
  404 carries `x-debug-exception`. The value is exactly `prod` — never `production`.
- The container writes into the live-mounted `./src` **as root**, so directories it creates (e.g.
  `src/translations/`) become un-manageable by host git and can block a `git pull`. Chown to `1000:1000`
  when it happens; a `user:` mapping in the dev override would fix it at the source.
- **Two dev containers sharing one live-mounted `var/cache/` corrupts it.** `app` and
  `messenger-worker` both bind `./src`, and Symfony warms the cache into a hash-named directory
  before swapping it in — so two processes warming concurrently (which is what happens the first
  time each serves a request after a code change) can leave one holding a container hash whose
  service files the other already replaced. It presents as
  `require(var/cache/dev/ContainerXXXX/getSomeService.php): Failed to open stream` on **every**
  route including the error controller, from an application that is correct and a suite that is
  green. `docker-compose.dev.yml` gives the worker an anonymous volume over `var/cache`. Prod is
  unaffected — no bind mount, so each container already has its own `var/`.
- **PHP scans `conf.d` alphabetically and last-write-wins, so an override file must sort *after* what
  it overrides — and `-` (0x2D) sorts before `.` (0x2E).** `zz-app-dev.ini` therefore loaded *before*
  `zz-app.ini` and every key both files set was silently reverted to the production value. The
  casualty was `opcache.validate_timestamps`: dev ran with it **Off** against a live-mounted `./src`,
  so edited code kept serving the previous version until the container was restarted. **Manual
  verification then exercises code that is not on disk — in both directions**: during slice 3's
  `/verify` it produced one false "the fix isn't working" (the code was already correct) and one
  false pass. `make test` is immune (`opcache.enable_cli = 0`, fresh process per run), which is
  exactly what made it dangerous — the automated gate stayed honest while hand-checks lied. Fixed
  2026-07-30 by installing the dev overrides as `zzz-app-dev.ini`. **When a hand-check contradicts a
  green suite, suspect the runtime before the code.**
- **`DEFAULT_URI` decides every absolute URL built outside an HTTP request** — the Messenger worker
  and every console command. Left at the skeleton's `http://localhost`, every link in every outgoing
  mail points somewhere unreachable, and **no functional test catches it**, because a test runs
  inside a simulated request where the fallback never fires. Set it per environment.
- **`getClientIp()` is the proxy's address until `framework.trusted_proxies` says otherwise** — so a
  rate limiter keyed on it is one global bucket, not one per visitor. Behind Traefik → nginx →
  php-fpm this made both limiters site-wide: five resend POSTs per hour from *anyone* would 429
  *everyone*. No test can catch it — `KernelBrowser` synthesises `REMOTE_ADDR=127.0.0.1`, a value
  that never occurs in production. Configured in `config/packages/framework.yaml`; dev and test
  deliberately disable trust, because dev publishes nginx straight to `127.0.0.1:8080` with no proxy
  in front, and trusting `X-Forwarded-For` there would let anyone reaching that port forge their own
  client IP.
- **Symfony owns client-IP/scheme/host reconstruction — nginx must NOT run `ngx_http_realip_module`.**
  `set_real_ip_from`/`real_ip_header` rewrite `$remote_addr`, which is what `fastcgi_params` passes as
  `REMOTE_ADDR`. Symfony then compares a *public* IP against the private-range trust list,
  `isFromTrustedProxy()` returns false, and `X-Forwarded-Proto`/`-Host` are never read — so
  `getClientIp()` comes out right by accident while `isSecure()` and `getSchemeAndHttpHost()` stay
  wrong, silently un-meeting the precondition `csrf.yaml` depends on. nginx logs the header directly
  (`xff=$http_x_forwarded_for`) instead. Two trust layers that each look right in isolation is the
  trap.
- **`private_ranges` cannot be passed through `%env()%`.** FrameworkBundle special-cases the keyword
  in a `beforeNormalization()` that runs at *compile* time, over the raw value — which is the
  unresolved placeholder. `.env.example` therefore spells the full `IpUtils::PRIVATE_SUBNETS` CIDR
  list out literally. Do not "simplify" it back.
- **A bare `%env(FOO)%` in config makes `FOO` a boot-time dependency of every kernel — including the
  worker, console commands, CI, and `docker build`.** A missing var is `EnvNotFoundException`, not a
  fallback. This took `messenger-worker` down while `make check` stayed green (it only `exec`s into
  `app`) and `/health/ready` stayed 200 (it probes Postgres and Redis, not queue depth). Use
  `%env(default::FOO)%` for optional infrastructure vars — then still set the var everywhere,
  because the fallback converts a loud outage into a silent misconfiguration. **When you add a
  required input to the boot path, enumerate every context that boots, not every context that serves
  traffic.**
- **Redis is now load-bearing for account recovery, not merely for its throttling.** The reset flow
  stashes the plaintext token in the **session** between `GET /reset-password/{token}` and the form
  it redirects to, so that a live account-takeover credential never sits in the URL of a page the
  user types into ([ADR-0011](./docs/adr/0011-password-reset-challenges-modelled-in-the-domain.md)
  decision 8). Sessions are Redis-backed, so **Redis down breaks password reset outright** — the
  route answers with the neutral invalid-link redirect, which is indistinguishable from a bad token,
  so the failure presents to the user as "your link doesn't work" and to the operator as nothing at
  all. Accepted knowingly; recorded here because it is the cost side of that decision and is easy to
  forget when reading only the benefit.
- **A Monolog *channel* decides which logger service writes a record; it decides nothing about
  whether any handler keeps it.** Under `prod` and `test` the top-level handler is `fingers_crossed`
  with `action_level: error`, which buffers everything below ERROR and **throws the buffer away**
  unless an error follows. So a new channel's INFO line — the one thing a healthy background job ever
  emits — is silently discarded in exactly the environment it exists for, while `debug:container`
  cheerfully shows the channel wired up. The prod `console` handler is not a fallback either:
  `ConsoleHandler` emits WARNING and above at normal verbosity, and cron runs at normal verbosity.
  Fixed for `pruning` by giving it a **dedicated handler** *and* excluding it from `main` in both
  environments. **Verified by removing them and re-running**: a green run wrote **zero bytes** to
  stderr and exited 0. When you add a channel for a job whose failure mode is silence, prove a record
  reaches a handler rather than assuming the channel is the work.
- **Never put `SENTRY_RELEASE` in the box `.env`.** `env_file:` outranks the image's `ENV`, so it
  would pin every release to one value and Sentry could not tell a regression from an old bug.
  The release is the git SHA baked in at build; only `SENTRY_DSN` belongs in `.env`.
- **A route requirement's failure mode is a bare 404 that no `catch` can convert.** `{token}` on
  `/reset-password/{token}` is deliberately only `[^/]+`, with the format gate living in the
  `ResetToken` value object, so a mangled token reaches the controller and gets the same neutral
  invalid-link response as an unknown one. A stricter `requirements` regex would dead-end exactly the
  people who need the form (mail clients hard-wrap long lines) and would make the controller's
  `InvalidResetToken` arm dead code. **Nothing will tell you**: every test using a well-formed token
  passes with the strict regex in place.

- **Google's `redirect_uri` is only as right as `trusted_proxies`.** It is built from the request's
  scheme and host, so behind Traefik it is `https://muzbar.com/...` only when trust is configured.
  **Prod trust comes solely from `TRUSTED_PROXIES` in the box `.env`** — there is no `when@prod`
  block, and the image's `src/.env` sets it empty (trust nobody). The box `.env` lacked it until
  2026-11-14, so rate limiters were one global bucket and `isSecure()` false from launch; only the
  first Google deploy's smoke check noticed. `KernelBrowser`
  never goes through a proxy, so **only `deploy.yml`'s smoke check** (`location` of
  `/connect/google` contains the https `redirect_uri`) can catch it. It also fails when
  `GOOGLE_CLIENT_ID` is empty on the box — by design.
- **knpu does no PKCE unless you pick `OAuth2PKCEClient`, and that needs `type: generic`.** The
  default `OAuth2Client` and league's `Google` (`getPkceMethod()` returns null) both silently skip
  it, and the flow still works — so nothing tells you. League also **always adds the `profile`
  scope**; `GoogleProvider` (ours, `final`) removes it. Do not "simplify" back to `type: google`.
- **A passwordless `SecurityUser` has `getPassword() === null`.** Symfony's
  `CheckCredentialsListener` then rejects form login with the same `BadCredentialsException` as a
  wrong password — that is the design (AC-14). Code reading `passwordHash()` must handle `null`.
- **`UserRegistered` now fires for users who are already verified** (Google provisioning). A listener
  on it must not assume "unverified": `IssueVerificationOnUserRegistered` returns early on
  `EmailAlreadyVerified`, or every Google sign-up becomes an `error` → Sentry.
- **The OAuth test seam is the provider's HTTP client, not the provider.** `when@test` in
  `knpu_oauth2_client.yaml` re-declares `knpu.oauth2.provider.google` with
  `FakeGoogleHttpClient` (Guzzle `MockHandler`) as its `httpClient`, so the real scopes, state check,
  PKCE body and error mapping all run. An empty mock queue throws, so no test can reach Google. Take
  the `state` from the start response's `Location`, stay in **one** `KernelBrowser`, and
  `disableReboot()` before scripting it.
- **A failed request in prod flushes the `fingers_crossed` buffer — including framework debug lines.**
  The `request` channel's "Matched route" record carries `request_uri`, so on a Google callback that
  ends in an `error` (provider down, 5xx) the callback's `code` and `state` reach stderr, and
  `doctrine` records carry SQL parameters (emails). Not Sentry — that path is scrubbed — only the
  box's container log. The code is single-use and already spent, so this is accepted for now; it is
  a pre-existing property of every route, surfaced (not introduced) by this slice's log scan.

- **The session target path has exactly one writer and every reader goes through `HttpUtils`.**
  The writer is `SecurityController::login()` on `GET`, behind `IntendedActionPath::fromUntrusted()`.
  A new writer must use that validator; a reader that does `new RedirectResponse($target)` on a
  session value is an open redirect (`GoogleAuthenticator` did, until ADR-0015). "Starts with `/`" is
  not a defence: **browsers strip TAB/CR/LF from URLs and read `\` as `/`**, so `/\t/evil.example`
  *becomes* `//evil.example`, and `%2F`/`%5C`/`%09` do the same after a downstream decode. Symfony's
  own `_target_path` **POST** parameter is a second door we cannot remove; it is safe only because
  `HttpUtils` absolutises and host-checks, and `IntendedActionRedirectTest` proves it.
- **An anonymous `GET` of any protected route overwrites the stashed target** (the firewall entry
  point is a writer too). A test that "probes" `/account` to check it is signed out silently replaces
  the intended action — read `_security_main` from the session instead.
- **The intended action lives only as long as the session: PHP's default 1440 s idle lifetime**
  (`RedisSessionHandler` has no `ttl`, nothing sets `session.gc_maxlifetime`). A registrant who takes
  longer to click the verification link lands on `/account`. Not a bug in the redirect.
- **To make a future action link an overlay trigger** ("Sell Gear"), copy the layout's "Profile"
  link: `<a href="{{ path('app_login', {_target_path: path('app_<action>')}) }}" data-auth-overlay>`.
  Without JS it is an ordinary link; the target is generated from the route, never typed. Protect the
  route itself too, so the entry point covers direct visits — with an **anchored** `access_control`
  regex (`^/profile(/|$)`, not `^/profile`, which would also lock a future public `/profiles/{slug}`). The overlay's background fetch of
  `/login` renders (and so consumes) flash messages on a page whose layout is discarded.

- **A unique violation during `flush()` closes the EntityManager.** The category forms therefore
  re-render from `DbalCategoryOutline` (DBAL), never through the EM — and the attribute admin from
  `DbalAttributeSchema`, with every option action redirecting (303) so the next page gets a fresh EM. For the same reason
  `PostgresCategoryTreeLock` uses DBAL `transactional()` + `EntityManager::clear()` on failure, **not**
  `wrapInTransaction()` — which closes the EM on *any* throwable, so a plain domain refusal (a cycle)
  would leave the request holding a dead EM.
- **Partial-index `where` must be written in PostgreSQL's normalised form** — `(parent_id IS NULL)`,
  parenthesised. Written as `parent_id IS NULL`, every `make migration.make` re-diffs the index as a
  DROP/CREATE. Proved by flipping it.
- **No expression indexes — DBAL re-diffs them forever; store the normalised form instead.**
  `lower(label)` cannot be declared in a mapping, and DBAL's introspection reads an expression column
  (`attnum = 0`) back as a different index, so no spelling fixes it (generated columns and `citext`
  are no better). Case-insensitive uniqueness is a persisted `label_key` = `AttributeLabel::key()`,
  set by the aggregate together with the label, under a plain unique index. `key()` is the one
  definition of "same label" for both the pre-check and the column.
- **`mb_strtolower` can lengthen a string** — full Unicode case mapping turns "İ" (U+0130) into two
  code points, so a 100-char label made a 101+-char key and a SQLSTATE 22001 (uncaught: not a unique
  violation). A stored case-folded key uses `mb_convert_case(…, MB_CASE_LOWER_SIMPLE)` (1:1). Likewise
  a read model's SQL cast must hold the VO's whole range: `NumberRules` bounds are PHP int64, so
  `::bigint`, never `::int`. Both found by `/verify`, not by the suite.
- **Symfony compiles services lazily: a missing adapter fails only when something resolves the
  service.** `cache:warmup` and a green Domain/Application suite prove nothing about the graph — the
  C-13 guard injected a port with no adapter yet, and stayed silent until `CategoryAdminController::
  delete` was hit (26 errors). Land a new port dependency **with** its adapter.
- **A domain exception cannot have a `$code` property** — `\Exception` already declares `protected
  $code` (the int), so a promoted `private readonly AttributeCode $code` is a compile-time fatal (access level and
  type both clash).
  Store it as `$attributeCode` and expose `code(): AttributeCode` (`AttributeCodeAlreadyTaken`).
- **A `readonly class` cannot use a trait whose property is not declared `readonly`** — a fatal
  error at class load, not a lint. `UuidIdShape`'s promoted property says `private readonly` for
  that reason; keep it.
- **phpstan cannot intersect array shapes**, so a read model that adds columns gets its **own**
  `@phpstan-type` rather than widening a shared one: `effectiveFor()` returns `EffectiveRow`, and
  `AttributeRow` stays as `find()` returns it (which has no lineage to report).
- **`make test filter=` breaks on a regex containing `|`** — the Makefile passes `--filter=$(filter)`
  unquoted, so the shell reads the pipe. For an alternation run phpunit directly:
  `docker compose -f docker-compose.yml -f docker-compose.dev.yml exec -T -e APP_ENV=test app php vendor/bin/phpunit --filter='A|B'`.
- **Symfony's default `slugger` maps `&` to `and`** (and follows the request locale), so
  `"Bass & Guitars"` became `bass-and-guitars`. `CategorySlugDeriver` gets its own
  `AsciiSlugger('en', [])` in `services.yaml`; do not "simplify" it to the framework service.

- **A Doctrine mapping block scans its `dir` recursively and reads a subdirectory as a namespace
  segment.** `mapping/embeddable/Price.orm.xml` would map `…\Entity\embeddable\Price`, so the
  `Price` embeddable has its own block (`ListingValueObjects`) over a **sibling** `embeddable/` dir.
- **`@phpstan-import-type` fails `deptrac --fail-on-uncovered`** — Deptrac reads the imported alias as
  a class in the importing namespace. Spell the array shape out instead (`LeafCategoryChoices`).
- **Test-env 404 debug pages embed the test's own call trace**, so a GET and a POST 404 never match
  byte-for-byte. "Indistinguishable" assertions compare like with like, after normalising ids and
  dump ids (`SellTest`).
- **A free-text VO must refuse control characters and invalid UTF-8, not just count length.**
  pdo_pgsql **truncates a string at NUL** (`"Gi\0tar"` is stored as `"Gi"`, under L-3, and the row then
  500s on every hydration), and invalid UTF-8 is SQLSTATE 22021 — an uncaught `DriverException`.
  `CategoryName`'s `/\p{Cc}/u` is the rule; a paragraph field allows `\t\n\r` only. Found by `/verify`.
- **The listing form carries no length constraints.** A Symfony `Length` would answer first, with its
  own wording, and override the failure contract; the value objects are the only bound.
  **Exception: a Details choice/boolean is a `ChoiceType`**, which rejects a tampered option itself
  (its `invalid_message` is the contract's wording) before the Domain sees it — so no HTTP test can
  catch a Domain that accepts any UUID; `AttributeRuleTest`/`ListingTest` do.
- **The repository is the only writer of attribute values.** `listing_attribute_value` is unmapped:
  an EntityManager flush, a Foundry `create()` or any other save that skips
  `DoctrineListingRepository::save()` persists **none**, silently. `ListingFactory::withValues()`
  revises through the aggregate and saves through the repository; do likewise. Reads come from the
  `postLoad` listener (`ListingAttributeValuesLoader`) — skip it and the property is uninitialised
  (loud). That is **one SELECT per hydrated `Listing`**: list pages read through DBAL, never by
  hydrating aggregates in bulk (N+1).
- **Two saves of one listing share the tree lock, so `writeValues()` locks the listing row
  (`FOR UPDATE`) before its delete-and-reinsert** — without it the second INSERT dies on 23505. Do
  not drop it because the flush's `UPDATE` "already locks the row": that only happens because
  `revise()` swaps VO instances, which Doctrine compares by identity.
- **`ReviseListing::$attributes` has no default on purpose** — a revise replaces the whole set, so
  `[]` clears every value; a caller must say so.
- **`listing_attribute_value` is invisible to every Doctrine safety net** — unmapped *and* excluded by
  `doctrine.dbal.schema_filter`, so `make migration.make` and `schema:validate` see nothing. AC-6's
  `information_schema`/`pg_constraint` test is its **only** shape guard: any column change is a
  hand-written migration **plus** an AC-6 update.
- **PDO hands a PostgreSQL array back as a `{…}` string.** `schemaOf()` aggregates option ids with
  `array_to_json(array_agg(…))` and decodes JSON; do the same for any new array column in a read.
- **Blank Details fields are omitted from `ReviseListing`, and revise replaces the whole value set —
  so blank = clear.** The edit page also drops stored values whose attribute is no longer on the form,
  or the next save would be refused for an attribute the seller cannot see — the dormant ones survive
  anyway, because `revise()` carries them over itself (L-17).
- **The isolation scans strip PHP comments first** — a Domain docblock names `listing_attribute_value`.
  A new scan that greps raw source flags documentation as a dependency.

- **Dispatch after `holdSteady()` returns, never inside it.** Inside the closure the save is flushed
  but **not committed** — a listener would act on a publish a later rollback undoes (and a mail
  already sent cannot be rolled back). The handler `return`s `$listing->releaseEvents()` from the closure and
  dispatches outside (ADR-0021 d.5, ADR-0008 amendment: *"after save" means after the transaction
  that holds it commits*). A crash between commit and dispatch loses the event — accepted, no
  listener yet. `CategoryTreeLockTest` records whether the lock was held at dispatch (mutation (i) —
  **its only catcher**).
- **`required` is never retroactive — do not add a sweep.** L-13 is checked only on writes that are
  or make a listing public, so an admin marking an attribute required (or narrowing bounds) leaves
  every published listing untouched until its seller's next save. That is the owner's decision
  (D11.2), not a gap: a job that re-checks, unpublishes or flags published listings is a new
  decision, not a fix. Drafts and withdrawn listings never check it (L-11).
- **The public read never selects `seller_id`** — `DbalSellerDrafts::findPublished()` names its
  columns, and `PublicListingTest` asserts the *SQL* (via `QueryRecordingStatement`), not just the
  HTML (mutation (l) — **its only catcher**). Not "selected and not rendered": a column the page
  never read is one no template change can leak. Contact channels are their own slice.
- **`/listings/{id}` has one 404** for draft, withdrawn, unknown and malformed ids, for everyone
  including the seller (no preview), and **no route `requirements`** (a route-level 404 differs).
  The status filter is in SQL, never PHP (mutation (k)). A malformed id issues no query.
- **POST 404 parity must normalise CSRF tokens too** — the test-env debug 404 embeds the request's
  `_token`, so `ReadsListingPages::normalised()` strips tokens, ids and dump ids. Bob's *valid* token
  for Ann's listing is minted through the real CSRF token manager (`mintToken()`), or the test
  proves the 403, not the 404. "Identical body" signed-in vs anonymous compares `article.listing`
  (the layout nav differs).
- **A template must not reference a route that does not exist yet** — `path()` throws at render, so
  the withdraw form landed with the withdraw route, not with the edit page that shows it.
- **`published_at`'s introspected default is `NULL::timestamp with time zone`**, not a literal
  `NULL`; a shape test accepts both.

- **Dormant values are kept by revise and dropped by re-file — never "clean them up"** (L-17,
  ADR-0022). A value whose attribute is archived or left the lineage is what makes restore and an
  undone move lossless. A sweep, a "drop on save" or a `dormant` flag column is a new owner decision
  (retroactive, C-22), not a tidy-up. They block hard delete of their attribute (C-25 counts them).
- **Archived names stay reserved, so restore takes no facts.** `codesDefinedOn()`, `isLabelTaken()`,
  `existsForCategory()` and both unique indexes read archived rows **on purpose** (C-29). Freeing a
  name (a filter there, a partial index) turns restore into a fourth door to C-21 and C-17: it must
  then read the branch under the lock and get a `CategoryTreeLockTest` case.
- **Active-only is the default read; `optionLabels()` deliberately is not.** `schemaOf()` and
  `effectiveFor()` drop archived attributes and options — `schemaOf()` in its **JOIN** conditions, so
  a choice whose options are all archived is an empty rule, not a vanished one. `optionLabels()`
  labels any stored id, so a **stale** "Brand: Fender" stays on the public page. Archived rows are
  read only by name: `archivedDefinedOn()`, `find()`/`optionsOf()`' `archivedAt`, `optionsFor(…, keep)`.
- **C-18 counts active options: `countActiveForAttribute()`** (renamed from `countForAttribute()` —
  a port method whose meaning narrows changes its name). Asked whenever an *active* option leaves the
  active set, by archive or delete; deleting an archived option never meets it.
- **`keep` takes the *choice* attributes' stored values only.** The seller edit page passes them to
  `optionsFor(…, keep)` so a stale option renders selected. Any other value is wrong there twice: a
  non-uuid text in `id IN (…)` is a uuid cast error → 500, and a uuid-shaped one (an archived
  option's id typed into a text field) would put that option onto the choice select.
  `SellController::detailsSchema()` builds `keep` from the choice rows — do not widen it.
- **Every facet/search query must filter archived rows** (plan Risk 3). A naive `GROUP BY option_id`
  over `listing_attribute_value` counts dormant and stale values and archived attributes. Scope to the
  subtree + `status = 'published'`, active-schema attributes only, join options
  `AND archived_at IS NULL` — and take the `EXPLAIN` on **that** query.

**Error tracking** ([ADR-0013](./docs/adr/0013-error-tracking-hosted-sentry-one-capture-path.md); runbook in [docs/infrastructure.md](./docs/infrastructure.md)
§*Sentry (runbook)*):

- **Anything logged at `error` or above leaves the box, on any channel, after scrubbing. The
  scrubber (`SentryEventScrubber`, `before_send`) is the second line, not the first** — code must
  still never log an address, token or secret.
- One capture path: the Monolog `Sentry\Monolog\Handler`. The bundle's error/Messenger listeners
  stay **off** (duplicate events, two places to get privacy wrong). Don't add a `captureException()`.
- Log context arrives at **`extra['monolog.context']`**, not the event's top level — assert and
  scrub there. Retries (`warning`) are never reported; exhausted failures (`critical`) are.
- Tests read events from `RecordingSentryTransport`; the DSN in `.env.test` is `.invalid` so nothing
  can send. Prove a Sentry test bites by removing the handler or rule and watching it fail.

## SDLC

Lean solo Spec-Driven Development. Loop: **`/plan` → `/implement` → `/verify`.** Every feature gets a
short spec in `docs/specs/<feature>/`. Details: [docs/sdlc.md](./docs/sdlc.md). Agents/commands:
[docs/tooling.md](./docs/tooling.md).

**Branching:** GitHub Flow — `feature/<name>` (or `fix/<name>`) → PR → squash-merge to protected
`main` → build image → **manual-gated** deploy. Never `git pull` on prod. See [docs/cicd.md](./docs/cicd.md).

## Documentation duties

When behaviour changes, update: this file (if a convention/command changed), the relevant ADR (if a
decision changed), and [FORboehpyk.md](./FORboehpyk.md) (the running plain-language project story —
capture bugs hit and lessons, per the owner's standing rule).
