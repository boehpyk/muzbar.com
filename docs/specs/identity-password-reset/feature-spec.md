# Feature Spec: identity-password-reset

> The *what & why*. Disposable — this dies once the feature ships and its behaviour lives in tests +
> code. Make it **executable** (measurable, enumerated), not pretty. Must not contradict the
> [Constitution](../../constitution.md) or an accepted ADR.

- **Feature:** `identity-password-reset` — slice **3 of 5** of the `Identity` context
- **Bounded context(s):** `Identity` only. `Notification` is **not** touched — same seam as slice 2.
- **Related PRD items:** US-1 (the *"quickly authenticate"* half), PRD §3 Role-Based Access,
  Constitution §8 (email isolation, anti-abuse)
- **Governing ADRs:** [ADR-0005](../../adr/0005-auth-oauth2-google-plus-api-keys.md) (and its two
  2026-07-26 amendments), [ADR-0007](../../adr/0007-persistence-conventions-for-domain-aggregates.md),
  [ADR-0008](../../adr/0008-domain-events-recorded-on-the-aggregate.md),
  [ADR-0009](../../adr/0009-email-verification-tokens-modelled-in-the-domain.md) — **especially its
  decision 5**, which was written for this slice — and
  [ADR-0010](../../adr/0010-event-delivery-and-transactional-mail.md)
- **Status:** **Approved 2026-07-28.** All eleven decisions in the
  [technical plan](./technical-plan.md) §*Decisions needing sign-off* were signed off **as
  recommended** — including decision 1 (in-domain aggregate, bundle not installed), decision 4
  (reset implies verified) and decision 5 (session stash, token never in a URL). **T0 (write
  ADR-0011) is unblocked and is the first commit.**
- **Date:** 2026-07-28

> **This slice contradicts the roadmap on purpose, and that contradiction is the first thing to
> resolve.**
>
> [`docs/roadmap.md` line 97](../../roadmap.md) says this slice is a
> "`symfonycasts/reset-password-bundle` flow". This spec proposes **not installing that bundle** and
> modelling the challenge as the `Identity` context's **third aggregate**, `PasswordResetRequest`.
> The argument is made in full in the technical plan — including the honest counter-arguments, and
> including the fact that ADR-0009's headline reason for rejecting the *sibling* bundle
> (statelessness) **does not transfer**, because `reset-password-bundle` is genuinely stateful. The
> real conflicts are different ones, and they are structural.
>
> **This needs a new ADR (ADR-0011), a dated amendment to ADR-0005, a dated amendment to ADR-0009,
> and a correction to roadmap line 97.** See *Risks / open questions* in the technical plan.

> **What this slice is for, beyond the feature.**
>
> Slice 1 taught *an aggregate, a value object, a port*. Slice 2 taught *a second aggregate,
> referenced by identity, and the eventual consistency that buys and costs*. This slice teaches the
> thing neither could: **the same shape with the opposite semantics.** `PasswordResetRequest` looks
> line-for-line like `EmailVerificationRequest` and behaves differently in four places that matter —
> replay is refused instead of welcomed, reissue revokes instead of coexisting, the two saves run in
> the *opposite* order, and the lifetime is 1/24 as long. Every one of those inversions is forced by
> the fact that the effect is **destructive and repeatable**, which is exactly what ADR-0009 decision
> 5 wrote down in advance so that this slice would not copy the wrong precedent.
>
> It is therefore also the slice where the temptation to extract a shared `Challenge` base class is
> strongest and most wrong. That question is answered explicitly rather than avoided — see the
> technical plan, §*Reuse vs duplication*.

---

## Ubiquitous language (this slice)

