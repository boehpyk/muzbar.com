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
 * Note what is *absent*: there is no `findByUser()` returning a list, no `deleteExpired()`, and no
 * way to reach a `User` from here. A repository should expose the queries the use cases actually
 * make, not the queries a table could answer; the pruning job owed later will add its own method
 * when it is written, with its own justification.
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
}
