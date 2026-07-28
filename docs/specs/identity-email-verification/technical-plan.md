# Technical Plan: identity-email-verification

> The *how*. Disposable. Written after the feature-spec is drafted, approved with it before any code.
> Follows DDD canonical order.

**Bounded context:** `Identity` (Constitution §4). Nothing outside `Identity` and `Shared` is
created. **`Notification` is deliberately not touched** — see the feature spec's non-goals; the mail
seam is a port *inside* `Identity`.

**Namespaces claimed by this slice**

```
src/src/Domain/Identity/          Entity/EmailVerificationRequest, ValueObject/{EmailVerificationRequestId,
                                  VerificationToken, HashedVerificationToken}, Event/EmailVerificationRequested,
                                  Port/{EmailVerificationRequestRepository, VerificationTokenGenerator,
                                  VerificationMailer}, Exception/*
src/src/Application/Identity/     Command/{RequestEmailVerification, VerifyEmailWithToken}, Handler/*
src/src/Infrastructure/Identity/  Persistence/Doctrine/{DoctrineEmailVerificationRequestRepository,
                                  Type/*, mapping/EmailVerificationRequest.orm.xml},
                                  Security/{VerifiedAccountUserChecker, RandomVerificationTokenGenerator},
                                  Mail/TwigVerificationMailer, EventListener/IssueVerificationOnUserRegistered,
                                  Http/Controller/EmailVerificationController, Form/*
```

**Unchanged on purpose:** `Domain/Identity/Entity/User`, `Application/Identity/{Command,Handler}/…UserEmail…`,
`Infrastructure/Identity/Console/VerifyUserEmailCommand`, and the `identity_user` table.

---

## Decisions taken — 2026-07-26

The owner resolved all four open decisions on 2026-07-26, each on the plan's recommendation. They are
recorded here; the two durable ones still need writing up as ADRs in **T0** before the Domain work
starts.

| # | Decision | Outcome |
|---|---|---|
| 1 | How verification tokens are modelled | **Option B — domain-modelled `EmailVerificationRequest` aggregate.** Needs **ADR-0009** + a dated amendment to ADR-0005's tooling parenthetical. |
| 2 | Event delivery and mail transport | **Synchronous PSR-14 event dispatch, asynchronous mail via Messenger over `doctrine://default`.** No outbox. Needs **ADR-0010** amending ADR-0008's watched clause. |
| 3 | Policy constants | **Confirmed as planned:** 24 h link lifetime, 5 issuances/user/hour, 5 resend POSTs/hour/IP. AC-1, AC-17 and AC-18 pin them. |
| 4 | The three smaller calls (Risks 4, 6, 7) | **`EmailVerificationRequested` is kept** (Risk 6 closed — it is a real auditable fact). **No shared UUID VO yet** (Risk 4 closed — wait for a third example). **`Referrer-Policy: no-referrer` is adopted** (Risk 7 closed → new **AC-39**). |

The two subsections below are kept as written because they are the *argument* the ADRs must record —
the ADR should not have to re-derive it.

### ⚠ Still to write in T0 — the ADRs themselves

### #1 — How email-verification tokens are modelled

**Decision at stake.** ADR-0005 and `docs/roadmap.md` both name **`symfonycasts/verify-email-bundle`**
in passing. That bundle is *stateless*: it stores nothing and instead HMAC-signs a URL with
`APP_SECRET` plus the user's id and address, and validates the signature on the way back. This plan
proposes the opposite: a **stored, hashed, single-use, expiring token modelled as its own aggregate**
in the `Identity` domain, with no bundle.

| Option | For | Against |
|---|---|---|
| **A — `verify-email-bundle` (as named in ADR-0005)** | Zero new tables, zero new domain code, a well-audited signature scheme, ships in an afternoon. | **Nothing is stored, so "single use" cannot exist** — a signed URL stays valid until it expires, replayable by anyone who reads the mailbox or a proxy log. Revocation is impossible. Rotating `APP_SECRET` silently invalidates every outstanding link. And the token concept never enters the model: the *only* interesting thing in the slice would live in a vendor bundle, which is precisely the trade Constitution §2 tells us to refuse when the alternative teaches. |
| **B — domain-modelled `EmailVerificationRequest` (proposed)** | Satisfies every security property the spec enumerates — hashed at rest, single-use via `redeemedAt`, time-limited, constant-time compare, revocable, auditable. Teaches the second aggregate, the cross-aggregate reference by identity, and eventual consistency. Reuses ADR-0007 exactly; no new persistence convention. | One table, one migration, ~8 new small classes, and a pruning job owed later. Contradicts ADR-0005's parenthetical, so that ADR needs a dated amendment. |

**Chosen: B** (owner, 2026-07-26), with a short ADR-0009 recording it and amending ADR-0005's tooling
parenthetical. The rest of this plan is written for B. Risk 1 (the Option-A delta) is therefore
**closed** and does not apply.

*(Sub-decision that rides along, and is small enough to be a paragraph inside the same ADR: **there
is no database foreign key from `identity_email_verification_request.user_id` to `identity_user.id`.**
Aggregates reference each other by identity, and a hand-added FK that Doctrine's mapping does not
know about shows up as spurious noise in every future `make migration.make` diff. The cost is that
orphan rows are possible if a user is ever hard-deleted — nothing deletes users today, and the
pruning job will sweep them. This is a convention every later context will copy, which makes it ADR
material, not a code comment.)*

### #2 — Event delivery and mail transport, now that a listener is load-bearing

**Decision at stake.** ADR-0008 §Consequences says, verbatim: *"The moment
`identity-email-verification` makes a listener load-bearing, this must be revisited… That slice must
make the choice deliberately rather than inheriting this one."* This slice hangs the verification
email off `UserRegistered`. Two orthogonal questions have to be answered together:

1. **Event publication:** stay with synchronous PSR-14 dispatch after `flush()` (a crash in the
   window between commit and dispatch loses the event), adopt a **transactional outbox**, or move to
   Messenger with `DispatchAfterCurrentBusStamp`.
2. **Mail delivery:** send **synchronously** inside the request (an SMTP hiccup then becomes a slow
   or failed signup for an account that already exists), or **asynchronously** via Messenger with
   retries and a failure transport (buys reliability, costs a worker process, a restart policy and a
   monitoring story on a one-box budget).

**Chosen** (owner, 2026-07-26): keep synchronous PSR-14 event dispatch (no outbox yet) **and** send
mail asynchronously through Messenger. The reasoning is that the two windows are not equally bad: a lost
*mail* is invisible and permanent, while a lost *event* here costs the user one click on the resend
form, which the slice ships anyway as the compensating action. That makes the resend endpoint the
honest, cheap alternative to an outbox — and it should be written down as the decision it is, not
discovered later.

**Transport note that constrains the choice:** Messenger's Redis transport **requires `ext-redis`**,
which this image does not have (slice 1 runs Redis through `predis/predis`). So the transport is
`doctrine://default` — durable, already-installed, and it costs one extra migration
(`messenger_messages`). In `APP_ENV=test`, route `SendEmailMessage` to `sync` so functional tests can
assert on the mailer.

**Operational consequence to accept explicitly:** a `messenger-worker` service in Compose plus a
restart policy. That is `devops` work with an `Identity` dependency — it must not be discovered at
the end of the slice.

### #3 — Not an ADR: what an unverified account may do

Already decided. ADR-0005's 2026-07-26 amendment fixes both the rule and its enforcement point: *"one
`VerifiedAccountUserChecker` (~15 lines) plus one line of `security.yaml`"*, blocking at
**authentication**. This plan implements exactly that and adds nothing. Recorded here only so that
the alternative — allowing an unverified session and gating individual actions behind a
`ROLE_VERIFIED`-style rule — is visibly *rejected* rather than never considered. It was rejected
because it multiplies enforcement points (every future action route becomes a place to forget) and
because ADR-0005 already put authentication policy in the Security layer.

---

## Domain layer (pure PHP)

Zero `use Symfony\...` / `use Doctrine\...`. Only core PHP (`\DateTimeImmutable`, `hash_equals`,
`preg_match`, `\DomainException`).

### The aggregate-boundary decision — and why the token is *not* on `User`

This is the modelling judgement of the slice, so it is argued rather than asserted.

| Candidate | Verdict |
|---|---|
| **Fields on `User`** (`verificationTokenHash`, `expiresAt`) | Rejected. It grows the root for a concern with a completely different clock speed — a `User` lives for years, a challenge for a day — and it puts a short-lived credential in the same row as the account's identity. It also drags a *multi-field, nullable* value object into the mapping, which Doctrine can only express as an embeddable (ADR-0007 decision 4 rejects embeddables) or as three loose nullable columns held in sync by hand. Both are worse than a table. |
| **A collection of challenge entities inside the `User` aggregate** | Rejected. Every resend appends a row, so the root acquires an unbounded collection — the classic aggregate smell — and every login would carry the risk of hydrating it. Modelling the challenge as an *entity* would also be dishonest: it has no continuity of identity worth tracking from the user's point of view; it is issued whole and redeemed whole. |
| **Its own aggregate root, `EmailVerificationRequest`** | **Chosen.** Different lifecycle, different rate of change, no invariant that demands *immediate* consistency with `User` (proved below), a natural home for the anti-abuse count, and a natural unit for a future pruning job. |

**The invariant test — the part worth internalising.** An aggregate boundary is drawn where a rule
must be true *at the end of every transaction*. So: is there a rule spanning the request and the
user that cannot tolerate a gap? Redemption changes two things — the request becomes redeemed and the
user becomes verified. Order them **user first, request second** and every crash window is benign:

- Crash after verifying the user, before marking the request redeemed → the user is verified and a
  live token exists. Redeeming it later is a **no-op**, because the handler short-circuits on an
  already-verified user (AC-8). Nothing is wrong.
- The reverse order would leave a burnt token and an unverified user — a user locked out of their own
  link. So the ordering is not cosmetic; it is what makes two aggregates safe here.

Because a safe ordering exists, the rule does **not** require one transaction, and Vernon's rule
("modify one aggregate per transaction, use eventual consistency between them") applies. This is also
the slice's answer to a question slice 1 raised and could not answer: invariant I-6 (email
uniqueness) showed a rule that *cannot* be an aggregate invariant because it spans instances; this
slice shows a rule that *could* have been forced into one aggregate and should not be.

*(Happy accident worth knowing, and worth **not** relying on: with the Doctrine adapter both aggregates
are managed by the same `EntityManager`, so the first `save()`'s `flush()` actually commits both
changes in one transaction. The port's per-aggregate `save()` is an abstraction over a global flush.
The ordering above is what keeps the design correct if that ever stops being true.)*

### Aggregate: `Domain/Identity/Entity/EmailVerificationRequest`

State:

| Property | Type | Notes |
|---|---|---|
| `id` | `EmailVerificationRequestId` | assigned at construction, never changes |
| `userId` | `UserId` | **a reference by identity** — never a `User` object (AC-34) |
| `tokenHash` | `HashedVerificationToken` | opaque; the plaintext never reaches this class |
| `issuedAt` | `\DateTimeImmutable` | from the `Clock` port |
| `expiresAt` | `\DateTimeImmutable` | derived, `issuedAt + LIFETIME_SECONDS` |
| `redeemedAt` | `?\DateTimeImmutable` | `null` = still live |

Policy constants (domain knowledge, deliberately **not** configuration — see *Risks*):

```php
public const int LIFETIME_SECONDS = 86400;   // 24 h
public const int MAX_ISSUES_PER_HOUR = 5;    // per user; read by the Application handler
```

