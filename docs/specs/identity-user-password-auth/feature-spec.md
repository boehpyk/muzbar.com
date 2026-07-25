# Feature Spec: identity-user-password-auth

> The *what & why*. Disposable — this dies once the feature ships and its behaviour lives in tests +
> code. Make it **executable** (measurable, enumerated), not pretty. Must not contradict the
> [Constitution](../../constitution.md) or an accepted ADR.

- **Feature:** `identity-user-password-auth` — slice **1 of 5** of the `Identity` context
- **Bounded context(s):** `Identity`
- **Related PRD items:** US-1 (the *"quickly authenticate"* half only), FR-2 (the **non**-OAuth half),
  PRD §3 Role-Based Access (Guest / Individual User / Business User / Admin)
- **Governing ADR:** [ADR-0005](../../adr/0005-auth-oauth2-google-plus-api-keys.md)
- **Status:** Approved — in implementation
- **Date:** 2026-07-25

> **This is the first Domain code in the repository.** Until now `src/src/Domain/` has held nothing
> but empty directories; the only application code is `HealthController` (Infrastructure). Everything
> this slice establishes — where aggregates live, how Doctrine maps them without touching the Domain,
> how identities are minted, how domain events are recorded and dispatched — becomes the pattern every
> later context (`Catalog`, `Listing`, `Directory`, `Billing`, `Notification`) copies. That is why the
> plan is heavier than the slice's user-visible surface suggests.

---

## Ubiquitous language (this slice)

| Term | Means | Not to be confused with |
|---|---|---|
| **User** | The `Identity` aggregate root: one person's account on muzbar, identified by a `UserId` and keyed by a unique `Email`. Owns its credentials, its roles and its verification state. | "Seller", "Member", "Customer", "Profile". Symfony's `UserInterface` — that is a *framework* concept and lives in Infrastructure as `SecurityUser`. |
| **Email** | Value object: a syntactically valid, **normalised** (trimmed, lower-cased) address. Doubles as the natural key and the login identifier. | A display string. Public interfaces never render another user's email (Constitution §8). |
| **PlainPassword** | Value object: the transient, never-persisted secret the user typed, having satisfied the **password policy** (length bounds). Exists only between the HTTP boundary and the hasher. | `HashedPassword`. A bare `string` passed around loosely. |
| **HashedPassword** | Value object: an **opaque** wrapper around the hash produced by the Infrastructure hasher. The Domain never inspects, parses, compares or re-derives it. | `PlainPassword`. An "encrypted password" — it is a one-way hash, not encryption. |
| **UserId** | Value object: an application-generated UUIDv7 minted **before** persistence, so a `User` is fully valid the moment it is constructed. | A database auto-increment id. A `Uuid` object from `symfony/uid` — that type never enters the Domain. |
| **Role** | Enum of the authorisation levels a `User` carries: `ROLE_USER`, `ROLE_BUSINESS`, `ROLE_ADMIN` (ADR-0005). | A "permission", or a "plan"/"tier" — `Billing` decides tiers, not `Identity`. |
| **Registration** | The act of creating a `User` from an email + a password. Raises `UserRegistered`. | "Signup flow" (UI wording), "onboarding" (product wording). |
| **Verified email** | A `User` whose email has been proven reachable, recorded as `emailVerifiedAt`. **Modelled in this slice; the self-service flow that sets it arrives in `identity-email-verification`.** | "A valid email" (syntax) — validity is not proof of reachability. |
| **Usable account** | ADR-0005's invariant: an account that may act on the platform because it has a verified email **or** a linked verified OAuth identity. Expressed here as `User::isUsable()`. | "An active account", "a logged-in user". Usability is a *domain* property; whether it *gates login* is an authentication policy decided in Infrastructure — see AC-24. |
| **Credentials** | The (email, password) pair a login attempt presents. **Checked by the Symfony Security layer, never by the Domain** (ADR-0005). | The stored `HashedPassword`, which is state, not an attempt. |
| **SecurityUser** | The **Infrastructure adapter** presenting a Domain `User` to Symfony Security (`UserInterface`, `PasswordAuthenticatedUserInterface`). | The `User` aggregate. The aggregate must never implement a framework interface — Deptrac will enforce this from now on. |

