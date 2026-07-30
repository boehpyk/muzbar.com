# Technical Plan: identity-password-reset

> The *how*. Disposable. Written after the feature-spec is drafted, approved with it before any code.
> Follows DDD canonical order.

**Bounded context:** `Identity` (Constitution §4). Nothing outside `Identity` and `Shared` is
created. **`Notification` is deliberately not touched** — the mail seam is a port *inside*
`Identity`, exactly as slice 2 established.

**Namespaces claimed by this slice**

```
src/src/Domain/Identity/          Entity/PasswordResetRequest, ValueObject/{PasswordResetRequestId,
                                  ResetToken, HashedResetToken}, Event/{PasswordResetRequested,
                                  UserPasswordChanged}, Port/{PasswordResetRequestRepository,
                                  ResetTokenGenerator, PasswordResetMailer}, Exception/*
                                  — plus a minimal change to Entity/User
src/src/Application/Identity/     Command/{RequestPasswordReset, ResetPasswordWithToken},
                                  Query/CheckPasswordResetToken, Handler/*
src/src/Infrastructure/Identity/  Persistence/Doctrine/{DoctrinePasswordResetRequestRepository,
                                  Type/*, mapping/PasswordResetRequest.orm.xml},
                                  Security/RandomResetTokenGenerator,
                                  Mail/TwigPasswordResetMailer,
                                  Http/Controller/PasswordResetController, Form/*
```

**Unchanged on purpose:** every slice-2 class, `Domain/Identity/Port/PasswordHasher` (reused
verbatim), `Infrastructure/Identity/Security/{VerifiedAccountUserChecker, SymfonyPasswordHasher,
DomainUserProvider, SecurityUser}`, `Infrastructure/Identity/Console/VerifyUserEmailCommand`, and the
whole `EmailVerificationRequest` aggregate. **No listener is added** — this flow has no trigger event;
it starts at a form.

---

## Decisions needing sign-off

> **SIGNED OFF 2026-07-28 — all eleven accepted as recommended.** T0 is unblocked. The reasoning
> below is retained because ADR-0011 is written *from* it, and because the amendments owed to
> ADR-0005 and ADR-0009 need the argument, not just the verdict. Two conditions were attached at
> sign-off:
>
> 1. **`/verify` for this slice runs `/security-review` in addition to the standard reviewer pass.**
>    Risk item 2 — hand-rolling the most attacked endpoint in the system — makes this mandatory, not
>    discretionary.
> 2. **Decision 11 (no selector/verifier split, no HMAC pepper) is flagged to the reviewer as the
>    designated place to disagree**, exactly as this plan proposes. Accepted on the stated reasoning;
>    if the reviewer or `/security-review` makes the counter-case, it is reopened rather than
>    defended.

Eleven, ordered by how much they change the shape of the slice. **T0 is blocked on 1–4**; the rest
can be confirmed alongside. Recommendations are made in every case — none of these is a "you decide".

| # | Decision | Recommendation |
|---|---|---|
| 1 | **`symfonycasts/reset-password-bundle` vs a third in-domain aggregate** — contradicts roadmap line 97 | **Domain aggregate**, bundle not installed. Needs **ADR-0011** + a dated amendment to **ADR-0005** + a **roadmap line 97** correction. Argued in full below. |
| 2 | **Reissue invalidates outstanding links** (the deliberate inversion of ADR-0009 decision 5) | **Yes.** Needs a dated amendment to **ADR-0009** recording that its forward-looking clause is discharged, and how. |
| 3 | **`User` gains `passwordChangedAt`** — the first change to `User` since slice 1 | **Yes.** It is load-bearing, not audit garnish: it closes the crash window between this use case's two saves. Argued below. |
| 4 | **A successful reset also verifies the email** | **Yes.** Same proof, same channel; refusing would strand unverified accounts with no recovery path at all. Becomes ADR-0011 decision 6. |
| 5 | **The token leaves the URL via a session stash + redirect** | **Yes.** Rejecting the bundle obliges us to match its security properties, not only its features — and this is one of them. |
| 6 | **The four numbers:** 1 h lifetime, 3 issuances/user/hour, 5 POSTs/hour/IP on `/forgot-password`, 10 requests/hour/IP on the reset routes | **Confirm as planned.** Each argued below; each pinned by an AC. |
| 7 | **No shared `Challenge` base class and no shared `Uuid` id value object** | **Confirm, and change the trigger.** ADR-0009 said "revisit at the third example"; this *is* the third, and the answer is still no — for a reason that is about *contexts*, not counts. |
| 8 | **The request-reset path stays synchronous**, with the timing channel recorded as an accepted residual | **Confirm.** The alternative (dispatch the whole use case to Messenger) is spelled out and rejected below. |
| 9 | **No "your password was changed" notification email in this slice**; the event ships as the hook | **Confirm**, and put the notification on the roadmap. |
| 10 | **The pruning debt is added to, not paid** — two tables now owe it | **Confirm**, and add a small `identity-challenge-pruning` slice to the roadmap **before** `identity-google-oauth`. |
| 11 | **No selector/verifier split and no HMAC pepper** on the stored token | **Confirm.** Reasoned below; it is the same argument ADR-0009 used for "why not Argon2", applied one level up. |

---

## ⚠ Decision 1 — the bundle, argued honestly

**What the roadmap says.** `docs/roadmap.md` line 97: *"`identity-password-reset` —
`symfonycasts/reset-password-bundle` flow."* ADR-0005 names the same bundle, and its 2026-07-26
amendment explicitly declines to cover it: *"`symfonycasts/reset-password-bundle` is **not** covered
by this amendment and remains the plan of record for `identity-password-reset`; that slice must make
its own call."* This is that call.

**The lazy argument, and why it is not available.** The tempting move is "ADR-0009 rejected the
sibling bundle, so reject this one." That argument does not work, and pretending it does would be
the worst possible way to make a security decision.

ADR-0009's headline reason was that `verify-email-bundle` is **stateless** — it HMAC-signs a URL and
stores nothing, so single use, revocation and auditability are not expressible with it.
**`reset-password-bundle` is not like that.** It is genuinely stateful: it requires the application
to own a `ResetPasswordRequest` entity, stores a selector and a hashed token, expires them (default
one hour), deletes the row on use (so: single use), and throttles per user (default one request per
hour). Every security property this spec enumerates, that bundle already implements — several of them
more carefully than a first attempt would.

So the argument has to be made on different ground.

### Where it actually conflicts

The blockers are structural, and each of them is a rule this repository has already written down.