| Term | Means | Not to be confused with |
|---|---|---|
| **PasswordResetRequest** | The **third aggregate root** in `Identity`: one issued challenge that, if answered, lets somebody set a new password on one `User` without knowing the old one. Holds the hash of a token, when it was issued, when it expires, whether it was redeemed, and whether it was invalidated. References its user **by `UserId`**, never by object. | `EmailVerificationRequest` — the same *shape*, deliberately different *semantics* (see the four inversions above). The `User` aggregate. |
| **ResetToken** | Value object: the **plaintext** secret — 32 CSPRNG bytes, base64url, 43 URL-safe characters. Exists only in memory, from the generator to the mail body and from the URL to the hasher. **Never persisted, never logged, never carried in a domain event.** | `HashedResetToken`. `VerificationToken` — same policy, different concept, different type, so one can never be presented for the other. |
| **HashedResetToken** | Value object: the **opaque** digest that is stored and compared in constant time. The Domain never learns which algorithm produced it. | `HashedPassword`. The short name is deliberate: `HashedPasswordResetToken` reads, at a glance, like a variant of `HashedPassword`, and those are the two things in this context that must never be confused. |
| **Issue** | Minting a token and creating a `PasswordResetRequest` for a user. Raises `PasswordResetRequested`. **Invalidates every outstanding request that user already had.** | "Send" — sending is the consequence, behind the `PasswordResetMailer` port. |
| **Redeem** | Presenting a valid, live token **together with a new password**, which burns the challenge (`redeemedAt`) *and* changes the password. Same verb as `EmailVerificationRequest::redeem()`, on purpose: one word per act across the context. | "Following the link" — following the link only *checks* the token; it mutates nothing. Redemption happens on the **POST**, never on the GET. |
| **Invalidate** | Killing a live request without redeeming it, because a newer one superseded it. Sets `invalidatedAt`. Raises nothing. | "Redeem" — a redeemed request was used; an invalidated one never will be. A request is never both (I-17). |
| **Outstanding** | A request that is neither redeemed nor invalidated. It may still be *expired* — expiry is a judgement about the clock, made by the aggregate, not a column the repository filters on. | "Live" — a **live** request is outstanding **and** unexpired **and** not stale. |
| **Reset link** | The absolute URL `…/reset-password/{token}` carrying the plaintext token in the path. Built by the Infrastructure adapter; the Domain never sees a URL. | The `ResetToken` itself. |
| **Stale request** | A request issued strictly *before* the user's `passwordChangedAt`. Refused even if the row still looks live — the defence that closes the crash window between this use case's two saves. | An **expired** request (past `expiresAt`) or an **invalidated** one (superseded). Three different reasons, one identical answer to the visitor. |
| **Neutral response** | The single reply `/forgot-password` gives to *every* well-formed submission — known account, unknown address, over the per-user cap, address in the validator gap. The anti-enumeration contract. | A "success message": it deliberately asserts nothing about the address. |
| **Invalid-link response** | The single reply every failure of the reset-link routes gives — malformed, unknown, expired, invalidated, already redeemed, stale, mismatched, dangling user, no session. Nine internal causes, one external answer. | A 404 or an error page. It is a 302 to `/forgot-password`, because the only useful thing to offer somebody holding a dead link is the form that mints a live one. |

---

## User story

As a **musician who has forgotten the password to their muzbar account**, I want to **prove I still
read the address I signed up with and then choose a new password**, so that **losing a password does
not mean losing my listings** — and so that the recovery path itself is not the way somebody else
takes my account.

And, as **muzbar**, I want **a successful reset to end every session that account already had**, so
that **a person who resets their password because they believe it was stolen is actually right.** A
reset that leaves the attacker logged in is a reset that only *feels* like a remedy.

Secondary and ranked (Constitution §2): as the **developer**, I want this slice to teach the third
DDD lesson honestly — **that two aggregates with the same shape can carry opposite rules**, and that
the reflex to extract the shared shape is the mistake rather than the insight.

---

## In scope

**Domain (`Identity`)**

- The `PasswordResetRequest` **aggregate root**, its invariants, and its lifetime/anti-abuse policy
  constants.
- Value objects: `PasswordResetRequestId`, `ResetToken` (plaintext, transient), `HashedResetToken`
  (opaque, stored, constant-time `equals()`).
