# Task List: identity-email-verification

> Ordered, small tasks in DDD canonical order. Each should be reviewable in < 5 minutes and is one
> commit on `feature/identity-email-verification`. Run `make check` before each commit. Check off as
> you go.

**Unblocked.** All four decisions were resolved on 2026-07-26 (technical plan §*Decisions taken*),
each on the plan's recommendation. T0 is now a *writing-up* task, not a deciding one — but still do it
first, because an ADR written after the code it justifies is a rationalisation, not a decision.

**Rule for this slice:** tasks T3–T13 must not contain the strings `use Symfony\` or `use Doctrine\`.
`make deptrac` proves it; the `pre-write-guard` hook would prove it earlier (roadmap carry-over).

## Decisions & prerequisites

- [x] **T0:** Write the two decided ADRs with `/adr`:
      **ADR-0009** "Email-verification tokens are modelled in the Domain" (incl. the no-FK-between-
      aggregates paragraph) + a dated amendment to **ADR-0005**'s tooling parenthetical; **ADR-0010**
      "Event delivery and transactional mail: sync dispatch, async send, no outbox yet" amending
      **ADR-0008**'s watched clause. Both arguments are already written in the technical plan — carry
      them over rather than re-deriving. *(Numbers and smaller calls: already confirmed — 24 h,
      5/user/hour, keep `EmailVerificationRequested`, no shared UUID VO, adopt `Referrer-Policy`.)*
- [x] **T1:** `composer require symfony/mailer symfony/messenger`. Review the recipes' generated
      config; commit `composer.json` / `composer.lock` / `config/packages/{mailer,messenger}.yaml`.
      Nothing else changes.
- [x] **T2 (devops):** `MAILER_DSN` / `MAILER_FROM` / `MESSENGER_TRANSPORT_DSN` in `src/.env`,
      `.env.example` and the Compose files; `DEFAULT_URI` corrected per environment
      (`http://localhost:8080` dev, `https://muzbar.com` prod — this is what makes the links in mail
      point somewhere real, **AC-6**); a `messenger-worker` service with `restart: unless-stopped`;
      confirm the app container reaches `mailpit:1025`.

## Domain — value objects

- [ ] **T3:** `ValueObject/VerificationToken` + `Exception/InvalidVerificationToken`. Exactly 43
      chars of `[A-Za-z0-9_-]`, `#[\SensitiveParameter]` constructor, `__debugInfo()` masking,
      deliberately **no** `__toString()`. **(AC-2, AC-12)**
- [ ] **T4:** `ValueObject/HashedVerificationToken`. Opaque: non-empty, ≤ 255, **no format check**;
      `equals()` via `hash_equals`. **(AC-31)**
- [ ] **T5:** `ValueObject/EmailVerificationRequestId` + `Exception/InvalidEmailVerificationRequestId`
      — UUID-layout regex, hex-case normalisation, `equals()`. **No generation here.**

## Domain — aggregate, event, ports

- [ ] **T6:** `Event/EmailVerificationRequested` — request id, user id, `issuedAt`, `expiresAt`.
      **No token, no email address.** **(AC-26)**
- [ ] **T7:** `Entity/EmailVerificationRequest` — private constructor, `issue()` deriving `expiresAt`
      from `LIFETIME_SECONDS`, `RecordsEvents`, readers. Invariants I-7, I-8. **(AC-1)**
- [ ] **T8:** `EmailVerificationRequest::redeem()` + `isRedeemed()` / `isExpiredAt()` and
      `Exception/{EmailVerificationLinkExpired, EmailVerificationLinkAlreadyRedeemed,
      EmailVerificationTokenMismatch}`. Invariants I-9, I-10, I-11 — checks in that order.
      **(AC-9, AC-10, AC-31)**
- [ ] **T9:** `Port/EmailVerificationRequestRepository` — `nextIdentity`, `save`, `findByTokenHash`,
      `countIssuedForUserSince` — plus `Exception/EmailVerificationRequestNotFound`. The repository
      does **not** filter on business state; document why.
- [ ] **T10:** `Port/VerificationTokenGenerator` (`generate()`, `hash()`) — one port, two methods,
      with the "don't let a strong generator pair with a weak digest" note.