Behaviour — no public constructor, no setters:

- `public static function issue(EmailVerificationRequestId $id, UserId $userId, HashedVerificationToken $tokenHash, \DateTimeImmutable $issuedAt): self`
  — the only creation path. Computes `expiresAt` itself so the lifetime rule cannot be varied per
  caller. Records `EmailVerificationRequested`.
- `public function redeem(HashedVerificationToken $presented, \DateTimeImmutable $at): void` —
  throws `EmailVerificationLinkAlreadyRedeemed`, then `EmailVerificationLinkExpired`, then
  `EmailVerificationTokenMismatch` (constant-time compare); otherwise sets `redeemedAt`. Records **no**
  event: the fact that matters to the rest of the system is `UserEmailVerified`, and it belongs to the
  `User` aggregate that raises it.
- `public function isRedeemed(): bool`, `public function isExpiredAt(\DateTimeImmutable $at): bool`
- Readers: `id()`, `userId()`, `issuedAt()`, `expiresAt()`, `redeemedAt()`.
- `releaseEvents()` via the `RecordsEvents` trait.

**Why `redeem()` re-checks the hash it was just looked up by.** The repository finds the request *by*
`token_hash`, so the comparison inside the aggregate is, strictly, redundant. It stays because a
repository query is a convenience and an aggregate is a rule-holder: "this token matches my
challenge" is the aggregate's rule to enforce, and a second adapter (or a future lookup by user id)
must not be able to bypass it by calling `redeem()` with the wrong token.

**Invariants, and what protects them**

| # | Invariant | Protected by |
|---|---|---|
| I-7 | A request always carries a non-empty token hash and belongs to exactly one user. | Typed constructor: `issue()` accepts `HashedVerificationToken` and `UserId`, never strings. |
| I-8 | `expiresAt` is always exactly `issuedAt + LIFETIME_SECONDS` and always after `issuedAt`. | Derived inside `issue()`; it is not a parameter, so no caller can shorten or extend it. |
| I-9 | A request is redeemed **at most once**: `redeemedAt` moves `null → instant` and never back. | `redeem()` throws on an already-redeemed request; there is no un-redeem operation (AC-9). |
| I-10 | An expired request can never be redeemed. | `redeem()` compares `$at` against `expiresAt` before mutating. |
| I-11 | Only the token whose hash matches may redeem the request. | `hash_equals` inside `HashedVerificationToken::equals()`, called by `redeem()`. |
| I-12 | *"At most 5 requests per user per rolling hour"* | **Not an aggregate invariant** — it spans instances, exactly like email uniqueness (I-6). Enforced by the Application handler over `EmailVerificationRequestRepository::countIssuedForUserSince()`, and it can lose a race under concurrent submissions. Accepted: the worst case is a sixth email, and the per-IP limiter is the second layer. Stating the asymmetry is the lesson. |
| I-13 | *(`User`, unchanged)* `emailVerifiedAt` moves `null → instant` exactly once. | Slice 1's idempotent `User::verifyEmail()`. Nothing here changes it. |

**A rule this slice deliberately does *not* adopt: reissuing does not invalidate outstanding links.**
Tempting by reflex, and wrong here. Once *any* token verifies the account, every other live token is
inert — redeeming one hits the already-verified short-circuit and changes nothing. The invalidation
would buy no security and would cost either an extra column or a cross-instance write. **This
reasoning does not transfer to `identity-password-reset`**, where a stale live token *is* dangerous
because the effect (setting a new password) can be applied repeatedly. Worth writing down so slice 3
does not copy the wrong precedent.

### Value objects

All `final readonly`, validated in the constructor, built through `fromString()`, compared by value.

| VO | Why it is a VO | Validation / normalisation |
|---|---|---|
| `EmailVerificationRequestId` | An identity *value*: immutable, compared by value, carried in an event. | RFC 4122 layout regex, lower-cased; throws `InvalidEmailVerificationRequestId`. Mirrors `UserId` exactly, including the deliberate absence of a `generate()` — the repository mints ids. *(The duplication with `UserId` is noted in Risks.)* |
| `VerificationToken` | Carries the **token policy** — what a token is made of and how long it is — which is domain knowledge, not a controller detail. Transient: never persisted. | Exactly **43** characters matching `^[A-Za-z0-9_-]{43}$` (32 bytes, base64url, unpadded). Throws `InvalidVerificationToken`. `#[\SensitiveParameter]` on the constructor, `__debugInfo()` returning `['value' => '***']`, and **no `__toString()`** — the same three guards `PlainPassword` carries, for the same reason. |
| `HashedVerificationToken` | Immutable, opaque, compared by value; makes "this string is a digest, not a token" a *type*, so `issue(…, string $token)` is unwriteable. | Non-empty, length ≤ 255. **No format check** — validating 64 hex characters would encode SHA-256, an Infrastructure choice, into a Domain rule. Exactly the reasoning `HashedPassword` uses. `equals()` uses **`hash_equals`**. |

**Why not Argon2 for the token (the teaching note).** Passwords need a slow KDF because they are
low-entropy and guessable offline. A verification token is 256 bits of CSPRNG output — an attacker
holding the digest cannot guess the pre-image at any cost, so a fast digest is the correct tool, and
a slow one would only add latency to every click. Same *pattern* as `PlainPassword`/`HashedPassword`,
different *reason*. The Domain does not state the algorithm at all; the adapter does.

### Domain events

