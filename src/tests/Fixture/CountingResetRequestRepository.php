<?php

declare(strict_types=1);

namespace App\Tests\Fixture;

use App\Domain\Identity\Entity\PasswordResetRequest;
use App\Domain\Identity\Port\PasswordResetRequestRepository;
use App\Domain\Identity\ValueObject\HashedResetToken;
use App\Domain\Identity\ValueObject\PasswordResetRequestId;
use App\Domain\Identity\ValueObject\UserId;

/**
 * The `PasswordResetRequestRepository` half of the pair — see
 * `CountingVerificationRequestRepository` for the full argument, which is not repeated here because
 * identical reasoning duplicated is reasoning that will eventually disagree with itself.
 *
 * The short version: driving `MAX_BATCHES_PER_TABLE` honestly costs fifty thousand inserts, the claim
 * under test is control flow rather than SQL, and the handler being a pure function of its three
 * ports is what makes the substitution available.
 *
 * **This file exists because PHP made it exist.** One double implementing both ports does not
 * compile: `nextIdentity()` is covariant, so it would have to return `PasswordResetRequestId` here and
 * `EmailVerificationRequestId` there, and a union of the two is wider than either. The type system
 * arrives at AC-32's conclusion unaided — the two ports cannot be collapsed into one even by a double
 * that models none of their rules — which is what declaring the port methods separately, on two ports
 * named for their own aggregate, actually bought.
 *
 * It carries **no** reset-specific behaviour, and that absence is the thing to protect: it knows
 * nothing of `invalidate()`, of replay being refused, or of the thirty-day window. If it ever grows a
 * branch that differs from its twin, the difference is a rule that has leaked into a test double and
 * belongs in the aggregate instead.
 */
final class CountingResetRequestRepository implements PasswordResetRequestRepository
{
    /** How many `deleteExpiredBefore()` calls the handler has made — see the twin's docblock. */
    public int $deleteCalls = 0;

    /** The largest `$limit` the handler ever asked for, pinning AC-19's real requirement. */
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
     * `$threshold` is deliberately ignored: this double holds no rows and therefore no timestamps, so
     * re-deriving which are overdue would make it a second, untested implementation of the retention
     * policy. That judgement belongs to the real adapter and is asserted against the real database.
     */
    public function deleteExpiredBefore(\DateTimeImmutable $threshold, int $limit): int
    {
        ++$this->deleteCalls;
        $this->largestLimitRequested = max($this->largestLimitRequested, $limit);

        $deleted = min($limit, $this->overdue);
        $this->overdue -= $deleted;

        return $deleted;
    }

    public function nextIdentity(): PasswordResetRequestId
    {
        throw new \LogicException('A sweep mints no identities.');
    }

    public function save(PasswordResetRequest $request): void
    {
        throw new \LogicException('A sweep saves nothing; it only deletes.');
    }

    public function findByTokenHash(HashedResetToken $hash): ?PasswordResetRequest
    {
        throw new \LogicException('A sweep never looks a challenge up by its digest — nothing is hydrated.');
    }

    public function countIssuedForUserSince(UserId $userId, \DateTimeImmutable $since): int
    {
        throw new \LogicException('The anti-abuse count belongs to the issuing use case, not to the sweep.');
    }

    /**
     * @return list<PasswordResetRequest>
     */
    public function findOutstandingForUser(UserId $userId): array
    {
        throw new \LogicException('The reissue sweep belongs to RequestPasswordResetHandler, not to pruning.');
    }
}