- Domain events `PasswordResetRequested` and `UserPasswordChanged` — **neither carrying a secret**.
- Ports: `PasswordResetRequestRepository`, `ResetTokenGenerator`, `PasswordResetMailer`.
- Domain exceptions for every failure this flow can produce.
- **A minimal change to the `User` aggregate** — the first since slice 1: one new method
  (`changePassword()`), one new property (`passwordChangedAt`), one reader, one recorded event.
  The `PasswordHasher` port is reused **unchanged**, exactly as its own docblock predicted
  (*"When `identity-password-reset` needs to re-hash, it reuses `hash()`. Nothing new is required."*).

**Application**

- `RequestPasswordReset` command + handler (used by the public form only).
- `CheckPasswordResetToken` query + handler — validates a token and **mutates nothing**, so a mail
  scanner's prefetch cannot burn a link.
- `ResetPasswordWithToken` command + handler.
- The existing `RegisterUser`, `VerifyUserEmail`, `RequestEmailVerification` and
  `VerifyEmailWithToken` use cases are **untouched**.

**Infrastructure**

- Doctrine adapter + XML mapping + **one migration** creating `identity_password_reset_request`
  **and** adding `identity_user.password_changed_at`.
- `RandomResetTokenGenerator`, `TwigPasswordResetMailer` + two mail templates.
- Routes, controllers, forms and templates: the forgot-password form, the "check your inbox" page,
  the link-check hop, and the new-password form.
- **Two new rate limiters** over the existing Redis-backed `cache.rate_limiter` pool — bringing that
  pool to four.
- **No new environment variable, no new Compose service, no new Composer dependency.** Slice 2 built
  the mail and queue infrastructure; this slice consumes it. Stated explicitly because ADR-0010's
  amendment makes "a new required boot-path env var" a four-place change — `app`,
  `messenger-worker`, CI and the image build — and the cheapest way to get that right is not to add
  one.

---

## Non-goals (explicit — hold the line)

| Deliberately excluded | Belongs to |
|---|---|
| An authenticated **"change my password"** screen — a different use case with a different precondition (knowing the current password) and no mailbox proof | later Identity work |
| A **"your password was changed"** security-notification email | a follow-up. `UserPasswordChanged` ships here as the hook, with no listener — see AC-33 and the technical plan's argument for keeping an unlistened event |
| Google OAuth2, `OAuthIdentity`, account linking, and what a reset means for an OAuth-only account | `identity-google-oauth` |
| The login/register **overlay** Live Component and the intended-action redirect | `identity-login-overlay` |
| **"Remember me"** — the firewall configures none today, so there is no persistent cookie to invalidate. The technical plan records the one thing (`signature_properties` must keep `password`) that whoever adds it must not remove | `identity-login-overlay` or later |
| Two-factor auth, security questions, recovery codes, admin-initiated resets, account lockout on repeated resets | not Phase 1 |
| A **pruning job** for expired / redeemed / invalidated challenge rows | **still owed, and now owed by two tables.** This slice adds to that debt rather than paying it — see *Risks* and the proposed `identity-challenge-pruning` slice |
| Rendering the account's email address on the reset page (a mild UX loss, accepted — the page stays static and address-free) | — |
| Mailing "there is no account here" to unknown addresses | never. It is a spam primitive fired on an attacker's command, and it confirms nothing the sender did not already supply |
| **Visual design.** The new pages render in the same unstyled skeleton as slices 1–2; the mail templates ship plain-text-first, because mail clients are not browsers | ADR-0006's pipelines, with the first real layout |
| **Sentry and the Claude Code hooks** — still the two roadmap carry-overs, still `devops` work | flagged again in *Risks*: this slice adds a second silent async mail path *and* the most-attacked endpoint in the system |

---

## Acceptance criteria (the Definition of Done checklist)