---

## User story

As a **musician who does not have (or does not want to use) a Google account**, I want to
**register with an email and a password and sign back in later**, so that **I can reach the
action-oriented parts of muzbar — selling gear, adding a studio, subscribing to a search — without
depending on a third-party identity provider.**

Secondary and ranked (Constitution §2): as the **developer**, I want this slice to establish an
honest DDD skeleton — a real aggregate with real invariants, real value objects, a real port, a real
domain event — so the six contexts that follow have a pattern worth copying.

---

## In scope

**Domain (`Identity`)**

- The `User` aggregate root with its invariants; the `Email`, `PlainPassword`, `HashedPassword` and
  `UserId` value objects; the `Role` enum.
- Domain events `UserRegistered` and `UserEmailVerified`.
- Ports: `UserRepository` (including `nextIdentity()`) and `PasswordHasher`.
- The first `Domain/Shared/` building blocks: a `DomainEvent` contract, a `RecordsEvents` trait, a
  `Clock` port and a `DomainEventDispatcher` port.

**Application**

- `RegisterUser` command + handler.
- `VerifyUserEmail` command + handler — *not* the verification flow, but the use case that flow will
  call (see the verification decision, AC-23 … AC-26).

**Infrastructure**

- Doctrine adapter for `UserRepository`, **XML mapping outside the Domain**, custom DBAL types for the
  value objects, and the repository's **first migration** (`identity_user`).
- Symfony Security wiring: a `SecurityUser` adapter, a user provider backed by the Domain port,
  `form_login`, `logout`, and **`login_throttling`**.
- Registration form (Symfony Form + Validator + CSRF), a login page, and a minimal authenticated
  `/account` page so that "logged in" is observable and testable.
- A `muzbar:identity:verify-email` console command — the slice-1 caller of `VerifyUserEmail` and the
  operational escape hatch until `identity-email-verification` ships.
- Redis-backed session storage and a Redis-backed rate-limiter cache pool. Constitution §3 locks Redis
  for cache/sessions, and a throttle counter that evaporates on every deploy is not a throttle.

**Tooling**

- Deptrac gains explicit `Symfony` and `Doctrine` layers. Today the ruleset only knows our own three
  directories, so a stray `use Symfony\...` inside `Domain/` would **not** be flagged — the guard has
  never been exercised because there has never been Domain code to guard. It is exercised now.

---

## Non-goals (explicit — hold the line)

These are **later slices of the same roadmap bullet**, named so nobody is tempted to "just add it
while we're in here":

| Deliberately excluded | Belongs to |
|---|---|
| Email verification flow: token generation, verification email, signed URLs, `symfonycasts/verify-email-bundle`, and **enforcing** the usable-account rule at login | `identity-email-verification` |
| Password reset / forgotten password, `symfonycasts/reset-password-bundle` | `identity-password-reset` |
| Google OAuth2, `knpuniversity/oauth2-client-bundle`, the `OAuthIdentity` value object, account linking by email | `identity-google-oauth` |
| The login/register **overlay** as a Symfony UX Live Component, and preserving the intended action ("Sell Gear", "Add Studio", "Subscribe to Search") across the login round-trip | `identity-login-overlay` |
| API keys — minting, hashing at rest, revocation, the `/api/*` authenticator | Phase 3 (roadmap). Not even a model stub here. |

Also **not** in this slice, for the record:

- **No mailer.** Nothing here sends email. No `symfony/mailer`, no Mailpit wiring.
- **No "remember me"**, no password rehash-on-login (`PasswordUpgraderInterface`), no two-factor, no
  account deletion, no profile editing, no email change, no admin user management.
