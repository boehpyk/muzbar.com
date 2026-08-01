# ADR-0009: Email-verification tokens are modelled in the Domain

- **Status:** Accepted
- **Date:** 2026-07-26
- **Established by** the `identity-email-verification` slice — the repository's **second aggregate**
  and its first cross-aggregate reference by identity.
- **Amends** [ADR-0005](./0005-auth-oauth2-google-plus-api-keys.md), whose Decision section names
  `symfonycasts/verify-email-bundle` in passing. See the dated amendment at the foot of that ADR.
- **Applies** [ADR-0007](./0007-persistence-conventions-for-domain-aggregates.md) unchanged — this
  decision adds an aggregate, not a persistence convention.

## Context

`identity-email-verification` has to turn "we sent you a link" into stored state. ADR-0005 and
`docs/roadmap.md` both name **`symfonycasts/verify-email-bundle`** as the tool for the job, and that
naming was made in Phase 0, before any Domain code existed, as part of a stack survey rather than as
a modelling decision.

The bundle is **stateless by design**: it stores nothing. It HMAC-signs a URL with `APP_SECRET` plus
the user's id and address, and validates the signature on the way back. That is an elegant trick, and
it is a different feature from the one the feature spec enumerates. The spec asks for *single use*,
*revocable*, *auditable*, *rate-limited per user* — every one of which is a statement about stored
state.

So the real question is not "which bundle" but **where the verification challenge lives**: nowhere (a
signature), on the `User` aggregate, or as an aggregate of its own.

The secondary goal ranks here too (Constitution §2): this is the slice that can teach the second
aggregate honestly, or can hide it in a vendor package.

## Decision

**1. A verification challenge is a first-class Domain concept: the `EmailVerificationRequest`
aggregate root.**

`Domain/Identity/Entity/EmailVerificationRequest` holds an `EmailVerificationRequestId`, the `UserId`
it belongs to, a `HashedVerificationToken`, `issuedAt`, `expiresAt` and a nullable `redeemedAt`. It is
created only through `issue()`, mutated only through `redeem()`, and it records
`EmailVerificationRequested`. `symfonycasts/verify-email-bundle` is **not** installed.

**2. The token is stored hashed and compared in constant time.** The plaintext `VerificationToken` —
32 CSPRNG bytes, base64url, 43 characters — exists only in memory between the generator and the mail
body. `HashedVerificationToken::equals()` uses `hash_equals`.

The digest is fast (SHA-256), deliberately **not** Argon2. Passwords need a slow KDF because they are
low-entropy and guessable offline; a 256-bit random token has no guessable pre-image at any cost, so a
slow digest would buy nothing and cost latency on every click. Same *pattern* as
`PlainPassword`/`HashedPassword`, different *reason* — and, as there, the Domain names no algorithm at
all. `HashedVerificationToken` validates length and emptiness and nothing else.

**3. It is its own aggregate, not fields on `User` and not a collection inside it.**

The invariant test decides it. An aggregate boundary is drawn where a rule must hold at the end of
*every* transaction. Redemption changes two things — the request becomes redeemed, the user becomes
verified. Order them **user first, request second** and every crash window is benign: a crash after
verifying the user leaves a live token whose later redemption is a no-op, because the handler
short-circuits on an already-verified user. The reverse order would burn the token and leave the user
unverified — locked out of their own link. Because a safe ordering exists, the rule does not require
one transaction, and Vernon's "one aggregate per transaction, eventual consistency between them"
applies.

The ordering is therefore not cosmetic; it is what makes two aggregates *correct* here, and it is
written into `VerifyEmailWithTokenHandler` with a comment saying so.

**4. There is no database foreign key from `identity_email_verification_request.user_id` to
`identity_user.id`.** This is the convention every later context inherits, which is why it is here and
not in a code comment.

Aggregates reference each other **by identity**. A hand-added FK that Doctrine's mapping does not know
about also shows up as spurious noise in every future `make migration.make` diff, so it would be
deleted-and-re-added forever. The accepted cost is that orphan rows become possible if a user is ever
hard-deleted; nothing deletes users today, the pruning job owed later will sweep them, and the
GDPR-erasure conversation before public launch must not forget this table.

**5. Reissuing does *not* invalidate outstanding links.** Once any token verifies the account, every
other live token is inert — redeeming one hits the already-verified short-circuit. Invalidation would
buy no security and cost either a column or a cross-instance write. **This reasoning does not transfer
to `identity-password-reset`**, where a stale live token *is* dangerous because setting a new password
can be applied repeatedly. Recorded so slice 3 does not copy the wrong precedent.