Enumerated, measurable, each independently checkable. These are what `/verify` checks off.
**Values are pinned here on purpose** — 1 hour, 3 issuances per user per hour, 5 POSTs per IP per
hour, 10 token-route requests per IP per hour, a 12-character minimum. The technical plan argues each
number; none of them may be changed here without changing it there.

### Requesting a reset — `/forgot-password`

- [ ] **AC-1:** `GET /forgot-password` returns 200 with a single `email` field and a CSRF token.
- [ ] **AC-2:** A successful request creates **exactly one** row in
      `identity_password_reset_request` with: `user_id` = the account's id; `token_hash` a
      64-character lower-case hex string; `issued_at` = the `Clock`'s value; `expires_at` =
      `issued_at` + **3600 s** exactly; `redeemed_at` = `NULL`; `invalidated_at` = `NULL`.
- [ ] **AC-3:** Exactly one email is sent per issued request, addressed to the account's own address,
      containing an absolute URL of the form `{scheme}://{host}/reset-password/{token}` where
      `{token}` is 43 URL-safe characters, stating the **one-hour** expiry both in human terms and as
      an absolute instant, and carrying an *"if you did not request this, your password has not been
      changed"* line.
- [ ] **AC-4:** The email's `From` is the configured no-reply sender, and its body renders no address
      other than the recipient's own (Constitution §8).
- [ ] **AC-5 (no user enumeration):** `POST /forgot-password` returns a **byte-identical** response
      for (a) an account that exists, (b) an address no account holds, (c) an account already at the
      per-user hourly cap, and (d) a well-formed address that the `Email` value object rejects (the
      strict-validator gap). The test asserts the four responses **against each other**, captured in
      the same run — not against four copies of a literal, which can drift apart while all four stay
      green. Only case (a) produces a row or an email.
- [ ] **AC-6 (single exit, structurally):** those four outcomes and the success case leave the
      controller through **one** `return`, reached by falling out of a `try` rather than by a
      `return` inside it — so there is one response to keep identical rather than five to keep in
      step. Checked by reading the method.
- [ ] **AC-7 (domain rate limit):** the **4th** issuance for the same user inside a rolling hour is
      refused by the Application handler (`MAX_ISSUES_PER_HOUR = 3`) — no row, no email, **and no
      invalidation of the request that already exists** — while the HTTP response stays the neutral
      one from AC-5.
- [ ] **AC-8 (boundary rate limit):** `/forgot-password` is additionally limited to **5 POSTs per
      hour per client IP**; the 6th is refused with HTTP **429** *before the controller body runs*,
      carrying a `Retry-After` header. Counters live in the Redis-backed `cache.rate_limiter` pool,
      never on the container filesystem.
- [ ] **AC-9 (the inversion of ADR-0009 decision 5):** issuing a request **invalidates every
      outstanding request that user already had.** The previous row's `invalidated_at` is set to the
      `Clock`'s value, and following its link now produces the invalid-link response. At most one
      live reset request exists per account at any moment.
- [ ] **AC-10:** The plaintext token appears in **no** database column, **no** log line, **no**
      exception message and **no** domain event. Asserted by (a) a test that scans every column of
      the request row for the plaintext and (b) `ResetToken::__debugInfo()` masking the value while
      the class exposes no `__toString()` — the absence verified by reflection.
- [ ] **AC-11:** The absolute URL is built from the configured base URI and is correct when the mail
      is rendered **outside an HTTP request** (Messenger worker / CLI) — not `http://localhost`.
      Asserted by rendering the mail with no request context. *`DEFAULT_URI` is already set per
      environment by slice 2; this AC exists because no functional test can catch a regression here,
      and the failure is total in production and invisible everywhere else.*

### Following the link — `/reset-password/{token}` then `/reset-password`

- [ ] **AC-12 (the prefetch guarantee — the important one):** `GET /reset-password/{token}` with a
      live token **mutates nothing**: `redeemed_at` stays `NULL`, `invalidated_at` stays `NULL`, no
      event is dispatched, and the token remains redeemable afterwards. *Rationale: mail clients and
      corporate scanners fetch links before humans do. Slice 2 could afford redemption-on-GET because
      a replay there is a friendly no-op; here a replay must be refused (AC-18), so a GET that burnt
      the token would break the flow for every user behind a scanning gateway.*