- **No self-service role assignment.** Every registration yields exactly `ROLE_USER`. `ROLE_BUSINESS`
  and `ROLE_ADMIN` are modelled and persistable but nothing in this slice grants them.
- **No public visual design.** `/register`, `/login` and `/account` render in the unstyled Symfony base
  template. The public SCSS system and the Tailwind admin system (ADR-0006) arrive with the first real
  layout; so does the Umami snippet.
- **No Sentry.** The roadmap earmarks the first Identity slice as the natural moment for it, but it is
  infrastructure, not `Identity` domain work — flagged for the human in the technical plan rather than
  smuggled in here.

---

## Acceptance criteria (the Definition of Done checklist)

Enumerated, measurable, each independently checkable. These are what `/verify` checks off.

### Registration

- [ ] **AC-1:** `GET /register` returns 200 and renders a form with `email`, `plainPassword.first` and
      `plainPassword.second` fields plus a CSRF token (`_token`) in the markup.
- [ ] **AC-2:** Posting a valid, unused email with a ≥ 12-character password creates **exactly one**
      row in `identity_user` with: `email` stored **lower-cased and trimmed**; `password_hash` matching
      `/^\$(2y|argon2)/` and **never** equal to the submitted plaintext; `roles` = `["ROLE_USER"]`;
      `email_verified_at` = `NULL`; `registered_at` = the `Clock`'s value.
- [ ] **AC-3:** On success the response is a 302 to `/login` with a flash message. The user is **not**
      auto-authenticated — deliberate: the friendly one-step flow belongs to `identity-login-overlay`.
- [ ] **AC-4:** Registering `Max@Example.COM ` (mixed case, trailing space) when `max@example.com`
      already exists re-renders the form with *"An account with this email already exists."* and creates
      **no** second row. Case-folding and trimming are asserted explicitly.
- [ ] **AC-5:** A password shorter than **12** characters is rejected at the form boundary with a field
      error, **and** `RegisterUserHandler` independently rejects it via the `PlainPassword` value object
      — two tests, one per layer (defence in depth). No row is created.
- [ ] **AC-6:** Mismatched password / confirmation is rejected with a field error; no row is created.
- [ ] **AC-7:** A syntactically invalid email (`not-an-email`, `a@`, `@b.com`, or an address longer than
      **180** characters) is rejected at the form boundary **and** by the `Email` value object. No row.
- [ ] **AC-8:** A missing or tampered CSRF token yields a form error and no row is created.
- [ ] **AC-9:** A duplicate that slips past the pre-check (simulated concurrent insert) is caught by the
      unique index and surfaces as the **same** `EmailAlreadyRegistered` domain exception with the same
      user-visible message — never a 500, never a leaked SQL string.
- [ ] **AC-10:** The submitted plaintext password appears in **no** log line, exception message,
      `var_dump`/`print_r` output or HTTP response body. `PlainPassword::__debugInfo()` masks the value
      and the class exposes no `__toString()`; a test asserts both.

### Login, logout, session

- [ ] **AC-11:** A registered user posting correct credentials to `/login` is redirected to `/account`;
      `GET /account` then returns 200 and displays that user's own email.
- [ ] **AC-12:** Correct email + wrong password re-renders `/login` with **exactly** the message
      *"Invalid credentials."* and establishes no session.
- [ ] **AC-13 (user enumeration):** An unknown email produces a response **indistinguishable** from
      AC-12 — same status code, same `Location`, same rendered error string. A test asserts the two
      responses' error output is identical. *(`hide_user_not_found` stays at its secure default; timing
      side-channels are out of scope and blunted by AC-14.)*
