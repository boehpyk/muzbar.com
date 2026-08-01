<?php

declare(strict_types=1);

namespace App\Domain\Identity\Port;

use App\Domain\Identity\Entity\PasswordResetRequest;
use App\Domain\Identity\ValueObject\HashedResetToken;
use App\Domain\Identity\ValueObject\PasswordResetRequestId;
use App\Domain\Identity\ValueObject\UserId;

/**
 * The collection of PasswordResetRequests, as the Domain wants to talk about it.
 *
 * One repository per aggregate root — this is the context's third, and its existence is the clearest
 * signal that `PasswordResetRequest` really is a root rather than a few columns on `User`. The
 * interface is declared here and implemented in `Infrastructure`: dependency inversion is what lets
 * the Domain state its needs without knowing that Postgres exists.
 *
 * Note what is **absent**: there is no `findByUser()` returning everything, no way to reach a `User`
 * from here, and no `findExpiredBefore()` handing back objects. **A repository should expose the
 * queries the use cases actually make, not the queries a table could answer** — that half of the rule
 * is unchanged, and it is why the list below stays short even as the context's fourth slice lands.
 *
 * The other half used to be an IOU: *"the pruning job owed later (`identity-challenge-pruning` on the
 * roadmap, which two tables now owe) will add its own method when it is written, with its own
 * justification; adding it now would be a method with no caller, no test that means anything, and a
 * retention policy nobody has decided."* **All three objections are now discharged.** That slice
 * shipped: there is a caller (`PruneExpiredChallengesHandler`), there are two-sided boundary tests,
 * and the retention policy is decided and written down as
 * `PasswordResetRequest::RETENTION_AFTER_EXPIRY_SECONDS` (ADR-0012 decision 3). It added
 * `countExpiredBefore()` and `deleteExpiredBefore()` below — declared here rather than on some shared
 * sweeper, named for *this* aggregate, and note that they are **not** the `deleteExpired()` the old
 * note imagined: the threshold arrives from the caller instead of being assumed, and the delete
 * refuses to run without a limit. What remains absent, still deliberately: `deleteRedeemed()` and
 * `deleteForUser()` — see `deleteExpiredBefore()` for why neither is a convenience worth having yet.
 */
interface PasswordResetRequestRepository
{
    /**
     * Mints the identity for a request that does not exist yet.
     *
     * The repository generates the id — not the database — so the aggregate is complete and valid
     * the moment it is constructed, before it has ever met a transaction (ADR-0007:
     * application-assigned UUIDv7, mapped with `<generator strategy="NONE"/>`). That is what lets
     * `issue()` record an event carrying its own id, and what lets a request be built and discarded
     * on a rate-limit refusal without having touched the database at all.
     *
     * It is also **why `PasswordResetRequestId` has no `generate()`**: choosing a UUID version and an
     * implementation (`symfony/uid`) is a vendor decision, and the Domain states only what a valid id
     * looks like.
     */
    public function nextIdentity(): PasswordResetRequestId;

    /**
     * Persists the aggregate, new or changed.
     *
     * **This method declares no `@throws`, and the contrast with `UserRepository::save()` is the
     * point.** There, the adapter is obliged to catch a unique-index violation on `email` and
     * translate it into `EmailAlreadyRegistered`, because two people racing to register the same
     * address is an ordinary Tuesday and "that address is taken" is a business answer.
     *
     * Here the only unique index is on `token_hash`, and a collision would mean two independent
     * draws of 256 CSPRNG bits landed on the same value. There is no business answer to an
     * impossibility, because it is not a business event; the honest response is a 500 and an alert.
     * Wrapping it in a domain exception would dress an impossibility up as a handled case and would
     * leave a `catch` in the handler that no test can ever reach and no reader can ever evaluate. A
     * failure contract should describe things that happen.
     */
    public function save(PasswordResetRequest $request): void;

    /**
     * Finds the request holding this digest, **whether or not it is redeemed, invalidated or
     * expired**.
     *
     * The filtering that is *not* here is deliberate, and it is the same rule slice 2 wrote down. A
     * repository that silently skipped dead rows would look tidier at the call site and would be a
     * genuine bug: it would make "this link expired an hour ago", "a newer request superseded this
     * one", "this link was already used" and "this token was never issued" produce the **same
     * `null`**, and the system would lose the ability to tell them apart at the exact moment it most
     * wants to — in a log, in a test, and in any future decision about which of them is worth an
     * alert.
     *
     * That the *visitor* sees one identical response for all of them (AC-16) is a **presentation**
     * policy, chosen to defeat account enumeration, and presentation policy that lives three layers
     * out is never a reason to destroy information down here. Business state is judged by the
     * aggregate — `assertRedeemableWith()`, `isExpiredAt()` — because a repository that judges
     * business state is an aggregate with a SQL accent.
     */
    public function findByTokenHash(HashedResetToken $hash): ?PasswordResetRequest;

