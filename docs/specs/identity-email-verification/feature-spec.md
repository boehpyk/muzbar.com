# Feature Spec: identity-email-verification

> The *what & why*. Disposable — this dies once the feature ships and its behaviour lives in tests +
> code. Make it **executable** (measurable, enumerated), not pretty. Must not contradict the
> [Constitution](../../constitution.md) or an accepted ADR.

- **Feature:** `identity-email-verification` — slice **2 of 5** of the `Identity` context
- **Bounded context(s):** `Identity` only. `Notification` is **not** touched — see *Non-goals*.
- **Related PRD items:** US-1 (the *"quickly authenticate"* half), FR-2, PRD §3 Role-Based Access,
  Constitution §8 (email isolation, anti-abuse)
- **Governing ADRs:** [ADR-0005](../../adr/0005-auth-oauth2-google-plus-api-keys.md) (and its
  2026-07-26 amendment), [ADR-0007](../../adr/0007-persistence-conventions-for-domain-aggregates.md),
  [ADR-0008](../../adr/0008-domain-events-recorded-on-the-aggregate.md)
- **Status:** **Approved** — the four open decisions were resolved by the owner on 2026-07-26 (see the
  technical plan's *Decisions taken* block). Implementation may start at **T0** (write the two ADRs).
- **Date:** 2026-07-26

> **This slice closes two debts that slice 1 wrote down on purpose.**
>
> 1. **ADR-0005's amendment:** `User::isUsable()` currently holds an opinion nobody enforces — an
>    unverified account can log in today. This slice installs the enforcement point (one
>    Infrastructure class) and inverts AC-24 of `identity-user-password-auth`.
> 2. **ADR-0008's "hard / watched" clause:** *"The moment `identity-email-verification` makes a
>    listener load-bearing, this must be revisited."* It does. That decision is made explicitly in
>    the [technical plan](./technical-plan.md), not inherited by default.
>
> It also introduces the repository's **second aggregate** and its **first cross-aggregate reference
> by identity** — the DDD lesson of this slice, the way "an aggregate, a value object, a port" was
> the lesson of slice 1.

---

## Ubiquitous language (this slice)

| Term | Means | Not to be confused with |
|---|---|---|
| **EmailVerificationRequest** | The **second aggregate root** in `Identity`: one issued attempt to prove that one `User`'s address is reachable. Holds the hash of a token, when it was issued, when it expires, and whether it has been redeemed. References its user **by `UserId`**, never by object. | The `User` aggregate. A "verification token" — that is the secret this aggregate holds a *hash* of. An "email" — that is the message the request causes to be sent. |
| **VerificationToken** | Value object: the **plaintext** secret — 32 CSPRNG bytes, base64url-encoded to 43 URL-safe characters. Exists only in memory, from the generator to the mail body. **Never persisted, never logged, never carried in a domain event.** | `HashedVerificationToken`. A signed URL or JWT — there is no signature here, only a random secret compared against a stored digest. |
| **HashedVerificationToken** | Value object: the **opaque** digest that is stored and compared. The Domain never learns which algorithm produced it. | `VerificationToken`. `HashedPassword` — same *pattern*, different concept, and a deliberately different hashing rationale (technical plan, *"Why not Argon2"*). |
| **Issue** | The act of minting a token and creating an `EmailVerificationRequest` for a user. Raises `EmailVerificationRequested`. | "Send" — sending is the consequence, and it happens in Infrastructure behind the `VerificationMailer` port. |
| **Redeem** | The act of presenting a valid, unexpired, unredeemed token, which marks the request `redeemedAt` **and** verifies the `User`. | "Verify" on its own — verification is the effect on the *user*; redemption is what happens to the *request*. Not "consume": one verb, held everywhere, including the column name `redeemed_at`. |
| **Verification link** | The absolute URL `…/verify-email/{token}` carrying the plaintext token in the path. Built by the Infrastructure adapter (it needs the router); the Domain never sees a URL. | The `VerificationToken` itself. |
| **Verified email** | Unchanged from slice 1: a `User` whose `emailVerifiedAt` is set. This slice ships the self-service flow that sets it. | "A valid email" (syntax) — validity is not proof of reachability. |
| **Usable account** | Unchanged: ADR-0005's invariant, expressed as `User::isUsable()`. This slice is where the Security layer starts **acting** on it. | "An active account", "a logged-in user". |
| **VerifiedAccountUserChecker** | The Infrastructure class that refuses authentication to a user whose account is not usable — running **after** the password check, so it is never an account-existence oracle. | A voter, a role, or a domain rule. Per ADR-0005, authentication policy belongs to the Security layer. |
| **Neutral response** | The single reply the resend endpoint gives to *every* input — existing, unknown, already verified, rate-limited. The anti-enumeration contract. | A "success message": it deliberately asserts nothing about the address. |

---

## User story

As a **musician who has just registered with an email and a password**, I want to **receive a link
that proves the address is mine and lets me sign in**, so that **my account is real to the platform
and my address is one muzbar can safely send renewal warnings and search alerts to.**

And, as **muzbar**, I want **unverified accounts to be unable to authenticate**, so that **a
registration made with somebody else's address, or with a typo, can never act on the platform** —
ADR-0005's invariant, finally enforced.

Secondary and ranked (Constitution §2): as the **developer**, I want this slice to teach the second
DDD lesson honestly — a second aggregate with a genuinely different lifecycle, referenced by
identity rather than by pointer, and the eventual consistency that choice both buys and costs.

---

## In scope

**Domain (`Identity`)**

- The `EmailVerificationRequest` **aggregate root**, its invariants, and its lifetime/anti-abuse
  policy constants.
- Value objects: `EmailVerificationRequestId`, `VerificationToken` (plaintext, transient),
  `HashedVerificationToken` (opaque, stored, constant-time `equals()`).
- Domain event `EmailVerificationRequested` — **carrying no secret**.
- Ports: `EmailVerificationRequestRepository`, `VerificationTokenGenerator`, `VerificationMailer`.
- Domain exceptions for every failure this flow can produce.
- **No change to the `User` aggregate.** `verifyEmail()`, `isEmailVerified()` and `isUsable()`
  already exist and already do the right thing. That is slice 1's design paying off, and it is worth
  saying out loud.

**Application**

- `RequestEmailVerification` command + handler (used by registration **and** by resend).
- `VerifyEmailWithToken` command + handler.
- The existing `VerifyUserEmail` command/handler is **untouched** (AC-29).

**Infrastructure**

- Doctrine adapter + XML mapping + **one additive migration** creating
  `identity_email_verification_request`. **No migration against `identity_user`** — slice 1's AC-23
  paying off.
- `VerifiedAccountUserChecker` + one `user_checker:` line in `security.yaml` — the enforcement point.
- `SecurityUser` gains the account's usability so the checker can read it.
- Mailer: `symfony/mailer`, a `VerificationMailer` adapter rendering a Twig email, `MAILER_DSN` wired
  to the Mailpit container in dev and to the external relay in prod.
- Asynchronous delivery via Symfony Messenger and a worker process (**decision flagged — technical
  plan §"Needs an ADR" #2**).
- An event listener on `UserRegistered` that issues the first verification request — the listener
  ADR-0008 predicted.
- Routes, controllers and templates: the verification link, the "check your inbox" page, the resend
  form, and the verification email itself.
- Rate limiting on the resend endpoint (Symfony RateLimiter over the Redis-backed pool).

---

## Non-goals (explicit — hold the line)

| Deliberately excluded | Belongs to |
|---|---|
| Password reset / forgotten password, `symfonycasts/reset-password-bundle` | `identity-password-reset` |
| Google OAuth2, `OAuthIdentity`, account linking, and widening `isUsable()` to *"…or a linked verified OAuth identity"* | `identity-google-oauth` |
| The login/register **overlay** Live Component and the intended-action redirect | `identity-login-overlay` |
| API keys — minting, hashing, revocation, the `/api/*` authenticator | Phase 3 |
| Changing a verified email address (which would have to un-verify the account and re-issue) | later Identity work; not Phase 1 |

Also **not** in this slice, for the record:

- **The `Notification` context is not created.** `Notification` owns saved-search alerts
  (`SearchAlert`, Constitution §4). A verification mail is *transactional identity mail*: it is
  caused by, and only meaningful inside, `Identity`. The seam is a port at
  `Domain/Identity/Port/VerificationMailer` with its adapter in `Infrastructure/Identity/Mail/`. If a
  shared mail capability is ever wanted, the adapter changes and the port does not.
- **No pruning job** for expired or redeemed requests (a Scheduler task; the rows are tiny).
- **No `ROLE_VERIFIED`** and no per-action verification gate. Enforcement is at authentication, per
  ADR-0005's amendment.
- **No welcome email** on `UserEmailVerified`, no email-change flow, no "enter this 6-digit code"
  alternative, no auto-login after verification.
- **No visual design.** The new pages render in the same unstyled skeleton template as slice 1
  (ADR-0006's SCSS/Tailwind systems arrive with the first real layout). The **email** template is the
  exception: it ships plain-text-first, because mail clients are not browsers.
- **No Sentry and no Claude Code hooks.** Still the two roadmap carry-overs, still `devops` work
  rather than `Identity` work. Flagged again in the technical plan.

---

## Acceptance criteria (the Definition of Done checklist)

Enumerated, measurable, each independently checkable. These are what `/verify` checks off.

### Issuing and sending

- [ ] **AC-1:** A successful registration creates **exactly one** row in
      `identity_email_verification_request` with: `user_id` = the new user's id; `token_hash` a
      64-character lower-case hex string; `issued_at` = the `Clock`'s value; `expires_at` =
      `issued_at` + **24 h** exactly; `redeemed_at` = `NULL`.
- [ ] **AC-2:** The plaintext token appears in **no** database column, **no** log line, **no**
      exception message and **no** domain event. Asserted by (a) a test that scans every column of
      the request row for the plaintext and (b) `VerificationToken::__debugInfo()` masking the value
      while the class exposes no `__toString()` — the same two guarantees `PlainPassword` carries.
- [ ] **AC-3:** Exactly one email is sent per issued request, addressed to the user's own address,
      containing an absolute URL of the form `{scheme}://{host}/verify-email/{token}` where `{token}`
      is 43 URL-safe characters, and stating the expiry in human terms.
- [ ] **AC-4:** The email's `From` is the configured no-reply sender, and its body renders no address
      other than the recipient's own (Constitution §8).
- [ ] **AC-5:** Registration still succeeds — 302, user row committed — when the mail transport
      throws. A test forces a transport failure and asserts the `identity_user` row is present and
      the response is the normal redirect.
- [ ] **AC-6:** The absolute URL is built from the configured base URI and is correct when the mail
      is rendered **outside an HTTP request** (from the Messenger worker / CLI) — not
      `http://localhost`. Asserted by rendering the mail with no request context.

### Redeeming the link

- [ ] **AC-7:** `GET /verify-email/{token}` with a valid, unexpired, unredeemed token sets
      `identity_user.email_verified_at` to the `Clock`'s value, sets `redeemed_at` on the request,
      and redirects (302) to `/login` with a success flash. `User::isUsable()` is then `true`.
- [ ] **AC-8 (single use — the important one):** Replaying the **same** URL after a successful
      redemption does **not** change `email_verified_at`, does **not** re-set `redeemed_at`,
      dispatches **no** event, and answers with the friendly "already verified" outcome (302 to
      `/login` with an informational flash) — **not** an error. *Rationale: mail clients pre-fetch
      links, so the first GET is frequently a robot and the human's click is the replay. A flow that
      punishes the replay is a flow that appears broken to a real user.*
- [ ] **AC-9:** A request that is already redeemed while its user is somehow **not** verified (a
      state reachable only by data corruption) is refused, not silently accepted — asserted at the
      aggregate's unit level by calling `redeem()` twice.
- [ ] **AC-10:** An **expired** token (clock advanced past `expires_at`) redirects to the resend form
      with *"That link is no longer valid. Enter your address and we will send a new one."* and
      mutates nothing: `email_verified_at` stays `NULL`, `redeemed_at` stays `NULL`.
- [ ] **AC-11:** An **unknown** token (well-formed, never issued) produces a response
      **indistinguishable** from the expired case — same status, same `Location`, same flash string.
- [ ] **AC-12:** A **malformed** token (wrong length, non-base64url characters, empty, 10 kB of junk)
      is rejected by the `VerificationToken` value object before any database query and produces the
      same response as AC-11. No 500, no stack trace, no SQL in the output.
- [ ] **AC-13:** Redemption performs **no** password check and starts **no** session: the user is
      redirected to `/login` and must still authenticate. *(Auto-login on verification is
      `identity-login-overlay`'s decision to make, not a side effect smuggled in here.)*

### Resending

- [ ] **AC-14:** `GET /verify-email/resend` returns 200 with a single `email` field and a CSRF token.
- [ ] **AC-15 (no user enumeration):** `POST /verify-email/resend` returns a **byte-identical**
      response for (a) an unverified existing account, (b) an address no account holds, (c) an
      already-verified account and (d) an address over the domain rate limit. A test asserts the four
      status codes and bodies are equal. Only case (a) results in an email.
- [ ] **AC-16:** Case (a) creates a **new** request row with a **different** `token_hash` and sends
      one new email. The previously issued link is **not** invalidated — see the technical plan for
      why that is safe here and would *not* be safe for password reset.
- [ ] **AC-17 (domain rate limit):** The **6th** issuance for the same user inside a rolling hour is
      refused by the Application handler (`MAX_ISSUES_PER_HOUR = 5`) — no row, no email — while the
      HTTP response stays the neutral one from AC-15.
- [ ] **AC-18 (boundary rate limit):** The resend endpoint is additionally limited to **5 POSTs per
      hour per client IP** by a Redis-backed limiter; the 6th is refused with HTTP **429** before the
      controller body runs. The counters live in the Redis rate-limiter pool, not on the container
      filesystem.
- [ ] **AC-19:** The resend flow never reveals a token, a request id, or any timestamp of a previous
      request.

### Enforcement at login (inverting slice 1's AC-24)

- [ ] **AC-20 (the inversion):** A user with `email_verified_at IS NULL` posting **correct**
      credentials to `/login` is refused, sees *"Please verify your email address before signing
      in."* plus a link to `/verify-email/resend`, and holds **no** session afterwards
      (`GET /account` → 302 `/login`). Slice 1's AC-24 is hereby inverted and ADR-0005's amendment is
      discharged.
- [ ] **AC-21 (enumeration-safe placement):** The same unverified user posting a **wrong** password
      sees the ordinary *"Invalid credentials."* message, **not** the verification message. The check
      therefore runs only after credentials pass (`checkPostAuth`), so it can never become a free
      oracle telling an attacker which addresses hold accounts. One test asserts both messages
      against the same account.
- [ ] **AC-22:** A verified user's login is unaffected: 302 to `/account`, session established,
      slice-1 behaviour intact.
- [ ] **AC-23:** The failed unverified login **counts** against `login_throttling` exactly like any
      other failure, and at attempt 6 the throttling message wins over the verification message.

### Behaviour of slice 1 that this slice deliberately changes

- [ ] **AC-24 (supersedes slice 1 AC-3):** After a successful registration the redirect target is
      `GET /verify-email/sent` ("check your inbox"), not `/login`. Telling a user to sign in when
      signing in is now guaranteed to fail would be shipping a lie on purpose. Slice 1's functional
      test is **updated**, not deleted.
- [ ] **AC-25:** Every slice-1 functional test that authenticates is updated to build a **verified**
      user (the existing `RegistersAUserWithKnownCredentials::$verified` flag →
      `UserFactory::verified()`). The suite must fail loudly first: the implementer confirms the
      pre-change failures are exactly the login-dependent tests and nothing else.

### Domain events

- [ ] **AC-26:** Issuing a request dispatches exactly one `EmailVerificationRequested` carrying the
      request id, the user id, `issuedAt` and `expiresAt` — and **no token and no email address**.
- [ ] **AC-27:** A successful redemption dispatches exactly one `UserEmailVerified` (slice 1's event,
      unchanged). A replayed redemption (AC-8) dispatches **none**.
- [ ] **AC-28:** A registration whose verification mail fails to send still dispatches
      `UserRegistered`; a mail failure neither rolls back nor suppresses the fact.

### The console command

- [ ] **AC-29:** `muzbar:identity:verify-email <email>` still works, unchanged, and still exits 0 on
      a repeat run. It is now documented as the **break-glass path** (an account whose provider
      bounces every message) and deliberately bypasses the token flow. Its slice-1 tests keep passing
      untouched.

### Security & privacy

- [ ] **AC-30:** The token is stored only as a digest; a dump of
      `identity_email_verification_request` contains nothing that can be replayed into a URL.
- [ ] **AC-31:** Token comparison inside the aggregate uses `hash_equals` (constant time), asserted
      in review and by a unit test proving a one-character-off token is rejected.
- [ ] **AC-32:** No response, page, flash or email in this slice renders an address other than the
      one the visitor supplied or the recipient's own (Constitution §8).
- [ ] **AC-39:** The `GET /verify-email/{token}` response carries `Referrer-Policy: no-referrer`. The
      token travels in a URL path, so it can reach nginx access logs, browser history and a `Referer`
      header; single use and a 24 h life are the real mitigations, and this is the cheap belt-and-braces
      one. Asserted on the header of both the success and the invalid-link responses.

### Architecture & quality gates

- [ ] **AC-33 (Domain purity):** `grep -rE '^use (Symfony|Doctrine)\\' src/src/Domain/` still returns
      nothing — the new aggregate, its value objects and its three ports contain no framework import
      — and Deptrac is green under `--fail-on-uncovered`.
- [ ] **AC-34:** `EmailVerificationRequest` holds a `UserId`, **not** a `User`: no Doctrine
      association between the two aggregates and no `$request->user()->…` traversal anywhere.
      Asserted by review of the XML mapping and by grep.
- [ ] **AC-35:** The new table follows ADR-0007 exactly — name
      `identity_email_verification_request`, every column named explicitly in XML,
      application-assigned UUIDv7 via `nextIdentity()` with `<generator strategy="NONE"/>`,
      `datetimetz_immutable` whole-second timestamps, value objects through custom DBAL types.
- [ ] **AC-36:** The migration is additive and reversible: `migrate prev` drops the new table
      cleanly, leaves `identity_user` untouched, and re-migrating succeeds.
- [ ] **AC-37 (index, not a latency budget):** `EXPLAIN` of the redemption lookup
      (`… WHERE token_hash = $1`) shows an **Index Scan** on
      `uniq_identity_email_verification_request_token_hash`, and the anti-abuse count
      (`… WHERE user_id = $1 AND issued_at >= $2`) shows an Index Scan on
      `idx_identity_email_verification_request_user_issued`. *The Constitution's < 200 ms budget
      governs faceted search (Phase 2) and does not apply to this slice.*
- [ ] **AC-38:** `make check` is green: php-cs-fixer clean, PHPStan **max** zero errors, Deptrac zero
      violations, PHPUnit green with new Domain unit tests **and** Application/Functional tests.

---

## Failure contract

*"No mutation"* means no row written or changed and no domain event dispatched.

| Condition | Expected behaviour |
|---|---|
| Malformed token in the URL (bad length / charset / empty) | `VerificationToken` throws `InvalidVerificationToken` at the boundary; 302 to the resend form with the single invalid-link message. No mutation, no database query. |
| Well-formed token that was never issued | Repository returns `null` → `EmailVerificationRequestNotFound`; response **identical** to the malformed and expired cases. No mutation. |
| Expired token (`now > expires_at`) | `EmailVerificationLinkExpired` from the aggregate; identical response. No mutation. |
| Redeemed token whose user is already verified (replay / mail-client prefetch) | Handler short-circuits **before** redeeming: 302 to `/login` with *"Your email address is already verified — please sign in."* No mutation, no event. |
| Redeemed token whose user is **not** verified (corruption only) | `EmailVerificationLinkAlreadyRedeemed`; the invalid-link response. No mutation. Logged at error level — this state should be unreachable. |
| Token found but its `user_id` matches no row | Slice-1's `UserNotFound`; the invalid-link response. No mutation. Logged at error level: a dangling request means a user row was deleted without cleanup. |
| Resend for an unknown address | Neutral response (AC-15). No mutation, no email. |
| Resend for an already-verified address | `EmailAlreadyVerified` caught at the boundary → neutral response. No mutation, no email. |
| Resend for an address with 5 requests in the last hour | `TooManyVerificationRequests` caught at the boundary → neutral response. No mutation, no email. |
| 6th resend POST from one IP within an hour | HTTP 429 from the rate limiter, before the controller body runs. No mutation. |
| Missing, stale or tampered CSRF token on the resend form | Form error *"The CSRF token is invalid. Please try to resubmit the form."* No mutation. |
| Mail transport unreachable at registration | The `User` row and the request row are committed and `UserRegistered` is dispatched; delivery is retried by the queue and, if retries are exhausted, lands in the failure transport. The user's recovery path is the resend form — which is exactly why that form is anonymous. Registration never 500s because of mail. |
| Mail transport unreachable at resend | The neutral response is still returned; the queue owns delivery. |
| Messenger worker not running | Mail queues and is delivered when the worker starts. This is an **operational** failure and must be visible in the runbook: a stopped worker looks exactly like a healthy system to every automated check we currently have. |
| Postgres unavailable | 500 from the framework error handler; one transaction, no partial write. `/health/ready` already reports it. |
| Redis unavailable | Sessions and both rate limiters fail. No silent filesystem fallback (slice 1's decision, unchanged). |
| Unverified user logs in with correct credentials | `CustomUserMessageAccountStatusException` from the user checker *after* the password check; the login page shows the verification message plus a resend link. No session. |
| Unverified user logs in with a wrong password | Ordinary *"Invalid credentials."* — verification state is never revealed to someone who does not hold the password (AC-21). |
| A stored `token_hash` cannot be rehydrated into a valid value object | The DBAL type throws — loud failure, never a half-valid aggregate (slice-1 rule, unchanged). |