- [ ] **AC-14 (throttling):** With `max_attempts: 5` / `interval: '15 minutes'`, the **6th** failed
      attempt for the same (username, IP) inside the window is rejected with *"Too many failed login
      attempts, please try again in 15 minutes."* and performs no password verification. A test drives
      five failures then asserts the sixth differs.
      *(Amended 2026-07-26 during implementation. The spec originally said "…please try again later."
      Symfony's `security` translation domain renders the `%minutes%` variant whenever `interval` is
      set, so the framework string is the one above. Forcing the original wording would require a
      `translations/security.en.xlf` override — a product-wording decision, not an implementation
      one — and any conditional in the template to vary it would breach AC-13. We assert what the
      framework actually renders.)*
- [ ] **AC-15:** Throttle counters live in the Redis-backed `cache.rate_limiter` pool, not on the
      container filesystem — asserted by inspecting the configured pool's adapter — so a deploy or
      container restart cannot silently reset the brute-force budget.
- [ ] **AC-16:** Logging out invalidates the session; a subsequent `GET /account` returns 302 to `/login`.
- [ ] **AC-17:** The session id changes across a successful login (session-fixation protection on).
- [ ] **AC-18:** Sessions are stored in Redis, not on the container filesystem (Constitution §3).

### Authorisation & privacy

- [ ] **AC-19:** Anonymous `GET /account` returns 302 to `/login` — never 200, never a partial render.
- [ ] **AC-20:** A freshly registered user's roles are exactly `['ROLE_USER']`. No route, form field or
      command in this slice accepts a role from request input; a test posts `roles[]=ROLE_ADMIN` to
      `/register` and asserts the stored roles are unchanged.
- [ ] **AC-21:** No response in this slice renders any email other than the authenticated user's own
      (Constitution §8, email isolation). `/account` renders exactly one address.
- [ ] **AC-22:** Neither `password_hash` nor the roles array appears anywhere in the HTML of
      `/register`, `/login` or `/account`.

### Verification state (the deferred invariant)

- [ ] **AC-23:** The **first** migration already creates
      `email_verified_at TIMESTAMP(0) WITH TIME ZONE NULL` on `identity_user`, so
      `identity-email-verification` can ship **without any migration against this table** and without
      reopening the aggregate's design. Asserted by review of the migration and restated in that
      slice's spec.
- [ ] **AC-24 (documented deferral — written to be inverted later):** A `User` with
      `email_verified_at IS NULL` **can** authenticate in this slice. `User::isUsable()` returns `false`
      for such a user, but **no** `UserCheckerInterface` is installed on the firewall, so the domain's
      opinion is modelled and not yet enforced. `identity-email-verification` adds the checker and flips
      this expectation. Rationale in the technical plan under *Risks / open questions*.
- [ ] **AC-25:** `php bin/console muzbar:identity:verify-email <email>` sets `email_verified_at` and
      makes `isUsable()` true. A **second** run is idempotent: exit code 0, message *"already
      verified"*, stored timestamp unchanged.
- [ ] **AC-26:** `muzbar:identity:verify-email` with an unknown email exits non-zero with a clear
      message and mutates nothing.

### Domain events

- [ ] **AC-27:** A successful registration dispatches exactly one `UserRegistered` carrying the new
      `UserId` and `Email`; a successful verification dispatches exactly one `UserEmailVerified`. A
      failed registration (any of AC-4 … AC-9) dispatches **none**.

### Architecture & quality gates

- [ ] **AC-28 (Domain purity, proven not assumed):** `grep -rE '^use (Symfony|Doctrine)\\' src/src/Domain/`
      returns nothing, **and** Deptrac's ruleset now contains `Symfony` and `Doctrine` layers such that
      adding a temporary `use Symfony\Component\Uid\Uuid;` to a Domain class makes `make deptrac` fail.
      The failure is demonstrated once and reverted.
- [ ] **AC-29:** The `User` aggregate implements no framework interface and carries no ORM attribute;
      every piece of Doctrine metadata for it lives in XML under `src/src/Infrastructure/`.