    /**
     * How many requests this user has been issued since `$since`.
     *
     * This is the anti-abuse rule I-21 in the only form it can take. "At most three per rolling
     * hour" spans *instances*, so no single aggregate can enforce it — an aggregate only ever sees
     * itself — exactly as email uniqueness (I-6) spans all users. The count lives here and the
     * comparison against `PasswordResetRequest::MAX_ISSUES_PER_HOUR` lives in the handler, which
     * makes it **before** the invalidation sweep so that a spammed form cannot kill a victim's
     * in-flight link.
     *
     * The window is passed in rather than assumed, because "an hour ago" is a value from the `Clock`
     * port and a repository that computed it would be reaching for the wall clock — the one thing
     * the `Clock` port exists to prevent.
     *
     * Unlike a unique index, this cannot be made race-free: two simultaneous submissions can both
     * read three and both write. Accepted knowingly — the worst case is a fourth email, and the
     * per-IP limiter at the HTTP boundary is the second layer.
     */
    public function countIssuedForUserSince(UserId $userId, \DateTimeImmutable $since): int;

    /**
     * The user's requests that are still **structurally open**: neither redeemed nor invalidated.
     *
     * THIS METHOD FILTERS AND `findByTokenHash()` DOES NOT, AND THE DISTINCTION IS A RULE RATHER
     * THAN A MOOD. It filters on **structure** — `redeemed_at IS NULL AND invalidated_at IS NULL`,
     * which is the aggregate's own recorded state, two columns it wrote itself. It must never filter
     * on **judgement**: expiry is not a column, it is a comparison against an instant, and an instant
     * comes from the `Clock` port, which a repository must not reach for. A repository that knew what
     * time it was would be able to answer questions whose truth changes between the query and the
     * assertion, and no test could pin it.
     *
     * So **expired-but-outstanding rows are returned and invalidated along with the rest**. That is
     * harmless — invalidating a request that was going to die of old age anyway costs one write and
     * makes the reason it stopped being live explicit — and it keeps clock arithmetic out of SQL,
     * which is worth much more than the write.
     *
     * The caller is `RequestPasswordResetHandler`'s reissue sweep (ADR-0011 decision 4), and it
     * invalidates each returned request **through the aggregate**, never with a bulk DQL `UPDATE`.
     * The result is bounded by `PasswordResetRequest::MAX_ISSUES_PER_HOUR`, so N ≤ 3 in practice —
     * which is what makes the principled choice also the cheap one.
     *
     * @return list<PasswordResetRequest> ordered by `issuedAt`; empty when the user has none open
     */
    public function findOutstandingForUser(UserId $userId): array;

    /**
     * How many reset requests have an `expiresAt` **strictly before** `$threshold` — this table's
     * pruning backlog, read before a single row is removed.
     *
     * `$threshold` arrives from the caller and is **never computed here**. That is the rule
     * `findOutstandingForUser()` states at length one method up and `countIssuedForUserSince()` states
     * again: an instant belongs to the `Clock` port, and **a repository that knew what time it was
     * could answer questions whose truth changes between the query and the assertion about it**. The
     * caller derives it once per run from `PasswordResetRequest::retentionThreshold()`, so the count
     * and the sweep that follows it describe the same moment rather than two.
     *
     * **The strict `<` is part of the contract, not an implementation detail.** `expires_at` is stored
     * to whole seconds (ADR-0007), so slipping to `<=` moves the boundary by a second — invisible to
     * any one-sided test, which is why the boundary is asserted from both sides (AC-4). This method
     * and `deleteExpiredBefore()` must agree on the operator, or a healthy table would report a
     * backlog the sweep can never clear.
     *
     * **The predicate is `expiresAt` and nothing else** — no `redeemedAt`, no `invalidatedAt`, no
     * comparison against `identity_user.password_changed_at`, no join. That will look like an omission
     * to a future reader holding this file next to the four inversions in `PasswordResetRequest`, so
     * the reason lives here: this aggregate and `EmailVerificationRequest` **disagree about what a
     * finished row is, by design** (ADR-0011 decision 9), and a "dead" predicate would be the rejected
     * `Challenge` base class re-derived in SQL, where — as ADR-0011 decision 4 already argued about
     * the bulk `UPDATE` it declined — no unit test could reach it. Worse, this table's staleness
     * notion lives on a *different aggregate*, so a complete "dead" predicate would have to join
     * `identity_user` and encode two aggregates' cross-boundary rules in one statement. `expiresAt` is
     * the one column both tables define identically, both aggregates derive identically and
     * un-overridably inside `issue()` (I-15), and which is a **ceiling** on every other reason a row
     * could be finished: an invalidated row expires, a stale row expires, a redeemed row expires. See
     * ADR-0012 decision 1 and the technical plan §*Decision 1*.
     *
     * Measured **before** the sweep on purpose: a backlog counted afterwards is always ~0 and would
     * therefore prove nothing about whether the job is running — which is the whole observability
     * claim of the slice.
     *
     * **Nothing is hydrated**: this counts rows, it does not build aggregates.
     */
    public function countExpiredBefore(\DateTimeImmutable $threshold): int;

