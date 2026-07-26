# Technical Plan: identity-user-password-auth

> The *how*. Disposable. Written after the feature-spec is drafted, approved with it before any code.
> Follows DDD canonical order.

**Bounded context:** `Identity` (Constitution §4) — owns users, roles, OAuth identities and API keys.
This slice touches only the user/roles/password part of it. Nothing outside `Identity` and
`Shared` is created.

**Namespaces claimed by this slice**

```
src/src/Domain/Shared/            Event/, Port/                     ← first content ever
src/src/Domain/Identity/          Entity/, ValueObject/, Event/, Port/, Exception/
src/src/Application/Identity/     Command/, Handler/
src/src/Infrastructure/Shared/    Clock/, Event/
src/src/Infrastructure/Identity/  Persistence/Doctrine/{,Type,mapping}, Security/, Http/Controller/,
                                  Form/, Console/
```

---

## Domain layer (pure PHP)

Zero `use Symfony\...` / `use Doctrine\...`. Only core PHP (`\DateTimeImmutable`, `filter_var`,
`\InvalidArgumentException`) is permitted.

### Aggregate / entity changes

**`Domain/Identity/Entity/User`** — the aggregate root and the consistency boundary. Small on purpose:
one row, no collections in this slice (`OAuthIdentity` collection arrives in `identity-google-oauth`,
`ApiKey` is a separate aggregate in Phase 3).

State:

| Property | Type | Notes |
|---|---|---|
| `id` | `UserId` | assigned at construction, never changes |
| `email` | `Email` | normalised; the natural key |
| `passwordHash` | `HashedPassword` | opaque |
| `roles` | `list<Role>` | always contains `Role::User` |
| `emailVerifiedAt` | `?\DateTimeImmutable` | `null` = unverified |
| `registeredAt` | `\DateTimeImmutable` | supplied by the `Clock`, never `new \DateTimeImmutable()` inside the aggregate |

Behaviour — no public setters, no public constructor:

- `public static function register(UserId $id, Email $email, HashedPassword $hash, \DateTimeImmutable $registeredAt): self`
  — the only creation path. Records `UserRegistered`.
- `public function verifyEmail(\DateTimeImmutable $at): void` — **idempotent**: a second call on an
  already-verified user is a no-op and records no event. Records `UserEmailVerified` on the first call.
- `public function isEmailVerified(): bool`
- `public function isUsable(): bool` — **the ADR-0005 invariant, expressed in code.** Today:
  `return $this->isEmailVerified();`. `identity-google-oauth` extends it to
  `isEmailVerified() || hasVerifiedOAuthIdentity()`. The method exists now precisely so that extension
  is a one-line change inside the aggregate rather than a redesign.
- `public function grantRole(Role $role): void` / `revokeRole(Role $role): void` — guard the role
  invariant. *(Not reachable from any route in this slice; reachable from tests and from the future
  admin slice. If the reviewer judges these premature, dropping them costs nothing — see Risks.)*
- Readers: `id()`, `email()`, `passwordHash()`, `roles()`, `emailVerifiedAt()`, `registeredAt()`.
- `releaseEvents(): list<DomainEvent>` via the `RecordsEvents` trait.

**Invariants, and what protects them**

| # | Invariant | Protected by |
|---|---|---|
| I-1 | A `User` always has a valid, normalised `Email`. | The `Email` VO constructor — impossible to build an invalid one. |
| I-2 | A `User` always has a non-empty `HashedPassword`; the Domain never sees plaintext at rest. | The `HashedPassword` VO + `register()` signature: it accepts only `HashedPassword`, never `string`. |
| I-3 | `roles` always contains `Role::User`, holds no duplicates, and is never empty. | `register()` seeds `[Role::User]`; `grantRole()` de-duplicates; `revokeRole()` refuses to remove `Role::User` (throws `CannotRevokeBaseRole`). |
| I-4 | `emailVerifiedAt` moves `null → timestamp` exactly once and never back. | `verifyEmail()` is idempotent and there is no "unverify" operation. |
| I-5 | **"A usable account has a verified email or a linked verified OAuth identity"** (ADR-0005). | Modelled by `isUsable()`. **Enforcement is explicitly deferred** — see *Risks / open questions → the verification-invariant tension*. |
| I-6 | Email uniqueness across all users. | **Not an aggregate invariant** — it spans instances, so it cannot be. Guarded by `UserRepository::existsByEmail()` (the pre-check) plus a database unique index (the truth), with the adapter translating the constraint violation back into a domain exception. This asymmetry is itself the lesson. |

### Value objects