| Event | Raised by | Payload | Who reacts *today* |
|---|---|---|---|
| `EmailVerificationRequested` | `EmailVerificationRequest::issue()` | `EmailVerificationRequestId`, `UserId`, `issuedAt`, `expiresAt` | Nobody. It is the auditable fact "we asked this account to prove its address at this time". **It carries no token and no email address** — a secret inside an event is a secret in every listener, every log line and, the day the transport goes async, every queue row. |
| `UserEmailVerified` *(slice 1, unchanged)* | `User::verifyEmail()` | `UserId`, `occurredAt` | Nobody yet; the welcome mail hangs off it later. |
| `UserRegistered` *(slice 1, unchanged)* | `User::register()` | `UserId`, `Email`, `occurredAt` | **Now load-bearing:** `IssueVerificationOnUserRegistered` listens. This is the trigger clause in ADR-0008 — see *Needs an ADR* #2. |

Note the deliberate asymmetry: the mail is **not** sent by a listener on
`EmailVerificationRequested`. The Application handler calls the `VerificationMailer` port directly,
because it is the only place that legitimately holds the plaintext token. Routing the secret through
an event bus to reach a listener would be an elegant-looking way to leak it.

### Ports (interfaces)

`Domain/Identity/Port/EmailVerificationRequestRepository`

```
nextIdentity(): EmailVerificationRequestId
save(EmailVerificationRequest $request): void
findByTokenHash(HashedVerificationToken $hash): ?EmailVerificationRequest
countIssuedForUserSince(UserId $userId, \DateTimeImmutable $since): int
```

`findByTokenHash()` returns the request whether or not it is redeemed or expired — **the repository
does not filter on business state.** A repository that silently hid expired rows would make "expired"
and "never existed" the same answer, and the failure contract distinguishes them internally even
though the *response* deliberately does not.

`Domain/Identity/Port/VerificationTokenGenerator`

```
generate(): VerificationToken
hash(VerificationToken $token): HashedVerificationToken
```

Two methods, one port, on purpose: the entropy source and the digest are a single crypto decision. A
`VerificationTokenHasher` split from a `…Generator` would let a future adapter pair a strong
generator with a weak digest and still satisfy both interfaces.

`Domain/Identity/Port/VerificationMailer`

```
sendVerificationLink(Email $recipient, VerificationToken $token, \DateTimeImmutable $expiresAt): void
```