- [ ] **T11:** `Port/VerificationMailer` (`sendVerificationLink(Email, VerificationToken,
      \DateTimeImmutable)`), plus `Exception/{EmailAlreadyVerified, TooManyVerificationRequests}` and
      the `MAX_ISSUES_PER_HOUR` constant.
- [ ] **T12:** Checkpoint — `make stan deptrac`, confirm zero framework imports under `Domain/` and
      that **`Domain/Identity/Entity/User.php` is unchanged in the diff**. That "no diff" is the
      slice's headline result, not an accident. **(AC-33)**

## Application

- [ ] **T13:** `Command/RequestEmailVerification` + `Handler/RequestEmailVerificationHandler` — the
      9-step flow: save **before** send, send **before** dispatch. **(AC-1, AC-17, AC-26)**
- [ ] **T14:** `Command/VerifyEmailWithToken` (+ `VerificationOutcome` enum) +
      `Handler/VerifyEmailWithTokenHandler` — the replay short-circuit **before** `redeem()`, then
      user-save **before** request-save. **(AC-7, AC-8, AC-27)**

## Infrastructure — persistence

- [ ] **T15:** DBAL types `EmailVerificationRequestIdType` and `HashedVerificationTokenType`;
      register both under `doctrine.dbal.types`.
- [ ] **T16:** `Persistence/Doctrine/mapping/EmailVerificationRequest.orm.xml` — explicit table and
      column names, `<generator strategy="NONE"/>`, `datetimetz_immutable`, the unique index on
      `token_hash` and the composite `(user_id, issued_at)`, **no association to `User`**.
      `doctrine:mapping:info` must list the new entity. **(AC-34, AC-35)**
- [ ] **T17:** `Persistence/Doctrine/DoctrineEmailVerificationRequestRepository` + its DI alias. No
      unique-constraint translation — a hash collision is a 500, not a business case.
- [ ] **T18:** Migration creating `identity_email_verification_request` + both indexes — generated
      with `make migration.make`, then **hand-reviewed**: correct types, working `down()`, and
      **`identity_user` untouched**. `make migrate` + `make test.db`. **(AC-36)**
- [ ] **T19:** Messenger's `messenger_messages` migration + `messenger.yaml` routing
      (`SendEmailMessage` → `async`, `sync` under `when@test`, failure transport).

## Infrastructure — security, mail, HTTP

- [ ] **T20:** `Security/RandomVerificationTokenGenerator` (32 CSPRNG bytes → base64url; SHA-256 hex)
      + DI alias. Both crypto choices in one class.
- [ ] **T21:** `SecurityUser` carries `isUsable()` from the Domain `User`; `DomainUserProvider`
      unchanged (it rebuilds the object on every refresh).
- [ ] **T22:** `Security/VerifiedAccountUserChecker` — **empty `checkPreAuth()` with the
      enumeration-oracle comment**, `checkPostAuth()` throwing
      `CustomUserMessageAccountStatusException`. Implement the optional `?TokenInterface` parameter.
      **(AC-20, AC-21)**
- [ ] **T23:** `security.yaml` — add `user_checker:` to `firewalls.main` and **replace** slice 1's
      "NO user_checker — and that is a decision" comment with a note pointing here. **(AC-20)**
- [ ] **T24:** `rate_limiter.yaml` — the `verification_email_resend` sliding window (5/hour), over the
      existing Redis-backed pool. **(AC-18)**
- [ ] **T25:** `Mail/TwigVerificationMailer` + DI alias + `templates/email/verify_email.{html,txt}.twig`
      — absolute URL via `ABSOLUTE_URL`, **text part mandatory** for deliverability. **(AC-3, AC-4, AC-6)**
- [ ] **T26:** `EventListener/IssueVerificationOnUserRegistered` (`#[AsEventListener]` on
      `UserRegistered`) dispatching `RequestEmailVerification`; log at error level if it fails, since
      the user is already committed. **(AC-1, AC-28)**
- [ ] **T27:** `Http/Controller/EmailVerificationController::verify` (`/verify-email/{token}` with the
      43-char route requirement) — outcome → flash, every failure → the **one** invalid-link redirect,
      and `Referrer-Policy: no-referrer` on both responses.
      **(AC-7, AC-8, AC-10 … AC-13, AC-39)**
