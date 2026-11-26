# Muzbar Roadmap — Phased, Gated Build

The PRD's three build phases, decomposed into step-by-step milestones for solo, spec-driven work.
Each phase **ends in a validation gate** tied to a PRD success metric or suggested validation — you do
not enter the next phase until its gate is green. Gates are where a solo dev catches a wrong
foundation before building three phases on top of it.

Legend: each milestone is a `/plan → /implement → /verify` cycle (see [sdlc.md](./sdlc.md)).

---

## Phase 0 — Foundation & scaffolding *(new; precedes PRD Phase 1)*

Stand up the skeleton the SDLC assumes. No product features yet.

- [x] Symfony 7 app under `src/`, hexagonal folders (`Domain/`, `Application/`, `Infrastructure/`),
      Deptrac config expressing the layer rules.
- [x] Docker: `docker-compose.yml` (prod) + `docker-compose.dev.yml` (dev), PHP 8.4-FPM multi-stage
      Dockerfile, nginx, postgres, redis — footgun guards applied (ADR-0003 / infrastructure.md).
- [x] Quality tooling: php-cs-fixer, PHPStan max (+ symfony/doctrine extensions), PHPUnit + Foundry +
      DAMA, Makefile (`up.dev`, `console`, `migrate`, `cs`, `stan`, `deptrac`, `test`, `check`,
      `db.dump`), tracked git hooks (`scripts/git-hooks`, `core.hooksPath`).
- [x] Test environment: `APP_ENV=test`, dedicated `muzbar_test` DB (never the dev DB), `.env.test`,
      DAMA transactional rollback; `make test` provisions/migrates it against the test env.
      Isolation comes from `when@test: dbal.dbname_suffix` in `config/packages/doctrine.yaml` — **not**
      from a `DATABASE_URL` in `.env.test`, which would defeat it (see the note in that file).
- [ ] `.claude/` agents, commands, hooks, skills per [tooling.md](./tooling.md).
      *Partial:* 6 agents, 5 commands, 3 skills are in place; the Claude Code hooks
      (`pre-write-guard`, `post-implement-check`) are **not** configured in `.claude/settings*.json`.
      (The *git* hooks — `pre-commit`, `commit-msg` — are done, under Quality tooling above.)
- [x] GitHub Actions CI (lint + stan + deptrac + test) + deploy workflow ([cicd.md](./cicd.md)).
- [x] Health endpoints (`/health/live`, `/health/ready`) — `/health/ready` probes Postgres + Redis and
      returns 503 when either is down (never a bare `return 'ok'`).
- [x] Umami analytics — muzbar is registered as a site on the shared VDS instance (no container to
      deploy). The `<script>` snippet itself still has nowhere to live: `templates/base.html.twig` is
      the untouched Symfony skeleton default and nothing user-facing renders yet. It lands with the
      first public layout, taking the site ID / script URL as config (infrastructure.md §Analytics).
- [ ] Sentry error tracking — **deferred by decision (2026-07-25)**, not an oversight.
      infrastructure.md calls it "Phase 0/1, cheap insurance for a solo dev"; the natural moment is the
      first user-facing flow (the Identity slices), which is when a silent 500 starts costing a signup.
      *2026-11-13:* `identity-password-changed-notification` adds a second silent async failure class —
      a lost security notice has no compensating action, so its `error` log is the only signal.
