# ADR-0011: Password-reset challenges are modelled in the Domain

- **Status:** Accepted
- **Date:** 2026-07-29
- **Established by** the `identity-password-reset` slice — the repository's **third aggregate**, and
  the first one whose rules are the *deliberate inverse* of an existing one's.
- **Amends** [ADR-0005](./0005-auth-oauth2-google-plus-api-keys.md), whose 2026-07-26 amendment
  explicitly left `symfonycasts/reset-password-bundle` open and told this slice to make its own call.
  See the dated amendment at the foot of that ADR.
- **Amends** [ADR-0009](./0009-email-verification-tokens-modelled-in-the-domain.md) — decision 5's
  forward-looking clause is discharged here, in the opposite direction to the flow it was written
  about, and the "revisit the shared UUID value object at the third example" consequence is restated
  as a criterion rather than a headcount. See the dated amendment at the foot of that ADR.
- **Applies** [ADR-0007](./0007-persistence-conventions-for-domain-aggregates.md) and
  [ADR-0008](./0008-domain-events-recorded-on-the-aggregate.md) unchanged — this decision adds an
  aggregate, not a convention.

## Context

`identity-password-reset` has to turn "we emailed you a link" into stored state a second time. Both
`docs/roadmap.md` (line 97) and ADR-0005's Decision section name **`symfonycasts/reset-password-bundle`**
as the tool, and that naming was made in Phase 0 as part of a stack survey, before any Domain code
existed.

**The lazy argument is not available.** The tempting move is "ADR-0009 rejected the sibling bundle,
so reject this one too." That argument does not work, and using it would be the worst possible way to
make a security decision. ADR-0009's headline reason was that `verify-email-bundle` is **stateless**
— it HMAC-signs a URL and stores nothing, so single use, revocation and auditability are not
expressible with it.

`reset-password-bundle` is not like that. It is genuinely stateful: it requires the application to
own a `ResetPasswordRequest` entity, stores a selector and a hashed token, expires them (default one
hour), deletes the row on use (so: single use), and throttles per user (default one request per
hour). **Every security property this slice's feature spec enumerates, that bundle already
implements** — several of them more carefully than a first attempt would.

So the decision has to be made on different ground, and this ADR is that ground. The secondary goal
ranks here too (Constitution §2), but it is not the only reason, and it is not the first one.

## Decision

**1. A password-reset challenge is a first-class Domain concept: the `PasswordResetRequest`
aggregate root. `symfonycasts/reset-password-bundle` is not installed.**

`Domain/Identity/Entity/PasswordResetRequest` holds a `PasswordResetRequestId`, the `UserId` it
belongs to, a `HashedResetToken`, `issuedAt`, `expiresAt`, a nullable `redeemedAt` and a nullable
`invalidatedAt`. It is created only through `issue()`, judged through `assertRedeemableWith()`,
mutated only through `redeem()` and `invalidate()`, and it records `PasswordResetRequested`.

The blockers against the bundle are **structural**, and each is a rule this repository already wrote
down:

| Conflict | The rule it breaks |
|---|---|
| `ResetPasswordRequestInterface` requires `getUser(): object` — an object reference to another aggregate root — and the bundle's maker generates a `#[ORM\ManyToOne]` with a real foreign key. | **ADR-0009 decision 4**: an aggregate holds another's *id value object, never the object*; the mapping is a plain typed column, never a `<many-to-one>`; and there is no database foreign key between aggregates. |
| Its repository is expected to extend `ServiceEntityRepository` via the bundle's trait and to implement a vendor interface. | **ADR-0007 decision 7**: repository adapters implement the Domain port and do not extend `ServiceEntityRepository`. |
| The entity carries ORM attributes and implements a vendor interface, so it cannot live in `Domain/` at all — a `use SymfonyCasts\…` there fails Deptrac under `--fail-on-uncovered`. | **Constitution §4.2** and **ADR-0007 decision 1** (XML mapping, one file per aggregate, physically outside the class). |
| `ResetPasswordHelperInterface` owns token generation, expiry arithmetic, throttling and validation. | **Constitution §2**: when two paths are equally valid for the product and one teaches DDD more honestly, choose the one that teaches. |