## Alternatives

- **`symfonycasts/verify-email-bundle` (as ADR-0005 named it).** Zero new tables, zero new domain
  code, a well-audited signature scheme, ships in an afternoon. Rejected because **nothing is stored,
  so "single use" cannot exist**: a signed URL stays valid until it expires, replayable by anyone who
  reads the mailbox or a proxy log. Revocation is impossible. Rotating `APP_SECRET` silently
  invalidates every outstanding link. And the token concept never enters the model — the only
  interesting thing in the slice would live in a vendor bundle, which is exactly the trade
  Constitution §2 tells us to refuse when the alternative teaches.
- **Fields on `User`** (`verificationTokenHash`, `expiresAt`, `redeemedAt`). Rejected: it grows the
  root for a concern with a completely different clock speed — a `User` lives for years, a challenge
  for a day — and puts a short-lived credential in the same row as the account's identity. It also
  drags a multi-field nullable value object into the mapping, which Doctrine can only express as an
  embeddable (ADR-0007 decision 4 rejects embeddables) or as three loose nullable columns held in sync
  by hand.
- **A collection of challenge entities inside the `User` aggregate.** Rejected: every resend appends a
  row, so the root acquires an unbounded collection — the classic aggregate smell — and every login
  risks hydrating it.
- **A foreign key with `ON DELETE CASCADE`.** Rejected per decision 4: referential integrity between
  aggregates is the application's job, and the migration-diff noise is a permanent tax.
- **Storing the token in plaintext.** Rejected without discussion; a database dump would then be a
  set of working account-takeover URLs.

## Consequences

- **Easy:** every security property the feature spec enumerates falls out of the model — hashed at
  rest, single-use via `redeemedAt`, time-limited, constant-time compared, revocable, auditable. The
  anti-abuse count (`countIssuedForUserSince`) has an obvious home. A pruning job later has a natural
  unit to prune. ADR-0007 is reused exactly; no new persistence convention is invented. `User` is
  **not touched at all** — slice 1's design paying off, and the headline result of slice 2.
- **Hard / watched:**
  - One table, one migration, ~8 small classes, and a **pruning job owed** for expired and redeemed
    rows. The rows are tiny; the debt is real but cheap.
  - **`EmailVerificationRequestId` duplicates `UserId` almost line for line.** Two is a coincidence;
    three is a pattern. No `Domain/Shared/ValueObject/Uuid` yet — revisit when `Catalog` produces the
    third example, because a shared base abstracted from two usually fits neither.
  - **"At most 5 requests per user per rolling hour" is not an aggregate invariant.** It spans
    instances, exactly like email uniqueness (I-6), so it is enforced by the Application handler over
    a repository count and can lose a race under concurrent submissions. The worst case is a sixth
    email, and the per-IP limiter is the second layer. The asymmetry is the lesson, not a defect.
  - **Orphan rows** are possible by construction (decision 4). Nothing deletes users today.
  - The plaintext token travels in a URL path, so it can reach nginx access logs and browser history.
    Single use and the 24 h lifetime are the real mitigations; `Referrer-Policy: no-referrer` on the
    verification responses is the cheap belt-and-braces one.

## Amendment, 2026-07-29 — decision 5's forward-looking clause is discharged, and the shared-UUID trigger is restated

`identity-password-reset` has landed, and it settles two things this ADR left pointing at the future.

**Decision 5 is discharged, in the opposite direction.** This ADR said reissuing does *not* invalidate
outstanding verification links, and added: *"This reasoning does not transfer to
`identity-password-reset`, where a stale live token is dangerous because setting a new password can be
applied repeatedly."* [ADR-0011](./0011-password-reset-challenges-modelled-in-the-domain.md) decision
4 cashes that carve-out in: **issuing a reset challenge invalidates the account's outstanding ones**,
swept through the aggregate rather than a bulk `UPDATE`, with the per-user cap checked *before* the
sweep so a spammed form cannot kill a victim's in-flight link.

The clause did its job. It was written so slice 3 would not copy the wrong precedent, and slice 3 did
not. The general lesson is worth keeping: **when an ADR declines a safeguard because a specific
property makes it unnecessary, name the property**, not the conclusion — that is what lets a later
slice check whether it still holds.

Decision 3's **save ordering** was discharged the same way. This ADR ordered user-first,
request-second because that crash window is benign. ADR-0011 decision 5 applies the identical rule
from decision 4 (*pick the order whose crash window is benign, and write down why at the call site*)
and gets **request-first, user-second** — the inverse. A rule that produces different answers from
different inputs is a rule; one that always produces the same answer is a habit.