All are `final readonly` classes, validated in the constructor, compared by value via `equals()`,
constructed through a named `fromString()` factory. They are VOs — not entities — because they have no
identity of their own, no lifecycle, and two instances with the same value are interchangeable.

| VO | Why it is a VO | Validation / normalisation |
|---|---|---|
| `Email` | Interchangeable by value; the whole point is that `Max@Example.com` and `max@example.com` **are the same email**, which a `string` cannot express. | `trim()`, then `mb_strtolower()` on the entire address; `filter_var(..., FILTER_VALIDATE_EMAIL)`; length ≤ 180. Throws `InvalidEmail`. **Decision:** we lower-case the *local part* too, though RFC 5321 permits case-sensitivity there — every real-world provider treats it case-insensitively, and the alternative (case-sensitive local parts) creates duplicate accounts for the same human and breaks account linking in `identity-google-oauth`. Recorded here so the choice is deliberate, not accidental. |
| `HashedPassword` | Immutable, opaque, compared by value; wrapping it makes "this string is a hash, not a password" a *type*, so `register(…, string $password)` becomes unwriteable. | Non-empty, length ≤ 255. **Deliberately no format validation** — validating `$2y$`/`$argon2` prefixes would couple the Domain to an Infrastructure algorithm choice. |
| `PlainPassword` | Carries the **password policy** — that policy is domain knowledge, not a form annotation. Transient: never persisted, never leaves the handler. | Length ≥ 12 and ≤ 4096 (Symfony's `NativePasswordHasher` hard limit; it pre-hashes for bcrypt, so the 72-byte truncation footgun does not apply). Throws `WeakPassword`. No `__toString()`; `__debugInfo()` returns `['value' => '***']`; constructor parameter marked `#[\SensitiveParameter]` so stack traces redact it. |
| `UserId` | An identity **value**: immutable, compared by value, meaningful outside the aggregate (events carry it). | UUID-format regex (pure PHP, no `symfony/uid` in Domain). Throws `InvalidUserId`. **Generation happens in Infrastructure** — see the port note below. |
| `Role` (enum) | A closed set of named constants; a backed enum is the honest PHP modelling of it. | `enum Role: string { case User = 'ROLE_USER'; case Business = 'ROLE_BUSINESS'; case Admin = 'ROLE_ADMIN'; }` — TitleCase cases per CLAUDE.md, backed values are exactly the strings Symfony Security expects. |

### Domain events

`Domain/Shared/Event/DomainEvent` — an interface with `occurredAt(): \DateTimeImmutable`.
`Domain/Shared/Event/RecordsEvents` — a trait providing `recordThat()` and `releaseEvents()` (which
empties the buffer, so events cannot be dispatched twice).

| Event | Raised by | Payload | Who reacts *today* |
|---|---|---|---|
| `UserRegistered` | `User::register()` | `UserId`, `Email`, `occurredAt` | Nobody. The event is dispatched to Symfony's dispatcher and asserted in a functional test. `identity-email-verification` subscribes to it to send the verification email — which is exactly why it exists now. |
| `UserEmailVerified` | `User::verifyEmail()` | `UserId`, `occurredAt` | Nobody yet; the welcome email / "you may now sell" flows hang off it later. |

Events carry **value objects, not entities** — an event must be a self-contained fact, and handing a
mutable aggregate to a listener is how aggregates get corrupted from the outside.

### Ports (interfaces)

`Domain/Identity/Port/UserRepository`

```
nextIdentity(): UserId
save(User $user): void
findById(UserId $id): ?User
findByEmail(Email $email): ?User
existsByEmail(Email $email): bool
```

`nextIdentity()` is the classic DDD identity-generation pattern: the **repository mints the id**, so
the aggregate is complete and valid before it ever meets a database, and no code depends on a
post-flush auto-increment. It also keeps UUID generation (a vendor concern — `symfony/uid`) out of the
Domain without hand-rolling UUIDv7 from `random_bytes`.

`Domain/Identity/Port/PasswordHasher`

```
hash(PlainPassword $password): HashedPassword
```

**Hashing only — no `verify()`.** Per ADR-0005, credential *checking* happens in the Symfony Security
layer, which verifies the hash itself; a `verify()` on this port would have no caller and would be
dead code. When `identity-password-reset` needs re-hashing it reuses `hash()`.

`Domain/Shared/Port/Clock` — `now(): \DateTimeImmutable` (UTC). Injected into handlers so registration
and verification timestamps are deterministic in tests and the aggregate never reaches for the wall
clock.

`Domain/Shared/Port/DomainEventDispatcher` — `dispatch(DomainEvent ...$events): void`.

### Domain exceptions

`Domain/Identity/Exception/`: `InvalidEmail`, `InvalidUserId`, `WeakPassword`, `EmailAlreadyRegistered`,
`CannotRevokeBaseRole`, `UserNotFound`. All extend `\DomainException`. Exception **messages must never
interpolate a password**; `EmailAlreadyRegistered` may carry the email (it is server-side only — the
HTTP layer maps it to a fixed user-facing string).

---

## Application layer

Thin, framework-free, depends only on `Domain`.

### Command / Query

`Application/Identity/Command/RegisterUser`

| Field | Type | Notes |
|---|---|---|
| `email` | `string` | raw, un-normalised |
| `plainPassword` | `string` | `#[\SensitiveParameter]` on the constructor |

`Application/Identity/Command/VerifyUserEmail`

| Field | Type | Notes |
|---|---|---|
| `email` | `string` | raw |

**Commands carry primitives, not value objects.** The handler builds the VOs, so the domain invariants
hold no matter which adapter dispatches the command (HTTP form today, console command today, OAuth
callback and verification link later). The Infrastructure boundary still validates first — for
*message quality and UX*; the VO validates for *correctness*. Two layers, two different jobs, and the
handler tests prove the second one works without the first.

No queries in this slice. `/account` renders from the authenticated `SecurityUser`; adding a
`UserProfileQuery` for a page that shows one email the session already holds would be ceremony.

### Handlers

`RegisterUserHandler::__invoke(RegisterUser $command): UserId`

1. `$email = Email::fromString($command->email)` → throws `InvalidEmail`.
2. `if ($this->users->existsByEmail($email)) throw EmailAlreadyRegistered::withEmail($email);`
3. `$plain = PlainPassword::fromString($command->plainPassword)` → throws `WeakPassword`.
4. `$hash = $this->hasher->hash($plain);`
5. `$user = User::register($this->users->nextIdentity(), $email, $hash, $this->clock->now());`
6. `$this->users->save($user);`
7. `$this->events->dispatch(...$user->releaseEvents());`
8. `return $user->id();`

`VerifyUserEmailHandler::__invoke(VerifyUserEmail $command): void`

1. `$user = $this->users->findByEmail(Email::fromString($command->email)) ?? throw UserNotFound::withEmail(...);`
2. `$user->verifyEmail($this->clock->now());`
3. `$this->users->save($user);`
4. `$this->events->dispatch(...$user->releaseEvents());` — empty on a repeat call, because `verifyEmail()`
   is idempotent. The idempotency lives in the aggregate, which is where it belongs.

**Transaction boundary.** One command = one transaction. `save()` performs `persist` + `flush` inside
the adapter; the handler owns no Doctrine concept. Events are dispatched **after** a successful
`save()`, so no listener can ever observe a fact that was rolled back. *(Consequence to accept
knowingly: dispatch is outside the transaction, so a crash between flush and dispatch loses the event.
Acceptable now — nothing listens. When `identity-email-verification` makes a listener load-bearing,
revisit with a transactional outbox or `DispatchAfterCurrentBusStamp`. Noted in Risks.)*

### Idempotency

- `RegisterUser` is **not** idempotent by design: a repeat is a duplicate-email failure, which is the
  correct answer. Duplicate protection is two-layered (pre-check + unique index, see I-6).
- `VerifyUserEmail` **is** idempotent (I-4) — necessary because `identity-email-verification` will
  expose it as a link users click twice, and mail clients pre-fetch links.

---

## Infrastructure layer

### Persistence — and the Doctrine-mapping decision

Today `config/packages/doctrine.yaml` auto-maps `%kernel.project_dir%/src/Entity` with prefix
`App\Entity` and `type: attribute` — the Symfony skeleton default. That directory holds nothing but a
`.gitignore`. Left as-is it is a trap: `make:migration` and every tutorial reflex would put entities in
`App\Entity` with ORM attributes on them, which is (a) outside all three layers and (b) a direct
Constitution §4.2 violation the moment a Domain entity is annotated.

**Decision (three parts):**

1. **Mapping format: XML, one file per aggregate, living in `Infrastructure`.**
   `src/src/Infrastructure/Identity/Persistence/Doctrine/mapping/User.orm.xml`, declared with
   `prefix: 'App\Domain\Identity\Entity'`. The Domain class stays a plain PHP object. XML is chosen
   over attributes because attributes on a Domain class are a `use Doctrine\...` import — the exact
   thing Deptrac and Constitution §4.2 forbid — and XML is the only mapping driver that keeps the
   metadata physically outside the class. `validate_xml_mapping: true` is already on, so a malformed
   mapping fails loudly at cache warm-up rather than at runtime.
2. **Replace the `App\Entity` mapping, do not add alongside it.** Remove the `App` mapping block, delete
   `src/src/Entity/`, and drop the now-dead `'../src/Entity/'` exclude from `config/services.yaml`.
   Each future context adds its own mapping block:

   ```yaml
   mappings:
       Identity:
           type: xml
           is_bundle: false
           dir: '%kernel.project_dir%/src/Infrastructure/Identity/Persistence/Doctrine/mapping'
           prefix: 'App\Domain\Identity\Entity'
           alias: Identity
   ```
3. **Value objects map through custom DBAL types, not embeddables.** Four tiny types in
   `Infrastructure/Identity/Persistence/Doctrine/Type/`:

   | Type name | Class | Column | Converts |
   |---|---|---|---|
   | `identity_user_id` | `UserIdType` (extends `GuidType`) | `UUID` | `UserId` ↔ `string` |
   | `identity_email` | `EmailType` (extends `StringType`) | `VARCHAR(180)` | `Email` ↔ `string` |
   | `identity_password_hash` | `HashedPasswordType` (extends `StringType`) | `VARCHAR(255)` | `HashedPassword` ↔ `string` |
   | `identity_role_set` | `RoleSetType` (extends `JsonType`) | `JSON` | `list<Role>` ↔ `string[]` |

   Embeddables were considered and rejected: they force a `@Embeddable` mapping for every one-field VO,
   they cannot express a single-column unique index cleanly, and they make the mapping XML noisier than
   the four ten-line type classes. Registered under `doctrine.dbal.types`.

   *Footgun to respect:* Doctrine's UnitOfWork compares custom-typed values with `!==`, so replacing a
   VO with an equal-but-distinct instance marks the entity dirty and emits a harmless UPDATE. Because
   our VOs are immutable and only replaced on real change, this is benign — but it is why VOs must
   never be mutated in place.

**Identifier strategy:** `<id name="id" type="identity_user_id"><generator strategy="NONE"/></id>` —
the id is assigned, never generated by the database. The existing
`identity_generation_preferences: PostgreSQLPlatform: identity` setting therefore does not apply to
this table; leave it for future aggregates that want it.

**Adapter:** `Infrastructure/Identity/Persistence/Doctrine/DoctrineUserRepository implements UserRepository`,
constructed with `EntityManagerInterface`.

- `nextIdentity()` → `UserId::fromString(Uuid::v7()->toRfc4122())` — UUIDv7 for time-ordered,
  index-friendly primary keys (random UUIDv4 keys fragment B-tree pages).
- `save()` → `persist` + `flush`, wrapped in a `try/catch (UniqueConstraintViolationException $e)` that
  rethrows `EmailAlreadyRegistered`. This translation is the adapter's job: the Domain must not know
  what a SQL constraint is.
- `findByEmail()` → DQL on the `email` field, hitting `uniq_identity_user_email`.

### Security

- **`Infrastructure/Identity/Security/SecurityUser`** — `final readonly`, implements `UserInterface` and
  `PasswordAuthenticatedUserInterface`. Built from a Domain `User`; exposes
  `getUserIdentifier()` (the email string), `getRoles()` (role strings), `getPassword()` (the hash
  string), `eraseCredentials()` (no-op — nothing plaintext is held). Also carries the `UserId` string so
  `refreshUser()` can reload by identity rather than by a mutable email. **The Domain `User` does not
  implement `UserInterface`** — that is the single most important line in this section, and AC-29 tests
  for it.
- **`Infrastructure/Identity/Security/DomainUserProvider implements UserProviderInterface`** — uses
  `UserRepository::findByEmail()` / `findById()`, maps to `SecurityUser`, throws `UserNotFoundException`
  when absent. **Does not** implement `PasswordUpgraderInterface` (explicit non-goal).
- **`Infrastructure/Identity/Security/SymfonyPasswordHasher implements PasswordHasher`** — wraps
  `PasswordHasherFactoryInterface`, resolving the hasher **for `SecurityUser::class`** so registration
  hashes with exactly the algorithm the firewall will later verify with. Using
  `UserPasswordHasherInterface` instead would require an existing user object, which does not exist yet
  at registration time — a small trap worth avoiding.
- **`config/packages/security.yaml`:**
  - `providers.identity_users: { id: App\Infrastructure\Identity\Security\DomainUserProvider }`
    (replaces `users_in_memory`).
  - `firewalls.main`: `lazy: true`, `provider: identity_users`,
    `form_login: { login_path: app_login, check_path: app_login, enable_csrf: true, default_target_path: app_account }`,
    `logout: { path: app_logout, target: app_login }`,
    `login_throttling: { max_attempts: 5, interval: '15 minutes' }`.
  - `access_control: - { path: ^/account, roles: ROLE_USER }`.
  - **No `user_checker`** — see the deferral decision. `hide_user_not_found` stays at its secure default.
  - Keep the existing `when@test` low-cost hasher block.

### HTTP / UI

| Route name | Path | Method | Controller |
|---|---|---|---|
| `app_register` | `/register` | GET, POST | `Infrastructure/Identity/Http/Controller/RegistrationController` |
| `app_login` | `/login` | GET, POST | `.../SecurityController::login` (renders form + `AuthenticationUtils` error) |
| `app_logout` | `/logout` | GET | `.../SecurityController::logout` (empty — the firewall intercepts) |
| `app_account` | `/account` | GET | `.../AccountController` |

Templates: `templates/identity/register.html.twig`, `templates/identity/login.html.twig`,
`templates/identity/account.html.twig`, extending the untouched skeleton `base.html.twig` plus a
minimal flash-message block. No SCSS, no Tailwind, no Live Component — all deferred by the non-goals.

**Form:** `Infrastructure/Identity/Form/RegistrationFormData` (a mutable DTO with Validator constraints)
+ `RegistrationFormType`:

- `email` — `NotBlank`, `Email(mode: 'strict')`, `Length(max: 180)`.
- `plainPassword` — `RepeatedType(PasswordType)`, `NotBlank`, `Length(min: 12, max: 4096)`,
  `NotCompromisedPassword(skipOnError: true)`.
- CSRF on by default (`symfony/security-csrf` is already installed; `framework.csrf_protection` comes in
  with the Form recipe).
- The controller maps the DTO to `RegisterUser`, dispatches it to the handler (direct call — **no
  Messenger bus in this slice**), and catches `EmailAlreadyRegistered` to attach a form error, so the
  race path (AC-9) and the pre-check path render identically.

### Console

`Infrastructure/Identity/Console/VerifyUserEmailCommand` — `muzbar:identity:verify-email <email>`.
Calls `VerifyUserEmailHandler`. This is what gives the verification use case a **real caller in slice
1** and gives the operator a way to make an account usable before the email flow exists; slice 2 adds a
second adapter (the signed-link controller) over the *same* handler. That handler-with-two-adapters
shape is hexagonal architecture demonstrating itself, which is worth the twenty lines.

### Async / schedule

None. No Messenger transport, no Scheduler task. Domain events dispatch **synchronously** via Symfony's
event dispatcher.

### External

None. No mailer, no HTTP client — except the Validator's own HIBP call for `NotCompromisedPassword`,
configured `skipOnError: true` and already disabled in the test environment.

### DI wiring (`config/services.yaml`)

Symfony does not auto-alias interfaces; every port needs an explicit binding. **A port with no binding
is a bug** (ddd-feature skill):

```yaml
App\Domain\Identity\Port\UserRepository: '@App\Infrastructure\Identity\Persistence\Doctrine\DoctrineUserRepository'
App\Domain\Identity\Port\PasswordHasher: '@App\Infrastructure\Identity\Security\SymfonyPasswordHasher'
App\Domain\Shared\Port\Clock: '@App\Infrastructure\Shared\Clock\SystemClock'
App\Domain\Shared\Port\DomainEventDispatcher: '@App\Infrastructure\Shared\Event\SymfonyDomainEventDispatcher'
```

The existing `exclude: ['../src/Domain/']` already keeps Domain classes from being registered as
services — correct, and it stays.

### Redis wiring (Constitution §3)

- `config/packages/cache.yaml`: `framework.cache.app: cache.adapter.redis`,
  `default_redis_provider: '%env(REDIS_URL)%'`, and a dedicated pool
  `cache.rate_limiter: { adapter: cache.adapter.redis }` — the pool Symfony's `login_throttling`
  limiters use.
- `config/packages/framework.yaml`: session handler → `RedisSessionHandler` fed by the existing
  `Predis\Client` service. The `when@test` `mock_file` session factory stays, so tests are unaffected.
- **Footgun:** the image has no `ext-redis`; `RedisAdapter::createConnection()` must fall back to the
  installed `predis/predis ^3`. Verify at implementation time (`bin/console cache:pool:list` + a real
  round-trip). Fallback if Symfony's cache component rejects predis 3: register the pool with an
  explicit `Predis\Client` provider, or add `ext-redis` to the Dockerfile (a `devops` task). CI already
  runs a `redis:7-alpine` service, so this is exercised in CI, not only locally.

### Dependencies to add

`composer require symfony/form symfony/rate-limiter symfony/translation`

- `symfony/form` — the registration form (not currently installed).
- `symfony/rate-limiter` — required by `login_throttling`; without it the config silently... does not
  boot.
- `symfony/translation` — the Twig form theme runs labels and errors through `|trans`; without a
  translator the form template blows up. Cheap, and every later UI slice needs it.

`symfony/validator`, `symfony/security-csrf`, `symfony/password-hasher`, `symfony/uid` and
`symfony/clock` are already installed.

### Tooling change — Deptrac actually enforcing Domain purity

Current `deptrac.yaml` defines only `Domain`, `Application`, `Infrastructure`. Deptrac reports
violations **between defined layers**; classes in no layer (i.e. all of `Symfony\*` and `Doctrine\*`)
are unassigned and therefore permitted everywhere. So today a `use Symfony\Component\Uid\Uuid;` inside
`Domain/` would pass `make deptrac` — the gate Constitution §4 leans on has never actually been able to
catch its headline rule, because there has never been Domain code to catch.

Add vendor layers:

```yaml
- name: SymfonyVendor
  collectors: [{ type: classLike, value: '^Symfony\\.*' }]
- name: DoctrineVendor
  collectors: [{ type: classLike, value: '^Doctrine\\.*' }]

ruleset:
  Domain: ~
  Application: [Domain]
  Infrastructure: [Domain, Application, SymfonyVendor, DoctrineVendor]
```

AC-28 requires demonstrating the failure once and reverting it — an unproven guard is not a guard.

---

## Interface boundary & input contract

**`POST /register`** — `multipart/form-data` or `application/x-www-form-urlencoded`

| Field | Accepts | Rejects |
|---|---|---|
| `registration_form[email]` | non-empty string, ≤ 180 chars, valid per `Email(mode: strict)`; normalised to trimmed lower-case | blank, malformed, > 180 chars, already registered |
| `registration_form[plainPassword][first]` | 12–4096 chars, not in the HIBP corpus | shorter/longer, compromised |
| `registration_form[plainPassword][second]` | must equal `first` | mismatch |
| `registration_form[_token]` | valid CSRF token | missing/stale/tampered |
| **anything else** | **ignored** — the form is `allow_extra_fields: false` and the DTO has no `roles` property, so `roles[]=ROLE_ADMIN` cannot bind (AC-20) | |

Responses: `302 → /login` + flash on success; **`422` with field errors on failure** — measured and
pinned during implementation (2026-07-26), so that is now the contract, not an open question.

**`POST /login`** — `_username`, `_password`, `_csrf_token`. Handled entirely by Symfony's
`form_login`. Success → `302 /account`. Failure → re-render with a **fixed** error string; the response
must not vary with whether the email exists (AC-13).

**`GET /account`** — requires `ROLE_USER`; renders the authenticated user's own email and nothing else
about any other user.

**`muzbar:identity:verify-email <email>`** — one required argument. Exit 0 on verify or on already-
verified; exit 1 on unknown email or invalid address.

**Application contract:** `RegisterUserHandler::__invoke(RegisterUser): UserId`, throwing
`InvalidEmail | WeakPassword | EmailAlreadyRegistered`.
`VerifyUserEmailHandler::__invoke(VerifyUserEmail): void`, throwing `InvalidEmail | UserNotFound`.

---

## Data & migrations

The repository's **first migration**. Additive, no dependency on existing data.

```sql
CREATE TABLE identity_user (
    id                UUID                        NOT NULL,
    email             VARCHAR(180)                NOT NULL,
    password_hash     VARCHAR(255)                NOT NULL,
    roles             JSON                        NOT NULL,
    email_verified_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
    registered_at     TIMESTAMP(0) WITH TIME ZONE NOT NULL,
    PRIMARY KEY (id)
);
CREATE UNIQUE INDEX uniq_identity_user_email ON identity_user (email);
```

- **Table naming convention established here:** `<context>_<aggregate>`, singular — `identity_user`,
  later `catalog_category`, `listing_listing`… Contexts stay legible in `\dt` and two contexts can own a
  `user`-ish concept without colliding. Table and column names are declared explicitly in the XML, so
  the `underscore` naming strategy never surprises us.
- **`uniq_identity_user_email`** serves two queries: the registration pre-check
  (`existsByEmail`) and every single login (`findByEmail`). It is also the enforcement of I-6.
  AC-32 asserts `EXPLAIN` picks it.
- **Timestamps are `TIMESTAMP WITH TIME ZONE`** (Doctrine `datetimetz_immutable`) and the `Clock`
  returns UTC. Postgres stores an absolute instant; no timezone ambiguity, no naive-datetime bugs.
- **`email_verified_at` ships in this first migration although nothing sets it via the web.** That is
  the whole point of the verification decision below: `identity-email-verification` must not need a
  migration on this table.
- `down()` drops the table and the index. AC-31 exercises it.
- No backfill, no backward-compatibility concern — the table does not exist yet anywhere, including
  production.

---

## Test plan

**Domain unit (no kernel boot, `tests/Unit/Domain/Identity/`)**

- `Email`: normalisation (case, whitespace), equality, rejection of malformed and > 180-char values.
- `PlainPassword`: min/max bounds; `__debugInfo()` masks; no `__toString()` exists (reflection assertion).
- `HashedPassword`: non-empty; **accepts an arbitrary opaque string** (proving the Domain does not care
  about the algorithm).
- `UserId`: format validation, equality.
- `User`: `register()` sets `[Role::User]`, leaves `emailVerifiedAt` null, records exactly one
  `UserRegistered`; `verifyEmail()` sets the timestamp, records `UserEmailVerified`, and a second call
  is a no-op recording nothing; `isUsable()` false before / true after; `revokeRole(Role::User)` throws;
  `grantRole()` de-duplicates; `releaseEvents()` empties the buffer.

**Application / Integration (real `muzbar_test` DB, DAMA rollback, `tests/Integration/`)**

- `DoctrineUserRepository`: save → clear identity map → `findById` round-trips **every VO** with equal
  values (this is the test that catches a broken DBAL type); `existsByEmail` is case-insensitive via
  normalisation; a second insert with the same email raises `EmailAlreadyRegistered`, not a raw
  Doctrine exception; `nextIdentity()` returns distinct, valid, monotonically increasing UUIDv7s.
- `RegisterUserHandler`: happy path persists and returns the id and dispatches `UserRegistered`
  (assert with a spy dispatcher); duplicate throws; weak password throws **before** anything is
  persisted; a fixed `Clock` stub proves `registeredAt` comes from the port.
- `VerifyUserEmailHandler`: verifies, is idempotent, throws `UserNotFound`.
- **Foundry factory** `tests/Factory/UserFactory` — must construct through `User::register()` (Foundry
  supports factory callbacks); it must not reach for setters that do not exist. Written early because
  every functional test needs it.

**Functional (`tests/Functional/Identity/`)**

- Registration: happy path (AC-2, AC-3), duplicate with mixed case (AC-4), weak password (AC-5),
  mismatch (AC-6), bad email (AC-7), missing CSRF (AC-8), `roles[]` injection ignored (AC-20).
- Login: success → `/account` (AC-11); wrong password (AC-12); **unknown email produces an identical
  error response** (AC-13); five failures then a distinct sixth (AC-14); session id rotates (AC-17);
  logout (AC-16); anonymous `/account` redirects (AC-19).
- Privacy: no hash, no roles, no foreign email in any rendered page (AC-21, AC-22).
- Console: verify, verify-again, unknown email (AC-25, AC-26).

**Performance / infrastructure checks**

- `EXPLAIN SELECT … WHERE email = $1` returns an Index Scan on `uniq_identity_user_email` (AC-32).
- Config assertion that the `cache.rate_limiter` pool resolves to a Redis adapter (AC-15) and that the
  session handler is `RedisSessionHandler` (AC-18).
- The Deptrac negative test (AC-28) is a **manual, documented one-off** during `/verify`, not a
  committed test — a committed always-failing fixture would break `make check`.

---

## Risks / open questions

### 1. The verification-invariant tension — the decision, stated plainly

ADR-0005 fixes the invariant *"a usable account has a verified email or a linked verified OAuth
identity"*, but email verification is a later slice. Three honest options existed:

| Option | Why not |
|---|---|
| **A — Enforce now:** register unverified, block login, verify only via the console command. | The invariant holds, but the slice ships a product where nobody except the box operator can ever log in. A feature whose happy path is unreachable is not a shippable slice. |
| **B — Mark users verified at registration**, "un-verify" them in slice 2. | A lie in the data (`email_verified_at` asserting a proof that never happened), and slice 2 inherits a backfill decision about pre-existing rows. Exactly the throwaway work the brief forbids. |
| **C — Ignore verification entirely now; add the column, the state and the invariant in slice 2.** | Reopens the aggregate's design *and* costs a migration against `identity_user` — the two outcomes the brief explicitly rules out. |

**Chosen: model fully, enforce later — and say so in the code's shape, not in a comment.**

1. **State ships now.** `emailVerifiedAt` is a property of the aggregate and a column in the *first*
   migration. Slice 2 needs **no migration on this table**.
2. **Behaviour ships now.** `verifyEmail()`, `isEmailVerified()` and `isUsable()` are written and
   unit-tested in this slice. `isUsable()` *is* invariant I-5 in executable form; slice 4 widens it by
   one boolean clause when `OAuthIdentity` arrives. The aggregate's design does not reopen.
3. **A real caller ships now**, so none of this is dead code: `VerifyUserEmail` + its handler +
   `muzbar:identity:verify-email`. Slice 2 adds a *second adapter* (the signed-link controller) over the
   same handler and deletes nothing.
4. **Only enforcement is deferred**, and it is deferred at exactly one point: the firewall has **no
   `UserCheckerInterface`**. Slice 2 adds `VerifiedAccountUserChecker` (≈ 15 lines) plus one line of
   `security.yaml`, and inverts AC-24. **The enforcement point is a single Infrastructure class — which
   is where ADR-0005 already says the authentication policy belongs** ("credential checking crosses the
   security layer, not the domain"). Nothing in `Domain` or `Application` changes.

**Residual risk:** between shipping this slice and shipping slice 2, unverified accounts can log in. The
mitigations are that Phase 1 is not publicly launched, that slice 2 is the *very next* cycle in the
roadmap, and that AC-24 is written as an explicitly invertible criterion so the debt is visible in the
spec rather than buried in code.

**→ Human decision requested:** should ADR-0005 get a short dated amendment to its *Consequences*
recording this phased enforcement? Without it, a reviewer comparing slice 1 against the ADR sees an
apparent contradiction. Recommended: yes, one paragraph, in the same PR.
**DECIDED 2026-07-25 — yes.** The amendment ships with this slice (task T43).

### 2. Decisions that should become ADRs (flagged, not written — use `/adr`)

- **Proposed ADR-0007 — "Persistence conventions for Domain aggregates."**
  *Decision at stake:* XML mapping under `Infrastructure` (never ORM attributes on Domain classes);
  custom DBAL types for value objects rather than embeddables; application-assigned UUIDv7 identity
  minted by `Repository::nextIdentity()` rather than database auto-increment; `<context>_<aggregate>`
  table naming; removal of the skeleton's `App\Entity` mapping. This is durable, cross-context, and
  every future context inherits it — the textbook ADR trigger. Should be written and accepted **with**
  this slice, not after.
- **Proposed ADR-0008 — "Domain events: recorded on the aggregate, released by the handler, dispatched
  through a Domain port."** *Decision at stake:* where events are buffered, who releases them, sync
  dispatch via Symfony's dispatcher now vs. Messenger later, and the after-commit ordering. Also
  durable and cross-context. Could reasonably wait until a second context raises events, but writing it
  now while the reasoning is fresh is cheaper.
- Redis for sessions and the rate-limiter store needs **no** ADR — Constitution §3 already locks it.

### 3. Other open questions for the human

- **`grantRole()` / `revokeRole()` have no caller in this slice.** Kept because I-3 is only meaningful
  if roles can change, and the unit tests exercise them. If the reviewer reads them as speculative,
  deleting them is a two-line change with no ripple. **Your call.**
  **DECIDED 2026-07-25 — keep them.**
- **Event dispatch happens after flush, outside the transaction.** Fine while nothing listens; becomes a
  real reliability question when slice 2 hangs the verification email off `UserRegistered`. Flagging it
  now so slice 2 decides deliberately (outbox vs. `DispatchAfterCurrentBusStamp` vs. accepting the risk).
- **Roadmap carry-overs that touch this slice but are not in it.** The roadmap parks two Phase-0 items
  on the doorstep of the Identity slices: the `pre-write-guard` Claude hook (which is precisely the
  guard that catches a stray `use Symfony\` in the `User` aggregate) and **Sentry** ("deferred to the
  first user-facing flow… which is when a silent 500 starts costing a signup"). Both are `devops` work,
  neither is `Identity` domain work, and folding them in would blur this slice. **Recommendation:** do
  them as a small separate cycle *before* `/implement` on this one — the hook especially, since it pays
  off while the Domain code is being written.
- **Registration is enumerable** (AC-4 tells an attacker an email is registered). Unavoidable without
  email. Revisit in `identity-email-verification`, which can adopt the "always say *check your inbox*"
  pattern.
- **Predis vs `ext-redis`** for the cache/rate-limiter pool — see the footgun note above. If it bites,
  the fix is a Dockerfile change, which is a `devops` task, not a reason to abandon Redis storage.
