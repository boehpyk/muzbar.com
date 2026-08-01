<?php

declare(strict_types=1);

namespace App\Domain\Identity\Port;

use App\Domain\Identity\Entity\EmailVerificationRequest;
use App\Domain\Identity\ValueObject\EmailVerificationRequestId;
use App\Domain\Identity\ValueObject\HashedVerificationToken;
use App\Domain\Identity\ValueObject\UserId;

/**
 * The collection of EmailVerificationRequests, as the Domain wants to talk about it.
 *
 * One repository per aggregate root — this is the context's second, and its existence is the
 * clearest signal that `EmailVerificationRequest` really is a root rather than a part of `User`.
 * The interface is declared here and implemented in `Infrastructure`: dependency inversion is what
 * lets the Domain state its needs without knowing that Postgres exists.
 *
 * Note what is *absent*: there is no `findByUser()` returning a list, no `findExpiredBefore()`
 * handing back objects, and no way to reach a `User` from here. **A repository should expose the
 * queries the use cases actually make, not the queries a table could answer** — that part of the rule
 * is unchanged and is the reason the list below is as short as it is.
 *
 * The other half of this note used to point at the future: *"the pruning job owed later will add its
 * own method when it is written, with its own justification."* **That debt is now paid.** The
 * `identity-challenge-pruning` slice arrived with a caller and a decided retention policy, and it
 * added exactly two methods — `countExpiredBefore()` and `deleteExpiredBefore()` — declared here,
 * named for *this* aggregate, with the justification in their own docblocks and in ADR-0012. They are
 * deliberately not the `deleteExpired()` that note anticipated: the threshold is supplied by the
 * caller rather than assumed, and the delete is bounded by a mandatory limit. Still absent, and still
 * on purpose: `deleteRedeemed()` and `deleteForUser()` — see `deleteExpiredBefore()`.
 */
interface EmailVerificationRequestRepository
{
    /**
     * Mints the identity for a request that does not exist yet.
     *
     * The repository generates the id — not the database — so the aggregate is complete and valid
     * the moment it is constructed, before it has ever met a transaction. That is what lets
     * `issue()` record an event carrying its own id, and what lets a request be built and discarded
     * on a rate-limit refusal without having touched the database at all.
     *
     * It also keeps UUID generation — a vendor concern (`symfony/uid`) — out of the Domain, without
     * the Domain having to hand-roll UUIDv7 out of `random_bytes`.
     */
    public function nextIdentity(): EmailVerificationRequestId;

    /**
     * Persists the aggregate, new or changed.
     *
     * **This method declares no `@throws`, and the contrast with `UserRepository::save()` is the
     * point.** There, the adapter is obliged to catch a unique-index violation on `email` and
     * translate it into `EmailAlreadyRegistered`, because two people racing to register the same
     * address is an ordinary Tuesday and "that address is taken" is a business answer.
     *
     * Here the only unique index is on `token_hash`, and a collision would mean two independent
     * draws of 256 CSPRNG bits landed on the same value. There is no business answer to that,
     * because it is not a business event; the honest response is a 500 and an alert. Wrapping it in
     * a domain exception would dress an impossibility up as a handled case, and would leave a
     * `catch` block in the handler that no test can ever reach and no reader can ever evaluate. A
     * failure contract should describe things that happen.
     */
    public function save(EmailVerificationRequest $request): void;

    /**
     * Finds the request holding this digest, **whether or not it is redeemed or expired**.
     *
     * The filtering that is *not* here is deliberate. A repository that silently skipped expired or
     * redeemed rows would look tidier at the call site and would be a genuine bug: it would make
     * "this link expired yesterday" and "this token was never issued" produce the same `null`, and
     * the system would lose the ability to tell them apart at the exact moment it most wants to —
     * in a log, in a test, and in the decision whether a redeemed-but-unverified row is a replay
     * (benign, AC-8) or corruption (loud, AC-9).
     *
     * The fact that the *visitor* sees one identical response for both (AC-11) is a presentation
     * policy, chosen to defeat enumeration, and presentation policy is not a reason to throw
     * information away three layers down. Business state is judged by the aggregate — `redeem()`,
     * `isExpiredAt()` — because a repository that judges business state is an aggregate with a SQL
     * accent.
     */
    public function findByTokenHash(HashedVerificationToken $hash): ?EmailVerificationRequest;

    /**
     * How many requests this user has been issued since `$since`.
     *
     * This is the anti-abuse rule I-12 in the only form it can take. "At most five per rolling
     * hour" spans *instances*, so no single aggregate can enforce it — an aggregate only ever sees
     * itself — exactly as email uniqueness (I-6) spans all users. The count lives here and the
     * comparison against `EmailVerificationRequest::MAX_ISSUES_PER_HOUR` lives in the handler.
     *
     * The window is passed in rather than assumed, because "an hour ago" is a value from the `Clock`
     * port and a repository that computed it would be reaching for the wall clock — the one thing
     * the `Clock` port exists to prevent.
     *
     * Unlike a unique index, this cannot be made race-free: two simultaneous submissions can both
     * read five and both write. Accepted knowingly — the worst case is a sixth email, and the
     * per-IP limiter at the HTTP boundary is the second layer.
     */
    public function countIssuedForUserSince(UserId $userId, \DateTimeImmutable $since): int;