- [ ] **AC-30:** `make check` is green: php-cs-fixer clean, PHPStan **max** zero errors, Deptrac zero
      violations, PHPUnit green with new Domain unit tests **and** Application/Functional tests.
- [ ] **AC-31:** The migration is reversible — `doctrine:migrations:migrate prev` against `muzbar_test`
      drops `identity_user` cleanly and re-migrating succeeds.
- [ ] **AC-32 (index, not a latency budget):** `EXPLAIN` of the login lookup
      (`SELECT … FROM identity_user WHERE email = $1`) shows an **Index Scan** on
      `uniq_identity_user_email`, never a Seq Scan.
      *The Constitution's < 200 ms budget governs faceted search (Phase 2) and does not apply to this
      slice — password hashing is deliberately slow and login latency is dominated by it. Stated
      explicitly so nobody "optimises" the hasher's cost factor to hit a budget that does not exist here.*

---

## Failure contract

What happens when things go wrong (enumerated, not invented at implementation time). *"No mutation"*
means no row written and no domain event dispatched.

| Condition | Expected behaviour |
|---|---|
| Malformed / over-long email submitted | Form re-renders with a field error; the `Email` VO would throw `InvalidEmail` if reached. No mutation. |
| Email already registered (pre-check hits) | Form error *"An account with this email already exists."* No mutation. **Accepted trade-off:** registration is inherently enumerable; hiding it requires sending an email, which this slice cannot do. Revisit in `identity-email-verification`. |
| Email already registered (race — pre-check missed, unique index fires) | Doctrine's `UniqueConstraintViolationException` is translated **inside the adapter** into the domain exception `EmailAlreadyRegistered`; the user sees the identical message as the row above. Never a 500, never a raw SQL string. |
| Password shorter than 12 or longer than 4096 characters | Field error at the form boundary; `PlainPassword` throws `WeakPassword` if reached. No mutation. |
| Password fails `NotCompromisedPassword` (found in a breach corpus) | Field error *"This password has been leaked in a data breach, please choose another."* No mutation. Already disabled in `APP_ENV=test` by `config/packages/validator.yaml`. |
| The HIBP range API is unreachable | The constraint is configured `skipOnError: true` — registration **proceeds**. A third-party outage must not become a muzbar outage; the length policy still applies. |
| Password / confirmation mismatch | Field error on the repeated field. No mutation. |
| Missing, stale or tampered CSRF token | Form error *"The CSRF token is invalid. Please try to resubmit the form."* No mutation. |
| Login with unknown email | Response identical to "wrong password" (AC-13). The attempt still counts against the throttle. |
| Login with wrong password | `/login` re-renders with *"Invalid credentials."* The attempt counts against the throttle. |
| 6th failed login in 15 minutes (same username + IP) | Rejected before password verification with the throttling message. Counter held in Redis. |
| Postgres unavailable during registration | 500 from the framework error handler; no partial write (single transaction). `/health/ready` already reports the outage. |
| Redis unavailable | Sessions and the throttle store fail. **Explicit decision:** no silent fallback to filesystem storage — a silent fallback would disable brute-force protection exactly when the box is unhealthy. The request errors and `/health/ready` reports Redis down. Recorded as a risk. |
| Authenticated user's row is deleted mid-session | `DomainUserProvider::refreshUser()` throws `UserNotFoundException`; Symfony logs the session out. The next request behaves as anonymous. |
| A stored row cannot be rehydrated into valid VOs (e.g. corrupted email) | The DBAL type throws a conversion exception — loud failure, not a half-valid aggregate. Bad data is a bug to fix, not a state to tolerate. |
| `muzbar:identity:verify-email` on an unknown email | Exit code 1, message *"No user found with email …"*, no mutation. |
| `muzbar:identity:verify-email` on an already-verified email | Exit code 0, message *"already verified"*, timestamp unchanged (idempotent). |