| Conflict | The rule it breaks |
|---|---|
| The bundle's `ResetPasswordRequestInterface` requires `getUser(): object` — an **object reference to another aggregate root** — and its maker generates a `#[ORM\ManyToOne]` with a real foreign key. | **ADR-0009 decision 4**: an aggregate holds another's *id value object, never the object*; the mapping is a plain typed column, never a `<many-to-one>`; and there is **no database foreign key between aggregates**. |
| Its repository is expected to extend `ServiceEntityRepository` (via the bundle's trait) and implement a vendor interface. | **ADR-0007 decision 7**: repository adapters implement the Domain port and do **not** extend `ServiceEntityRepository`. |
| The entity carries ORM attributes and implements a vendor interface, so it cannot live in `Domain/` at all — `use SymfonyCasts\…` in `Domain/` fails Deptrac under `--fail-on-uncovered`. | **Constitution §4.2** and **ADR-0007 decision 1** (XML mapping, one file per aggregate, physically outside the class). |
| `ResetPasswordHelperInterface` owns token generation, expiry arithmetic, throttling and validation. | **Constitution §2**: when two paths are equally valid for the product and one teaches DDD more honestly, choose the one that teaches. The only interesting thing in the slice would live in vendor code. |

The last row is the one that decides it, but the first three are why there is no compromise position.
The bundle's entity would have to be an **Infrastructure** class implementing a vendor interface,
holding a Doctrine association to `User` — which means the `Identity` *domain* would contain no
concept of a password-reset challenge at all. Every rule this spec enumerates (single use, expiry,
supersession, staleness, the ordering of the two saves) would then live in a controller, and the
Domain would not be able to state, in any type, that a reset requires a live challenge.

### The honest case *for* the bundle

Stated at full strength, because a decision that only lists the other side's weaknesses is not a
decision:

- **It is battle-tested on the most attacked endpoint in any auth system.** ADR-0005's own words:
  *"Don't roll your own auth."* A subtle mistake here is account takeover, not a cosmetic bug.
- **Its token scheme is better engineering than a single hashed token.** It splits the secret into a
  non-secret **selector** (used for the lookup) and a **verifier** (never used as a lookup key, and
  stored HMAC-ed with a server-side secret). That means the database lookup cannot be a timing oracle
  over the secret, and a stolen database dump is useless without the application secret.
- **Its throttle is opinionated in a good direction** — one request per hour per user, which
  incidentally makes the "your new link killed the old one" confusion rare.
- **It ships this afternoon.**

### Why we still decline

- On *"don't roll your own auth"*: we are not. Every cryptographic primitive is vendor code —
  `random_bytes`, `hash('sha256')`, `hash_equals`, and Symfony's `auto` password hasher. What we
  write is ~60 lines of **orchestration**, all of which is readable in one sitting and all of which
  is covered by enumerated acceptance criteria. The maxim protects against inventing crypto and
  inventing flows; this flow is not invented, it is the same one the bundle implements, written where
  we can see it.
- On the **selector/verifier + HMAC pepper**: see decision 11 below. The short version is that the
  pepper's benefit is proportional to how guessable the pre-image is, and a 43-character base64url
  draw from `random_bytes(32)` has no guessable pre-image at any cost — the same reasoning ADR-0009
  used to choose SHA-256 over Argon2. The timing-oracle argument for the selector is real but
  bounded: an index scan over a unique btree on a 256-bit-entropy digest leaks nothing an attacker can
  walk.
- On **auditability**: the bundle *deletes* the row on use. For the endpoint most likely to appear in
  an incident review, "when was this account's password reset, and from which request" is worth
  keeping. Our model keeps it with `redeemedAt` and `invalidatedAt`, at the cost of a pruning job we
  already owe.
- On **expressiveness**: our anti-abuse rule is a rolling-window *count* (`countIssuedForUserSince`),
  reusing machinery slice 2 already built and tested. The bundle's is a single
  seconds-between-requests throttle.

**Recommendation: model it in the Domain. Do not install the bundle.** Record it as **ADR-0011**,
amend **ADR-0005** (whose amendment explicitly left this open), amend **ADR-0009** (decision 5's
forward-looking clause is discharged here), and **correct roadmap line 97** — an unchallenged roadmap
line that contradicts an accepted ADR is how a codebase starts disagreeing with its own documentation.

---

## Reuse vs duplication — the question this slice exists to answer wrongly if unasked

`PasswordResetRequest` will be, line for line, about 80% the same file as `EmailVerificationRequest`.
Three UUID id value objects will now exist that differ only in two identifiers. Two token value
objects and two hashed-token value objects will be near-clones. The reflex is a
`Domain/Identity/Entity/Challenge` base class, or at minimum a `Domain/Shared/ValueObject/Uuid`.

**Recommendation: extract nothing. Premature generalisation is by far the bigger risk here**, and
unusually the argument is concrete rather than aesthetic.

### On a shared aggregate base

The two aggregates share a *shape*. They do not share *behaviour*, and a base class abstracts
behaviour or it abstracts nothing worth having. Four rules point in opposite directions:

| Rule | `EmailVerificationRequest` | `PasswordResetRequest` |
|---|---|---|
| Replaying a redeemed challenge | **Friendly no-op** — mail scanners prefetch, so the human's click is often the replay | **Refused** — the effect is destructive and repeatable (AC-18) |
| Issuing a new challenge | Leaves outstanding links alive; they are inert anyway | **Invalidates all of them** (AC-9) |
| What burns the challenge | The **GET** on the link | The **POST** that sets the password; the GET mutates nothing (AC-12) |
| Save ordering across the two aggregates | User first, request second | **Request first, user second** — the opposite, and for a good reason |

A shared base would have to guess on all four. Any guess is right for one subclass and a latent
security bug in the other — and the one it is wrong for is the password-reset one, where "wrong"
means a replayable account-takeover primitive. The two classes are 80% identical *today*, in the
narrow sense that both hold a UUID, a `UserId`, a digest and some timestamps. That is a coincidence
of storage, not a shared concept.

### On a shared `Uuid` value object

ADR-0009 deferred this with *"two is a coincidence, three is a pattern — revisit when `Catalog`
produces the third example."* `PasswordResetRequestId` is the third example, and it arrives early.
The recommendation is still **no**, with the trigger **restated in terms of contexts rather than
counts**, because the count was always a proxy for the real question:

1. **All three examples are from one bounded context.** An abstraction induced from three samples of
   `Identity` is an `Identity` abstraction wearing a `Shared/` namespace. `Catalog` was named as the
   trigger precisely because it is a *different* context, and that is what tests whether the
   commonality is "an aggregate identity" or "how `Identity` happens to spell things".
2. **The naive extraction introduces a type hole, and it is not obvious.** A base class declaring
   `public function equals(self $other): bool` resolves `self` to the **base**, so after extraction
   `$userId->equals($passwordResetRequestId)` compiles and returns a meaningful-looking `false`.
   Today that is a `TypeError` at the moment it is written. Trading a compile-time guarantee for
   forty saved lines, in the context that holds the credentials, is a bad trade. Recovering the
   guarantee costs an `instanceof static` check in every caller's mental model — i.e. more complexity
   than the duplication.
3. The duplication is **cheap, local and non-viral**: three ~40-line files that nothing else depends
   on, each with a docblock explaining that the sameness is an encoding coincidence.

**Proposed amendment to ADR-0009's consequence:** replace *"revisit when `Catalog` produces the third
example"* with *"revisit at the first aggregate id outside `Identity`, and only if the extraction can
preserve cross-type comparison as a compile-time error."* That is a criterion, not a headcount.

### What *is* reused

Genuinely, and worth listing so the "no reuse" reading is not taken too far: the `PasswordHasher`
port (unchanged — its own docblock predicted this slice), `PlainPassword` and `HashedPassword`
(unchanged, and AC-22 forbids inventing a second password policy), `User::verifyEmail()` (unchanged,
and its idempotency is what makes AC-30 free), the `Clock` and `DomainEventDispatcher` ports, the
`RecordsEvents` trait, the whole Messenger + Mailer + `DEFAULT_URI` setup from slice 2, the
`cache.rate_limiter` Redis pool, and the `ClearsRateLimiters` trait. The reuse is at the level of
**ports and infrastructure**, which is where reuse belongs; the duplication is at the level of
**policy objects**, which is where it is honest.

---

## Domain layer (pure PHP)

Zero `use Symfony\...` / `use Doctrine\...`. Only core PHP (`\DateTimeImmutable`, `hash_equals`,
`preg_match`, `\DomainException`).

### The aggregate-boundary decision (and why it is *not* just slice 2's again)

The boundary lands in the same place as slice 2's, but the argument that puts it there is different,
and the difference is the interesting part.

Slice 2's reasoning was: a safe *ordering* exists (user first, request second), therefore the rule
spanning the two aggregates does not need one transaction, therefore two aggregates are legitimate
(Vernon). Repeat that test here.

Redemption changes two things: the request becomes redeemed, and the user's password changes. Order
them and look at each crash window:

- **User first, request second** (slice 2's order) → crash leaves a **changed password and a live
  token**. That token can be used *again* to set *another* password. This is exactly the danger
  ADR-0009 decision 5 named: *"a stale live token **is** dangerous because setting a new password is
  destructive."* **Rejected.**
- **Request first, user second** → crash leaves a **burnt token and an unchanged password**. The user
  asks for another link. Annoying; entirely safe.

**So the ordering is inverted relative to slice 2, and the inversion is forced.** ADR-0009 decision 4
already states the rule — *"pick the order whose crash window is benign, and write down why at the
call site"* — so this is the rule producing a different answer from the same input, which is the
strongest evidence that it is a real rule and not a habit.

Because a safe ordering exists, two aggregates remain correct. *(The same happy accident applies as
in slice 2: with the Doctrine adapter both roots are managed by one `EntityManager`, so the first
`save()`'s flush commits both. That is an accident, not the guarantee; the ordering is what keeps the
design correct the day it stops being true.)*

**And a second layer, because "benign" is not "impossible".** The remaining exposure is not the crash
window between the two saves — it is the *sibling* window: a request issued before this reset and
never invalidated (a lost invalidation write, a concurrent request) is still live and still
redeemable. That is closed by `passwordChangedAt` (decision 3): the handler refuses any request whose
`issuedAt` is strictly before the user's last password change. One column, one comparison, no
cross-row write, and it holds even when the sweep in AC-9 is lost.

### Aggregate: `Domain/Identity/Entity/PasswordResetRequest`

State:

| Property | Type | Notes |
|---|---|---|
| `id` | `PasswordResetRequestId` | assigned at construction, never changes |
| `userId` | `UserId` | **a reference by identity** — never a `User` object (AC-40) |
| `tokenHash` | `HashedResetToken` | opaque; the plaintext never reaches this class |
| `issuedAt` | `\DateTimeImmutable` | from the `Clock` port |
| `expiresAt` | `\DateTimeImmutable` | derived, `issuedAt + LIFETIME_SECONDS` |
| `redeemedAt` | `?\DateTimeImmutable` | `null` = never used |
| `invalidatedAt` | `?\DateTimeImmutable` | `null` = never superseded |

Properties are plain `private`, not `readonly`, for slice 1's reason (Doctrine hydrates by reflection
and readonly trips its refresh/proxy paths); immutability from outside is guaranteed by the private
constructor and the absence of setters.

**Why two nullable timestamps rather than one `closedAt` plus a reason enum.** Each answers a
question worth asking separately — "how many resets completed?" versus "how many were superseded?" —
and each is a predicate a query wants on its own. More importantly, invariant I-16 ("redeemed at most
once") then reads directly off one column instead of off a column-plus-discriminator pair, and the
mutual exclusion (I-17) becomes two guards in two methods rather than a state machine.

Policy constants (domain knowledge, deliberately **not** configuration — same reasoning as slice 2):

```php
public const int LIFETIME_SECONDS = 3600;   // 1 hour — one twenty-fourth of a verification link
public const int MAX_ISSUES_PER_HOUR = 3;   // per user; read by the Application handler
```

**Why one hour, and why the ratio to slice 2 is the point.** A verification token proves reachability;
if it leaks, an attacker verifies an address they would have had to already control to receive it. A
reset token **is an account-takeover primitive** — whoever holds it owns the account. So the exposure
window should be as short as usability allows, and 24 hours is indefensible for it.

The floor is set by delivery latency, not by patience: a queued message, an external relay, and a
recipient's greylisting can easily cost five to fifteen minutes before the mail is even readable, and
a person who requests at a desk and reads on a phone needs slack on top. Fifteen minutes — the
tightest number anyone proposes — is uncomfortable against that floor. One hour clears it with room,
is the default `symfonycasts/reset-password-bundle` itself chose, and sits inside the range OWASP
ASVS describes as short-lived. Picking the same number as the bundle is deliberate: we are declining
its *packaging*, not claiming to have better judgement about its *parameters*.

**Why three issuances per user per hour, versus verification's five.** Two reasons, both about the
mail rather than the token. First, a reset mail is the *alarming* one — it is the message an attacker
uses to flood a victim's inbox with "someone tried to reset your password", and every extra one is
free anxiety delivered on the attacker's behalf. Second, AC-9 means each new request kills the
previous link, so a high cap actively degrades the experience: the more resends we allow, the more
often a user clicks the first mail they find and is told it is dead. Three is enough for a mistyped
address, a mail that landed in spam, and one more try; it is not enough to be a weapon.

Behaviour — no public constructor, no setters:

- `public static function issue(PasswordResetRequestId $id, UserId $userId, HashedResetToken $tokenHash, \DateTimeImmutable $issuedAt): self`
  — the only creation path. Computes `expiresAt` itself (I-15) with
  `$issuedAt->add(new \DateInterval('PT3600S'))`, so the lifetime cannot be varied per caller and so
  the UTC zone and whole-second precision of the `Clock` are inherited rather than reinterpreted.
  Records `PasswordResetRequested`.
- `public function assertRedeemableWith(HashedResetToken $presented, \DateTimeImmutable $at): void`
  — **the whole rule, with no mutation.** Throws, in this order:
  `PasswordResetLinkInvalidated` → `PasswordResetLinkAlreadyUsed` → `PasswordResetLinkExpired` →
  `PasswordResetTokenMismatch`. The token comparison is last for slice 2's reason: it is the only
  check that touches a secret, and there is no reason to perform it for a request that could not be
  redeemed anyway.
- `public function redeem(HashedResetToken $presented, \DateTimeImmutable $at): void` — calls
  `assertRedeemableWith()`, then sets `redeemedAt`. **Records no event**: the fact the rest of the
  system cares about is "this account's password changed", and that belongs to `User`.
- `public function invalidate(\DateTimeImmutable $at): void` — throws
  `PasswordResetLinkAlreadyUsed` if redeemed (I-17); returns early if already invalidated (idempotent,
  so a re-run of the sweep is harmless); otherwise sets `invalidatedAt`. Records no event (AC-34).
- `isRedeemed()`, `isInvalidated()`, `isExpiredAt(\DateTimeImmutable $at)`,
  `isLiveAt(\DateTimeImmutable $at)`.
- Readers: `id()`, `userId()`, `tokenHash()`, `issuedAt()`, `expiresAt()`, `redeemedAt()`,
  `invalidatedAt()`.
- `releaseEvents()` via the `RecordsEvents` trait.

**Why `assertRedeemableWith()` exists as a separate method** — this is the shape difference that the
prefetch problem forces. The GET must be able to answer "is this link good?" *without* burning it
(AC-12), and the POST must apply exactly the same judgement. Two entry points, one rule, one
implementation. The alternative — a `bool` predicate for the GET — would collapse four
distinguishable causes into one at the layer that must keep them distinct for the log and the tests,
while the visitor's single answer stays a presentation concern.

**Why `redeem()` re-checks a hash it was just looked up by** — unchanged from slice 2's reasoning: a
repository query is a convenience, an aggregate is a rule-holder, and an invariant that holds only
because of *how* the caller found the object is not an invariant.

**Invariants, and what protects them** *(continuing slice 1's I-1…I-6 and slice 2's I-7…I-13)*

| # | Invariant | Protected by |
|---|---|---|
| I-14 | A request always carries a non-empty token hash and belongs to exactly one user. | Typed `issue()`: it accepts `HashedResetToken` and `UserId`, never strings. The signature is also the proof this class never held the plaintext. |
| I-15 | `expiresAt` is always exactly `issuedAt + LIFETIME_SECONDS` and always after `issuedAt`. | Derived inside `issue()`; not a parameter, so no caller can shorten or extend it. |
| I-16 | A request is redeemed **at most once**: `redeemedAt` moves `null → instant` and never back. | `assertRedeemableWith()` throws on an already-redeemed request; there is no un-redeem. |
| I-17 | A request is **never both** redeemed and invalidated. | `assertRedeemableWith()` refuses an invalidated request; `invalidate()` refuses a redeemed one. Two guards, one mutual exclusion. |
| I-18 | An expired request can never be redeemed. | `assertRedeemableWith()` compares `$at` against `expiresAt` before anything mutates. Strictly `>`, so the boundary instant itself is still valid — a `>=` would silently shorten every link by up to a second, given whole-second storage. |
| I-19 | Only the token whose hash matches may redeem the request. | `hash_equals` inside `HashedResetToken::equals()`. |
| I-20 | **At most one *live* reset request per user.** | **Not an aggregate invariant** — it spans instances, exactly like I-6 (email uniqueness) and I-12 (issuance count). Enforced by `RequestPasswordResetHandler` over `findOutstandingForUser()`, and it can lose a race. The `passwordChangedAt` guard (I-23) is the second layer, and it is the one that makes the loss of this race safe rather than merely unlikely. |
| I-21 | At most 3 issuances per user per rolling hour. | Not an aggregate invariant, same asymmetry; handler + `countIssuedForUserSince()`. Worst case is a fourth email. |
| I-22 | *(`User`, new)* `passwordHash` is always non-empty, and `passwordChangedAt` is `null` until the first change and set thereafter. | `changePassword()` accepts `HashedPassword`, never a string. **Monotonicity is deliberately *not* claimed** — it is a property of the `Clock`, not something the aggregate can enforce without comparing instants it has no business ordering. Stating the weaker true thing beats stating the stronger false one. |
| I-23 | A request issued strictly before its user's last password change is not redeemable. | **Not an aggregate invariant** — it spans two aggregates, so neither can hold it. Enforced by `ResetPasswordWithTokenHandler` comparing `$request->issuedAt() < $user->passwordChangedAt()`. Strict `<`, so a same-second tie is allowed: whole-second storage makes ties real, and erring toward "let the legitimate user through" is right when I-20 is the primary mechanism. |
| I-24 | *(`User`, unchanged)* `emailVerifiedAt` moves `null → instant` exactly once. | Slice 1's idempotent `verifyEmail()`. Nothing here changes it — which is what makes AC-30 free. |

**The rule this slice adopts that slice 2 refused: reissuing invalidates outstanding links.**
ADR-0009 decision 5 argued that verification does not need it, because once *any* token verifies the
account every other live token is inert. Reset has no such property: each live token is an
independent, repeatable chance to set a password. So each issuance sweeps the account's outstanding
requests through `invalidate()`. This is not a copy of a precedent; it is the precedent's own
carve-out being cashed in.

*(The cost is a real, accepted UX wrinkle: a user who requests twice and then clicks the mail that
arrived first is told the link is dead. That is why the per-user cap is 3 rather than 5 — a lower cap
makes the situation rarer — and why the invalid-link response is a redirect to the form rather than an
error page.)*

### Value objects

All `final readonly`, validated in the constructor, built through `fromString()`, compared by value.

| VO | Why it is a VO | Validation / normalisation |
|---|---|---|
| `PasswordResetRequestId` | An identity *value*: immutable, compared by value, carried in an event. | RFC 4122 layout regex, version-agnostic, lower-cased; throws `InvalidPasswordResetRequestId`. Mirrors `UserId` and `EmailVerificationRequestId`, including the deliberate absence of a `generate()` — the repository mints ids. Duplication accepted knowingly; see *Reuse vs duplication*. |
| `ResetToken` | Carries the **token policy** — 32 CSPRNG bytes, base64url, 43 characters — which is domain knowledge, not a controller detail. Transient: never persisted. | Exactly 43 characters matching `^[A-Za-z0-9_-]{43}$`, case-sensitive. Throws `InvalidResetToken`. `#[\SensitiveParameter]` on constructor and factory, `__debugInfo()` returning `['value' => '***']`, **no `__toString()`**, and a single deliberately uncomfortable reader `reveal()` — the same four guards `PlainPassword` and `VerificationToken` carry. Not trimmed: a whitespace-padded token arrived damaged, which is the case this method exists to refuse. |
| `HashedResetToken` | Makes "this string is a digest, not a token" a *type*, so `issue(…, string $token)` is unwriteable. | Non-empty, length ≤ 255. **No format check** — validating 64 hex characters would encode SHA-256, an Infrastructure choice, into a Domain rule. `equals()` uses **`hash_equals`** (AC-27). |

**A separate type from `VerificationToken` on purpose.** The two are structurally identical and the
type system is exactly where that must not matter: a verification token presented at the reset
endpoint, or vice versa, must be a compile error rather than a lookup miss. One shared `ChallengeToken`
type would make that class of confusion expressible.

**Why the digest is fast (SHA-256), not Argon2** — unchanged from ADR-0009 decision 2, and worth
re-stating because the presence of the word "password" makes the wrong reflex stronger here: a
*password* needs a slow KDF because it is low-entropy and guessable offline; a 256-bit CSPRNG draw
has no guessable pre-image at any cost, so a slow digest would add latency to every click and buy
nothing. The Domain names no algorithm at all.

### Changes to `User` — and how small they are kept

Slice 2's headline result was that `User.php` did not appear in the diff. This slice cannot have that
result, and pretending otherwise would be worse than admitting it: *changing a password* is a
statement about the account, and the account aggregate owns it.

```php
public function changePassword(HashedPassword $newHash, \DateTimeImmutable $at): void
{
    $this->passwordHash = $newHash;
    $this->passwordChangedAt = $at;
    $this->recordThat(new UserPasswordChanged($this->id, $at));
}

public function passwordChangedAt(): ?\DateTimeImmutable { … }
```

That is the entire change: one method, one property, one reader, one event import. Three things it
deliberately does **not** do:

1. **It does not verify the email.** That coupling belongs to *this use case*, not to the aggregate
   (AC-31). A future authenticated "change my password" screen proves nothing about the mailbox, and
   an aggregate method that verified an address would silently make it a verification bypass.
2. **It does not compare `$at` against the current `passwordChangedAt`.** See I-22 — monotonicity is
   a `Clock` property, and a guard here would be an unreachable branch that no test can exercise
   honestly.
3. **It is not idempotent and does not need to be.** Unlike `verifyEmail()`, there is no scenario in
   which the same password change is applied twice — the token is burnt first (see the handler's
   ordering), and I-23 catches the pathological case.

**Why `passwordChangedAt` is load-bearing rather than audit garnish** (decision 3): without it, I-23
is unexpressible, and I-23 is the only thing standing between a lost invalidation write and a
replayable account-takeover primitive. It is one nullable column added in a migration this slice needs
anyway. It is *also* the field a future "your password was changed on X" notification reads, but that
is a bonus, not the justification — if it were only the bonus, this would be speculative state and
should be cut.

### Domain events

| Event | Raised by | Payload | Who reacts *today* |
|---|---|---|---|
| `PasswordResetRequested` | `PasswordResetRequest::issue()` | `PasswordResetRequestId`, `UserId`, `issuedAt`, `expiresAt` | Nobody. The auditable fact "this account was sent a reset challenge at this time". **No token, no email address** (AC-32) — a secret in an event is a secret in every listener, every log line and every queue row. |
| `UserPasswordChanged` | `User::changePassword()` | `UserId`, `occurredAt` | Nobody yet. **The hook for the security-notification email** deferred by decision 9. **No hash, no plaintext** (AC-33). |
| `UserEmailVerified` *(slice 1, unchanged)* | `User::verifyEmail()` | `UserId`, `occurredAt` | Nobody. Now dispatched by a *second* use case (AC-29) — which is a nice demonstration that events are facts, not commands: the same fact, from a different cause, needs no new event. |
| *(none)* | `PasswordResetRequest::invalidate()` | — | Deliberately nothing (AC-34). |

**On keeping two unlistened events.** Slice 2 made the same call for `EmailVerificationRequested` and
the reviewer should read these the same way: recorded history, not speculative generality. The bar is
that the event names a fact a domain expert would recognise and that its payload is complete without
a second query. Both clear it. `PasswordResetRequestInvalidated` does not, which is why it does not
exist.

**The same deliberate asymmetry as slice 2: no listener sends this mail.** The Application handler
calls the `PasswordResetMailer` port directly, because it is the only place that legitimately holds
the plaintext token. Routing the secret through an event bus to reach a listener would be the more
elegant-looking wiring and would put a live account-takeover credential in every subscriber, every
debug dump and every `messenger_messages` row.

### Ports (interfaces)

`Domain/Identity/Port/PasswordResetRequestRepository`

```
nextIdentity(): PasswordResetRequestId
save(PasswordResetRequest $request): void
findByTokenHash(HashedResetToken $hash): ?PasswordResetRequest
countIssuedForUserSince(UserId $userId, \DateTimeImmutable $since): int
findOutstandingForUser(UserId $userId): list<PasswordResetRequest>
```

- `findByTokenHash()` returns the request **whether or not it is redeemed, invalidated or expired**
  — slice 2's rule, for slice 2's reason: a repository that hid dead rows would make "expired
  yesterday" and "never existed" the same `null`, destroying information the system needs in a log and
  a test to satisfy a presentation goal that lives three layers out.
- `findOutstandingForUser()` **does** filter, and the distinction is worth stating rather than
  hand-waving: it filters on **structure** (`redeemed_at IS NULL AND invalidated_at IS NULL`), which
  is the aggregate's own recorded state, not on **judgement** (expiry, which requires a clock the
  repository must not reach for). Expired-but-outstanding rows are returned and invalidated along with
  the rest; that is harmless and keeps clock arithmetic out of SQL.
- `save()` declares no `@throws`. The only unique index is on `token_hash`, and a collision would mean
  two independent 256-bit draws landed on the same value. There is no business answer to an
  impossibility; the honest response is a 500. *(Contrast `UserRepository::save()`, which must
  translate a unique-index violation on `email` into `EmailAlreadyRegistered`, because two people
  racing to register one address is an ordinary Tuesday.)*
- Absent on purpose: no `deleteExpired()`. The pruning job owed later adds its own method with its own
  justification.

`Domain/Identity/Port/ResetTokenGenerator`

```
generate(): ResetToken
hash(ResetToken $token): HashedResetToken
```

Two methods, one port, for ADR-0009's reason: the entropy source and the digest are **a single
cryptographic decision** — a fast SHA-256 is only correct *because* the input is 256 bits of CSPRNG
output — and splitting them would let a future adapter pair a weakened generator with the same fast
digest while satisfying both contracts. A separate port from `VerificationTokenGenerator` because the
types differ; merging them would require a shared token type, which is the abstraction *Reuse vs
duplication* declines.

`Domain/Identity/Port/PasswordResetMailer`

```
sendResetLink(Email $recipient, ResetToken $token, \DateTimeImmutable $expiresAt): void
```

Narrow and intention-revealing rather than a generic `MailerPort`, for the reason
`VerificationMailer` states at length: a port names **what the Domain needs**, not what the vendor
provides. The adapter owns the URL, the two templates, the subject, the sender and the transport.
`$expiresAt` is passed rather than recomputed, so the message and the stored row can never disagree
about the deadline.

`Domain/Identity/Port/PasswordHasher` — **reused unchanged.** Its docblock already says so.

### Domain exceptions

`Domain/Identity/Exception/`: `InvalidResetToken`, `InvalidHashedResetToken`,
`InvalidPasswordResetRequestId`, `PasswordResetRequestNotFound`, `PasswordResetLinkExpired`,
`PasswordResetLinkAlreadyUsed`, `PasswordResetLinkInvalidated`, `PasswordResetTokenMismatch`,
`StalePasswordResetRequest`, `TooManyPasswordResetRequests`. All extend `\DomainException`.
**No exception message may contain a plaintext token or a password** (AC-10); messages may carry a
request id or a user id, which are server-side only. `PasswordResetRequestNotFound::forToken()` takes
no argument, on purpose.

---

## Application layer

Thin, framework-free, depends only on `Domain`. Commands carry **primitives, not value objects** —
slice 1's rule, for slice 1's reason: the invariants must hold no matter which adapter dispatches the
command.

### Commands and query

| Class | Fields | Notes |
|---|---|---|
| `Command/RequestPasswordReset` | `string $email` | raw, un-normalised; the handler builds the `Email` |
| `Query/CheckPasswordResetToken` | `string $token` | `#[\SensitiveParameter]` on the constructor |
| `Command/ResetPasswordWithToken` | `string $token`, `string $newPassword` | both `#[\SensitiveParameter]`; the second is a live credential too |

**No outcome enum.** Slice 2 needed `VerificationOutcome` because redemption had two *successful*
answers (verified now / already verified) and the controller had to choose a flash without a second
query. Here there is exactly one success, so `void` is the honest return type and an enum would be a
one-case ceremony.

**`CheckPasswordResetToken` returns `void` and throws.** That looks odd for a query, and it is
deliberate: the answer is "yes, and there is nothing else you need". A `bool` would collapse four
distinguishable causes into one *in the layer that must keep them distinct* — the invalid-link
response is a presentation policy chosen to defeat enumeration (AC-16), and presentation policy is
never a reason to throw information away three layers down.

### `RequestPasswordResetHandler::__invoke(RequestPasswordReset $command): void`

1. `$email = Email::fromString($command->email);` → `InvalidEmail`.
2. `$user = $this->users->findByEmail($email) ?? throw UserNotFound::withEmail($email);`
3. `$now = $this->clock->now();` — **one `now()` for the whole use case.** Slice 2's comment applies
   verbatim: the rate-limit window and `issuedAt` are two halves of one statement and only compose if
   both name the same instant, and a frozen clock makes the discrepancy invisible to every test that
   could catch it.
4. `if ($this->requests->countIssuedForUserSince($user->id(), $now->modify('-1 hour')) >= PasswordResetRequest::MAX_ISSUES_PER_HOUR) { throw TooManyPasswordResetRequests::forUser($user->id()); }`
   — **before the invalidation sweep**, so a spammed form cannot kill a victim's in-flight link
   (failure contract, and AC-7 asserts it).
5. **Invalidate the outstanding set** (AC-9):
   `foreach ($this->requests->findOutstandingForUser($user->id()) as $old) { $old->invalidate($now); $this->requests->save($old); }`
   Bounded by `MAX_ISSUES_PER_HOUR`, so N ≤ 3. **Through the aggregate, not a bulk DQL `UPDATE`** — a
   bulk update would set `invalidated_at` without the aggregate ever agreeing, making I-17
   unenforceable and putting a rule in SQL where no unit test can reach it. The volume is what makes
   the principled choice also the cheap one; if it ever were not, the port would grow a method whose
   docblock said why.
6. `$token = $this->tokens->generate(); $hash = $this->tokens->hash($token);` — the plaintext exists
   from here to step 8 and nowhere else in the system.
7. `$request = PasswordResetRequest::issue($this->requests->nextIdentity(), $user->id(), $hash, $now);`
   `$this->requests->save($request);`
8. `$this->mailer->sendResetLink($user->email(), $token, $request->expiresAt());`
   — **save before send**, so a link sitting in an inbox always has a row behind it. The reverse can
   produce a valid-looking URL that matches no request, indistinguishable from a forgery when clicked.
9. `$this->events->dispatch(...$request->releaseEvents());` — **send before dispatch**, so the audit
   fact is published only once the whole use case succeeded.

**All three declared throws are normal outcomes, not errors** — an unknown address, a fourth request
in an hour, an address in the validator gap. They are exceptions because the *domain* genuinely cannot
proceed; collapsing them into one indistinguishable response is a **presentation** policy and belongs
at the boundary. That separation is what would let a second adapter (a console tool, an admin action)
log a real anomaly where the public form must reveal nothing.

**Not idempotent, on purpose**, and unlike slice 2's resend that non-idempotency has teeth: each call
kills the previous link. `MAX_ISSUES_PER_HOUR` plus the per-IP limiter are what stop it being a
weapon.

### `CheckPasswordResetTokenHandler::__invoke(CheckPasswordResetToken $query): void`

1. `$plain = ResetToken::fromString($query->token);` → `InvalidResetToken` (before any query — AC-17).
2. `$hash = $this->tokens->hash($plain);`
3. `$request = $this->requests->findByTokenHash($hash) ?? throw PasswordResetRequestNotFound::forToken();`
4. `$user = $this->users->findById($request->userId()) ?? throw UserNotFound::withId($request->userId());`
5. `$request->assertRedeemableWith($hash, $this->clock->now());`
6. `$this->assertNotStale($request, $user);` — I-23, shared with the command handler.

**Mutates nothing and dispatches nothing** (AC-12). That is the whole point of the method existing.

### `ResetPasswordWithTokenHandler::__invoke(ResetPasswordWithToken $command): void`

1–4 as above (token VO → digest → request → user). Steps 1–4 are re-run rather than trusted from the
GET, because the GET happened in a different request, possibly minutes ago, possibly from a mail
scanner.
5. `$now = $this->clock->now();` `$request->assertRedeemableWith($hash, $now);`
6. `$this->assertNotStale($request, $user);` → `StalePasswordResetRequest` (I-23, AC-26).
7. `$plain = PlainPassword::fromString($command->newPassword);` → `WeakPassword`.
   **Deliberately after the token checks**: an attacker holding no valid token never reaches the
   password hasher, which is what makes AC-19's limiter belt-and-braces rather than the only defence
   against CPU amplification.
8. `$newHash = $this->hasher->hash($plain);`
9. `$request->redeem($hash, $now);`
10. `$user->changePassword($newHash, $now);`
11. **`$user->verifyEmail($now);`** — AC-28. One line, with a comment saying *why*: the proof came
    from the channel, not from the password change. Idempotent for an already-verified account, so
    AC-30 is free.
12. **`$this->requests->save($request);` then `$this->users->save($user);`** — **REQUEST FIRST,
    USER SECOND. This is the inversion of slice 2's order and it must carry a comment saying so at
    the call site** (ADR-0009 decision 4's rule). Losing the second save leaves a burnt token and an
    unchanged password: the user asks for another link. The reverse would leave a changed password
    and a live, re-usable token.
13. `$this->events->dispatch(...$user->releaseEvents(), ...$request->releaseEvents());` — `$user`
    releases `UserPasswordChanged` and possibly `UserEmailVerified`; `$request` releases nothing,
    because `redeem()` records none. The spread is written anyway so this call site stays correct if
    that ever changes.

`assertNotStale()` is a private method on the handler, shared with the query handler via a small
`GuardsAgainstStaleResetRequests` trait in `Application/Identity/` (or duplicated in two four-line
methods — the implementer may choose; the rule is that the comparison and its `<` are written once).

### Idempotency

- `ResetPasswordWithToken` is **deliberately not idempotent** — a replay is refused (AC-18). This is
  the sharpest single contrast with slice 2 and the reason the two aggregates cannot share a base.
- `CheckPasswordResetToken` is idempotent and side-effect-free by construction, which is what makes
  mail-scanner prefetch safe (AC-12).
- `RequestPasswordReset` is not idempotent: each call mints a new challenge and kills the old one.

### Transaction boundary

One command, one *logical* transaction; the adapter owns `persist`/`flush`. Events dispatch after a
successful save (ADR-0008), synchronously, with the mail already queued (ADR-0010). The
commit→dispatch window remains open and remains accepted.

---

## Infrastructure layer

### Persistence

**Mapping** — `Infrastructure/Identity/Persistence/Doctrine/mapping/PasswordResetRequest.orm.xml`,
picked up by the existing `Identity` mapping block (same directory, no config change). ADR-0007
throughout: explicit table and column names, `<generator strategy="NONE"/>`, `datetimetz_immutable`
timestamps, one custom DBAL type per value object, **no association** to `User`.
`User.orm.xml` gains one nullable `password_changed_at` field.

**New DBAL types** (`Infrastructure/Identity/Persistence/Doctrine/Type/`, registered under
`doctrine.dbal.types`):

| Type name | Class | Column | Converts |
|---|---|---|---|
| `identity_password_reset_request_id` | `PasswordResetRequestIdType` (extends `GuidType`) | `UUID` | `PasswordResetRequestId` ↔ `string` |
| `identity_reset_token_hash` | `HashedResetTokenType` (extends `StringType`) | `VARCHAR(255)` | `HashedResetToken` ↔ `string` |

`user_id` reuses the existing `identity_user_id` type — a plain typed column, not an association.

**Adapter** — `DoctrinePasswordResetRequestRepository implements PasswordResetRequestRepository`,
constructed with `EntityManagerInterface`, not extending `ServiceEntityRepository` (ADR-0007 §7).

- `nextIdentity()` → `PasswordResetRequestId::fromString(Uuid::v7()->toRfc4122())`.
- `save()` → `persist` + `flush`. No unique-constraint translation.
- `findByTokenHash()` → `findOneBy(['tokenHash' => $hash])`, hitting the unique index.
- `countIssuedForUserSince()` → DQL `COUNT` over `userId` + `issuedAt >= :since`.
- `findOutstandingForUser()` → DQL over `userId` + `redeemedAt IS NULL` + `invalidatedAt IS NULL`,
  ordered by `issuedAt`, hitting the same composite index on its leading column.

### Security

- **`Infrastructure/Identity/Security/RandomResetTokenGenerator implements ResetTokenGenerator`** —
  `generate()` = `ResetToken::fromString(rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='))`;
  `hash()` = `HashedResetToken::fromString(hash('sha256', $token->reveal()))`. Both crypto choices in
  one class, neither of them in `Domain`.
- **`VerifiedAccountUserChecker` is not touched.** AC-28's decision is what keeps it that way: a reset
  makes the account usable, so the checker never has to learn about reset state.
- **Session invalidation is inherited, not built** (AC-24). `DomainUserProvider::refreshUser()`
  rebuilds `SecurityUser` from the database on every request, and Symfony's
  `AbstractToken::hasUserChanged()` compares `getPassword()` between the session's token and the
  refreshed user — so a changed hash deauthenticates every other session on its next request. Two
  things follow, and both must be written down rather than remembered:
  - **`SecurityUser::getPassword()` must keep returning the stored hash.** If it is ever changed to
    return a constant, or `SecurityUser` stops implementing `PasswordAuthenticatedUserInterface`,
    AC-24 silently stops holding with no test failing unless AC-24's test exists. It does.
  - **If `remember_me` is ever added to `security.yaml`, `signature_properties` must keep
    `password`** (its default). Removing it would leave a persistent cookie that survives a reset —
    the exact hole AC-24 exists to close, reopened in a different file.
- **Rate limiting** — `config/packages/rate_limiter.yaml` gains two entries, bringing the
  `cache.rate_limiter` pool to four:

  ```yaml
  password_reset_request:   { policy: 'sliding_window', limit: 5,  interval: '1 hour' }
  password_reset_submit:    { policy: 'sliding_window', limit: 10, interval: '1 hour' }
  ```

  `cache_pool` is deliberately not set on either — `cache.yaml` already pins `cache.rate_limiter` to
  Redis, and naming it again would be a second place to keep in step. `sliding_window` for slice 2's
  reason (a fixed window lets 5-at-10:59 plus 5-at-11:00 through).

  **Per-account limiting is deliberately *not* done at the HTTP boundary.** Keying a limiter on the
  submitted address would itself be an oracle — a 429 for known-and-throttled addresses and a 302 for
  everything else sorts a list into "has an account here" and "does not" without ever reading a mail.
  The per-account rule therefore lives where its effect is invisible: inside the handler, behind the
  neutral response (I-21, AC-7). Two limits, two subjects, two homes, exactly as slice 2 argued.

  **Test-suite consequence, unchanged and now four times as likely to bite:** DAMA rolls back
  Postgres, never Redis. Every functional test touching any of these routes clears the pool in
  `setUp()` via `ClearsRateLimiters`.

### HTTP / UI

| Route name | Path | Methods | Action |
|---|---|---|---|
| `app_forgot_password` | `/forgot-password` | GET, POST | `PasswordResetController::request` |
| `app_forgot_password_sent` | `/forgot-password/sent` | GET | `PasswordResetController::sent` |
| `app_reset_password_check` | `/reset-password/{token}` | GET | `PasswordResetController::check` |
| `app_reset_password` | `/reset-password` | GET, POST | `PasswordResetController::reset` |

All four are anonymous; nothing is added to `access_control` (`^/account` stays the only rule). They
must be anonymous — they exist for people who cannot log in.

**The route shapes are chosen so slice 2's priority trap cannot recur.** There, `/verify-email/{token}`
with a `[^/]+` requirement matched the literal `/verify-email/sent`, and only declaration order plus
an explicit `priority` kept the flow working. Here the parameterised route lives under
`/reset-password/` and every literal lives under `/forgot-password/`, so no literal is shadowed and
no `priority` is needed. **If a literal is ever added under `/reset-password/…`, the trap returns
immediately** — verify with `router:match`, and prefer adding it under `/forgot-password/` instead.

- **`request`** — a `ForgotPasswordFormType` over a `ForgotPasswordFormData` DTO with a single
  `email` field (`NotBlank`, `Email(mode: strict)`, `Length(max: Email::MAX_LENGTH)`),
  `allow_extra_fields: false`, CSRF on. On POST: consume one `password_reset_request` token keyed on
  `$request->getClientIp() ?? 'unknown-client'` **before the controller body does anything else**
  (429 via `TooManyRequestsHttpException`, so the framework adds a real `Retry-After`); then dispatch
  `RequestPasswordReset`, catching
  `UserNotFound | TooManyPasswordResetRequests | InvalidEmail` and falling through to **one** exit
  with the neutral flash (AC-5, AC-6). Logged at INFO with `['reason' => $e::class]` and **no
  address** — writing an attacker-chosen string of unverified addresses into the log a line at a time
  is its own problem.
- **`sent`** — a static, address-free "check your inbox" page (AC-36).
- **`check`** — dispatches `CheckPasswordResetToken`. On success: stash the plaintext token in the
  session under `identity.password_reset_token` and 302 to `app_reset_password` (AC-13). On any
  failure: the single invalid-link response. **Both carry `Referrer-Policy: no-referrer`** (AC-15).
  Consumes one `password_reset_submit` token.
- **`reset`** — reads the session-stashed token; absent → the invalid-link response. GET renders
  `NewPasswordFormType` (a `RepeatedType` of `PasswordType`, per `RegistrationFormType`); POST
  dispatches `ResetPasswordWithToken`, and **on success removes the session key**, adds the success
  flash and 302s to `app_login`. Every domain failure → the invalid-link response, which also removes
  the session key (a stashed token that can never work again is a secret with no purpose). A
  *validation* failure re-renders at 422 and **leaves the session key in place** (AC-23). Consumes
  one `password_reset_submit` token on POST.
- **Templates:** `templates/identity/forgot_password.html.twig`,
  `forgot_password_sent.html.twig`, `reset_password.html.twig`, plus
  `templates/email/reset_password.{html,txt}.twig`. The login template gains a *"Forgot your
  password?"* link. **Text part mandatory** on the mail: a `multipart/alternative` with only an HTML
  part is one of the oldest spam signals there is, and this message's entire purpose is to arrive
  (PRD validation #3).

**Why the session stash rather than the token in the form's URL** (decision 5). Slice 2's token lived
in a URL that produced an instant 302, so it was in the address bar for milliseconds. This token would
live in the URL of a **page the user sits on and types into** — which puts a live account-takeover
credential in browser history, in a synced-to-the-cloud history, in a shoulder-surfable address bar,
in any `Referer` the page emits, and in whatever the user pastes when they ask a friend for help.
`Referrer-Policy: no-referrer` closes exactly one of those. One redirect and one session key close the
rest, and we already have Redis-backed sessions. The framing that settles it: **declining the bundle
obliges us to match its security properties, not merely its feature list**, and this is one of the
properties it has.

*(Accepted cost, stated: the reset flow now needs a working session, so Redis being down breaks it
rather than merely un-throttling it. Recorded in the failure contract.)*

### External — mail

`Infrastructure/Identity/Mail/TwigPasswordResetMailer implements PasswordResetMailer` — builds the
absolute URL with `UrlGeneratorInterface::ABSOLUTE_URL`, renders a `TemplatedEmail` with both parts,
sends through `MailerInterface`. No `->from(...)` — `mailer.yaml` drives both the envelope sender and
the `From` header from one `MAILER_FROM`, and setting it here would produce exactly the
From/return-path mismatch that config warns about. No error handling: a transport that refuses throws,
and the *caller* decides what that means.

**`DEFAULT_URI` again (AC-11).** Mail is rendered in the **worker**, with no request context, so the
host comes from `framework.router.default_uri`. It is already set correctly per environment by slice
2; this slice re-asserts it because the failure mode is total in production, invisible in dev and CI,
and uncatchable by any test that runs inside a simulated request.

### Async / schedule

Nothing new. `SendEmailMessage` is already routed to `async` (`sync` under `when@test`). **No new
Messenger message, no new transport, no new Compose service, no new env var** — deliberately, because
ADR-0010's amendment makes every new required boot-path env var a four-place change (`app`,
`messenger-worker`, CI, image build) and the cheapest way to get that right is not to add one. If one
does become necessary, it must be `%env(default::FOO)%` **and** set in all four places.

No Scheduler task. The pruning job stays a non-goal (decision 10).

### DI wiring (`config/services.yaml`)

Three new port aliases — a port with no binding is a compile-time failure, which is the cheapest place
to find out:

```yaml
App\Domain\Identity\Port\PasswordResetRequestRepository: '@App\Infrastructure\Identity\Persistence\Doctrine\DoctrinePasswordResetRequestRepository'
App\Domain\Identity\Port\ResetTokenGenerator:            '@App\Infrastructure\Identity\Security\RandomResetTokenGenerator'
App\Domain\Identity\Port\PasswordResetMailer:            '@App\Infrastructure\Identity\Mail\TwigPasswordResetMailer'
```

---

## Interface boundary & input contract

**`GET|POST /forgot-password`** — `forgot_password_form[email]`, `forgot_password_form[_token]`.
`allow_extra_fields: false`. Responses: **always** 302 → `app_forgot_password_sent` with the same
flash, except **429** when the IP limiter refuses and **422** on a form/CSRF error.

**`GET /reset-password/{token}`**

| Segment | Accepts | Rejects |
|---|---|---|
| `{token}` | any non-empty single path segment (`[^/]+`) at the route; exactly 43 characters of `[A-Za-z0-9_-]` at `ResetToken` | anything else → the invalid-link redirect **from the value object**, byte-identical to the unknown-token response; never a 404, never a 500 |

**Do not add a `requirements` regex here.** Slice 2 did, then removed it: a route requirement's failure
mode is a bare 404 that no `catch` can turn into anything useful, which makes AC-16(c) unsatisfiable
and the controller's `InvalidResetToken` arm dead code — and because mail clients hard-wrap long
lines, the users hitting it are exactly the ones who need the form. The value object is the only
format gate. Every test using a well-formed token still passes with the regex in place, so nothing
will tell you.

**`GET|POST /reset-password`** — `new_password_form[plainPassword][first]`,
`new_password_form[plainPassword][second]`, `new_password_form[_token]`. `allow_extra_fields: false`.
**No token field of any kind** (AC-14) — the token comes from the session. Responses: 302 →
`app_login` on success, 302 → `app_forgot_password` (invalid-link) on any domain failure, **422** on
validation/CSRF failure, **429** when the IP limiter refuses.

**`POST /login`** — unchanged shape. New behaviour is emergent, not coded: a session established
before a reset is deauthenticated on its next request.

**Application contract**

```
RequestPasswordResetHandler::__invoke(RequestPasswordReset): void
    throws InvalidEmail | UserNotFound | TooManyPasswordResetRequests

CheckPasswordResetTokenHandler::__invoke(CheckPasswordResetToken): void
    throws InvalidResetToken | PasswordResetRequestNotFound | PasswordResetLinkExpired
         | PasswordResetLinkAlreadyUsed | PasswordResetLinkInvalidated
         | PasswordResetTokenMismatch | StalePasswordResetRequest | UserNotFound

ResetPasswordWithTokenHandler::__invoke(ResetPasswordWithToken): void
    throws (all of the above) | WeakPassword
```

---

## Data & migrations

One migration. Additive; existing rows keep `password_changed_at = NULL`.

```sql
ALTER TABLE identity_user
    ADD password_changed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL;

CREATE TABLE identity_password_reset_request (
    id             UUID                        NOT NULL,
    user_id        UUID                        NOT NULL,
    token_hash     VARCHAR(255)                NOT NULL,
    issued_at      TIMESTAMP(0) WITH TIME ZONE NOT NULL,
    expires_at     TIMESTAMP(0) WITH TIME ZONE NOT NULL,
    redeemed_at    TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
    invalidated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
    PRIMARY KEY (id)
);

CREATE UNIQUE INDEX uniq_identity_password_reset_request_token_hash
    ON identity_password_reset_request (token_hash);

CREATE INDEX idx_identity_password_reset_request_user_issued
    ON identity_password_reset_request (user_id, issued_at);
```

- **`uniq_…_token_hash`** serves the lookup by digest — the only hot query — and makes a duplicate
  hash a database-level impossibility rather than a hope.
- **`idx_…_user_issued`** serves `countIssuedForUserSince()` (leading column for equality, second for
  the range) **and** `findOutstandingForUser()` (leading column only). A separate partial index on
  `(user_id) WHERE redeemed_at IS NULL AND invalidated_at IS NULL` was considered and rejected: the
  per-user row count is bounded at 3 per hour and the pruning job will keep it small, so the composite
  index is already selective enough. All three queries are asserted with `EXPLAIN` (AC-43).
- **No foreign key to `identity_user`** — ADR-0009 decision 4, and AC-40 asserts its absence.
- `down()` drops the table, both indexes and the new column.
- **Rollback note:** this slice ships **one** migration, so `migrate prev` genuinely reverses it —
  unlike slice 2, where the last migration applied was Messenger's and rolling back "the new table"
  took two steps. Worth stating because the last slice's verification note warns about exactly this.

---

## Test plan

**Domain unit (no kernel, `tests/Unit/Domain/Identity/`)**

- `ResetToken`: accepts a real 43-character base64url string; rejects 42/44 characters, `+`, `/`,
  `=`, empty, and a 10 kB string; `__debugInfo()` masks; **no `__toString()` exists** (reflection).
- `HashedResetToken`: accepts an arbitrary opaque string (proving the Domain does not know the
  algorithm); rejects empty and > 255; `equals()` true for identical values, false for a
  one-character difference (AC-27).
- `PasswordResetRequestId`: format validation, hex-case normalisation, equality.
- `PasswordResetRequest`: `issue()` derives `expiresAt` at exactly +3600 s and records exactly one
  `PasswordResetRequested` **whose payload contains no token**; `redeem()` with the right hash sets
  `redeemedAt` and records nothing; a second `redeem()` throws (I-16); `invalidate()` after `redeem()`
  throws and `redeem()` after `invalidate()` throws (I-17); `invalidate()` twice is a no-op;
  redeeming **at** `expiresAt` succeeds and at `expiresAt + 1 s` throws (I-18 — assert both sides of
  the boundary, or the test proves nothing about which comparison operator is in the code); a
  near-miss hash throws (I-19); `assertRedeemableWith()` mutates nothing; `releaseEvents()` empties.
- `User`: `changePassword()` replaces the hash, sets `passwordChangedAt` and records exactly one
  `UserPasswordChanged` carrying no secret; `verifyEmail()` after `changePassword()` still records
  exactly one event on an unverified user and none on a verified one.

**Application / Integration (real `muzbar_test`, DAMA rollback, `tests/Integration/Identity/`)**

- `DoctrinePasswordResetRequestRepository`: save → `clear()` → `findByTokenHash` round-trips **every**
  value object with equal values (this is what catches a broken DBAL type); `countIssuedForUserSince`
  respects the boundary instant; `findOutstandingForUser` excludes redeemed **and** invalidated rows
  and includes expired ones; `nextIdentity()` yields distinct valid ids.
- `RequestPasswordResetHandler` with a `FrozenClock`, a spy dispatcher and a recording mailer: happy
  path persists one row, sends one message and **invalidates the account's previous outstanding
  request**; unknown email throws and persists nothing; the **4th** call inside the hour throws
  `TooManyPasswordResetRequests`, persists nothing **and leaves the existing request outstanding**.
- `CheckPasswordResetTokenHandler`: happy path returns and **mutates nothing** (re-read the row);
  each of the failure causes throws its own exception class.
- `ResetPasswordWithTokenHandler`: happy path changes the hash, sets `passwordChangedAt`, marks the
  request redeemed and dispatches `UserPasswordChanged`; an **unverified** user is additionally
  verified and dispatches `UserEmailVerified` too (AC-28, AC-29); a verified user dispatches only one
  event (AC-30); expired, unknown, mismatched, invalidated, replayed and **stale** each throw; a weak
  password throws `WeakPassword` **and leaves `redeemed_at` NULL** (AC-23 at the handler level).
- **Foundry factory** `tests/Factory/PasswordResetRequestFactory` — built through
  `PasswordResetRequest::issue()` via `instantiateWith()`, with `afterInstantiate()` releasing the
  recorded event (a factory that persists around the handler leaves events in the buffer and pollutes
  a later test). States: `expired()`, `redeemed()`, `invalidated()`, `issuedAt()`.

**Functional (`tests/Functional/Identity/`)**

- `/forgot-password`: the **four-way indistinguishability** assertion — the four responses captured in
  one run and compared **to each other**, never to four copies of a literal (AC-5); one queued mail
  with a working absolute link; a new request invalidates the old one and the old link then fails
  (AC-9); the IP limiter's 429; CSRF failure.
- The link: a GET **burns nothing** and the token still works afterwards (AC-12); the 302 to the
  token-less path (AC-13); the form page contains the token **nowhere** (AC-14); `Referrer-Policy` on
  success *and* failure (AC-15); expired / unknown / malformed / invalidated / already-used / stale /
  dangling-user / no-session all **byte-identical to each other** (AC-16).
- The reset: password changes and the request is redeemed; **the old password fails and the new one
  succeeds at `/login`** in the same test (AC-21); no auto-login (AC-25); a weak password gives 422
  and the token survives (AC-23); a replay is refused (AC-18).
- **Session invalidation** (AC-24): log in, confirm `GET /account` is 200, perform the reset in the
  same client, then confirm `GET /account` is 302 to `/login`.
- Unverified-account reset (AC-28): reset, then log in successfully **without** touching the
  verification flow at all.
- Mail assertions use `MailerAssertionsTrait` with `SendEmailMessage` routed to `sync` in test.

**Test-environment gotchas to honour**

- **DAMA rolls back Postgres, not Redis.** Four limiters now share `cache.rate_limiter`; every test
  touching `/login`, `/verify-email/resend`, `/forgot-password` or the reset routes clears the pool in
  `setUp()` via `ClearsRateLimiters`. **Update that trait's docblock** — its body does not change (it
  clears the whole pool), but a docblock that names two limiters while protecting four is exactly the
  drift CLAUDE.md warns about.
- **The session is now load-bearing in a functional test.** Test sessions use
  `session.storage.factory.mock_file`, which persists across requests in one `KernelBrowser` — so the
  stash/redirect flow works — but a test that creates a second client, or reboots the kernel, loses
  it. Keep the reset flow inside one client.
- **Swapping an adapter in a test needs two things and each fails silently alone**: target the
  **concrete class's** service id (never the port alias — `ResolveReferencesToAliasesPass` rewrote the
  reference at compile time) and call `$client->disableReboot()` first. Relevant here for a
  deterministic `ResetTokenGenerator` and for a throwing mailer.