**The shared-UUID trigger is restated as a criterion rather than a headcount.** The consequence above
reads: *"Two is a coincidence; three is a pattern. No `Domain/Shared/ValueObject/Uuid` yet — revisit
when `Catalog` produces the third example."* `PasswordResetRequestId` is the third example, and it
arrived early and from inside `Identity`. The answer is still **no**, so the trigger is replaced:

> **Revisit at the first aggregate id outside `Identity`, and only if the extraction can preserve
> cross-type comparison as a compile-time error.**

Both halves matter, and the count never did:

1. All three examples come from **one bounded context**. An abstraction induced from three samples of
   `Identity` is an `Identity` abstraction wearing a `Shared/` namespace. `Catalog` was named as the
   trigger precisely *because* it is a different context — that is what tests whether the commonality
   is "an aggregate identity" or "how `Identity` happens to spell things".
2. The naive extraction opens a **type hole**, and it is not obvious. A base class declaring
   `public function equals(self $other): bool` resolves `self` to the **base**, so after extraction
   `$userId->equals($passwordResetRequestId)` compiles and returns a meaningful-looking `false`.
   Today that is a `TypeError` at the moment it is written. Trading a compile-time guarantee for forty
   saved lines, in the context that holds the credentials, is a bad trade.

The duplication being tolerated is cheap, local and non-viral: three ~40-line files that nothing else
depends on, each with a docblock saying the sameness is an encoding coincidence.

**One thing this ADR got right and should be read as precedent:** it kept `EmailVerificationRequested`
with no listener, on the grounds that an event naming a fact a domain expert would recognise, whose
payload is complete without a second query, is recorded history rather than speculative generality.
ADR-0011 ships two more on the same basis, and declines a third (`PasswordResetRequestInvalidated`)
that fails the test.

## Amendment, 2026-08-01 — decision 4's orphan clause is discharged, and the pruning debt is paid

`identity-challenge-pruning` has landed
([ADR-0012](./0012-challenge-retention-and-recurring-background-work.md)), and it settles the two
things this ADR left owing.

**Decision 4's orphan clause is answered, not reversed. No foreign key is added.** The clause read:
*the cost is possible orphan rows; the pruning job and the GDPR-erasure design own that.* ADR-0012
decision 6 discharges the first half and specifies the second:

- **Orphan-ness is not a prune criterion.** An orphan is deleted on the ordinary `expires_at`
  schedule like any other row, with no special handling. It is a state that resolves itself inside
  the retention window.
- **A read-only `ChallengeIntegrityProbe` counts them per table and never deletes**, reporting into
  the run's log line and `/health/ready`, at warning when non-zero. This ADR's consequence said
  *"orphan rows are possible by construction. Nothing deletes users today."* The second sentence was
  true and unmeasured; it is now **measured every hour** rather than assumed, which is the actual
  difference between an accepted cost and an ignored one.
- **Erasure gets an ordering rule** rather than a mention: challenge rows from both tables first,
  the `identity_user` row last — so a crash leaves a user with no challenges instead of orphans that
  read as corruption — and retention windows explicitly do **not** apply to an erasure request.

The reasoning that declined the FK is unchanged and was re-tested rather than assumed: an FK the
mapping does not know about is diffed as unwanted on every `make migration.make`, deleted and
re-added forever.

**The pruning debt is paid, but not in the shape this ADR predicted, and the difference is the
interesting part.** The consequence above reads: *"a pruning job owed for expired and redeemed
rows."* ADR-0012 prunes on **`expires_at` alone** and never consults `redeemed_at` at all. The reason
is that by the time the job was written there were two challenge tables whose notions of "finished"
had been deliberately inverted (ADR-0011 decision 9), so a predicate built out of "dead" would have
been the rejected shared base class re-derived in SQL, where no unit test can reach it. `expires_at`
is a *ceiling* on every reason a row is finished — a redeemed row still expires — so the sweep gets
the same rows without ever encoding the disagreement.

The lesson generalises, and it is the counterpart to this ADR's earlier one about naming the property
rather than the conclusion:

> **A debt recorded in one slice's vocabulary should be discharged in the vocabulary that is true when
> it comes due.** "Expired and redeemed" was an accurate description of one table in isolation. Two
> tables later it had become a trap, and copying the phrase forward would have implemented it.

**Also discharged:** the consequence *"a pruning job later has a natural unit to prune"* — it did, and
the unit turned out to be a column rather than an aggregate, because deletion is not a state
transition and needs no aggregate to agree to it (ADR-0012 decision 2).