- [ ] **T28:** `::sent` (`/verify-email/sent`) + template, and `RegistrationController` redirecting
      here with new flash copy. **(AC-24)**
- [ ] **T29:** `Form/ResendVerificationForm{Data,Type}` + `::resend` (`/verify-email/resend`) +
      template — rate-limiter consume → 429, and **one** neutral response for success, unknown,
      already-verified and over-limit. Add the "Didn't get the email?" link to the login template.
      **(AC-14 … AC-19)**

## Tests (qa — written after implementation, by the independent agent)

- [ ] **T30:** `tests/Factory/EmailVerificationRequestFactory` (Foundry) through
      `EmailVerificationRequest::issue()` with `afterInstantiate()` releasing events; `expired()` and
      `redeemed()` states. Generalise `ClearsLoginRateLimiter` → `ClearsRateLimiters` (**DAMA does not
      roll back Redis**).
- [ ] **T31:** Domain unit — the three value objects: bounds, charset, masking, absence of
      `__toString()`, `hash_equals` near-miss. **(AC-2, AC-12, AC-31)**
- [ ] **T32:** Domain unit — `EmailVerificationRequest`: `issue()` derives +86400 s and records one
      secret-free event; `redeem()` happy path, double redeem, expired-by-one-second, mismatch;
      `releaseEvents()` empties. **(AC-1, AC-9, AC-26)**
- [ ] **T33:** Integration — the Doctrine adapter: full value-object round-trip after `clear()`,
      `countIssuedForUserSince` boundary behaviour, `nextIdentity()` uniqueness.
- [ ] **T34:** Integration — `RequestEmailVerificationHandler` with `FrozenClock`, spy dispatcher and
      a fake mailer: happy path, already-verified, unknown email, and the 6th call in an hour.
      **(AC-17, AC-26)**
- [ ] **T35:** Integration — `VerifyEmailWithTokenHandler`: happy path, replay, expired, unknown hash,
      mismatch; assert `UserEmailVerified` counts. **(AC-7, AC-8, AC-27)**
- [ ] **T36:** Functional — registration: one request row, one queued mail, redirect to
      `/verify-email/sent`; transport failure still commits the user. **(AC-1, AC-3, AC-5, AC-24, AC-28)**
- [ ] **T37:** Functional — the link: happy path, replay, expired, unknown, malformed — the last three
      asserted **byte-identical**; plus the `Referrer-Policy` header on success and failure.
      **(AC-7, AC-8, AC-10 … AC-13, AC-39)**
- [ ] **T38:** Functional — resend: the four-way indistinguishability assertion, a new distinct token,
      the IP limiter's 429, CSRF failure. **(AC-15, AC-16, AC-18, AC-19)**
- [ ] **T39:** Functional — login enforcement: unverified + correct password blocked; unverified +
      wrong password shows the *ordinary* message; verified still logs in; throttle still wins at
      attempt 6. **(AC-20 … AC-23)**
- [ ] **T40:** Update slice-1 tests: `verified: true` in `LoginLogoutTest` / `ThrottlingTest`, new
      redirect target in `RegistrationControllerTest`. Record which tests failed **before** the fix —
      the list should be exactly the login-dependent ones. **(AC-25)**
- [ ] **T41:** Infrastructure assertions — `EXPLAIN` shows Index Scans on both new indexes; the mail
      renders a correct absolute URL with **no request context**. **(AC-6, AC-37)**

## Docs & verify

- [ ] **T42:** Docs: `CLAUDE.md` (the mailer/Messenger commands and the worker, the second aggregate
      in `Identity`, the "DAMA does not roll back Redis" note now covering two limiters),
      `docs/roadmap.md` (tick the slice; note Sentry still outstanding), `docs/infrastructure.md` (the
      worker and the failure transport in the runbook), `FORboehpyk.md` (the story — the
      aggregate-boundary argument, the `checkPreAuth`-vs-`checkPostAuth` enumeration trap, and the
      `DEFAULT_URI` footgun are the three lessons worth telling).
- [ ] **T43:** `/verify` → `make check` green, reviewer PASS (zero CRITICAL / MAJOR), all 39
      acceptance criteria checked off, then open the PR.