- **`FrozenClock` honours the `Clock` contract** (UTC, whole seconds). Expiry tests advance time by
  constructing a second `FrozenClock`, never by `sleep()`. Any new hand-written double must truncate
  too, or it feeds the Domain a precision production can never produce.
- **Do not write a timing assertion** (AC-37). A wall-clock latency comparison is either flaky or
  unfalsifiable, and this repository does not ship assertions that cannot fail.

**Infrastructure assertions**

- `EXPLAIN` shows Index Scans for all three new queries (AC-43).
- The mail renders a correct absolute URL with **no request context** (AC-11).
- `symfonycasts/reset-password-bundle` appears in neither `composer.json` nor `composer.lock`
  (AC-38).

---

## Risks / open questions

1. **The roadmap contradicts this plan and must be corrected, not quietly outvoted.** `docs/roadmap.md`
   line 97 names `symfonycasts/reset-password-bundle`. **Action: correct that line, write ADR-0011,
   amend ADR-0005** (whose 2026-07-26 amendment explicitly left this open) **and amend ADR-0009**
   (decision 5's forward-looking clause is discharged here, in the opposite direction to the flow it
   was written about). A roadmap line that survives contradicting an accepted ADR is how documentation
   starts lying.
2. **This is the most attacked endpoint in the system and we are hand-rolling the flow.** That is the
   honest framing of decision 1's residual risk. Mitigations: every cryptographic primitive is vendor
   code; 45 enumerated acceptance criteria; the reviewer agent treats Constitution §8 as
   non-negotiable; and the design deliberately matches the bundle's parameters (1 hour, single use,
   hashed at rest, throttled) rather than inventing better ones. **Recommendation: this slice's
   `/verify` should get a `/security-review` pass as well as the standard reviewer pass.**
