# Task List: identity-password-reset

> Ordered, small tasks in DDD canonical order. Each should be reviewable in < 5 minutes and is one
> commit on `feature/identity-password-reset`. Run `make check` before each commit. Check off as
> you go.

**UNBLOCKED 2026-07-28.** All eleven decisions in the [technical plan](./technical-plan.md)
§*Decisions needing sign-off* were accepted as recommended, with two conditions attached: `/verify`
runs `/security-review` as well as the standard reviewer pass, and decision 11 (no selector/verifier
split, no HMAC pepper) is flagged to the reviewer as the designated place to disagree.

**Rule for this slice:** tasks T1–T13 must not contain the strings `use Symfony\` or `use Doctrine\`.
`make deptrac` proves it under `--fail-on-uncovered`.

**Second rule, specific to this slice:** every place where this design *differs* from
`identity-email-verification` — the replay refusal, the reissue invalidation, the GET that mutates
nothing, and the inverted save order — must carry a comment at the call site saying **why it is
inverted**, not merely what it does. The two files will sit next to each other in the repository
forever, and a reader who notices the difference and finds no explanation will assume one of them is
a bug.

## Decisions & prerequisites

- [x] **T0:** Write **ADR-0011** with `/adr` — *"Password-reset challenges are modelled in the Domain"*
      — carrying the argument from the technical plan rather than re-deriving it, including: the
      honest case *for* `symfonycasts/reset-password-bundle` and why it still loses (ADR-0007 §7,
      ADR-0009 §4, Constitution §2); the 1-hour lifetime and 3/hour cap with their reasoning; the
      **inverted save order** and why the same rule from ADR-0009 §4 produces the opposite answer
      here; **reissue invalidates outstanding links**; **a successful reset verifies the email**; and
      the declined selector/verifier + pepper.
      Plus, in the same commit: a **dated amendment to ADR-0005** (its 2026-07-26 amendment left
      `reset-password-bundle` explicitly open — close it), a **dated amendment to ADR-0009**
      (decision 5's forward-looking clause is discharged; and its "revisit the shared UUID VO at the
      third example" consequence is restated as a criterion rather than a headcount), and a
      **correction to `docs/roadmap.md` line 97**.
      **No code in this commit.**
- [x] **T1 (checkpoint, not a change):** confirm this slice needs **no new Composer package, no new
      env var, no new Compose service, no new Messenger transport**. Record it in the commit message.
      *Stated as a task because ADR-0010's amendment makes a new required boot-path env var a
      four-place change — `app`, `messenger-worker`, CI, image build — and the way that bites is by
      being added without anyone noticing it was added.*

## Domain — value objects

- [x] **T2:** `ValueObject/ResetToken` + `Exception/InvalidResetToken`. Exactly 43 characters of
      `[A-Za-z0-9_-]`, case-sensitive, not trimmed; `#[\SensitiveParameter]` on constructor and
      factory; `__debugInfo()` masking; a single `reveal()`; deliberately **no** `__toString()`.
      **(AC-10, AC-17)**
- [x] **T3:** `ValueObject/HashedResetToken` + `Exception/InvalidHashedResetToken`. Opaque:
      non-empty, ≤ 255, **no format check**; `equals()` via `hash_equals`. Docblock must say why the
      digest is fast rather than Argon2 — the word "password" in this slice makes the wrong reflex
      stronger than it was in slice 2. **(AC-27, AC-35)**
- [x] **T4:** `ValueObject/PasswordResetRequestId` + `Exception/InvalidPasswordResetRequestId` —
      UUID-layout regex, hex-case normalisation, `equals()`. **No generation here.** Docblock records
      that this is the *third* near-clone and points at the technical plan's argument for why nothing
      is extracted.

## Domain — events, aggregate, ports

- [x] **T5:** `Event/PasswordResetRequested` — request id, user id, `issuedAt`, `expiresAt`.
      **No token, no email address.** **(AC-32)**
- [x] **T6:** `Event/UserPasswordChanged` — `UserId`, `occurredAt`. **No hash, no plaintext.**
      **(AC-33)**
- [x] **T7:** `Entity/User` — `changePassword(HashedPassword, \DateTimeImmutable)`, the
      `passwordChangedAt` property and its reader. **One method, one property, one reader, one event
      import — nothing else in the diff.** The docblock must state that it deliberately does *not*
      verify the email (that is this use case's business, not the aggregate's — **AC-31**) and does
      *not* claim monotonicity (I-22).