    /**
     * How many verification requests have an `expiresAt` **strictly before** `$threshold` — the
     * pruning backlog for this table, measured before anything is deleted.
     *
     * `$threshold` is **passed in and never computed here**, the same rule
     * `countIssuedForUserSince()` states for its window: an instant is a value from the `Clock` port,
     * and a repository that knew what time it was could answer questions whose truth changes between
     * the query and the assertion about it. The caller derives it from
     * `EmailVerificationRequest::retentionThreshold()`, once per run, so both counts and both sweeps
     * of a run describe the same moment.
     *
     * **The strict `<` is contract, not implementation.** Timestamps are stored to whole seconds, so
     * a `<=` here would move the boundary by a second in a way that is invisible to any one-sided
     * test — which is why the boundary is asserted from both sides (AC-4). The two methods on this
     * interface must use the same operator, or the backlog would count a row the sweep would not
     * remove and a healthy table would report a permanent backlog of one.
     *
     * **The predicate is `expiresAt` and nothing else.** Not `redeemedAt`, not the account's
     * `email_verified_at`, no join to `identity_user`, no notion of "dead". This is the improvement a
     * future reader will offer first — *"surely a redeemed row can go early?"* — so the reason is
     * written here rather than left to be re-derived: this aggregate and `PasswordResetRequest`
     * **disagree about what a finished row is, deliberately** (four inverted rules, ADR-0011 decision
     * 9), a predicate built out of "dead" would be the rejected shared base class expressed in SQL
     * where no unit test can reach it, and `expiresAt` is the one column both tables define
     * identically, both aggregates derive identically and un-overridably inside `issue()`, and which
     * is a **ceiling** on every other reason a row could be finished — a redeemed row expires too.
     * See ADR-0012 decision 1 and the technical plan §*Decision 1*.
     *
     * This is the backlog number the observability design leans on, which is why it is a separate
     * method rather than a field of the delete's return: it is measured **before** the sweep, because
     * a count taken afterwards is always ~0 and therefore says nothing about whether the job has been
     * running at all.
     *
     * **Nothing is hydrated.** This counts rows; it does not build aggregates.
     */
    public function countExpiredBefore(\DateTimeImmutable $threshold): int;

    /**
     * Deletes at most `$limit` verification requests whose `expiresAt` is **strictly before**
     * `$threshold`, and returns how many rows were **actually** deleted.
     *
     * `$threshold` is **passed in, never computed here** — `countExpiredBefore()`'s reason, and the
     * same rule `countIssuedForUserSince()` already states. The caller derives it once per run from
     * `EmailVerificationRequest::retentionThreshold()`; a repository that reached for the wall clock
     * would make every assertion about this method a race.
     *
     * **The comparison is the same strict `<`, and that is contract rather than implementation** — see
     * `countExpiredBefore()`. **The predicate is `expiresAt` and nothing else**, for the reason given
     * there in full: the two challenge aggregates disagree about "dead" on purpose, `expiresAt` is the
     * one thing they agree about and a ceiling on every other reason a row is finished, and encoding
     * "dead" in SQL would resurrect the rejected shared abstraction in the one place a unit test
     * cannot see it (ADR-0012 decision 1).
     *
     * **The return value is rows actually deleted, not rows requested.** The caller's batching loop
     * terminates on a short batch — fewer than `$limit` means the table is drained — and the run's
     * reported counters are therefore *measured* rather than assumed. A method that returned
     * `$limit`, or `void`, would force the handler to guess.
     *
     * **`$limit` is mandatory and has no default, deliberately.** An optional limit is an unbounded
     * `DELETE` waiting for the one caller who omits it, and the caller who omits it will be the one
     * running by hand against production at two in the morning.
     *
     * **NOTHING IS HYDRATED, AND THAT IS THE POINT RATHER THAN AN OPTIMISATION.** No
     * `EmailVerificationRequest` is constructed, so no `HashedVerificationToken` is rebuilt, so a row
     * whose `token_hash` is corrupt is simply **deleted** instead of stalling the sweep. Under the
     * load-and-delete alternative that one bad row would throw inside hydration, kill the run, and go
     * on killing every run afterwards while the backlog grew and nothing named the cause — silently,
     * forever (AC-17). That is the sharpest practical argument for the set-based design.
     *
     * WHAT LICENCES A SET-BASED DELETE AT ALL, since ADR-0011 decision 4 refused a bulk `UPDATE` for a
     * neighbouring operation, and this is the lesson of the slice: **an aggregate governs its state
     * transitions, not its own non-existence.** `invalidate()` is a transition — it produces a state
     * the object must still be valid in, it has an invariant to protect, and a bulk `UPDATE` bypasses
     * the guard. Deletion is not a transition: there is no post-condition, no invariant a
     * non-existent object can violate and no method it could refuse, so a set-based `DELETE` bypasses
     * **nothing**. Put in the Domain the part that can be wrong; here that part is the **selection**,
     * and the selection is what stays here — the constant and the static on the aggregate, and the
     * `$threshold` this method is handed.
     *
     * **Deliberately absent, so that the absences read as decisions rather than gaps:** no
     * `deleteRedeemed()` (it would be the "dead" predicate again, and it would delete precisely the
     * rows the retention window exists to keep), no `findExpiredBefore()` returning objects (it would
     * reintroduce hydration and with it the corrupt-row stall), and no `deleteForUser()` — that one
     * belongs to the future GDPR-erasure slice, which must add it with a caller, an ordering rule and
     * a decision behind it (ADR-0012 decision 6), not to a housekeeping job that happens to be in the
     * neighbourhood.
     *
     * @param int $limit the maximum number of rows to delete in this call; must be positive
     */
    public function deleteExpiredBefore(\DateTimeImmutable $threshold, int $limit): int;
}
