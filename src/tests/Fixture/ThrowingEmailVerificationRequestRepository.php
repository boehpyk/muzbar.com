<?php

declare(strict_types=1);

namespace App\Tests\Fixture;

use App\Domain\Identity\Entity\EmailVerificationRequest;
use App\Domain\Identity\Port\EmailVerificationRequestRepository;
use App\Domain\Identity\ValueObject\EmailVerificationRequestId;
use App\Domain\Identity\ValueObject\HashedVerificationToken;
use App\Domain\Identity\ValueObject\UserId;

/**
 * A test double for `EmailVerificationRequestRepository` that throws on every call, standing in for
 * Postgres being unreachable.
 *
 * Used by `PruneChallengesCommandTest` (AC-38) to prove the command's failure contract: a genuine
 * infrastructure failure inside `PruneExpiredChallengesHandler` propagates untouched, is logged at
 * **error** by `PruneChallengesCommand`, deletes nothing, and exits **1** — rather than being caught
 * somewhere in between and downgraded to a quiet, successful-looking run.
 *
 * **INJECTED INTO A HAND-BUILT `PruneExpiredChallengesHandler`, NOT SWAPPED INTO THE CONTAINER — and
 * the container route is not merely unused here, it is unavailable.** The obvious move would be
 * `self::getContainer()->set(DoctrineEmailVerificationRequestRepository::class, ...)`, which is the
 * concrete adapter's id rather than the port alias and so satisfies CLAUDE.md's rule about
 * `ResolveReferencesToAliasesPass`. It still fails: `PruneChallengesCommandTest::setUp()` has already
 * resolved that service, and `TestContainer` refuses to replace an initialized one. The call site
 * spells this out where a reader meets it; it is repeated here so that anyone who finds this double
 * first does not follow an instruction into an `InvalidArgumentException` and conclude the double is
 * broken. If you need it from a class whose `setUp()` does *not* touch that service, the container
 * swap is legitimate again — with `disableReboot()` first, as always.
 *
 * `countExpiredBefore()` is the very first port call `PruneExpiredChallengesHandler::sweep()` makes
 * for this table — before a single row is touched and before the *other* table's sweep starts — so
 * throwing there is enough to prove nothing downstream ran. Every other method throws too, so a
 * future change that reordered the handler's calls would still be caught rather than silently
 * reaching a method this double happened to leave safe.
 */
final class ThrowingEmailVerificationRequestRepository implements EmailVerificationRequestRepository
{
    public function nextIdentity(): EmailVerificationRequestId
    {
        throw new \RuntimeException('Simulated Postgres failure (test double).');
    }

    public function save(EmailVerificationRequest $request): void
    {
        throw new \RuntimeException('Simulated Postgres failure (test double).');
    }

    public function findByTokenHash(HashedVerificationToken $hash): ?EmailVerificationRequest
    {
        throw new \RuntimeException('Simulated Postgres failure (test double).');
    }

    public function countIssuedForUserSince(UserId $userId, \DateTimeImmutable $since): int
    {
        throw new \RuntimeException('Simulated Postgres failure (test double).');
    }

    public function countExpiredBefore(\DateTimeImmutable $threshold): int
    {
        throw new \RuntimeException('Simulated Postgres failure (test double).');
    }

    public function deleteExpiredBefore(\DateTimeImmutable $threshold, int $limit): int
    {
        throw new \RuntimeException('Simulated Postgres failure (test double).');
    }
}