- [x] **T8:** `Entity/PasswordResetRequest` — private constructor, `issue()` deriving `expiresAt`
      from `LIFETIME_SECONDS`, both policy constants, `RecordsEvents`, readers. Invariants I-14, I-15.
      **(AC-2, AC-32)**
- [x] **T9:** `PasswordResetRequest::assertRedeemableWith()` + `redeem()` +
      `isRedeemed()/isExpiredAt()` and `Exception/{PasswordResetLinkExpired,
      PasswordResetLinkAlreadyUsed, PasswordResetTokenMismatch}`. Invariants I-16, I-18, I-19 —
      checks in the documented order. **`assertRedeemableWith()` mutates nothing**, and its docblock
      says that the GET path depends on that. **(AC-12, AC-16, AC-18, AC-27)**
- [x] **T10:** `PasswordResetRequest::invalidate()` + `isInvalidated()` / `isLiveAt()` +
      `Exception/PasswordResetLinkInvalidated`. Invariant I-17 (never both), idempotent second call,
      **records no event** with the reason written down. **(AC-9, AC-34)**
- [x] **T11:** `Port/PasswordResetRequestRepository` (`nextIdentity`, `save`, `findByTokenHash`,
      `countIssuedForUserSince`, `findOutstandingForUser`) + `Exception/PasswordResetRequestNotFound`
      (whose factory takes **no** argument). Document the distinction that `findByTokenHash` filters
      on **nothing** while `findOutstandingForUser` filters on **structure, never judgement**.
- [x] **T12:** `Port/ResetTokenGenerator` (`generate()`, `hash()`) — one port, two methods, with the
      "don't let a strong generator pair with a weak digest" note and the reason it is *not* merged
      with `VerificationTokenGenerator`.
- [x] **T13:** `Port/PasswordResetMailer` (`sendResetLink(Email, ResetToken, \DateTimeImmutable)`)
      plus `Exception/{TooManyPasswordResetRequests, StalePasswordResetRequest}`.
- [x] **T14:** Checkpoint — `make stan deptrac`; confirm zero framework imports under `Domain/`, and
      confirm the **`User` diff is exactly** one property, one method, one reader, one import.
      **(AC-39)**

## Application

