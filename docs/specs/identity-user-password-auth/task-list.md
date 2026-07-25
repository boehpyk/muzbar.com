# Task List: identity-user-password-auth

> Ordered, small tasks in DDD canonical order. Each should be reviewable in < 5 minutes and is one
> commit on `feature/identity-user-password-auth`. Run `make check` before each commit. Check off as
> you go.

**Rule for this slice:** tasks T3–T15 must not contain the strings `use Symfony\` or `use Doctrine\`.
T2 makes that mechanically enforceable — do it first.

## Prerequisites (tooling & dependencies — no domain code)

- [x] **T1:** `composer require symfony/form symfony/rate-limiter symfony/translation`. Review the
      recipes' generated config, commit `composer.json` / `composer.lock` / new `config/packages/*`.
      Nothing else changes. *(`symfony/validator`, `security-csrf`, `password-hasher`, `uid`, `clock`
      are already present.)*
- [x] **T2:** `deptrac.yaml` — add `SymfonyVendor` / `DoctrineVendor` layers (`classLike` collectors on
      `^Symfony\\.*` / `^Doctrine\\.*`) and allow them only from `Infrastructure`. Prove the guard by
      temporarily adding `use Symfony\Component\Uid\Uuid;` to a throwaway `Domain/` class, watching
      `make deptrac` fail, then reverting. **(AC-28)**

## Domain — `Domain/Shared/` building blocks (first content in this namespace)

- [x] **T3:** `Domain/Shared/Event/DomainEvent` interface (`occurredAt(): \DateTimeImmutable`) +
      `Domain/Shared/Event/RecordsEvents` trait (`recordThat()`, `releaseEvents()` which empties the
      buffer).
- [x] **T4:** `Domain/Shared/Port/Clock` (`now(): \DateTimeImmutable`, UTC) and
      `Domain/Shared/Port/DomainEventDispatcher` (`dispatch(DomainEvent ...$events): void`).

## Domain — `Identity` value objects

- [x] **T5:** `ValueObject/Email` + `Exception/InvalidEmail`. Trim, lower-case the whole address,
      `FILTER_VALIDATE_EMAIL`, max 180, `equals()`, `toString()`.
- [x] **T6:** `ValueObject/UserId` + `Exception/InvalidUserId`. UUID-format regex, `equals()`.
      **No generation here** — the repository port mints ids.
- [x] **T7:** `ValueObject/HashedPassword`. Opaque: non-empty, ≤ 255, **no algorithm-format check**.
- [x] **T8:** `ValueObject/PlainPassword` + `Exception/WeakPassword`. Length 12–4096,
      `#[\SensitiveParameter]` constructor, `__debugInfo()` masking, deliberately **no** `__toString()`.
- [x] **T9:** `ValueObject/Role` backed enum (`User`/`Business`/`Admin` → `ROLE_USER`/`ROLE_BUSINESS`/
      `ROLE_ADMIN`).

## Domain — `Identity` aggregate, events, ports

- [x] **T10:** `Event/UserRegistered` and `Event/UserEmailVerified` (both implement `DomainEvent`,
      carry VOs only — never the aggregate).
- [x] **T11:** `Entity/User` — private constructor; `register()`; readers; `RecordsEvents`.
      Invariants I-1…I-3 (`roles` always contains `Role::User`).
- [x] **T12:** `User::verifyEmail()` (idempotent), `isEmailVerified()`, `isUsable()` — invariants
      I-4/I-5 — plus `grantRole()` / `revokeRole()` and `Exception/CannotRevokeBaseRole`.
- [x] **T13:** `Port/UserRepository` — `nextIdentity`, `save`, `findById`, `findByEmail`,
      `existsByEmail`. Plus `Exception/EmailAlreadyRegistered` and `Exception/UserNotFound`.
- [x] **T14:** `Port/PasswordHasher` — `hash(PlainPassword): HashedPassword`. Hashing only; no
      `verify()` (Symfony Security verifies).
- [x] **T15:** Checkpoint commit — run `make stan deptrac` and confirm zero framework imports under
      `Domain/`. No new code; this is the "the Domain compiles and is pure" gate.

## Application

- [x] **T16:** `Application/Identity/Command/RegisterUser` (primitives; `#[\SensitiveParameter]`) +
      `Handler/RegisterUserHandler` — the 8-step flow in the technical plan, returning `UserId`.
- [x] **T17:** `Application/Identity/Command/VerifyUserEmail` + `Handler/VerifyUserEmailHandler` —
      load, verify, save, dispatch; idempotent via the aggregate.

## Infrastructure — shared adapters

- [x] **T18:** `Infrastructure/Shared/Clock/SystemClock` (UTC) and
      `Infrastructure/Shared/Event/SymfonyDomainEventDispatcher` (wraps `EventDispatcherInterface`),
      plus their two DI aliases in `config/services.yaml`.

## Infrastructure — persistence

- [x] **T19:** Custom DBAL types in `Infrastructure/Identity/Persistence/Doctrine/Type/`:
      `UserIdType`, `EmailType`, `HashedPasswordType`, `RoleSetType`; register them under
      `doctrine.dbal.types`.
- [x] **T20:** `Persistence/Doctrine/mapping/User.orm.xml` **+** the `doctrine.yaml` mapping switch:
      add the `Identity` XML mapping, **remove** the `App` → `src/Entity` attribute mapping, delete
      `src/src/Entity/`, drop the dead `'../src/Entity/'` exclude from `config/services.yaml`.
      `bin/console doctrine:mapping:info` must list `App\Domain\Identity\Entity\User`. **(AC-29)**
- [x] **T21:** `Persistence/Doctrine/DoctrineUserRepository` implementing `UserRepository` —
      `nextIdentity()` via `Uuid::v7()`, `save()` translating `UniqueConstraintViolationException` into
      `EmailAlreadyRegistered`, `findByEmail()`/`existsByEmail()`/`findById()`. Add the DI alias.
- [x] **T22:** Migration creating `identity_user` + `uniq_identity_user_email`, including
      `email_verified_at` (nullable) — generated with `make migration.make`, then **hand-reviewed**:
      correct types, working `down()`. Run `make migrate` and `make test.db`. **(AC-23, AC-31)**

## Infrastructure — security

- [x] **T23:** `Security/SecurityUser` — the `UserInterface` / `PasswordAuthenticatedUserInterface`
      adapter over the Domain `User`, carrying the id string for refresh.
- [x] **T24:** `Security/DomainUserProvider implements UserProviderInterface` — `loadUserByIdentifier`
      via `findByEmail`, `refreshUser` via `findById`, `UserNotFoundException` when absent. No
      `PasswordUpgraderInterface`.
- [x] **T25:** `Security/SymfonyPasswordHasher implements PasswordHasher` — resolves the hasher for
      `SecurityUser::class` from `PasswordHasherFactoryInterface`. Add the DI alias.
- [x] **T26:** `config/packages/security.yaml` — provider `identity_users`, `form_login`
      (`enable_csrf: true`, `default_target_path: app_account`), `logout`,
      `login_throttling: { max_attempts: 5, interval: '15 minutes' }`, `access_control` on `^/account`.
      **Deliberately no `user_checker`** — add the AC-24 rationale as a comment pointing at
      `identity-email-verification`.
- [x] **T27:** Redis wiring — `cache.app: cache.adapter.redis` + `default_redis_provider`, the
      `cache.rate_limiter` pool, and `RedisSessionHandler` for sessions (keep the `when@test` mock
      session). Verify a real round-trip in the container. **(AC-15, AC-18)**

## Infrastructure — HTTP, forms, console

- [x] **T28:** `Form/RegistrationFormData` DTO + constraints (`Email(strict)`, `Length(max:180)`,
      `Length(min:12,max:4096)`, `NotCompromisedPassword(skipOnError: true)`) and
      `Form/RegistrationFormType` (`RepeatedType`, `allow_extra_fields: false`). **(AC-20)**
- [x] **T29:** `Http/Controller/RegistrationController` (`/register`) + `templates/identity/register.html.twig`
      — map DTO → `RegisterUser`, catch `EmailAlreadyRegistered` into a form error, redirect to
      `app_login` with a flash. **(AC-1 … AC-4, AC-9)**
- [x] **T30:** `Http/Controller/SecurityController` (`/login`, `/logout`) +
      `templates/identity/login.html.twig` using `AuthenticationUtils`; render the error verbatim so
      unknown-email and wrong-password are identical. **(AC-12, AC-13)**
- [x] **T31:** `Http/Controller/AccountController` (`/account`) + `templates/identity/account.html.twig`
      showing only the authenticated user's own email; add a flash-message block to `base.html.twig`.
      **(AC-11, AC-21, AC-22)**
- [x] **T32:** `Console/VerifyUserEmailCommand` (`muzbar:identity:verify-email <email>`) over
      `VerifyUserEmailHandler` — exit 0 on verified/already-verified, 1 on unknown. **(AC-25, AC-26)**

## Tests (qa — written after implementation, by the independent agent)

- [x] **T33:** `tests/Factory/UserFactory` (Foundry) constructing through `User::register()`; a
      `verified()` state helper. Everything below depends on it.
- [x] **T34:** Domain unit tests — value objects: normalisation, bounds, equality, the `PlainPassword`
      masking/`__toString`-absence assertions. **(AC-5, AC-7, AC-10)**
- [x] **T35:** Domain unit tests — `User`: registration state + event, idempotent `verifyEmail`,
      `isUsable()` before/after, role invariants, `releaseEvents()` empties. **(AC-24, AC-27)**
- [x] **T36:** Integration — `DoctrineUserRepository`: full VO round-trip after `clear()`, duplicate →
      `EmailAlreadyRegistered`, `nextIdentity()` uniqueness/validity. **(AC-9)**
- [x] **T37:** Integration — `RegisterUserHandler` + `VerifyUserEmailHandler` with a fixed `Clock` and a
      spy dispatcher: happy path, duplicate, weak password (nothing persisted), idempotent verify.
      **(AC-2, AC-27)**
- [x] **T38:** Functional — registration: happy path, mixed-case duplicate, weak password, mismatch,
      bad email, missing CSRF, `roles[]=ROLE_ADMIN` ignored. **(AC-1 … AC-8, AC-20)**
- [x] **T39:** Functional — login/logout: success → `/account`, wrong password, **unknown email is
      byte-identical**, session id rotates, logout kills the session, anonymous `/account` redirects.
      **(AC-11 … AC-13, AC-16, AC-17, AC-19)**
- [x] **T40:** Functional — throttling: five failures then a distinct sixth. **(AC-14)**
- [x] **T41:** Functional — console command: verify, verify again (idempotent, timestamp unchanged),
      unknown email exits 1. **(AC-25, AC-26)**
- [x] **T42:** Infrastructure assertions — `EXPLAIN` on the email lookup shows an Index Scan on
      `uniq_identity_user_email`; the `cache.rate_limiter` pool and the session handler resolve to
      Redis. **(AC-15, AC-18, AC-32)**

## Docs & verify

- [x] **T43:** Write the two flagged ADRs with `/adr` — **ADR-0007 "Persistence conventions for Domain
      aggregates"** and **ADR-0008 "Domain events: recorded on the aggregate, released by the handler"**
      — and (if the human agreed) the dated amendment to ADR-0005's *Consequences* recording the phased
      enforcement of the usable-account invariant.
- [x] **T44:** Docs: CLAUDE.md (mapping location, table-naming convention, the new `make`-visible
      commands), `docs/roadmap.md` (tick the slice), `FORboehpyk.md` (the running story — the
      verification-invariant decision, the Deptrac blind spot, and the Doctrine-mapping trap are the
      three lessons worth telling).
- [ ] **T45:** `/verify` → `make check` green, reviewer PASS (zero CRITICAL / MAJOR), all 32 acceptance
      criteria checked off, then open the PR.