- **Gate — MET** (merged in #1, hardened by #3–#6, 2026-07-23 → 07-24): CI green, `make check` passes,
  the VDS deploy succeeds and `https://muzbar.com/health/ready` returns 200 with Postgres + Redis
  probed. *Caveat:* the "hello" slice is `HealthController` — an Infrastructure endpoint, not a Domain
  slice; `Domain/Shared/` is still empty. The first real Domain code lands in Phase 1.

> **Phase 0 carry-over into Phase 1** (neither blocks the gate, but neither may be forgotten):
>
> 1. The two Claude Code hooks from [tooling.md](./tooling.md) — `pre-write-guard` (Domain purity) and
>    `post-implement-check`. Worth wiring *before* the Identity slices: `pre-write-guard` is exactly
>    the guard that catches a stray `use Symfony\...` in the `User` aggregate.
>    **Still outstanding after slice 1.** Largely mitigated: `deptrac.yaml` declares a catch-all
>    `Vendor` layer (anything outside `App\`, excluding PHP built-ins) and enables the `use` emitter,
>    and `make deptrac` runs `--fail-on-uncovered` — so *any* stray vendor import in `Domain/` fails
>    `make check`, even an unused one (proven, not assumed). The hook would still catch it earlier — at
>    write time rather than at commit time.
> 2. ~~Sentry, deliberately deferred to the first Identity slice (see above).~~
>    **CLOSED 2026-11-13** by `observability-sentry` ([ADR-0013](./adr/0013-error-tracking-hosted-sentry-one-capture-path.md)): one Monolog capture path,
>    scrubbed before send, proven by tests. The DSN is set by the owner at first deploy (task T15).
>    **Remainder, not closed:** crash loops and pre-boot failures never reach Sentry — a worker that
>    dies before the kernel boots, or is simply stopped, still looks like a healthy system. That is
>    owned by the Phase 2 queue-age readiness item (infrastructure.md footgun #7) and the deploy's
>    image check. History below is kept as the record of why it was due.
>    **Now due.** That trigger condition — "the first user-facing flow, which is when a silent 500
>    starts costing a signup" — was met on 2026-07-26 when `/register` and `/login` shipped.
>    **Overdue after slice 2 (2026-07-28), and the argument has changed shape.** Until now the worst
>    case was a 500 somebody eventually notices. `identity-email-verification` adds an *asynchronous*
>    failure path: `IssueVerificationOnUserRegistered` deliberately swallows its own exceptions so a
>    dead mail relay cannot 500 a committed registration, and the Messenger worker's failure
>    transport is a queue nobody looks at. Both are correct designs and both are silent by
>    construction. The log lines exist; nothing reads them. This is now the highest-value
>    `devops` item outstanding — see [ADR-0010](./adr/0010-event-delivery-and-transactional-mail.md).
>    Related and cheaper: `/health/ready` still probes only Postgres and Redis, so **a stopped
>    messenger worker looks exactly like a healthy system.**
>    **Overdue after slice 4 (2026-08-01), and for the first time on work whose *only* symptom of
>    failure is the absence of a symptom.** `identity-challenge-pruning` ships a backlog, a heartbeat
>    and a log line on every run, so the failure is now genuinely *visible* — and nothing yet
>    *notices* it. That is the whole remaining gap, and it is Sentry's.
> 3. **`deploy.yml` never restarts `messenger-worker`.** The deploy runs `docker compose pull app`
>    and `docker compose up -d app nginx`; the worker is in neither list, so it runs whatever image it
>    last had, indefinitely, and the only symptom is behaviour that does not match the source. Found
>    2026-08-01 while costing slice 4's scheduling decision — **a pre-existing bug, not one that slice
>    introduced.** It is also the strongest single argument against adding any second long-running
>    container: a scheduler silently running an *old retention policy* would delete data by a rule
>    nobody currently believes is in force. **Fix this before any future slice adds a `scheduler`
>    service.** Manual workaround in `docs/infrastructure.md`.
>    **FIXED 2026-08-02.** `deploy.yml` now pulls both services, stops the worker for the migration
>    window, starts it last on the new image, and **fails the deploy** if either container is not
>    running the image just released.
>    *This item was first scheduled for the end of Phase 1 and then pulled forward the same day, which
>    is worth recording because the reasoning is reusable.* The deferral looked sound — nothing in
>    Phase 1 adds a second daemon, so the trigger condition stated above stayed unmet. It was wrong
>    because the trigger was the wrong one. Twig mail templates are rendered by `BodyRenderer` at
>    **send** time, and sending happens in the worker; so the danger is not "a second daemon" but
>    **any slice that ships a new mail template** — of which the very next one,
>    `identity-password-changed-notification`, is an example. That slice would have deployed an `app`
>    that queues the message and a worker that cannot render it, failing into a transport nobody
>    reads, and the undelivered mail is the one telling an account-takeover victim their password
>    changed. **The lesson: when deferring a known bug, check what the *next* slice does, not what the
>    stated trigger condition says.** A trigger condition is a guess made before the work that meets
>    it.
> 4. **`messenger_messages` and the `failed` queue still grow without bound.** They are Messenger's
>    tables with Messenger's semantics, and `identity-challenge-pruning` deliberately did not acquire
>    them — a job named for `Identity` challenges must not quietly become the system's general
>    garbage collector. Named here so nobody assumes slice 4 covered it. The pruning command is a
>    working pattern to copy, not to extend.

## Phase 1 — Data model, dynamic schema & auth *(PRD Phase 1)*

- [ ] `Identity` context: `User` aggregate, roles, three authenticators (ADR-0005) — email/password
      with **email verification + password reset + login throttling**, Google OAuth, and account
      linking; login/register overlay + intended-action redirect. **Too big for one cycle — sliced:**
  - [x] `identity-user-password-auth` — **DONE 2026-07-26.** `User` aggregate, `Email` /
        `HashedPassword` / `PlainPassword` / `UserId` VOs, `Role` enum, `UserRegistered` /
        `UserEmailVerified` events, `UserRepository` + `PasswordHasher` ports, the first migration
        (`identity_user`), register + form login + logout + `/account`, `login_throttling` on Redis,
        and `muzbar:identity:verify-email`. Established [ADR-0007](./adr/0007-persistence-conventions-for-domain-aggregates.md)
        (persistence conventions) and [ADR-0008](./adr/0008-domain-events-recorded-on-the-aggregate.md)
        (domain events), and amended ADR-0005 to record that the usable-account invariant is modelled
        now and enforced in the next slice.
  - [x] `identity-email-verification` — **DONE 2026-07-28.** The context's **second aggregate**,
        `EmailVerificationRequest`, with `VerificationToken` / `HashedVerificationToken` /
        `EmailVerificationRequestId` VOs, the `EmailVerificationRequested` event, three ports
        (repository, token generator, mailer), a second additive migration, and the verify / sent /
        resend routes with their mail templates. `VerifiedAccountUserChecker` + one `security.yaml`
        line invert the previous slice's AC-24, and **`Domain/Identity/Entity/User.php` is untouched
        in the whole diff** — which was the point.
        Established [ADR-0009](./adr/0009-email-verification-tokens-modelled-in-the-domain.md)
        (the token is domain-modelled, and **`symfonycasts/verify-email-bundle` is not installed**:
        it is stateless, so single use, revocation and auditability are not expressible with it —
        ADR-0005 amended accordingly) and
        [ADR-0010](./adr/0010-event-delivery-and-transactional-mail.md) (sync event dispatch, async
        mail over Messenger + a worker container, no outbox — discharging ADR-0008's watched clause).
        **Owed by this slice:** a pruning job for expired/redeemed requests, and the orphan-row
        question when GDPR erasure is designed (there is deliberately no FK to `identity_user`).
  - [x] `identity-password-reset` — **DONE 2026-07-29.** The context's **third aggregate**,
        `PasswordResetRequest`, with `ResetToken` / `HashedResetToken` / `PasswordResetRequestId`
        VOs, the `PasswordResetRequested` and `UserPasswordChanged` events, three more ports
        (repository, token generator, mailer), a third additive migration, and the
        forgot-password / sent / link-check / reset routes with their mail templates.
        **`symfonycasts/reset-password-bundle` is not installed**
        ([ADR-0011](./adr/0011-password-reset-challenges-modelled-in-the-domain.md), which also
        amends ADR-0005 and ADR-0009). *This line previously named the bundle. It was written in
        Phase 0 as part of a stack survey, and it is corrected rather than quietly outvoted — a
        roadmap line that survives contradicting an accepted ADR is how documentation starts lying.*
        The slice is the **same shape as slice 2 with four rules inverted** — a replay is refused,
        reissuing invalidates outstanding links, the GET mutates nothing, and the two saves go in
        the opposite order — and each inversion carries a call-site comment saying *why*, because
        two files that differ four times without stated reasons is how one of them eventually gets
        "fixed". `User` **is** touched, unlike slice 2: one property, one method, one reader, one
        import, and no more (AC-39). A successful reset also verifies the email, which is what stops
        an unverified account being stranded with no recovery path at all.
        **Owed by this slice:** nothing new — it *adds to* the pruning debt rather than paying it,
        which is why `identity-challenge-pruning` is scheduled next.
  - [x] `identity-challenge-pruning` — **DONE 2026-08-01;
        [ADR-0012](./adr/0012-challenge-retention-and-recurring-background-work.md) accepted.** A
        `muzbar:identity:prune-challenges` console command under **host cron** (hourly), sweeping
        **overdue** rows from both challenge tables (`identity_email_verification_request`,
        `identity_password_reset_request`), plus the orphan-row answer that ADR-0009 decision 4 left
        for GDPR erasure. Scheduled here, before OAuth, because two tables is where this debt stops
        being a footnote.
        *This line previously read "one **Scheduler** task sweeping **expired/redeemed/invalidated**
        rows". Both halves are corrected rather than quietly outvoted, on slice 3's precedent — a
        roadmap line that survives contradicting an accepted ADR is how documentation starts lying.*
        **(a)** `symfony/scheduler` is not installed: it would add a bundle to every kernel that
        boots plus a **second daemon on a system that cannot see the first one**, and the deploy
        pipeline does not currently restart the daemon it already has (see `devops` below).
        Constitution §3's Scheduling row is read as **scoped** by its own `(30-day ad lifecycle)`
        parenthetical — this slice defers to that slice rather than picking a rival technology.
        **(b)** the predicate is **`expires_at` + a per-table retention window (7 days verification,
        30 days reset)** and deliberately never consults `redeemed_at` or `invalidated_at`: the two
        tables' notions of "dead" are inverted by design (ADR-0011 decision 9), so a "dead" predicate
        would be the rejected shared base class re-derived **in SQL**, where no unit test can reach
        it. `expires_at` is a ceiling on every reason a row is finished, so the sweep gets the same
        rows without encoding the disagreement.
        **The observability is the point, not a trimming:** this is the first recurring process here
        whose only failure symptom is silence, so it ships a backlog count that **cannot be faked by
        a job that runs and does nothing**, a Redis heartbeat, and a log line on every run including
        the all-zero ones — rather than adding a fourth "Sentry is overdue" to the risk list.
        **What it actually shipped**, beyond the plan: the first `DELETE` in the repository's
        history, licensed by a rule worth keeping — *an aggregate governs its state transitions, not
        its own non-existence; put in the Domain the part that can be wrong.* The Domain diff is one
        constant and one static per aggregate and nothing else. Two findings came out of it that the
        plan did not predict. **(i)** A dedicated Monolog channel is *not* enough on its own: prod's
        `fingers_crossed` handler buffers an INFO record and discards it, so the AC-20 line would have
        been silently dropped in the one environment it exists for. Verified by removing the
        dedicated handler and watching a green run write **zero bytes**. **(ii)** PHP will not compile
        one test double implementing both repository ports — `nextIdentity()` is covariant and a union
        of the two id types is wider than either — so the type system reaches AC-32's "no shared
        abstraction" conclusion unaided. **Owed by this slice:** nothing. It pays the debt slices 2
        and 3 both recorded, and hands GDPR erasure a written specification rather than a hope.
  - [x] `identity-password-changed-notification` (2026-11-13) — a listener on `UserPasswordChanged`
        mailing "your password was changed on X UTC" with the forgot-password URL. It is how an
        account-takeover victim finds out, **unless the attacker also owns the mailbox** (today the
        only producer is the reset link, mailed to that same address); it becomes decisive once an
        authenticated change-password path exists, and the listener subscribes to the *fact* so that
        path is covered without touching it. **What it shipped:** zero Domain and zero Application
        code — one Infrastructure listener and two templates. A complete event payload made a new
        consequence Infrastructure-only, and no port was added because nothing *inside* the hexagon
        calls it. It met ADR-0010's "revisit the outbox" trigger and declined it (dated amendment).
        **Owed:** it adds a second silent async failure class (a lost notice has no compensating
        action; a template failure in the worker lands in the `failed` transport) — see Sentry.
  - [x] `identity-google-oauth` (2026-11-14) — knpu's `OAuth2PKCEClient` + league's Google provider,
        `OAuthIdentity` VOs inside `User` (jsonb), sub-first recognition, linking by verified email
        (never a duplicate `User`). **Linking to an unverified account discards its password** (I-29,
        the pre-hijack defence); passwords became optional; I-5 deliberately not widened.
        [ADR-0014](./adr/0014-google-sign-in-linked-identities-on-the-user-aggregate.md).
        **Before deploy:** Google Console client + `GOOGLE_CLIENT_ID`/`_SECRET` in the box `.env` —
        the deploy smoke check fails without them.
  - [x] `identity-login-overlay` (2026-11-14) — login overlay (native `<dialog>` + plain JS, **not** a
        Live Component — login must go through the firewall) + intended-action redirect preserved
        across the form, the Google round trip and registration (same session, first sign-in).
        One writer, one validator, `HttpUtils` readers.
        [ADR-0015](./adr/0015-intended-action-redirect-and-login-overlay.md). The google-oauth AC-4
        TODO is closed (`LoginOverlayMarkupTest`). `/profile` is a **placeholder** proving target
        awaiting real profile content.
- [ ] `Catalog` context: `Category` + `Attribute` + `AttributeOption` aggregates with invariants;
      admin CRUD for categories and attribute schemas (FR-1). This is the core DDD exercise.
      **Too big for one cycle — sliced (2026-11-14):**
  - [x] `catalog-category-tree` (2026-11-14) — the `Category` aggregate: hierarchy (parent by id, never an
        association), invariants (no cycles, sibling-unique slug, a depth ceiling), and admin CRUD.
        One aggregate per node on an adjacency list; cycles and depth decided by the aggregate on
        facts read under one tree-wide advisory lock; `ROLE_ADMIN` granted from the console.
        [ADR-0016](./adr/0016-category-tree-per-node-aggregates-under-one-tree-lock.md).
        Passed `/verify` 2026-11-15 in one iteration (reviewer PASS; its 4 MINOR + 1 STYLE fixed the same day).
  - [x] `catalog-attribute-schema` (2026-11-15; passed `/verify` 2026-11-15 in two iterations — int4 cast on 64-bit bounds, `mb_strtolower` lengthening "İ") — `Attribute` +
        `AttributeOption`: four types fixed at definition (choice, integer number with unit and
        bounds, boolean, text), admin CRUD under `/admin/categories/{id}/attributes`.
        [ADR-0017](./adr/0017-attribute-schema-attributes-and-options-as-separate-aggregates.md) +
        ADR-0004/0016 amendments. *The open question this line used to ask is answered the other way:*
        the lean was "value collection", the first draft was a `jsonb` list, and the owner's Q5
        ("no cap — Brand lists run to thousands") plus Q7 (dependent options are coming) made
        `AttributeOption` **its own aggregate**. Hard delete for now (ADR-0004 amendment: soft delete
        and "option in use" guards arrive with the first `listing_attribute_value`). 958 → 1207 tests.
        **Slice 3 inherits (D4):** definitions are stored **once, on the defining category**, any
        category may define them, and nothing is copied down — so inheritance must be a **read-time
        derivation over the lineage**, under the same lock, and a move stays one row (AC-25).
        **C-11 (code unique per defining category) is the rule to widen:** it deliberately allows the
        same code lower in a lineage, which is the natural shape of an override; slice 3 decides
        whether that is an override or a conflict.
        **Dependent options inherit (D14):** no `parent_option_id` yet. When it lands, an option gains
        `?AttributeOptionId $parentOptionId`, `isLabelTaken()` gains a `?parent` argument, and the
        `(attribute_id, label_key)` index is replaced by the slice-1 **two-partial-index pair**
        (`… WHERE (parent_option_id IS NULL)` / `(attribute_id, parent_option_id, label_key) WHERE
        (parent_option_id IS NOT NULL)`) — one plain index would not do, because NULLs are distinct.
  - [x] `catalog-schema-inheritance` (2026-11-16; passed `/verify` 2026-11-16 in two iterations — AC-7's isolation scan was an empty file) — a category's **effective
        schema** is its own attributes plus every ancestor's, **derived at read time over the lineage,
        never stored** (C-22): relabelling on a parent writes one row, a move is still one row.
        **No overrides:** an attribute code appears at most once along any branch (C-21); sibling
        branches may reuse one. C-21 has no index — the aggregates decide it on facts read under the
        tree lock, through **three doors** (define, recode on `Attribute`; move on `Category`, C-5's
        shape exactly). C-11 is its same-category case; `isCodeTaken()` is gone. Admin page shows
        "Defined here" + "Inherited". No migration: **before deploy, run the one-off D10 query**
        ([infrastructure.md](./infrastructure.md) §*Catalog: one-off pre-deploy check*).
        [ADR-0018](./adr/0018-schema-inheritance-derived-along-the-lineage-codes-unique-per-branch.md)
        + ADR-0016/0017 amendments. 1207 → 1283 tests.
- [ ] `Listing` context (skeleton): `Listing` aggregate + `listing_attribute_value` model and the
      hot-column promotion mechanism (ADR-0004). No search/wizard UI yet.
      **Inherits from `catalog-schema-inheritance` (ADR-0018 decision 8 / plan D11) — design these
      here, not later:**
      1. **A move changes the effective schema of every listing in the subtree** — values for
         attributes that leave it, required attributes that arrive. The guard (or archive rule) goes
         in **`EditCategoryHandler`, beside C-21**: the move is the *second* accretion point, as
         `DeleteCategoryHandler` is for deletion. Under the tree lock, like every Catalog write.
      2. **A new required attribute on a parent makes existing listings below it incomplete.**
         `Listing` decides when "required" bites (likely at the listing's next edit, not
         retroactively).
      3. **Deleting a parent's attribute** is ADR-0004's soft delete (and its "option in use" guard),
         already handed over by the ADR-0004 amendment.
      4. **The effective-schema query for other contexts** (an `EffectiveSchema` type, or a read
         port) is designed with the listing form. Today only the admin read model
         `DbalAttributeSchema::effectiveFor()` computes it.

      **Too big for one cycle — sliced (2026-11-16).** Rule for every slice: **prod is consistent
      after each deploy on its own.** A slice that creates a new way for Catalog edits to strand
      listing data ships the guard for it too, failing closed (refuse) if the proper rule comes later.
  - [x] `listing-draft` (2026-11-17; passed `/verify` 2026-11-17 in two iterations — title/description accepted NUL and invalid UTF-8) — the `Listing` aggregate with no attribute values: `ListingId`, seller
        `UserId`, `CategoryId`, title, description, price; one status, `Draft`. Table
        `listing_listing`. "Sell Gear" link (ADR-0015 overlay-trigger shape) → `/sell` create, plus
        list/edit of the seller's own drafts. **First cross-context dependency:** Listing asks
        "does this category exist?" through its *own* port, adapter reads Catalog; a
        `ListingIsolationTest` copied from `CatalogIsolationTest`. **Guard it must ship:**
        `DeleteCategoryHandler`'s third guard (after C-7, C-13) — a category with listings cannot be
        deleted. Moves stay harmless: no values exist yet.
        *Owner decisions (2026-11-16):* **currency per listing** (price = amount in minor units +
        ISO 4217 code; Phase 2 must then decide how price facets/sorting work across currencies).
        **Listings only on leaf categories** — which opens two more doors that must ship guarded in
        this slice: creating a child under a category with listings, and moving a category under one
        with listings, are both refused (they would turn a listed-on leaf into a non-leaf).
        **Shipped:** L-2 decided by `Listing` inside `Categories::holdSteady()` (the tree lock's new
        **shared** mode); C-23 (delete) and C-24 (create-under, move-under) in Catalog's handlers
        under the exclusive lock; price as integer minor units, `USD`/`EUR`/`SEK`/`UAH` *(`UAH` removed 2026-11-23, ADR-0019 amendment)*.
        [ADR-0019](./adr/0019-listing-context-cross-context-ports-leaf-only-under-one-lock-in-two-modes.md)
        + ADR-0007/0016 amendments. 1436 tests.
  - [x] `listing-attribute-values` (2026-11-18; **passed `/verify` 2026-11-20 first time**) — the value layer (ADR-0004 §2): `listing_attribute_value` with
        typed columns and the generic `(attribute_id, option_id)`-style indexes, values owned by the
        `Listing` aggregate. Catalog exposes **`EffectiveSchema`** (D11.4) to other contexts; Listing
        consumes it through its own port. The draft edit form gains a **server-rendered** attribute
        section built from the effective schema — not the Live Component wizard (Phase 2), but it *is*
        the gate's "dynamic form-render path". Values validated per type (option belongs to the
        attribute, number within rules). `required` is **not** enforced on drafts.
        **Guards it must ship (fail closed):** delete-attribute and remove-option refused while in use
        by any listing (expires ADR-0004 amendment 1, by refusal rather than soft delete); a move
        refused when the subtree holds any listing values (D11.1, in `EditCategoryHandler` beside C-21).
        **Shipped:** values are **parts** of `Listing` (ADR-0017's test, opposite answer), held as an
        immutable `AttributeValues`; `DoctrineListingRepository::save()` is their only writer
        (delete-and-reinsert into the unmapped `listing_attribute_value`), a `postLoad` listener
        hydrates them. `Categories::schemaOf()` is the `EffectiveSchema` of D11.4, a fact true only
        inside `holdSteady()`; `revise()` reads the raw input against it (L-6 → L-2 → L-10 → L-9 →
        assign). C-25/C-26/C-27 through Catalog's new `AttributeUsage` port and
        `CategoryUsage::hasAttributeValues()`, under `exclusively()`.
        [ADR-0020](./adr/0020-attribute-values-parts-of-listing-validated-against-catalog-schema-guarded-by-refusal.md)
        + ADR-0004/0007/0016/0018/0019 amendments. All fourteen AC-28 mutations bit. 1436 → 1637 tests.
  - [x] `listing-publish` (2026-11-21; **passed `/verify` 2026-11-21 first time**) — `Draft → Published` (+ `Withdrawn`). **D11.2 answered here: "required"
        and current number rules bite at publish and at every later edit, never retroactively** — so
        a new required attribute, or narrowed bounds, makes a listing incomplete only when its owner
        next touches it. Public page `/listings/{id}` for published listings only, no seller email
        (PRD §6 privacy). `ListingPublished` event, no listener yet. No expiry — that is Phase 2's
        30-day lifecycle.
        **Shipped:** the lifecycle is a state machine inside `Listing` (L-12: `Draft → Published`,
        `Published ⇄ Withdrawn`, nothing returns to `Draft`); L-13 (complete against the schema
        current *at that write*) checked by `publish()` and by `revise()` only while published — so
        "never retroactive" is a consequence, not a mechanism; L-14 (publish re-checks leaf and
        stored values unconditionally); L-15 (a published listing cannot be re-filed); L-16
        (`published_at`, kept on withdraw). "Save and publish" is a flag on `ReviseListing` — one
        transaction; `ListingPublished` released inside `holdSteady()`, **dispatched after it
        returns** (after commit, not merely after save). Public page reads with `status =
        'published'` in SQL and **never selects `seller_id`**; option labels fetched only for stored
        ids (≤ 5 queries). Catalog's guards confirmed **status-blind** (ADR-0019's watch item
        discharged). Routes and `DbalSellerDrafts` keep their names (Q8); the page is "Your listings".
        [ADR-0021](./adr/0021-listing-lifecycle-in-the-aggregate-completeness-on-public-writes-dispatch-after-commit.md)
        + ADR-0008/0018/0019/0020 amendments. All fifteen AC-28 mutations bit. 1637 → 1743 tests.
  - [x] `uuid-id-trait` *(immediately after `listing-publish` — plan Q7)* — `refactor/uuid-id-trait`:
        **eleven** copies of the id value-object shape (ADR-0009; plan Risk 4), Listing's
        `SellerId`/`CategoryId`/`AttributeId`/`AttributeOptionId` included; one trait, touching
        Identity, Catalog and Listing, its own mechanical all-green slice. ADR-0020 asked for it
        *before* `listing-publish` to stop the count growing; that slice added no copy, so it moved
        to just after.
        **Shipped (2026-11-21):** `App\Domain\Shared\ValueObject\UuidIdShape`, used by all eleven;
        `self` in a trait resolves to the using class, so every cross-type `equals()` is still a
        `TypeError` (`UuidIdShapeTest`, an 11-case ring). Each id keeps its own exception through a
        `malformed()` hook. The two challenge ids had drifted (no `D`) and now refuse a trailing
        newline — the one behaviour change. The nine DBAL id types are deferred until a fix would
        have to be made nine times. ADR-0009 amendment; no new ADR. 1746 → 1759 tests.
  - [x] `listing-schema-drift` (2026-11-21; **passed `/verify` 2026-11-22 first time** — 3 MINORs fixed anyway, chiefly `keep` built from choice values only; six hand-checks in Chromium green) *(taken before the gate)* — replaces slice 2's
        refusals with ADR-0004's real rules: soft-delete attributes/options (`archived_at`, hidden
        from forms, values retained — and decide whether an archived code still blocks C-21), and a
        move that is allowed, retaining values whose attribute left the schema but hiding them.
        **Shipped:** archive/restore as transitions on `Attribute` and `AttributeOption` (C-28),
        separate from hard delete, which survives for unused rows (C-25/C-26 now guard only that, and
        count dormant values). **An archived name stays reserved** (C-29) — so it *does* still block
        C-21 and C-17, and restore takes no facts. C-18 counts active options
        (`countActiveForAttribute()`). `schemaOf()` and `effectiveFor()` are active-only;
        `optionLabels()` is not. A value whose attribute is not in force is **dormant** (L-17: kept by
        revise, dropped by re-file, read by no rule, never stored as a flag); one naming an archived
        option is **stale** (shown, refused at the next write and at publish — L-14 narrowed).
        **C-27 retired** with `CategoryUsage::hasAttributeValues()`. Migration: two nullable
        `timestamptz(0)` columns, no backfill, no index. Seller form shows a stale option selected as
        "… (no longer offered)".
        [ADR-0022](./adr/0022-schema-drift-archive-reserved-names-dormant-values.md)
        + ADR-0004/0016/0017/0018/0020/0021 amendments. All fifteen AC-23 mutations bit. 1759 → 1883 tests.
  - [x] `phase1-gate` *(2026-11-22: `SchemaMutationGateTest` + the restore rehearsal, logged in
        docs/infrastructure.md §*Rehearsal log*)* — the Schema-Mutation PoC as a test (define "Fretboard Wood" through the admin
        handlers → it renders on the draft form → a facet count `GROUP BY option_id` over the value
        layer returns it, with `EXPLAIN` on the generic index, existing rows untouched) **plus** the
        restore-from-backup rehearsal on the box. **The facet count must speak "active"**
        (`listing-schema-drift` plan Risk 3, ADR-0022): scope it to the browsed subtree and
        `status = 'published'`, offer only active-schema attributes, and join options
        `AND archived_at IS NULL` — a bare `GROUP BY option_id` counts dormant values, stale values and
        archived attributes. The `EXPLAIN` is taken on **that** query.
      **Dropped from this item: the hot-column promotion mechanism.** ADR-0004 says start with zero
      promoted columns and promote only when the Phase 2 benchmark demands it; a mapping with zero
      entries is scaffolding. It moves to the Phase 2 latency gate, where the measurement lives.
- **Gate — Schema-Mutation PoC** (PRD validation #2): add a new attribute (e.g. "Fretboard Wood") via
      the admin UI and confirm it propagates to the dynamic form-render path and to a facet query
      **without corrupting existing listing rows**. Restore-from-backup rehearsed once.
      **MET 2026-11-22** — `SchemaMutationGateTest` (ADR-0004 amendment 2026-11-22) and the restore
      rehearsal on the box (pg_restore exit 0, counts identical, both schema checks exit 0).

## Phase 2 — Faceted search & listing marketplace *(PRD Phase 2)*

**Sliced 2026-11-22.** Same rule as Phase 1's Listing slices: **prod is consistent after each deploy
on its own** — no port without a consumer (a `SearchPort` with no page is scaffolding, `phase1-gate`
Q4's verdict on API keys), no mail without the readiness check that notices it is not leaving, no
public state that a later slice must repair. Order is by dependency; the wizard (6) and the contact
slices (7–9) have none on search and may be pulled forward. `/` stays the coming-soon stub throughout —
launch is not a Phase 2 item.

- [ ] `Search` context — **too big for one cycle, sliced:**
  - [x] 1. `search-browse` — the `Search` context's first slice: `SearchPort` + `FacetedQuery` VO (in
        Search's own words — local id copies, ADR-0019) + the Postgres adapter, for **category subtree +
        choice and boolean facets + counts + paging, newest first**, published listings only. Consumer:
        a minimal, unstyled, JS-free `GET` browse page (facets as a plain form) — the page is what
        makes the port deployable. **Carry-overs from `phase1-gate`:** (1) lift
        `SchemaMutationGateTest::FACET_COUNT` **and its AC-6…AC-11 fixture** into the adapter — the
        fixture is what proves the four "active" clauses (subtree, published, active-schema
        attribute, `archived_at IS NULL` option); (2) **decide how `Search` reads Catalog's and
        Listing's tables** — a read-only projection context (ADR-0019 amendment: the fifth crossing,
        formalised) or composition through ports with id lists. New ADR. `FacetedQuery` parsing is a
        trust boundary: unknown attribute/option ids are dropped, never a 500.
  - [x] 2. `search-range-facets` (2026-11-23) — number attributes as min/max ranges (against `NumberRules`), and
        **price**. Owes the decision `listing-draft` deferred: **price across currencies** (filter and
        sort only within one chosen currency, or convert — and from what rate source). Owner decision
        in the plan, not in the adapter.
        **Shipped:** number facets with unit, count and observed range (disjunctive, in the existing
        count statement); `r[<id>][min|max]` ranges, swapped when inverted; **S-12** — `NumberRules`
        never clamp a read; price **within one chosen currency** (ADR-0024, option A), whole units →
        minor units in the Domain; Search-local `Currency` mirror + `CurrencyMirrorTest`. `UAH` left
        Listing's allow-list (owner), sell default `EUR`, pre-deploy row check. All 23 AC-25
        mutations bit; 2158 tests. ADR-0024 + ADR-0019/0023 amendments.
  - [x] 3. `search-full-text` — FTS + `pg_trgm` on title/description (ADR-0002): `unaccent` on
        **both** sides via one SQL function used by the write and the read, the **tsquery sanitizer**
        (strips every operator, `:*` on the last token only — security-critical, Constitution §8),
        trigram fallback only when FTS returns too few rows, RRF if ranks combine. Migration adds the
        extensions — `muzbar_test` and the box both, so check the `postgres:16-alpine` contrib set and
        the migration role's rights before planning.
        **Shipped:** `q` on `/browse` — a Search-owned `search_listing_text` kept by a trigger on
        `listing_listing` (ADR-0025, replaces ADR-0023 fence 5), one fold (`search_fold()`) for write
        and read, the query built by `plainto_tsquery` inside `search_tsquery()` (a PHP-sanitize-then-
        `to_tsquery` design was measured injectable: `unaccent` turns `ʼ` into `'`), `simple`,
        trigram on the title only when the exact match finds nothing, newest first, no RRF. All 21
        AC-21 mutations bit (three after strengthening — (j)'s union form at `/verify`); 2323 tests. ADR-0025 + ADR-0002/0009/0023
        amendments.
  - [x] 4. `search-benchmark` — **taken early on purpose**, before any UI is built on the query: a
        seeding command (10k+ listings over a realistic tree and Brand-sized option lists), `ANALYZE`,
        `EXPLAIN` without planner switches (carry-over 3), and a 50-concurrent load run. **Index tuning
        and hot-column promotion (ADR-0004) happen here or not at all.** The phase gate re-runs it on
        the final query; this slice exists so the gate is a confirmation, not a discovery.
        **Carry-over from `search-browse`'s `/verify` (2026-11-22): `/browse` has no rate limit.**
        Statements are bounded (≤ 5 + k, k ≤ 20) but cost is not — 20 selected facets at the root
        category is up to 25 statements, each count joining every published listing with up to 19
        `EXISTS`. Measure that worst case under the load run; if it hurts, add a per-IP limiter on
        the `cache.rate_limiter` pool (and to `ClearsRateLimiters`' list), or decide it is fine.
        **Done 2026-11-25** ([ADR-0026](./adr/0026-search-latency-proven-on-a-seeded-benchmark-database-on-the-box.md)). Box, 10 000 published: service p95 40–102 ms
        (B1–B8); 50 closed-loop clients **FAIL, CPU-bound** (p95 0.6–1.4 s at ~85 % of what 4 cores
        serve; slowest statement 57.5 ms; a 12-child FPM pool was 2 % slower). Owner re-read the
        budget as **10 req/s arriving** (ADR-0026 amendment). **AC-10 deferred:** the 10 req/s box
        re-run has not been made — due before `search-ui` (7), at the latest `phase2-gate` (15). No index, no
        promoted column. B9 (20 selections) is planner-bound — 1.4 s in `bind` — so **`/browse` is
        rate-limited, 30/min per IP**.
- [x] 5. `public-styles` *(before the public search UI — `listing-publish` plan Q7)* — the public SCSS
      pipeline (ADR-0006): `sass-bundle`, `public.css`, tokens, and the **first public layout** — which
      is also where the **Umami snippet** finally lands (Phase 0 left it with nowhere to live). The sell
      pages, the listing page and the browse page ship as unstyled semantic markup with stable class
      names until then, so styling is purely additive.
      **Done 2026-11-26.** sass-bundle (Dart Sass 1.69.7), `public.scss` + plain-CSS `tokens.css`,
      `base.html.twig` as the public layout, `admin/layout.html.twig` opting admin out, Umami behind
      two optional env vars (ADR-0003/0006 amendments). Found on the way: **prod had never served an
      asset** (the image never ran `asset-map:compile`) — the image now compiles and asserts, and
      the deploy smoke check fetches the stylesheet. Not done here: admin styling (below).
- [x] 6. `listing-wizard` — morphing single-page listing form as a **Symfony UX Live Component**:
      selecting a category reshapes the lower form (parametric dropdowns) without a reload or data loss
      (US-1, UX §). Replaces the server-rendered Details section; the POST still re-reads
      `schemaOf()` under `holdSteady()` and a drift only refuses (ADR-0019). Dependent options (D14,
      `parent_option_id`) are **not** in scope unless the owner pulls them in.
      **Done 2026-11-27** ([ADR-0027](./adr/0027-symfony-ux-under-assetmapper-live-component-renders-controller-writes.md)
      + ADR-0019/0020/0021 amendments). `ListingForm` (symfony/ux-live-component 3.8.1, Stimulus
      3.2.2, live controller `fetch: lazy`) **renders and never writes** — no `#[LiveAction]`, a scan
      proves it; every save is still the page's JS-free POST. **L-7 amended:** `draft()` reads details
      at birth by L-9, so `/sell` can draft, fill and publish in one transaction. A hidden
      `details_for` tells the POST which category the Details on the page were rendered for; it
      publishes only when that equals the submitted category. The image `ls`-asserts the Stimulus and
      live-controller files and `deploy.yml` fetches the live controller. Dependent options (D14)
      still not in scope.
- [ ] 7. `search-ui` — the public faceted search UI over slices 1–3 (US-2, FR-3): category-driven
      facets that update with the selection, counts, ranges, text box, 0-result state. Progressive
      enhancement of slice 1's `GET` form (Live Component or Turbo Frame — the plan decides); the URL
      stays the query, so a search is a link. Leaves a **slot** on the 0-result page for slice 12.
- [ ] Seller contact channels *(before public launch — `listing-publish` plan Q4)* — the public listing
      page shows **no** seller data and no way to reach the seller. PRD §6: "explicitly preferred
      external channels" without exposing the email (Constitution §8). Each slice reverses
      `listing-publish`'s "the public read never selects `seller_id`" **for one named field only** —
      an ADR-0021 amendment, and `PublicListingTest`'s SQL assertion widened by exactly that field.
  - [ ] 8. `seller-contact-handles` — phone and Telegram handle on the seller's profile (real content
        for the `/profile` placeholder, ADR-0015), opted in per channel, shown on their published
        listings. Validated as VOs (a Telegram handle is not free text).
  - [ ] 9. `ops-queue-age-readiness` *(devops; before the first new Phase 2 mail)* — teach
        `/health/ready` the age of the oldest `messenger_messages` row and the `failed` count, so a
        stopped worker stops looking healthy (infrastructure.md footgun #7, Phase 0 carry-over 2's
        remainder). Phase 2 triples the mail kinds; this is the cheapest moment to stop being blind.
        Also owns the decision on carry-over 4 (`messenger_messages` / `failed` growth).
  - [ ] 10. `seller-contact-relay` — a "message the seller" form that mails through the relay;
        neither side sees the other's address until the seller replies. Signed-in senders only
        (overlay trigger, ADR-0015 shape), rate-limited per sender and per listing (one more
        `cache.rate_limiter` limiter → `ClearsRateLimiters`), body is a free-text VO (`\p{Cc}` rule).
- [ ] 30-day ad lifecycle (PRD §6):
  - [ ] 11. `listing-expiry` — `expires_at` on publish (+30 days), **hidden from the public page and
        search the moment it passes**, renew from "Your listings" (+30 days). Plan question to answer
        first: is "expired" a **predicate** (`expires_at > now` in the public reads — no job can be
        late, the `required`-is-never-retroactive argument again) or an L-12 **state** that a job
        writes? Lean: predicate. **Backfill:** listings already published get `expires_at` = deploy
        time + 30 days, not `published_at` + 30 (which would hide them on deploy).
  - [ ] 12. `listing-renewal-reminder` — the 3-day-prior warning mail with a renewal link: the
        slice's recurring work **re-opens ADR-0012 decision 5** (host cron vs Symfony Scheduler —
        Constitution §3's parenthetical scopes Scheduler to exactly this feature, and the worker-image
        gap that argued against it was fixed 2026-08-02). New ADR either way. **The renewal link's GET
        must not renew** — ADR-0011 (mail scanners prefetch links) and ADR-0015's accepted risk (no GET
        may change state for the signed-in user) — so "1-click" is a token link to a page with one
        button. Owner sign-off on that wording against PRD §6. Idempotent per listing per period.
- [ ] "Subscribe to this Search" (US-5, FR-6) — the `Notification` context:
  - [ ] 13. `search-alert-subscribe` — `SearchAlert` aggregate: the facet params in Notification's
        own words + the subscriber's `UserId` (a signed-in, therefore verified, user — the PRD's
        overlay trigger), created from the 0-result slot; list and cancel own alerts; a one-click
        unsubscribe token (needed for slice 14's `List-Unsubscribe` and the spam gate). An alert
        whose attribute or option is later archived is a stale alert — decide: kept dormant, like
        L-17, or pruned.
  - [ ] 14. `search-alert-match` — **the first listener on `ListingPublished`**: evaluates the new
        listing against active alerts (reusing `SearchPort` with an id filter — one definition of
        "matches", never a second one in PHP) and queues one mail per match. Re-opens ADR-0021 d.5's
        accepted loss (a crash between commit and dispatch drops the event — accepted because there
        was **no listener**; there now is) and ADR-0010's outbox trigger. Decide whether a renewal
        (slice 11/12) or re-publish of a withdrawn listing re-fires alerts.
- [ ] 15. `phase2-gate` — both gates below, as one proof slice like `phase1-gate`: re-run slice 4's
      benchmark on the final query; send renewal and alert mail through the production relay to
      Gmail/Outlook/Yahoo test inboxes with SPF/DKIM/DMARC aligned, logged in docs/infrastructure.md.
- [ ] `admin-styles` *(unnumbered; whenever admin's unstyled pages start to hurt)* — Tailwind via
      `symfonycasts/tailwind-bundle`, `admin.css` on `admin/layout.html.twig`, theme read from
      `tokens.css` (ADR-0006 and its 2026-11-26 amendment). Not a gate item.
- **Gate — Search Latency Benchmark** (PRD metric + validation #1): seed 10k+ mock listings; prove
      **p95 < 200 ms per scenario at 10 req/s arriving** (ADR-0026 as amended 2026-11-25), with the
      plans PostgreSQL *chooses* recorded as evidence — a seq scan is recorded with its reason, not
      "fixed" (ADR-0026 d.3; this replaces "every facet hits an index"). **Gate —
      Spam-Filter Clearance** (validation #3): renewal/alert emails clear major inbox filters via the
      external SMTP relay.

## Phase 3 — Directory, monetization & API sync *(PRD Phase 3)*

- [ ] `Directory` context: `ServiceProvider` aggregate with coordinates, equipment lists, room specs;
      Leaflet/OSM map with responsive, swipeable cards centering on the selected pin (US-3, FR-4).
- [ ] `Billing` context: Stripe subscriptions + webhook processing toggling the "Featured" flag on
      listings/profiles on verified payment (US-4, FR-5) — behind `PaymentPort`.
- [ ] API-key issuance (hashed, revocable) — beside the endpoints that consume it *(moved from
      Phase 1 by `phase1-gate` plan Q4, 2026-11-22: model-only with no consumer was scaffolding)*.
- [ ] Commercial API: authenticated `/api/*` endpoints (API-key auth) for inventory sync (FR-2).
      Consider API Platform here, behind the same ports (ADR-0001).
- **Gate — Monetization loop end-to-end:** a business user purchases a featured tier via Stripe test
      mode, the webhook flips the flag, and the featured listing ranks first in local search. Alpha
      telemetry begins feeding the MRR-vs-cost and completion-rate baselines (PRD weak-spot #1).

---

## Last — deferred operational activations

Work that is **built, tested and merged, but deliberately not switched on**. Each item is here because
turning it on early buys nothing and costs attention; each is also a written promise the system is not
yet keeping, which is the reason they get their own section instead of a line in a runbook.

- [ ] **Enable the `identity-challenge-pruning` cron.** *Deferred 2026-08-02, by decision — to the very
      end, once everything else is done.* The `muzbar:identity:prune-challenges` command shipped with
      slice 4 (merged to `main` in #13) and is fully tested, but **nothing invokes it on the box**: the
      crontab line in [docs/infrastructure.md](./infrastructure.md) is documented and not installed.
      Deferring is reasonable — the retention windows are 7 and 30 days, the tables gain a few rows per
      registration, and there is no production traffic yet, so the backlog cannot become interesting on
      the timescale of the remaining phases.
      **What it means in the meantime, stated plainly so nobody has to infer it:** ADR-0012 records a
      retention policy that **is not in force**. `jobs.challenge_pruning.stale` in `/health/ready` will
      read `true` permanently and `last_run` will be absent — so the one signal built to mean *"the
      pruner stopped"* currently means *"the pruner never started"*, and anyone reading that endpoint
      before this item is ticked will be reading a false alarm. That is the price of the deferral and
      it is worth paying; it is not worth forgetting.
      When it is enabled, follow the first-run procedure rather than just adding the line: the first
      real run is the only one that can meet an unbounded backlog, so rehearse with `--dry-run`, then
      an explicit small `--limit`, then let cron take it.

## Cross-cutting, every phase

Security (email isolation, input validation, authz on mutations), accessibility/responsive UI, and
**FORboehpyk.md** kept current with the bugs hit and lessons learned. GDPR review before public
launch, including a **privacy-policy page that names Umami** (ADR-0003 amendment 2026-11-26). Revisit Meilisearch **only** if the Phase 2 benchmark fails on real data.