- [x] **T15:** `Command/RequestPasswordReset` + `Handler/RequestPasswordResetHandler` — one `now()`
      for the whole use case; **cap check before the invalidation sweep** (so a spammed form cannot
      kill a victim's live link); sweep through the aggregate, not a bulk `UPDATE`; save before send;
      send before dispatch. **(AC-2, AC-7, AC-9, AC-32)**
- [x] **T16:** `Query/CheckPasswordResetToken` + `Handler/CheckPasswordResetTokenHandler` — returns
      `void`, throws, and **mutates nothing**. Docblock explains why a `bool` would be wrong.
      **(AC-12, AC-16)**
- [x] **T17:** `Command/ResetPasswordWithToken` + `Handler/ResetPasswordWithTokenHandler` — the
      stale-request guard (I-23); `PlainPassword` built **after** the token checks; then
      **request-save FIRST, user-save SECOND**, with a call-site comment stating that this is the
      *inversion* of `VerifyEmailWithTokenHandler` and why the crash windows differ.
      **(AC-20, AC-23, AC-26, AC-28, AC-29, AC-33)**

## Infrastructure — persistence

- [x] **T18:** DBAL types `PasswordResetRequestIdType` and `HashedResetTokenType`; register both
      under `doctrine.dbal.types`.
- [x] **T19:** `Persistence/Doctrine/mapping/PasswordResetRequest.orm.xml` — explicit table and
      column names, `<generator strategy="NONE"/>`, `datetimetz_immutable`, the unique index on
      `token_hash` and the composite `(user_id, issued_at)`, **no association to `User`**. Add
      `password_changed_at` to `User.orm.xml`. `doctrine:mapping:info` must list the new entity.
      **(AC-40, AC-41)**
- [x] **T20:** `Persistence/Doctrine/DoctrinePasswordResetRequestRepository` + its DI alias. No
      unique-constraint translation — a hash collision is a 500, not a business case.
- [x] **T21:** Migration — `identity_password_reset_request` + both indexes **and**
      `identity_user.password_changed_at` (nullable). Generated with `make migration.make`, then
      **hand-reviewed**: correct types, **no FK to `identity_user`**, working `down()` that reverses
      both halves. `make migrate` + `make test.db`. **(AC-42)**

## Infrastructure — security, mail, HTTP

- [x] **T22:** `Security/RandomResetTokenGenerator` (32 CSPRNG bytes → base64url; SHA-256 hex) + DI
      alias. Both crypto choices in one class.
- [x] **T23:** `rate_limiter.yaml` — `password_reset_request` (5/hour) and `password_reset_submit`
      (10/hour), both sliding windows over the existing Redis-backed pool. The comment must record
      **why per-account limiting is not done here** (a limiter keyed on the submitted address is
      itself an enumeration oracle) and that the pool now backs **four** limiters.
      **(AC-8, AC-19)**
- [ ] **T24:** `Mail/TwigPasswordResetMailer` + DI alias +
      `templates/email/reset_password.{html,txt}.twig` — absolute URL via `ABSOLUTE_URL`, **text part
      mandatory**, the expiry rendered both as a duration and an instant (derived from
      `LIFETIME_SECONDS`, never hardcoded), and the *"if you did not request this, your password has
      not been changed"* line. No `->from(...)`. **(AC-3, AC-4, AC-11)**
- [ ] **T25:** `Form/ForgotPasswordForm{Data,Type}` + `PasswordResetController::request`
      (`/forgot-password`) + `::sent` (`/forgot-password/sent`) + templates — limiter consumed
      **before the body does anything else** (429 with `Retry-After`), the four-way collapse into
      **one** exit reached by falling out of the `try`, and INFO logging with `['reason' => $e::class]`
      and **no address**. **(AC-1, AC-5, AC-6, AC-8, AC-36)**
- [ ] **T26:** `Form/NewPasswordForm{Data,Type}` — `RepeatedType` of `PasswordType`, constraints
      **quoted from `PlainPassword`, not retyped**, `NotCompromisedPassword(skipOnError: true)`,
      `allow_extra_fields: false`, CSRF on, and **no token field of any kind**. **(AC-14, AC-22)**
- [ ] **T27:** `PasswordResetController::check` (`/reset-password/{token}`, GET only, route
      requirement `[^/]+` and **no format regex**) — validate, stash the plaintext in the session,
      302 to the token-less route; `Referrer-Policy: no-referrer` on **both** the success and the
      invalid-link responses. Verify with `router:match` that no literal is shadowed.
      **(AC-12, AC-13, AC-15, AC-16, AC-17)**
- [ ] **T28:** `PasswordResetController::reset` (`/reset-password`, GET + POST) + template — read the
      stashed token; success clears the session key and 302s to `/login`; any domain failure gives the
      **one** invalid-link response and also clears the key; a *validation* failure re-renders at 422
      and **keeps** the key. **(AC-14, AC-16, AC-18, AC-20, AC-23, AC-25)**
- [ ] **T29:** Add the *"Forgot your password?"* link to the login template. One line, its own commit,
      because it is the only thing that makes the whole flow reachable.

## Tests (qa — written after implementation, by the independent agent)

- [ ] **T30:** `tests/Factory/PasswordResetRequestFactory` (Foundry) through
      `PasswordResetRequest::issue()` with `afterInstantiate()` releasing events; states `expired()`,
      `redeemed()`, `invalidated()`, `issuedAt()`. **Update the `ClearsRateLimiters` docblock** —
      the body does not change, but it currently names two limiters and now protects four.
- [ ] **T31:** Domain unit — the three value objects: bounds, charset, masking, absence of
      `__toString()` by reflection, `hash_equals` near-miss. **(AC-10, AC-17, AC-27)**
- [ ] **T32:** Domain unit — `PasswordResetRequest`: `issue()` derives +3600 s and records one
      secret-free event; `redeem()` happy path records **nothing**; double redeem; redeem-after-
      invalidate and invalidate-after-redeem (I-17); double invalidate is a no-op; **valid at
      `expiresAt` and expired at `expiresAt + 1 s` — both sides**, or the test proves nothing about
      which operator is in the code; mismatch; `assertRedeemableWith()` mutates nothing;
      `releaseEvents()` empties. **(AC-2, AC-12, AC-18, AC-27, AC-32)**
- [ ] **T33:** Domain unit — `User::changePassword()`: replaces the hash, sets `passwordChangedAt`,
      records exactly one `UserPasswordChanged` carrying no secret; `verifyEmail()` afterwards is
      still idempotent. **(AC-30, AC-33)**
- [ ] **T34:** Integration — the Doctrine adapter: full value-object round-trip after `clear()`;
      `countIssuedForUserSince` boundary behaviour; `findOutstandingForUser` excludes redeemed **and**
      invalidated rows and **includes** expired ones; `nextIdentity()` uniqueness.
- [ ] **T35:** Integration — `RequestPasswordResetHandler` with `FrozenClock`, spy dispatcher and a
      recording mailer: happy path persists one row, sends one message and invalidates the previous
      outstanding request; unknown email throws; the **4th** call inside the hour throws, persists
      nothing **and leaves the existing request outstanding**. **(AC-7, AC-9, AC-32)**
- [ ] **T36:** Integration — `CheckPasswordResetTokenHandler`: happy path returns and the row is
      **byte-for-byte unchanged** on re-read; each failure cause throws its own class. **(AC-12)**
- [ ] **T37:** Integration — `ResetPasswordWithTokenHandler`: happy path; **unverified user is also
      verified and two events are dispatched**; verified user dispatches one; expired / unknown /
      mismatched / invalidated / replayed / **stale** each throw; a weak password throws and leaves
      `redeemed_at` NULL. **(AC-23, AC-26, AC-28, AC-29, AC-30)**
- [ ] **T38:** Functional — `/forgot-password`: the **four-way** assertion with the responses compared
      **against each other in one run** (never against four copies of a literal); a mail with a
      working absolute link; a new request kills the old link; the IP limiter's 429; CSRF failure.
      **(AC-1, AC-3, AC-5, AC-8, AC-9)**
- [ ] **T39:** Functional — the link: a GET **burns nothing** and the token still works afterwards;
      302 to the token-less path; the form page contains the token **nowhere**; `Referrer-Policy` on
      success *and* failure; and all nine invalid-link causes asserted **byte-identical to each
      other**. **(AC-12 … AC-17)**
- [ ] **T40:** Functional — the reset: the password changes and the request is redeemed; **the old
      password fails and the new one succeeds at `/login`** in the same test; no auto-login; a weak
      password gives 422 with the token surviving; a replay is refused. **(AC-18, AC-20, AC-21,
      AC-23, AC-25)**
- [ ] **T41:** Functional — **session invalidation** (log in → 200 on `/account` → reset → 302 on
      `/account`) and the **unverified-account** path (reset, then log in without touching the
      verification flow at all). **(AC-24, AC-28)**
- [ ] **T42:** Infrastructure assertions — `EXPLAIN` shows Index Scans for all three new queries; the
      mail renders a correct absolute URL with **no request context**; `symfonycasts/reset-password-bundle`
      is absent from `composer.json` and `composer.lock`. **(AC-11, AC-38, AC-43)**

## Docs & verify

- [ ] **T43:** Docs: `CLAUDE.md` (the third aggregate, the four limiters now sharing
      `cache.rate_limiter`, and the reset flow's new session dependency), `docs/roadmap.md` (tick the
      slice; the line-97 correction from T0 should already be in; **add the `identity-challenge-pruning`
      slice** before `identity-google-oauth`; add the password-changed notification), `FORboehpyk.md`
      (the story — *the same shape with opposite rules*, the inverted save order, the prefetch problem
      that forced redemption onto the POST, and the fact that rejecting a bundle obliges you to match
      its security properties rather than only its features).
- [ ] **T44:** `/verify` → `make check` green, reviewer PASS (zero CRITICAL / MAJOR), all 45
      acceptance criteria checked off, then open the PR.

      **Three things `make check` cannot tell you, and this slice's verification must not take on
      trust:**
      1. **`docker compose ps` shows `messenger-worker` Up, not Restarting**, and one real reset mail
         is drained into Mailpit with a working absolute link (**AC-45**). ADR-0010's amendment made
         this part of verifying anything that touches the boot path, after a session in which the
         worker crash-looped while all four gates were green.
      2. **Run `/security-review` as well as the standard reviewer pass** (technical plan, Risk 2).
         This is the most attacked endpoint in the system and the flow is hand-rolled.
      3. **Read the four inversion comments** (replay, reissue, GET-mutates-nothing, save order) and
         confirm each says *why* rather than *what*. A future reader diffing this aggregate against
         `EmailVerificationRequest` will find four differences; without the reasons, at least one of
         them will eventually be "fixed".