- [ ] **AC-13 (the token leaves the URL):** that GET responds **302 to `/reset-password`** — a
      token-less path — having stashed the plaintext token in the session. The token therefore
      appears in the address bar, in browser history and in any outbound `Referer` for exactly one
      redirect hop, and in no page the user then sits on and types into.
- [ ] **AC-14:** `GET /reset-password` with a stashed live token returns 200 with a repeated password
      field and a CSRF token, and **the plaintext token appears nowhere in the response body, in no
      form field, and in no URL on the page**. Asserted by searching the rendered body for the token.
- [ ] **AC-15:** Every response from both reset-link routes — success and failure alike — carries
      `Referrer-Policy: no-referrer`. *A header present only on success is itself a signal.*
- [ ] **AC-16 (one answer for nine causes):** each of these produces a response **indistinguishable**
      from the others — same status, same `Location`, same flash string — and mutates nothing:
      (a) an **expired** token; (b) an **unknown** token (well-formed, never issued); (c) a
      **malformed** token (wrong length, non-base64url characters, empty, 10 kB of junk); (d) an
      **invalidated** (superseded) token; (e) an **already-redeemed** token; (f) a **stale** token
      (issued before the user's `password_changed_at`); (g) a token whose `user_id` matches no row;
      (h) `GET /reset-password` with **no** stashed token; (i) `POST /reset-password` with no stashed
      token. The test asserts them **against each other**, live.
- [ ] **AC-17:** Case (c) is refused by the `ResetToken` value object **before any database query** —
      no 500, no stack trace, no SQL in the output. The route requirement is `[^/]+` and does **no**
      format checking. *Slice 2 shipped that regex on the route, discovered it made the equivalent AC
      unsatisfiable, and removed it. Do not re-add it: every test using a well-formed token still
      passes with it in place, so nothing will tell you.*
- [ ] **AC-18 (replay is refused, and that is the inversion):** after a successful reset, presenting
      the same token again produces the invalid-link response and does **not** change the password a
      second time. Unlike `EmailVerificationRequest`, there is no friendly already-done branch —
      because the effect is destructive and repeatable, which is precisely what ADR-0009 decision 5
      told this slice not to copy.
- [ ] **AC-19 (boundary rate limit on the token routes):** the reset-link routes are limited to **10
      requests per hour per client IP**; the 11th is refused with HTTP 429. *The justification is not
      token brute force — a 256-bit token is not guessable — it is that an anonymous endpoint able to
      reach a deliberately slow password hasher is a CPU amplification primitive. Same argument
      `security.yaml` already makes for `login_throttling`.*

### Setting the new password — `POST /reset-password`

- [ ] **AC-20:** A valid submission changes `identity_user.password_hash`, sets
      `identity_user.password_changed_at` to the `Clock`'s value, sets `redeemed_at` on the request,
      clears the session-stashed token, and redirects 302 to `/login` with a success flash.
- [ ] **AC-21 (the credential actually changed):** after the reset, `POST /login` with the **new**
      password succeeds (302 to `/account`, session established) and `POST /login` with the **old**
      password fails with the ordinary *"Invalid credentials."* Both asserted through the real
      firewall, in one test, against the same account.
- [ ] **AC-22 (password strength is reused, not reinvented):** the new-password field carries exactly
      the constraints `RegistrationFormData` already carries — `NotBlank`,
      `Length(min: PlainPassword::MIN_LENGTH, max: PlainPassword::MAX_LENGTH)` with the constants
      **quoted, not retyped**, and `NotCompromisedPassword(skipOnError: true)` (already disabled
      under `APP_ENV=test` by `validator.yaml`) — and `PlainPassword::fromString()` remains the
      correctness gate behind them. **No new password policy is introduced anywhere in this slice.**
- [ ] **AC-23:** A submission failing password validation returns **422** with the field error and
      **does not burn the token**: `redeemed_at` stays `NULL` and the same session-stashed token
      still works on the next attempt. *A recovery flow that spends the user's one link on their own
      typo is a support ticket.*
- [ ] **AC-24 (session invalidation — the property the user story is about):** a session
      authenticated as that user **before** the reset is deauthenticated on its very next request:
      `GET /account` returns 302 to `/login`. Asserted end-to-end through the real firewall, **not**
      by reasoning about `AbstractToken::hasUserChanged()`. *The mechanism is free — the provider
      rebuilds `SecurityUser` from the database each request and the framework compares the stored
      password hash — but "free" and "asserted" are different words, and only one of them survives a
      refactor.*
- [ ] **AC-25:** The reset does **not** log the resetting browser in: after success, `GET /account`
      returns 302 to `/login`. *Auto-login stays `identity-login-overlay`'s decision to make, not a
      side effect smuggled in through a link that arrived by email.*
- [ ] **AC-26 (the stale-request guard):** a request whose `issued_at` is **strictly before** the
      user's `password_changed_at` is refused even when its row is otherwise live. Asserted at the
      handler level against a hand-built row. *This is what closes the crash window between the two
      saves; see the technical plan's ordering argument.*
- [ ] **AC-27:** Token comparison inside the aggregate uses `hash_equals`, asserted by a unit test
      proving a one-character-off token is refused.

### The cross-aggregate decision: what a reset means for an unverified account

- [ ] **AC-28:** An **unverified** account may request and complete a reset, and a successful reset
      **also sets `email_verified_at`** to the `Clock`'s value — so `User::isUsable()` becomes `true`
      and the user can sign in immediately with the new password, asserted by an actual `/login`
      POST rather than by reading the column. *Rationale: redeeming a link delivered to that mailbox
      proves exactly what a verification link proves. Mailing a second challenge to re-prove a fact
      we have just proved is incoherent — and refusing resets to unverified accounts would leave them
      with no path forward at all, unable to log in and unable to recover.*
- [ ] **AC-29:** That reset dispatches **both** `UserPasswordChanged` and `UserEmailVerified`,
      exactly once each.
- [ ] **AC-30:** A reset for an **already-verified** account dispatches `UserPasswordChanged` only;
      `email_verified_at` is unchanged, because `User::verifyEmail()` is idempotent (I-4) and records
      nothing on a repeat. Slice 1's aggregate design paying off again.
- [ ] **AC-31:** The verification side effect lives in the **Application handler for this use case**,
      not inside `User::changePassword()`. A future authenticated "change my password" screen must
      not verify an address — nothing about knowing your old password proves you read your mail.
      Checked by reading `User::changePassword()`, which must contain no reference to verification.

### Domain events

- [ ] **AC-32:** Issuing a request dispatches exactly one `PasswordResetRequested` carrying the
      request id, the user id, `issuedAt` and `expiresAt` — and **no token and no email address**.
- [ ] **AC-33:** A successful reset dispatches exactly one `UserPasswordChanged` carrying a `UserId`
      and an instant — and **no password, no plaintext, no hash**. It has no listener today, and that
      is recorded as a deliberate choice rather than an omission.
- [ ] **AC-34:** Invalidating superseded requests dispatches **nothing**. *Argued rather than
      assumed: "a link nobody will now click was quietly retired" is not a fact any listener needs,
      and emitting one event per superseded row would put N events on the bus for zero subscribers.*

### Security & privacy

- [ ] **AC-35:** The token is stored only as a digest; a dump of `identity_password_reset_request`
      contains nothing that can be replayed into a URL.
- [ ] **AC-36:** No response, page, flash or email in this slice renders an address other than the
      one the visitor supplied or the recipient's own (Constitution §8). In particular the "check
      your inbox" page and the new-password page are **static** and address-free.
- [ ] **AC-37 (the timing channel, honestly bounded):** the known-address path does strictly more
      work than the unknown-address path — a rate-limit count, an invalidation sweep, an insert and a
      queue insert — and that difference is **not** removed. It is *bounded* instead: AC-8's per-IP
      limiter caps an attacker at 5 probes per hour, orders of magnitude below what distinguishing a
      few milliseconds through internet jitter would require. **No timing assertion is written** — a
      test that measures wall-clock latency is either flaky or unfalsifiable, and this repository
      does not ship assertions that cannot fail. What is *asserted* is AC-5 and AC-6; what is
      *recorded* is this paragraph and the technical plan's rejected alternative.

### Architecture & quality gates

- [ ] **AC-38 (the bundle decision, made checkable):** `symfonycasts/reset-password-bundle` appears
      in neither `composer.json` nor `composer.lock`. Asserted by grep, so ADR-0011's decision cannot
      quietly erode into "somebody installed it to try something".
- [ ] **AC-39 (Domain purity):** `grep -rE '^use (Symfony|Doctrine)\\' src/src/Domain/` still returns
      nothing — the new aggregate, its value objects and its three ports contain no framework import
      — and Deptrac is green under `--fail-on-uncovered`.
- [ ] **AC-40:** `PasswordResetRequest` holds a `UserId`, **not** a `User`: no Doctrine association
      between the two aggregates, **no database foreign key** to `identity_user`, and no
      `$request->user()->…` traversal anywhere. Asserted by reading the XML mapping, by grep, and by
      the migration's DDL.
- [ ] **AC-41:** The new table follows ADR-0007 exactly — name `identity_password_reset_request`,
      every column named explicitly in XML, application-assigned UUIDv7 via `nextIdentity()` with
      `<generator strategy="NONE"/>`, `datetimetz_immutable` whole-second timestamps, value objects
      through custom DBAL types, and an adapter implementing the port without extending
      `ServiceEntityRepository`.
- [ ] **AC-42:** The migration is additive and reversible: it creates the new table **and** adds
      `identity_user.password_changed_at` as a nullable column (existing rows keep `NULL`);
      `migrate prev` reverses both cleanly, `identity_user` keeps its other six columns, and
      re-migrating succeeds. *This slice ships exactly one migration, so a single `prev` genuinely is
      a full rollback — unlike slice 2, where the last migration applied was Messenger's and rolling
      back "the new table" took two steps.*
- [ ] **AC-43 (index, not a latency budget):** `EXPLAIN` shows an **Index Scan** on
      `uniq_identity_password_reset_request_token_hash` for the lookup by digest, and on
      `idx_identity_password_reset_request_user_issued` for both the anti-abuse count and the
      outstanding-requests sweep. *The Constitution's < 200 ms budget governs faceted search
      (Phase 2) and does not apply to this slice.*
- [ ] **AC-44:** `make check` is green: php-cs-fixer clean, PHPStan **max** zero errors, Deptrac zero
      violations and zero uncovered, PHPUnit green with new Domain unit tests **and**
      Application/Functional tests.
- [ ] **AC-45 (the gate `make check` cannot give you):** during `/verify`, `docker compose ps` shows
      `messenger-worker` **Up, not Restarting**, and one real reset mail is drained into Mailpit with
      a working absolute link. *ADR-0010's 2026-07-28 amendment made this part of verifying any
      change that touches config or the boot path, after a session in which the worker crash-looped
      while all four gates reported green and `/health/ready` returned 200.*

---

## Failure contract

*"No mutation"* means no row written or changed and no domain event dispatched.

| Condition | Expected behaviour |
|---|---|
| Malformed address on `/forgot-password` (fails `Assert\Email`) | Form error, **422**, the visitor's input re-rendered beside it. No mutation. *Distinguishable from success, and that is fine: it is a statement about syntax the submitter already knows, not about who holds an account. Slice 2 made the same call on the same grounds.* |
| Well-formed address the `Email` value object rejects (the strict-validator gap) | `InvalidEmail` caught at the boundary → the neutral response. No mutation, no email. |
| `/forgot-password` for an address no account holds | Neutral response (AC-5). No mutation, no email. **Nothing whatsoever is mailed to that address.** |
| `/forgot-password` for an account with 3 requests in the last hour | `TooManyPasswordResetRequests` caught at the boundary → the neutral response. No mutation, no email, **and the account's existing live request is not invalidated** — otherwise an attacker could kill a victim's in-flight link just by spamming the form. |
| 6th `/forgot-password` POST from one IP within an hour | HTTP 429 with `Retry-After`, thrown before the controller body runs. No mutation. |
| Missing, stale or tampered CSRF token on either form | Form error *"The CSRF token is invalid. Please try to resubmit the form."*, 422. No mutation. |
| Malformed token in the reset URL (bad length / charset / empty / 10 kB) | `ResetToken` throws `InvalidResetToken`; the invalid-link response. No mutation, **no database query**. |
| Well-formed token that was never issued | Repository returns `null` → `PasswordResetRequestNotFound`; the invalid-link response. No mutation. |
| Expired token (`now > expires_at`) | `PasswordResetLinkExpired` from the aggregate; the invalid-link response. No mutation. |
| Invalidated (superseded) token | `PasswordResetLinkInvalidated` from the aggregate; the invalid-link response. No mutation. |
| Already-redeemed token (replay) | `PasswordResetLinkAlreadyUsed` from the aggregate; the invalid-link response. No mutation, no event. **Deliberately not the friendly branch `EmailVerificationRequest` has** — AC-18. |
| Stale token (issued before `password_changed_at`) | `StalePasswordResetRequest` from the Application handler; the invalid-link response. No mutation. Logged at **warning** — reachable only through a lost write or a concurrent reset, so it is neither routine nor impossible. |
| Token found but `hash_equals` disagrees | `PasswordResetTokenMismatch`; the invalid-link response. No mutation. Logged at **error** — the repository looked the row up *by* that digest, so a mismatch means a broken DBAL type or a broken query. |
| Token found but its `user_id` matches no row | Slice 1's `UserNotFound`; the invalid-link response. No mutation. Logged at **error**: there is no FK (ADR-0009 decision 4), so a dangling request means a user row was removed without cleanup — a fact the GDPR-erasure design must not rediscover in production. |
| `GET|POST /reset-password` with no stashed session token (fresh browser, expired session, back button after success) | The invalid-link response. No mutation. |
| New password fails `PlainPassword` or the form constraints | 422 with the field error; **the token is not burnt** (AC-23) and the session stash survives. No mutation. |
| 11th request per hour per IP on either reset-link route | HTTP 429 with `Retry-After`. No mutation. |
| Mail transport unreachable at request time | The neutral response is still returned and the row is committed; delivery is retried by the queue and, if retries are exhausted, lands in the failure transport. The user's recovery is to request again once the cap window rolls. **`/forgot-password` never 500s because of mail.** |
| Messenger worker not running | Mail queues and is delivered when the worker starts. **An operational failure that looks exactly like a healthy system** to `/health/ready`, which probes Postgres and Redis, not queue depth. Hence AC-45 and the runbook line. |
| Postgres unavailable | 500 from the framework error handler; one transaction, no partial write. `/health/ready` already reports it. |
| Redis unavailable | Sessions and all four rate limiters fail. No silent filesystem fallback (slice 1's decision, unchanged). **Note the new coupling: the reset flow stashes its token in the session, so Redis being down breaks the reset flow itself, not merely its throttling.** |
| A stored `token_hash` cannot be rehydrated into a valid value object | The DBAL type throws — loud failure, never a half-valid aggregate (slice 1's rule, unchanged). |
| Two concurrent resets for one account | Both may pass the outstanding sweep and both may redeem their own request; the second `changePassword` wins. Accepted: both are the same person holding two links mailed to the same box, and the `passwordChangedAt` guard makes the loser's later replay refused. Worst case is "your password is the one from the link you clicked second". |