The last row is the one that decides it; the first three are why there is no compromise position.
The bundle's entity would have to be an **Infrastructure** class implementing a vendor interface and
holding a Doctrine association to `User` — which means the `Identity` *domain* would contain no
concept of a password-reset challenge at all. Every rule this slice enumerates (single use, expiry,
supersession, staleness, the ordering of the two saves) would live in a controller, and the Domain
would not be able to state, in any type, that a reset requires a live challenge.

**On "don't roll your own auth"** — ADR-0005's own words, and the strongest objection to this
decision. We are not. Every cryptographic primitive is vendor code: `random_bytes`, `hash('sha256')`,
`hash_equals`, and Symfony's `auto` password hasher. What we write is roughly sixty lines of
**orchestration**, readable in one sitting and covered by 45 enumerated acceptance criteria. The
maxim protects against inventing crypto and inventing flows; this flow is not invented — it is the
same one the bundle implements, written where we can see it.

**2. The token is stored hashed and compared in constant time, with a fast digest.** The plaintext
`ResetToken` — 32 CSPRNG bytes, base64url, 43 characters — exists only in memory between the
generator and the mail body. `HashedResetToken::equals()` uses `hash_equals`.

The digest is SHA-256, deliberately **not** Argon2, and this is worth restating rather than
cross-referencing because the word *password* in this slice's name makes the wrong reflex stronger
than it was in ADR-0009. A **password** needs a slow KDF because it is low-entropy and guessable
offline. A 256-bit CSPRNG draw has no guessable pre-image at any cost, so a slow digest would add
latency to every click and buy nothing. The Domain names no algorithm at all; `HashedResetToken`
validates emptiness and length and nothing else, so that "SHA-256" stays an Infrastructure fact.

**3. The lifetime is one hour and the per-user cap is three issuances per rolling hour — and the
ratio to email verification (24 hours, five issuances) is the point.**

A *verification* token proves reachability; if it leaks, an attacker verifies an address they would
already have had to control in order to receive it. A *reset* token **is an account-takeover
primitive** — whoever holds it owns the account. So the exposure window should be as short as
usability allows, and 24 hours is indefensible for it.

The floor is set by delivery latency, not by patience: a queued message, an external relay and a
recipient's greylisting can cost five to fifteen minutes before the mail is readable, and someone who
requests at a desk and reads on a phone needs slack on top. Fifteen minutes — the tightest number
anyone proposes — is uncomfortable against that floor. One hour clears it with room, is the default
`reset-password-bundle` itself chose, and sits inside the range OWASP ASVS calls short-lived. Picking
the same number as the bundle is deliberate: **we are declining its packaging, not claiming better
judgement about its parameters.**

Three issuances rather than verification's five, for two reasons that are both about the mail rather
than the token. First, a reset mail is the *alarming* one — it is what an attacker uses to flood a
victim's inbox with "someone tried to reset your password", and every extra one is free anxiety
delivered on the attacker's behalf. Second, decision 4 means each new request kills the previous
link, so a high cap actively degrades the experience. Three covers a mistyped address, a mail that
landed in spam, and one more try; it is not enough to be a weapon.

Both numbers are Domain constants on the aggregate (`LIFETIME_SECONDS`, `MAX_ISSUES_PER_HOUR`), not
configuration — same reasoning as ADR-0009: they are policy a domain expert would state, not a knob
an operator should turn.

**4. Issuing a new challenge invalidates the account's outstanding ones.** This is the deliberate
inversion of ADR-0009 decision 5, and it is that decision's own carve-out being cashed in rather than
a new argument.