    /**
     * Deletes at most `$limit` reset requests whose `expiresAt` is **strictly before** `$threshold`,
     * and returns the number of rows **actually** removed.
     *
     * `$threshold` is the caller's, never this method's — see `countExpiredBefore()` for the rule and
     * `findOutstandingForUser()` for the fuller statement of why a repository must not reach for a
     * clock. The same strict `<` applies, and it is **contract rather than implementation**, for the
     * whole-second reason given there.
     *
     * **The predicate is `expiresAt` and nothing else**, and in this file that is the sentence most
     * likely to be argued with, because this aggregate has *two* terminal columns and a staleness rule
     * living on `User`. The answer is `countExpiredBefore()`'s in full: the two challenge aggregates
     * disagree about "dead" deliberately, `expiresAt` is the single thing they agree about and a
     * ceiling on every other reason a row is finished, and putting "dead" into SQL would smuggle back
     * the shared abstraction ADR-0011 decision 9 rejected — into the one layer where no unit test can
     * see it (ADR-0012 decision 1). A redeemed or invalidated row inside its window is kept **on
     * purpose**: it is what still answers *"this link was already used"* rather than *"no such link"*
     * during an incident review (AC-6).
     *
     * **The return value is rows actually deleted, not rows requested**, so the caller's loop can stop
     * on a short batch — fewer than `$limit` means the table is drained — and so the run's reported
     * counters are measured rather than assumed. Returning `$limit` or `void` would leave the handler
     * guessing about the one number an operator will actually read.
     *
     * **`$limit` is mandatory, with no default, deliberately.** An optional limit is an unbounded
     * `DELETE` waiting for the first caller who omits it — and on this table the rows are personal
     * data, so an unbounded delete is not merely a long transaction.
     *
     * **NOTHING IS HYDRATED, AND THAT IS A DESIGN GUARANTEE RATHER THAN A SPEED TRICK.** No
     * `PasswordResetRequest` is constructed and no `HashedResetToken` is rebuilt, so a row whose
     * `token_hash` cannot be turned back into a valid value object is **deleted** rather than fatal.
     * Load-and-delete would throw *inside hydration* on that one row, kill the run, and go on killing
     * every subsequent run while the backlog grew and nothing named the offender — silently, forever
     * (AC-17).
     *
     * WHY A SET-BASED DELETE IS LEGITIMATE HERE WHEN A BULK `UPDATE` WAS REFUSED THERE. `invalidate()`
     * is reached one aggregate at a time precisely because it is a **state transition**: it produces a
     * state the object must still be valid in, it has I-17 to protect, and a bulk `UPDATE` would set
     * `invalidated_at` without the aggregate ever agreeing. Deletion is **not** a state transition —
     * it is the aggregate ceasing to exist, and there is no post-condition, no invariant a
     * non-existent object can violate and no method it could refuse, so a set-based `DELETE` bypasses
     * **nothing**. The rule generalises rather than breaks: **put in the Domain the part that can be
     * wrong.** Here that part is the **selection**, and the selection is exactly what stayed in the
     * Domain — the retention constant and the pure static that derives `$threshold`.
     *
     * **Deliberately absent, so the gaps read as decisions:** no `deleteRedeemed()` (the "dead"
     * predicate again, and it would delete precisely the rows the window exists to keep), no
     * `findExpiredBefore()` returning objects (hydration back through the front door), and no
     * `deleteForUser()`. The last one is the future GDPR-erasure slice's method and must be added by
     * the slice that has a caller, the ordering rule — **challenge rows before the `identity_user`
     * row** — and the carve-out that retention windows do not apply to an erasure request (ADR-0012
     * decision 6). It is not a convenience for a housekeeping job to acquire in passing.
     *
     * @param int $limit the maximum number of rows to delete in this call; must be positive
     */
    public function deleteExpiredBefore(\DateTimeImmutable $threshold, int $limit): int;
}