3. **`passwordChangedAt` is the one piece of new state on `User`.** Argued as load-bearing (it makes
   I-23 expressible, which is what makes the lost-invalidation window safe). If the owner disagrees,
   the fallback is to drop it *and* accept that AC-26 disappears and I-20's race becomes the only
   defence — which is worse. **Recommendation: keep.**
4. **Reset-implies-verified (AC-28) is a real policy decision with a real blast radius.** It means a
   bug in the reset flow is a verification bypass. Weighed against: refusing resets to unverified
   accounts strands them completely (cannot log in, cannot recover), and mailing a second challenge to
   re-prove a fact just proved is incoherent. **Recommendation: adopt**, and record it as ADR-0011
   decision 6. If `identity-google-oauth` produces a second instance of the same shape ("a channel we
   have proven control of verifies that channel"), promote it to its own ADR.
5. **The session stash adds a Redis dependency to the reset flow itself**, not merely to its
   throttling. Recorded in the failure contract. The alternative — token in the form page's URL — is
   simpler and leaks to history, `Referer` and shoulders. **Recommendation: keep the stash.**
6. **Reissue-invalidates has a UX cost**: click the older of two mails and the link is dead. Mitigated
   by the low cap (3/hour) and by the invalid-link response being a redirect to the form rather than
   an error page. **Recommendation: accept**; revisit only if support traffic says otherwise.
7. **The timing side channel on `/forgot-password` is real and is not removed** (AC-37). The rejected
   alternative is to dispatch the entire use case to Messenger so the controller does identical work
   for every address; that would make the response constant-time by construction but moves the
   unknown-address and rate-limit paths into the worker, where a stopped worker means silent total
   failure of the flow with no synchronous signal at all. Given ADR-0010's amendment — a stopped worker
   already looks like a healthy system — adding a *second* invisible dependency to a recovery flow is
   the wrong trade today. **Recommendation: stay synchronous; revisit if the per-IP limiter is ever
   removed or if `/health/ready` learns about queue depth.**
8. **No selector/verifier split and no HMAC pepper** on the stored digest — the one place this design
   is measurably simpler than the bundle. The pepper's value is proportional to how guessable the
   pre-image is, and a 256-bit CSPRNG draw has none at any cost; the selector's value is removing a
   timing channel from an index scan whose key has 256 bits of entropy. Both benefits are real and
   both are ~0 here, against the cost of a second column, an application secret in the trust boundary,
   and a key-rotation story. Same reasoning ADR-0009 used for "why not Argon2", one level up.
   **Recommendation: decline. Flagged for the reviewer as the most defensible place to disagree.**
9. **The pruning debt now spans two tables** (`identity_email_verification_request` and
   `identity_password_reset_request`) and this slice **adds to it rather than paying it.** Defensible
   because a redeemed or expired row holds nothing dangerous — the digest of a dead 256-bit secret —
   so this is hygiene, not security. But two tables is where it stops being a footnote.
   **Recommendation: add a small `identity-challenge-pruning` slice to the roadmap, scheduled before
   `identity-google-oauth`.** It is one Scheduler task, one repository method per table, and it is the
   natural moment to also answer the orphan-row question ADR-0009 decision 4 left for GDPR erasure.
10. **`UserPasswordChanged` ships with no listener.** Same judgement slice 2 made for
    `EmailVerificationRequested`, and the reviewer should read it the same way. **Recommendation: add
    "password-changed security notification" to the roadmap** — it is the mechanism by which a victim
    of an account takeover finds out, and it is ~40 lines once this event exists.
11. **Sentry and the Claude Code hooks are still outstanding, and this slice sharpens the argument
    again.** Slice 2 added an asynchronous failure path; this slice hangs the *account-recovery* flow
    off that same path, and adds a destructive endpoint whose interesting failures (`StalePasswordResetRequest`,
    `PasswordResetTokenMismatch`, `UserNotFound` on a dangling request) are logged at warning and
    error and read by nobody. Still `devops` work, still not `Identity` work.
    **Recommendation: a small separate cycle before or alongside this one.**
12. **Orphan rows remain possible by construction** (no FK, ADR-0009 decision 4) — now in a second
    table. Nothing deletes users today. The GDPR-erasure conversation before public launch must sweep
    **both** challenge tables, and the pruning slice in item 9 is the natural place to write that
    down.
