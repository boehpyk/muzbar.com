<?php

declare(strict_types=1);

namespace App\Tests\Fixture;

use App\Domain\Identity\Entity\EmailVerificationRequest;
use App\Domain\Identity\Port\EmailVerificationRequestRepository;
use App\Domain\Identity\ValueObject\EmailVerificationRequestId;
use App\Domain\Identity\ValueObject\HashedVerificationToken;
use App\Domain\Identity\ValueObject\UserId;

/**
 * An in-memory `EmailVerificationRequestRepository` holding nothing but a count of overdue rows —
 * enough to drive `PruneExpiredChallengesHandler`'s batching loop, and nothing more.
 *
 * WHY A FAKE EXISTS AT ALL, WHEN THIS REPOSITORY'S RULE IS TO TEST AGAINST A REAL DATABASE. Because
 * of arithmetic. `MAX_BATCHES_PER_TABLE` is 50 and `BATCH_SIZE` is 1000, so honestly driving a run
 * into the batch cap means inserting **fifty thousand rows**, and the resumability assertion needs it
 * twice. That is minutes of test time spent to observe a loop counter. Every claim in this slice that
 * is genuinely *about* SQL — the strict boundary, the corrupt row, the stored `expires_at`, what
 * actually survives — is asserted against the real `muzbar_test` database in
 * `DoctrineEmailVerificationRequestRepositoryTest`, where it belongs. What is left for this double is
 * a claim about **control flow in the Application layer**, and for that the database is not evidence,
 * it is scenery.
 *
 * That the substitution is possible at all is the design paying out. `PruneExpiredChallengesHandler`
 * takes three ports and touches nothing else: no Redis, no probe, no console, no container. The run
 * lock, the heartbeat and the orphan probe were pushed into `PruneChallengesCommand` precisely so the
 * use case would stay a pure function of its ports — and a pure function is a thing you can hand two
 * fakes and a frozen clock.
 *
 * WHY THIS IS A SECOND FILE RATHER THAN ONE DOUBLE IMPLEMENTING BOTH PORTS, AND THE ANSWER IS BETTER
 * THAN THE QUESTION. The obvious economy is one `CountingChallengeRepository implements
 * EmailVerificationRequestRepository, PasswordResetRequestRepository`, since the sweep calls only two
 * methods and they are identically shaped on both ports. **PHP refuses to compile it.** Return types
 * are covariant, so `nextIdentity()` would have to return `EmailVerificationRequestId` to satisfy one
 * interface and `PasswordResetRequestId` to satisfy the other, and a union of the two is *wider* than
 * either — a fatal error, not a warning.
 *
 * That is worth more than the file it costs. AC-32 forbids a type spanning the two challenge
 * aggregates because such a type would have to guess at rules the aggregates deliberately invert, and
 * any guess would be right for one and a latent security bug in the other. Here the type system
 * reaches the same conclusion on its own: **the two ports cannot be collapsed into one even by a test
 * double that models none of their rules.** Declaring the port methods separately, on two ports named
 * for their own aggregate, is what makes that true — and it is exactly the property decision 2
 * wanted, showing up in the one place nobody designed for it.
 *
 * **Only what the sweep calls is implemented.** Every other port method throws rather than returning
 * a polite empty value — the rule the anonymous doubles in the handler tests already follow. A double
 * that quietly answers a question nobody should ask it is a double that cannot fail, and the failure
 * it would hide here is a handler reaching for a query the use case has no business making.
 */
final class CountingVerificationRequestRepository implements EmailVerificationRequestRepository
{
    /**
     * How many `deleteExpiredBefore()` calls the handler has made.
     *
     * The reason this double is preferable to counting rows: the real adapter would report the same
     * final row count whether the loop stopped on a short batch or paid for one extra round trip to
     * see a zero. Only the call count tells those two apart.
     */
    public int $deleteCalls = 0;

    /**
     * The largest `$limit` the handler ever asked for.
     *
     * Pins AC-19's actual requirement: `--limit` must be applied by *shrinking the batch*, so the
     * port is never asked for more rows than the cap allows. A handler that requested 1000 and then
     * discarded the surplus would produce identical row counts while deleting rows it had been told
     * not to.
     */
    public int $largestLimitRequested = 0;

    public function __construct(
        private int $overdue,
    ) {
    }

    public function countExpiredBefore(\DateTimeImmutable $threshold): int
    {
        return $this->overdue;
    }

    /**
     * Note what is deliberately ignored: `$threshold`. This double holds no rows and therefore no
     * timestamps, so it cannot and must not re-derive which of them are overdue — that judgement is
     * the real adapter's, asserted against the real database. Ignoring the threshold is what keeps
     * this double a statement about *control flow* and stops it quietly becoming a second, untested
     * implementation of the retention policy.
     */
    public function deleteExpiredBefore(\DateTimeImmutable $threshold, int $limit): int
    {
        ++$this->deleteCalls;
        $this->largestLimitRequested = max($this->largestLimitRequested, $limit);

        $deleted = min($limit, $this->overdue);
        $this->overdue -= $deleted;

        return $deleted;
    }

    public function nextIdentity(): EmailVerificationRequestId
    {
        throw new \LogicException('A sweep mints no identities.');
    }

    public function save(EmailVerificationRequest $request): void
    {
        throw new \LogicException('A sweep saves nothing; it only deletes.');
    }

    public function findByTokenHash(HashedVerificationToken $hash): ?EmailVerificationRequest
    {
        throw new \LogicException('A sweep never looks a challenge up by its digest — nothing is hydrated.');
    }

    public function countIssuedForUserSince(UserId $userId, \DateTimeImmutable $since): int
    {
        throw new \LogicException('The anti-abuse count belongs to the issuing use case, not to the sweep.');
    }
}