Constitution §4.3 names a generic `MailerPort`; this port is deliberately narrower and
intention-revealing. A port states **what the Domain needs** ("tell this person how to prove their
address"), not what the vendor provides ("send a MIME message"). The adapter owns the URL, the
template, the sender address and the transport — none of which the Domain can name without importing
a framework.

### Domain exceptions

`Domain/Identity/Exception/`: `InvalidVerificationToken`, `InvalidEmailVerificationRequestId`,
`EmailVerificationRequestNotFound`, `EmailVerificationLinkExpired`,
`EmailVerificationLinkAlreadyRedeemed`, `EmailVerificationTokenMismatch`, `EmailAlreadyVerified`,
`TooManyVerificationRequests`. All extend `\DomainException`. **No exception message may contain a
plaintext token** (AC-2); messages may carry a request id or a user id, which are server-side only.

---

## Application layer

Thin, framework-free, depends only on `Domain`.

### Commands

`Application/Identity/Command/RequestEmailVerification`

| Field | Type | Notes |
|---|---|---|
| `email` | `string` | raw, un-normalised — the handler builds the `Email` |

`Application/Identity/Command/VerifyEmailWithToken`

| Field | Type | Notes |
|---|---|---|
| `token` | `string` | raw, straight off the URL; `#[\SensitiveParameter]` on the constructor |

`Application/Identity/VerificationOutcome` — a backed enum with two cases, `Verified` and
`AlreadyVerified`. It lives in `Application`, not `Domain`: it is not a business concept the domain
expert would name, it is the answer this *use case* gives its caller so the caller need not re-query.

Primitives, not value objects — slice 1's rule, for slice 1's reason: the invariants must hold no
matter which adapter dispatches the command (the registration listener, the resend form, the link
controller, and OAuth later).

### Handlers

`RequestEmailVerificationHandler::__invoke(RequestEmailVerification $command): void`

1. `$email = Email::fromString($command->email);` → `InvalidEmail`.
2. `$user = $this->users->findByEmail($email) ?? throw UserNotFound::withEmail($email);`
3. `if ($user->isEmailVerified()) { throw EmailAlreadyVerified::forUser($user->id()); }`
4. `$since = $this->clock->now()->modify('-1 hour');`
   `if ($this->requests->countIssuedForUserSince($user->id(), $since) >= EmailVerificationRequest::MAX_ISSUES_PER_HOUR) { throw TooManyVerificationRequests::forUser($user->id()); }`
5. `$token = $this->tokens->generate(); $hash = $this->tokens->hash($token);`
6. `$request = EmailVerificationRequest::issue($this->requests->nextIdentity(), $user->id(), $hash, $this->clock->now());`
7. `$this->requests->save($request);`
8. `$this->mailer->sendVerificationLink($user->email(), $token, $request->expiresAt());`
9. `$this->events->dispatch(...$request->releaseEvents());`

**Order matters twice here.** Save *before* send, so a link that exists in somebody's inbox always
has a row behind it — the reverse order can produce a link that verifies nothing. And send *before*
dispatch, so the audit event is only published once the whole use case succeeded.

**Every one of the three "failure" throws is a normal outcome**, not an error: the boundary catches
all three and renders the neutral response (AC-15). They are exceptions rather than a result enum
because the *domain* genuinely cannot proceed; hiding the distinction is a **presentation** policy and
belongs at the presentation boundary, not inside the use case. That separation is why the same
handler can serve the registration listener (which should log a real failure) and the public form
(which must reveal nothing).

`VerifyEmailWithTokenHandler::__invoke(VerifyEmailWithToken $command): VerificationOutcome`

*(The return value is a two-case enum, `VerificationOutcome::{Verified, AlreadyVerified}`, so the
controller can choose its flash message without a second query. `RegisterUserHandler` already set
the precedent that a command handler may return the one fact its caller cannot otherwise know.)*

1. `$plain = VerificationToken::fromString($command->token);` → `InvalidVerificationToken`.
2. `$hash = $this->tokens->hash($plain);`
3. `$request = $this->requests->findByTokenHash($hash) ?? throw EmailVerificationRequestNotFound::forToken();`
   *(the factory takes no argument — a token must never enter an exception message)*
4. `$user = $this->users->findById($request->userId()) ?? throw UserNotFound::withId($request->userId());`
5. **`if ($user->isEmailVerified()) { return VerificationOutcome::AlreadyVerified; }`** — the replay
   short-circuit (AC-8). It comes *before*
   `redeem()` so a pre-fetched-then-clicked link is a friendly no-op rather than an
   already-redeemed error.
6. `$request->redeem($hash, $this->clock->now());` → expired / already-redeemed / mismatch.
7. `$user->verifyEmail($this->clock->now());`
8. `$this->users->save($user);` **then** `$this->requests->save($request);` — the ordering argued in
   the aggregate-boundary section: if the second save is ever lost, the surviving state is
   "verified user, live token", which redeems as a no-op.
9. `$this->events->dispatch(...$user->releaseEvents(), ...$request->releaseEvents());` then
   `return VerificationOutcome::Verified;`

### Idempotency

- `VerifyEmailWithToken` is **idempotent** — step 5, plus slice 1's already-idempotent
  `User::verifyEmail()`. Non-negotiable: mail clients pre-fetch links, corporate scanners follow
  them, and users double-click.
- `RequestEmailVerification` is **not** idempotent by design — each call is a new request with a new
  token. That is the point of "resend". The anti-abuse rule (I-12) is what stops it being a mail
  cannon.

### Transaction boundary

One command, one *logical* transaction; the adapter owns `persist`/`flush`. Events dispatch after
save (ADR-0008), synchronously, with the mail already queued. See *Needs an ADR* #2 for the
knowingly-accepted window.

---

## Infrastructure layer

### Persistence

**Mapping** — `Infrastructure/Identity/Persistence/Doctrine/mapping/EmailVerificationRequest.orm.xml`,
picked up by the existing `Identity` mapping block (same directory, no config change). ADR-0007
throughout: explicit table and column names, `<generator strategy="NONE"/>`, `datetimetz_immutable`
timestamps, one custom DBAL type per value object, **no association** to `User`.

**New DBAL types** (`Infrastructure/Identity/Persistence/Doctrine/Type/`, registered under
`doctrine.dbal.types`):

| Type name | Class | Column | Converts |
|---|---|---|---|
| `identity_email_verification_request_id` | `EmailVerificationRequestIdType` (extends `GuidType`) | `UUID` | `EmailVerificationRequestId` ↔ `string` |
| `identity_verification_token_hash` | `HashedVerificationTokenType` (extends `StringType`) | `VARCHAR(255)` | `HashedVerificationToken` ↔ `string` |

`user_id` reuses the existing `identity_user_id` type — a plain typed column, not an association.

**Adapter** — `DoctrineEmailVerificationRequestRepository implements EmailVerificationRequestRepository`,
constructed with `EntityManagerInterface`, not extending `ServiceEntityRepository` (ADR-0007 §7).

- `nextIdentity()` → `EmailVerificationRequestId::fromString(Uuid::v7()->toRfc4122())`.
- `save()` → `persist` + `flush`. **No unique-constraint translation needed:** a token-hash collision
  is a 2⁻²⁵⁶ event, and if it ever happened the honest answer is a 500, not a domain exception
  pretending it is a business case.
- `findByTokenHash()` → `findOneBy(['tokenHash' => $hash])`, hitting the unique index.
- `countIssuedForUserSince()` → a DQL `COUNT` over `userId` + `issuedAt >= :since`, hitting the
  composite index.

### Security

- **`Infrastructure/Identity/Security/VerifiedAccountUserChecker implements UserCheckerInterface`**
  — the whole enforcement point.
  - `checkPreAuth()`: **empty, and that emptiness is the security control.** Symfony calls it
    *before* the password is verified (`UserCheckerListener::preCheckCredentials` on
    `CheckPassportEvent`), so throwing there would tell any anonymous visitor which addresses hold
    unverified accounts — a free enumeration oracle bolted onto the login form.
  - `checkPostAuth()`: runs on `AuthenticationSuccessEvent`, i.e. only once the correct password has
    been presented. Throws
    `new CustomUserMessageAccountStatusException('Please verify your email address before signing in.')`
    when `$user instanceof SecurityUser && !$user->isUsable()`.
  - Signature note: `UserCheckerInterface::checkPostAuth()` is documented in 7.4 as
    `checkPostAuth(UserInterface $user /* , ?TokenInterface $token = null */)` — implement the
    optional second parameter to stay forward-compatible.
- **`SecurityUser` gains usability.** It is built from the Domain `User`, so add a `private bool $usable`
  populated from `$user->isUsable()` plus an `isUsable()` reader. Tracking `isUsable()` rather than
  `isEmailVerified()` means `identity-google-oauth` widens the domain rule and the checker inherits
  it for free. `refreshUser()` rebuilds the object every request, so the flag can never go stale.
- **`config/packages/security.yaml`:** add `user_checker: App\Infrastructure\Identity\Security\VerifiedAccountUserChecker`
  to `firewalls.main`, and **replace slice 1's "NO user_checker — and that is a decision" comment
  block** with a short note pointing at this slice. Leaving a comment that contradicts the line under
  it is how config files start lying.
- **`Infrastructure/Identity/Security/RandomVerificationTokenGenerator implements VerificationTokenGenerator`**
  — `generate()` = `VerificationToken::fromString(rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='))`;
  `hash()` = `HashedVerificationToken::fromString(hash('sha256', $token->toString()))`. Both crypto
  choices in one class, none of them in `Domain`.
- **Rate limiting** — `config/packages/rate_limiter.yaml`:

  ```yaml
  framework:
      rate_limiter:
          verification_email_resend:
              policy: 'sliding_window'
              limit: 5
              interval: '1 hour'
  ```

  Injected into the controller as `$verificationEmailResendLimiter` and keyed on
  `$request->getClientIp()`. It uses the Redis-backed `cache.rate_limiter` pool slice 1 already
  configured — so the "counters must survive a deploy" argument (AC-15 of slice 1) applies unchanged,
  and so does the test-suite consequence: **DAMA rolls back Postgres, never Redis.**

### HTTP / UI

| Route name | Path | Method | Controller |
|---|---|---|---|
| `app_verify_email` | `/verify-email/{token}` | GET | `EmailVerificationController::verify` |
| `app_verify_email_sent` | `/verify-email/sent` | GET | `EmailVerificationController::sent` |
| `app_verify_email_resend` | `/verify-email/resend` | GET, POST | `EmailVerificationController::resend` |

All three are anonymous — they must be, because the people who need them cannot log in. Add nothing
to `access_control`; `^/account` stays the only rule.

- **`verify`** — builds `VerifyEmailWithToken`, calls the handler, and maps its
  `VerificationOutcome` to one of two flashes (`Verified` → *"Your email address is verified — please
  sign in."*, `AlreadyVerified` → *"Your email address is already verified — please sign in."*), both
  with a 302 to `/login`. **Every** failure exception — malformed, unknown, expired, already
  redeemed, mismatched, dangling user — maps to the **same** flash string and a 302 to
  `app_verify_email_resend`, so the four distinguishable internal causes are one indistinguishable
  external answer (AC-10 … AC-12). **Both** responses carry `Referrer-Policy: no-referrer` (AC-39):
  the token is in the path, and the header costs one line.
- **`sent`** — a static "check your inbox" page with a link to the resend form. `RegistrationController`
  redirects here instead of to `app_login` (AC-24), and its flash text changes accordingly.
- **`resend`** — a `ResendVerificationFormType` over a `ResendVerificationFormData` DTO with a single
  `email` field (`NotBlank`, `Email(mode: strict)`, `Length(max: 180)`), CSRF on. On POST: consume
  one rate-limiter token (429 if refused), dispatch `RequestEmailVerification`, and **catch
  `UserNotFound | EmailAlreadyVerified | TooManyVerificationRequests | InvalidEmail` into exactly the
  same response as success** (AC-15). The neutral response is a 302 back to `app_verify_email_sent`
  with the identical flash in every case, so status line, `Location` and body all match.
- **Templates:** `templates/identity/verify_email_sent.html.twig`,
  `templates/identity/verify_email_resend.html.twig`, and the login template gains a
  *"Didn't get the email?"* link. `templates/email/verify_email.{html,txt}.twig` for the message
  itself — **text part mandatory**: a text/plain alternative measurably improves deliverability and
  is what a spam filter reads (PRD validation #3).

### External — mail

- `composer require symfony/mailer symfony/messenger`.
- `config/packages/mailer.yaml`: `framework.mailer.dsn: '%env(MAILER_DSN)%'` plus
  `envelope.sender` / `headers.from` = `%env(MAILER_FROM)%`.
- `.env` / `.env.example` / compose: `MAILER_DSN=smtp://mailpit:1025` in dev (the container is
  already in `docker-compose.dev.yml` on the default network), the external relay DSN in prod
  (Constitution §3: Postmark/SendGrid — protecting the VDS IP reputation is the whole point),
  `MAILER_DSN=null://null` in test. `MAILER_FROM=no-reply@muzbar.com`.
- **`Infrastructure/Identity/Mail/TwigVerificationMailer implements VerificationMailer`** — builds the
  absolute URL with `UrlGeneratorInterface::ABSOLUTE_URL`, renders a `TemplatedEmail`, sends through
  `MailerInterface`.
- **Footgun with teeth (AC-6):** URL generation from a **worker or console** process has no request
  context, so the host comes from `framework.router.default_uri`, which is `DEFAULT_URI` and is
  currently `http://localhost`. Every verification link would point at localhost in production, and
  no test that runs inside an HTTP request would catch it. `DEFAULT_URI` must be set per environment
  (`http://localhost:8080` dev, `https://muzbar.com` prod) **and** the mail must be rendered once in a
  test with no request context.

### Async / schedule

Per decision #2, now settled:

- `config/packages/messenger.yaml`: transport `async: '%env(MESSENGER_TRANSPORT_DSN)%'` with
  `MESSENGER_TRANSPORT_DSN=doctrine://default` (**not** Redis — Messenger's Redis transport needs
  `ext-redis`, which this image does not have), a `failed: 'doctrine://default?queue_name=failed'`
  transport, and `routing: Symfony\Component\Mailer\Messenger\SendEmailMessage: async`.
- `when@test:` route `SendEmailMessage` to `sync` so `MailerAssertionsTrait` works.
- A **second migration** for `messenger_messages` (generated by Messenger's own setup).
- A `messenger-worker` Compose service running
  `bin/console messenger:consume async --time-limit=3600 --memory-limit=128M` with
  `restart: unless-stopped` (the time limit plus restart is the standard way to survive leaks without
  a supervisor). **`devops` task** — flag it before implementation starts, not after.
- No Scheduler task in this slice. The pruning job for expired requests is a non-goal.

### DI wiring (`config/services.yaml`)

Three new port aliases — a port with no binding is a compile-time failure, which is the cheapest
place to find out:

```yaml
App\Domain\Identity\Port\EmailVerificationRequestRepository: '@App\Infrastructure\Identity\Persistence\Doctrine\DoctrineEmailVerificationRequestRepository'
App\Domain\Identity\Port\VerificationTokenGenerator: '@App\Infrastructure\Identity\Security\RandomVerificationTokenGenerator'
App\Domain\Identity\Port\VerificationMailer: '@App\Infrastructure\Identity\Mail\TwigVerificationMailer'
```

`IssueVerificationOnUserRegistered` is autoconfigured via `#[AsEventListener(event: UserRegistered::class)]`
— ADR-0008 decision 5 means the domain event's FQCN *is* the event name, so no mapping table.

---

## Interface boundary & input contract

**`GET /verify-email/{token}`**

| Segment | Accepts | Rejects |
|---|---|---|
| `{token}` | any non-empty single path segment (`[^/]+`) at the route; exactly 43 characters of `[A-Za-z0-9_-]` at `VerificationToken` | anything else → the invalid-link redirect from the VO, byte-identical to the unknown-token response; never a 500 |

**Superseded on 2026-07-28 by `/verify`.** This section originally specified the requirement as
`[A-Za-z0-9_-]{43}` — "enforced twice: a route `requirements` regex, and `VerificationToken`… two
layers, two jobs — slice 1's form/VO pattern, repeated." That was wrong, and the analogy is where it
went wrong. A duplicated rule in a *form constraint* buys a friendly field-level error before the
Domain is reached. A duplicated rule in a *route requirement* buys a bare 404 that no `catch` can
turn into anything useful — so it made AC-12 unsatisfiable and rendered the controller's
`InvalidVerificationToken` arm unreachable. Since mail clients hard-wrap long lines, the users hitting
it are exactly the ones needing the resend form. The value object is now the **only** format gate; the
route matches any single segment. Do not re-add the regex — every test using a well-formed token still
passes with it in place, so nothing will tell you.

**`GET|POST /verify-email/resend`** — `resend_verification_form[email]`, `resend_verification_form[_token]`.
`allow_extra_fields: false`. Response: **always** 302 → `app_verify_email_sent` with the same flash,
except 429 when the IP limiter refuses and 422 on a form/CSRF error.

**`POST /login`** — unchanged shape. New failure mode: correct credentials on an unverified account
now yield the account-status message instead of a session (AC-20).

**`POST /register`** — unchanged shape. New behaviour: 302 → `app_verify_email_sent` (AC-24).

**Application contract**
`RequestEmailVerificationHandler::__invoke(RequestEmailVerification): void`, throwing
`InvalidEmail | UserNotFound | EmailAlreadyVerified | TooManyVerificationRequests`.
`VerifyEmailWithTokenHandler::__invoke(VerifyEmailWithToken): VerificationOutcome`, throwing
`InvalidVerificationToken | EmailVerificationRequestNotFound | EmailVerificationLinkExpired | EmailVerificationLinkAlreadyRedeemed | EmailVerificationTokenMismatch | UserNotFound`.

---

## Data & migrations

Additive. **`identity_user` is not touched** — slice 1 shipped `email_verified_at` in the first
migration precisely so this slice would not have to (its AC-23).

```sql
CREATE TABLE identity_email_verification_request (
    id          UUID                        NOT NULL,
    user_id     UUID                        NOT NULL,
    token_hash  VARCHAR(255)                NOT NULL,
    issued_at   TIMESTAMP(0) WITH TIME ZONE NOT NULL,
    expires_at  TIMESTAMP(0) WITH TIME ZONE NOT NULL,
    redeemed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
    PRIMARY KEY (id)
);

CREATE UNIQUE INDEX uniq_identity_email_verification_request_token_hash
    ON identity_email_verification_request (token_hash);

CREATE INDEX idx_identity_email_verification_request_user_issued
    ON identity_email_verification_request (user_id, issued_at);
```

- **`uniq_…_token_hash`** serves the redemption lookup, which is the only hot query on this table, and
  makes a duplicate hash a database-level impossibility rather than a hope.
- **`idx_…_user_issued`** serves `countIssuedForUserSince()` — leading column `user_id` for equality,
  second column `issued_at` for the range. Both are asserted with `EXPLAIN` (AC-37).
- **No foreign key to `identity_user`** — see *Needs an ADR* #1's sub-decision.
- **Second migration:** `messenger_messages`, generated by Messenger, if async delivery is adopted.
- `down()` drops the table and both indexes. No backfill: no rows exist anywhere, including
  production.

---

## Test plan

**Domain unit (no kernel, `tests/Unit/Domain/Identity/`)**

- `VerificationToken`: accepts a real 43-char base64url string; rejects 42/44 chars, `+`, `/`, `=`,
  empty, and a 10 kB string; `__debugInfo()` masks; **no `__toString()` exists** (reflection
  assertion) — the same three tests `PlainPassword` has.
- `HashedVerificationToken`: accepts an arbitrary opaque string (proving the Domain does not know the
  algorithm); rejects empty and > 255; `equals()` is true for identical values and false for a
  one-character difference.
- `EmailVerificationRequestId`: format validation, hex-case normalisation, equality.
- `EmailVerificationRequest`: `issue()` derives `expiresAt` at exactly +86400 s and records exactly
  one `EmailVerificationRequested` **whose payload contains no token**; `redeem()` with the right hash
  sets `redeemedAt`; a second `redeem()` throws (I-9); redeeming at `expiresAt + 1 s` throws (I-10);
  redeeming with a near-miss hash throws (I-11); `releaseEvents()` empties.
- *Regression guard:* `User` unit tests are untouched — proof that the aggregate did not need to
  change.

**Application / Integration (real `muzbar_test`, DAMA rollback, `tests/Integration/Identity/`)**

- `DoctrineEmailVerificationRequestRepository`: save → `clear()` → `findByTokenHash` round-trips
  **every** value object with equal values (this is what catches a broken DBAL type);
  `countIssuedForUserSince` respects the boundary instant; `nextIdentity()` yields distinct valid ids.
- `RequestEmailVerificationHandler` with a `FrozenClock`, a spy dispatcher and a **fake mailer**
  (recording the plaintext token so the test can build the URL): happy path persists one row and
  sends one message; already-verified throws and persists nothing; unknown email throws; the **6th**
  call inside the hour throws `TooManyVerificationRequests` and persists nothing (AC-17).
- `VerifyEmailWithTokenHandler`: happy path verifies the user and marks the request redeemed and
  dispatches exactly one `UserEmailVerified`; replay returns `AlreadyVerified` and dispatches nothing
  (AC-8); expired throws; unknown hash throws; mismatch throws.
- **Foundry factory** `tests/Factory/EmailVerificationRequestFactory` — built through
  `EmailVerificationRequest::issue()` via `instantiateWith()`, with `afterInstantiate()` releasing the
  recorded event, exactly like `UserFactory` (ADR-0008's last "hard / watched" bullet: a factory that
  persists around the handler leaves events in the buffer and pollutes a later test). States:
  `expired()`, `redeemed()`.

**Functional (`tests/Functional/Identity/`)**

- Registration → one request row + one queued email + 302 to `/verify-email/sent` (AC-1, AC-3, AC-24);
  transport failure still commits the user (AC-5).
- Verification link: happy path (AC-7), replay (AC-8), expired (AC-10), unknown (AC-11), malformed
  (AC-12) — the last three asserted **byte-identical**.
- Resend: the four-way indistinguishability assertion (AC-15), a new distinct token (AC-16), the IP
  limiter's 429 (AC-18), CSRF failure.
- Login enforcement: unverified + correct password blocked (AC-20); unverified + wrong password shows
  the *ordinary* message (AC-21); verified logs in (AC-22); the throttle still wins at attempt 6
  (AC-23).
- **Updated slice-1 tests** (AC-25): every `registerUserWithKnownCredentials(...)` call in
  `LoginLogoutTest` and `ThrottlingTest` passes `verified: true`; `RegistrationControllerTest`'s
  redirect assertion moves to `app_verify_email_sent`.
- **Mail assertions** use `MailerAssertionsTrait` with `SendEmailMessage` routed to `sync` in test.

**Test-environment gotchas to honour**

- **DAMA rolls back Postgres, not Redis.** The resend limiter shares the `cache.rate_limiter` pool, so
  any test touching `/verify-email/resend` must clear it in `setUp()`. Generalise the existing
  `ClearsLoginRateLimiter` trait (rename to `ClearsRateLimiters`, keeping one call site per test) —
  otherwise counters accumulate across the suite and the failure looks like flakiness with no diff to
  blame.
- The `FrozenClock` double must keep honouring the `Clock` contract (UTC, whole seconds). Expiry tests
  advance it by constructing a second `FrozenClock`, never by `sleep()`.

**Infrastructure assertions**

- `EXPLAIN` on both new queries shows Index Scans on the two named indexes (AC-37).
- The mail renders a correct absolute URL with **no request context** (AC-6).

---

## Risks / open questions

1. ~~**If the owner picks Option A (`verify-email-bundle`)**, the delta is large and should be
   re-planned, not patched.~~ **CLOSED 2026-07-26 — Option B chosen.** Kept for the record: had A been
   picked, the aggregate, its repository, the migration and roughly half the acceptance criteria (AC-1,
   AC-8, AC-9, AC-16, AC-17, AC-30, AC-35 … AC-37) would have disappeared or changed meaning, and
   "single use" would have had to be struck from the security properties.
2. **Async mail adds an operational surface** — a worker container, a restart policy, and a failure
   transport nobody looks at. A stopped worker is invisible to `/health/ready`. **Open question:**
   should `/health/ready` learn to report a stale queue, or is that Phase 2 work? Recommendation:
   Phase 2, but write the runbook line now.
3. **Sentry is now overdue.** The roadmap deferred it to "the first user-facing flow… when a silent
   500 starts costing a signup"; that trigger fired in slice 1 and this slice adds an *asynchronous*
   failure path where a silent error is genuinely invisible. Still `devops` work and still not
   `Identity` work — **recommendation: a small separate cycle before or alongside this one.** The
   `pre-write-guard` Claude hook is the other outstanding carry-over.
4. **`EmailVerificationRequestId` duplicates `UserId` almost line for line.** Two is a coincidence;
   three is a pattern. **CLOSED 2026-07-26 — wait.** No `Domain/Shared/ValueObject/Uuid` in this slice;
   revisit when `Catalog` produces the third, because a shared base abstracted from two examples
   usually fits neither. The duplication is accepted knowingly, not overlooked.
5. **The 24 h lifetime and the 5-per-hour cap are Domain constants, not configuration.** That is
   deliberate (they are policy, and config-driven policy is policy nobody can unit-test), but it means
   tuning them is a deploy. **CLOSED 2026-07-26 — both numbers confirmed as planned.**
6. **`EmailVerificationRequested` has no listener.** Same judgement call slice 1 made with
   `grantRole()`. **CLOSED 2026-07-26 — kept.** It is a genuine auditable fact and costs ~30 lines; the
   reviewer should read it as recorded history, not speculative generality.
7. **The token travels in a URL path**, so it can appear in nginx access logs, browser history and a
   `Referer` header. Mitigated by single use, a 24 h life, and the fact that the verification page
   loads no third-party resources (nothing to leak a `Referer` *to*). **CLOSED 2026-07-26 — adopt
   `Referrer-Policy: no-referrer`** on the verification responses. Now **AC-39**, implemented in T27.
8. **Orphan rows** if a user is ever hard-deleted (no FK, no cascade). Nothing deletes users today;
   the GDPR-erasure conversation before public launch must not forget this table.
9. **The registration flow now depends on a listener firing.** If `IssueVerificationOnUserRegistered`
   throws (mail queue down, database hiccup), the user is registered and the request row may not
   exist. The compensating action is the resend form — which is why the resend form is *anonymous* and
   is part of this slice rather than a follow-up. Worth an explicit log line at error level so the
   operator sees it even before Sentry lands.