ADR-0009 could decline invalidation because once *any* verification token verifies the account, every
other live token is inert — redeeming one hits the already-verified short-circuit. Reset has no such
property: **each live token is an independent, repeatable chance to set a password.** So
`RequestPasswordResetHandler` sweeps `findOutstandingForUser()` and calls `invalidate()` on each,
through the aggregate rather than a bulk DQL `UPDATE` — a bulk update would set `invalidated_at`
without the aggregate ever agreeing, putting a rule in SQL where no unit test can reach it. The sweep
is bounded by `MAX_ISSUES_PER_HOUR`, so N ≤ 3.

The **cap is checked before the sweep**, so a spammed form cannot kill a victim's in-flight link.

The accepted cost is a real UX wrinkle: request twice, click the mail that arrived first, and the
link is dead. That is *why* the cap is 3 rather than 5, and why the invalid-link response is a
redirect back to the form rather than an error page.

**5. The two saves are ordered request-first, user-second — the inverse of ADR-0009 decision 3's
order, produced by applying the same rule.**

ADR-0009 decision 4 states the rule: *pick the order whose crash window is benign, and write down why
at the call site.* Applied here it gives the opposite answer, which is the strongest available
evidence that it is a real rule and not a habit:

- **User first, request second** (slice 2's order) → a crash leaves a **changed password and a live
  token**, and that token can set *another* password. This is exactly the danger ADR-0009 decision 5
  named. **Rejected.**
- **Request first, user second** → a crash leaves a **burnt token and an unchanged password**. The
  user asks for another link. Annoying; entirely safe. **Adopted.**

Because a safe ordering exists, two aggregates remain correct under Vernon's "one aggregate per
transaction, eventual consistency between them" — the same test ADR-0009 applied, reaching the same
structural conclusion by the opposite route.

**And a second layer, because "benign" is not "impossible".** The remaining exposure is not the crash
window between the two saves; it is the *sibling* window — a request issued before this reset and
never invalidated (a lost invalidation write, a concurrent request) is still live and still
redeemable. `User` therefore gains one nullable `passwordChangedAt`, and both handlers refuse any
request whose `issuedAt` is strictly before it. One column, one comparison, no cross-row write, and
it holds even when the decision-4 sweep is lost. `passwordChangedAt` is **load-bearing, not audit
garnish**: without it that guard is unexpressible.

The comparison is strict (`<`), so a same-second tie lets the legitimate user through — whole-second
storage (ADR-0007) makes ties real, and erring toward the user is right when the sweep is the primary
mechanism.

**6. A successful reset also marks the email verified.**

The proof is the same proof, arriving over the same channel: the person clicked a link delivered to
that mailbox. Refusing would strand every unverified account with no recovery path at all — such an
account cannot log in (`VerifiedAccountUserChecker`) and, if the reset flow also declined to verify,
could never become usable. Mailing a second challenge to re-prove a fact just proved is incoherent.

Two things this decision deliberately does **not** become:

- **It is not on the aggregate.** `User::changePassword()` does not verify anything. A future
  authenticated "change my password" screen proves nothing about the mailbox, and an aggregate method
  that verified an address would silently make that screen a verification bypass. The coupling is
  *this use case's* business, so it is one line in `ResetPasswordWithTokenHandler` with a comment
  saying why.
- **It needs no new event.** `verifyEmail()` records `UserEmailVerified` exactly as it always did,
  now dispatched by a second use case — a clean demonstration that events are facts, not commands:
  the same fact from a different cause needs no new type.

The residual risk is real and is accepted: **a bug in the reset flow is now also a verification
bypass.** It is priced against stranding unverified accounts permanently, which is worse.

**7. No selector/verifier split and no HMAC pepper on the stored digest.** This is the one place the
design is measurably simpler than the bundle, and it is flagged as the most defensible place to
disagree.

The bundle splits the secret into a non-secret **selector** (the lookup key) and a **verifier**
(never used as a lookup key, stored HMAC-ed with an application secret). Both benefits are real and
both are ~0 here:

- The **pepper's** value is proportional to how guessable the pre-image is, and a 43-character
  base64url draw from `random_bytes(32)` has none at any cost. This is ADR-0009 decision 2's argument
  ("why not Argon2") applied one level up.
- The **selector's** value is removing a timing channel from the lookup. An index scan over a unique
  btree keyed on a 256-bit-entropy digest leaks nothing an attacker can walk.

Against that: a second column, an application secret inside the trust boundary, and a key-rotation
story nobody has written. **Declined.** If a reviewer or a security pass makes the counter-case, this
is reopened rather than defended.

**8. The plaintext token leaves the URL via a session stash plus a redirect.**

`GET /reset-password/{token}` validates and **mutates nothing**, stashes the plaintext under a
session key, and 302s to a token-less `/reset-password`. The form the user types into never carries
the token in its URL or in any field.

The framing that settles it: **declining the bundle obliges us to match its security properties, not
merely its feature list**, and keeping a live credential out of the page a user sits on is one of
them. Slice 2's token lived in a URL that produced an instant 302, so it was in the address bar for
milliseconds; this one would live in the URL of a page with a form, which means browser history,
cloud-synced history, a shoulder-surfable address bar, any `Referer` the page emits, and whatever
gets pasted into a support chat. `Referrer-Policy: no-referrer` closes exactly one of those; one
redirect and one session key close the rest.

The accepted cost is recorded rather than hidden: **the reset flow now needs a working session**, so
Redis being down breaks account recovery rather than merely un-throttling it.

**9. Redemption happens on the POST, not on the GET — and a replay is refused.** This is the second
deliberate inversion of the verification aggregate, and it is forced by mail-scanner prefetch.

Slice 2 could burn its challenge on the GET and treat a replay as a friendly no-op, because scanners
prefetch links and the human's click is often *already* the replay. Here the GET cannot burn
anything: burning it would mean a scanner consumes the link before the user ever sees the form. So
the GET only judges (`assertRedeemableWith()`, which mutates nothing) and the POST redeems.

And a replay must be **refused**, not absorbed, because the effect is destructive and repeatable:
"already used" is the honest answer, and treating it as a no-op would leave a used link able to reach
a password-setting form.

**These four inversions — refuse the replay, invalidate on reissue, mutate nothing on the GET, save
in the opposite order — are why `PasswordResetRequest` and `EmailVerificationRequest` do not share a
base class**, even though the two files are roughly 80% identical. They share a *shape*; they do not
share *behaviour*, and a base class abstracts behaviour or it abstracts nothing worth having. Any
guess a shared base made on those four rules would be right for one subclass and a latent security
bug in the other — and the one it would be wrong for is this one, where "wrong" means a replayable
account-takeover primitive. Each of the four carries a comment at its call site saying *why* it is
inverted, because a future reader who diffs the two aggregates will find four differences and, absent
reasons, will eventually "fix" one.

## Alternatives

- **`symfonycasts/reset-password-bundle`, stated at full strength.** It is battle-tested on the most
  attacked endpoint in any auth system; a subtle mistake here is account takeover, not a cosmetic
  bug. Its token scheme (decision 7) is better engineering than a single hashed token. Its throttle
  is opinionated in a good direction — one request per hour per user — which incidentally makes the
  "your new link killed the old one" confusion rare. And it ships this afternoon. **Rejected** on the
  four structural conflicts in decision 1, none of which has a compromise position, and on
  Constitution §2: the only interesting thing in the slice would live in vendor code. The
  auditability difference is worth naming too — the bundle *deletes* the row on use, and for the
  endpoint most likely to appear in an incident review, "when was this account's password reset, and
  from which request" is worth keeping. We keep it with `redeemedAt` and `invalidatedAt`, at the cost
  of a pruning job we already owed.
- **A shared `Challenge` base class** for the two challenge aggregates. **Rejected** per decision 9 —
  four rules point in opposite directions, and the 80% overlap is a coincidence of *storage*, not a
  shared concept.
- **A shared `Domain/Shared/ValueObject/Uuid`**, now that `PasswordResetRequestId` is the third
  near-clone. **Rejected**, and ADR-0009's trigger is restated as a criterion instead of a headcount
  (see the amendment there). Two reasons beyond the count: all three examples come from **one**
  bounded context, so an abstraction induced from them is an `Identity` abstraction wearing a
  `Shared/` namespace; and the naive extraction opens a type hole — a base declaring
  `equals(self $other)` resolves `self` to the **base**, so `$userId->equals($passwordResetRequestId)`
  would compile and return a meaningful-looking `false` where today it is a `TypeError` at the moment
  it is written. Trading a compile-time guarantee for forty saved lines, in the context that holds
  the credentials, is a bad trade.
- **One `closedAt` column plus a reason enum**, instead of two nullable timestamps. **Rejected**:
  "redeemed at most once" then reads off a column-plus-discriminator pair rather than one column, the
  mutual exclusion becomes a state machine rather than two guards, and "how many resets completed?"
  versus "how many were superseded?" are questions worth answering separately.
- **Dispatching the whole request-reset use case to Messenger**, making the response constant-time by
  construction and closing the timing side channel on `/forgot-password`. **Rejected today**: it
  moves the unknown-address and rate-limit paths into the worker, and per ADR-0010's amendment a
  stopped worker already looks exactly like a healthy system. Adding a *second* invisible dependency
  to the account-recovery flow is the wrong trade. Revisit if the per-IP limiter is ever removed or
  if `/health/ready` learns about queue depth.
- **A route `requirements` regex on `{token}`.** Rejected for slice 2's discovered reason: a route
  requirement's failure mode is a bare 404 that no `catch` can turn into anything useful, which makes
  the single-invalid-link-response criterion unsatisfiable and the controller's `InvalidResetToken`
  arm dead code. Mail clients hard-wrap long lines, so the people hitting it are exactly the ones who
  need the form. The value object is the only format gate — and note that every test using a
  well-formed token passes with the regex in place, so nothing will tell you.

## Consequences

- **Easy:** every security property the feature spec enumerates falls out of the model — hashed at
  rest, single-use via `redeemedAt`, revocable via `invalidatedAt`, time-limited, constant-time
  compared, auditable. ADR-0007, ADR-0008 and ADR-0009's reference-by-identity rule are reused
  exactly; no new persistence convention is invented. `PlainPassword`, `HashedPassword`, the
  `PasswordHasher` port, `User::verifyEmail()`, the `Clock` and `DomainEventDispatcher` ports, the
  `RecordsEvents` trait and the whole Messenger/Mailer setup from slice 2 are reused unchanged. **The
  reuse is at the level of ports and infrastructure, which is where reuse belongs; the duplication is
  at the level of policy objects, which is where it is honest.** Session invalidation after a reset
  is *inherited, not built*: `DomainUserProvider::refreshUser()` plus Symfony's
  `AbstractToken::hasUserChanged()` deauthenticate every other session on its next request.
- **Hard / watched:**
  - **`User` is touched**, unlike slice 2's headline result: one property, one method, one reader,
    one event import. That is the honest floor — *changing a password* is a statement about the
    account, and the account aggregate owns it.
  - **A bug in the reset flow is now a verification bypass** (decision 6). Priced deliberately.
  - **`SecurityUser::getPassword()` must keep returning the stored hash**, and `SecurityUser` must
    keep implementing `PasswordAuthenticatedUserInterface`, or session invalidation silently stops
    working with nothing failing. If `remember_me` is ever added to `security.yaml`, its
    `signature_properties` must keep `password` — removing it reopens the same hole in a different
    file.
  - **The reset flow depends on the session store.** Redis down breaks account recovery, not just its
    throttling.
  - **The timing side channel on `/forgot-password` is real and is not removed.** A known address does
    measurably more work than an unknown one. Accepted; the alternative is rejected above.
  - **`UserPasswordChanged` ships with no listener.** It is the hook for the "your password was
    changed" security notification — the mechanism by which a takeover victim finds out — which is on
    the roadmap and is roughly 40 lines once this event exists.
  - **The pruning debt now spans two tables** (`identity_email_verification_request` and
    `identity_password_reset_request`) and this slice adds to it rather than paying it. A redeemed or
    expired row holds only the digest of a dead 256-bit secret, so this is hygiene rather than
    security — but two tables is where it stops being a footnote. An `identity-challenge-pruning`
    slice is on the roadmap **before** `identity-google-oauth`, and it is the natural moment to answer
    the orphan-row question ADR-0009 decision 4 left for GDPR erasure.
  - **Orphan rows remain possible by construction** (no FK), now in a second table.
  - **The cap and the "at most one live request per user" rule are not aggregate invariants.** They
    span instances, exactly like email uniqueness, so they are enforced by the handler over repository
    queries and can lose a race. Decision 5's `passwordChangedAt` guard is what makes losing that race
    safe rather than merely unlikely.

## Amendment, 2026-08-01 — the two-table pruning debt is discharged, and decision 9 is tested one level down

`identity-challenge-pruning` has landed
([ADR-0012](./0012-challenge-retention-and-recurring-background-work.md)).

**The debt is paid.** The consequence above reads: *"the pruning debt now spans two tables … an
`identity-challenge-pruning` slice is on the roadmap before `identity-google-oauth`, and it is the
natural moment to answer the orphan-row question ADR-0009 decision 4 left for GDPR erasure."* Both
halves happened, in that slice, in that order. `PasswordResetRequest` rows are kept **30 days past
expiry** and then deleted; the orphan question is answered without a foreign key (see the dated
amendment on ADR-0009).

That 30 days is the number behind a promise this ADR made in its Alternatives section. Declining
`reset-password-bundle` partly on the grounds that it *deletes* the row on use — *"for the endpoint
most likely to appear in an incident review, 'when was this account's password reset, and from which
request' is worth keeping"* — was a claim that only meant something once a retention window existed.
It is now the longest window in the system, and it is longer than the verification table's on exactly
that reasoning. **Cashing a claim like that is what makes it a decision rather than a rhetorical
flourish**; leaving it uncashed would have made the bundle's behaviour the better-argued option in
retrospect.

**Decision 9 was re-tested one level down and held.** This ADR argued that the four inversions are
why the two challenge aggregates share no base class. A pruning slice arrives with that same
abstraction ready-made and disguised — *"delete the dead rows from both tables"* is the rejected base
class written as a `WHERE` clause, and in SQL rather than PHP, so **it would have been invisible to
every unit test.** ADR-0012 decision 1 declines it and prunes on `expires_at` alone, which is the one
column the two tables genuinely agree about.

The lesson worth carrying: **a rejected abstraction does not stay rejected by itself.** It comes back
in a different layer, wearing the vocabulary of that layer, and the second time it is harder to
recognise because nobody is looking at the two class definitions side by side any more.

**Decision 4's rule was generalised rather than contradicted, and the distinction is worth keeping.**
That decision rejected a bulk DQL `UPDATE` for invalidation — *"putting a rule in SQL where no unit
test can reach it"* — while ADR-0012 ships a bulk `DELETE`. Both are right, because `invalidate()` is
a **state transition** with an invariant to protect (I-17) and deletion is not a transition at all:
an aggregate has no invariants about its own non-existence, so a bulk `DELETE` bypasses nothing. The
general form is recorded in ADR-0012 decision 2: **put in the Domain the part that can be wrong; a
bulk operation is illegitimate when it bypasses a rule and legitimate when there is no rule to
bypass.**

**The aggregate itself is barely touched:** one public constant (`RETENTION_AFTER_EXPIRY_SECONDS`)
and one pure static (`retentionThreshold()`). Every rule this ADR established — the four inversions,
both save orderings, the lifetime, the cap, the exception ordering — is unchanged.
