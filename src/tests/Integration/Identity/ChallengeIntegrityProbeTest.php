<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Domain\Identity\Entity\EmailVerificationRequest;
use App\Domain\Identity\Entity\PasswordResetRequest;
use App\Domain\Identity\Port\EmailVerificationRequestRepository;
use App\Domain\Identity\Port\PasswordResetRequestRepository;
use App\Domain\Identity\ValueObject\HashedResetToken;
use App\Domain\Identity\ValueObject\HashedVerificationToken;
use App\Domain\Identity\ValueObject\UserId;
use App\Infrastructure\Identity\Persistence\Doctrine\ChallengeIntegrityProbe;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * `ChallengeIntegrityProbe` against the real `muzbar_test` database — the ADR-0009 decision 4 answer,
 * finally exercised.
 *
 * Orphan rows exist here **by construction, not by accident**: the mapping deliberately holds no
 * foreign key between a challenge table and `identity_user` (referential integrity between aggregate
 * roots is the application's job), so a challenge row referencing a `user_id` with no matching user
 * is a row Postgres will happily accept. In production there are currently zero, because nothing
 * deletes a `User`. That is exactly why this probe has to be tested against rows a test creates on
 * purpose — the production expectation is a permanent zero, and a permanent zero is indistinguishable
 * from a probe that does not work.
 *
 * The two tests below pin the two halves of the decision that are easiest to lose later: that the
 * probe **counts and never deletes**, and that **orphan-ness is not a prune criterion**.
 */
final class ChallengeIntegrityProbeTest extends KernelTestCase
{
    private ChallengeIntegrityProbe $probe;
    private EmailVerificationRequestRepository $verificationRequests;
    private PasswordResetRequestRepository $resetRequests;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        $probe = self::getContainer()->get(ChallengeIntegrityProbe::class);
        self::assertInstanceOf(ChallengeIntegrityProbe::class, $probe);
        $this->probe = $probe;

        $verificationRequests = self::getContainer()->get(EmailVerificationRequestRepository::class);
        self::assertInstanceOf(EmailVerificationRequestRepository::class, $verificationRequests);
        $this->verificationRequests = $verificationRequests;

        $resetRequests = self::getContainer()->get(PasswordResetRequestRepository::class);
        self::assertInstanceOf(PasswordResetRequestRepository::class, $resetRequests);
        $this->resetRequests = $resetRequests;

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
    }

    /**
     * AC-25: an orphan is counted, **and is still there afterwards**.
     *
     * The second assertion is the one that matters and it is the one a hurried test would omit. A
     * probe that counted correctly and quietly deleted what it counted would pass a count-only test
     * on every run. The docblock on `ChallengeIntegrityProbe` says three times that it never deletes;
     * this is the assertion that makes the claim checkable rather than merely repeated.
     *
     * The non-orphan is built from a **real** `UserFactory` user so the test distinguishes "counts
     * orphans" from "counts rows" — without it, a probe with a broken `NOT EXISTS` that simply
     * counted the whole table would pass.
     */
    public function testAnOrphanIsCountedOnBothTablesAndIsStillPresentAfterwards(): void
    {
        $realUser = UserFactory::createOne();
        $realUserId = UserId::fromString($realUser->id()->toString());

        $this->persistVerificationFor($realUserId, new \DateTimeImmutable('now'));
        $this->persistResetFor($realUserId, new \DateTimeImmutable('now'));

        $orphanVerification = $this->persistVerificationFor($this->anAbsentUserId(), new \DateTimeImmutable('now'));
        $orphanReset = $this->persistResetFor($this->anAbsentUserId(), new \DateTimeImmutable('now'));

        self::assertSame(1, $this->probe->countOrphanedEmailVerificationRequests());
        self::assertSame(1, $this->probe->countOrphanedPasswordResetRequests());

        // THE ASSERTION THE WHOLE TEST EXISTS FOR: counting did not remove anything.
        self::assertTrue($this->rowExists('identity_email_verification_request', $orphanVerification));
        self::assertTrue($this->rowExists('identity_password_reset_request', $orphanReset));

        // Counting twice returns the same answer, which a probe that deleted as it counted could not
        // manage.
        self::assertSame(1, $this->probe->countOrphanedEmailVerificationRequests());
        self::assertSame(1, $this->probe->countOrphanedPasswordResetRequests());
    }

    /**
     * AC-26: an **overdue** orphan is removed by the ordinary sweep, with no special handling and no
     * reference whatsoever to its orphan status.
     *
     * This is the positive statement of the same rule: orphan-ness is a thing to *report*, never a
     * thing to select on. The row below is taken because its `expires_at` is past the retention
     * threshold — the identical reason any other row is taken — and the fact that its user is missing
     * plays no part. Orphan-ness is a state that resolves itself inside the window.
     *
     * The pairing with the previous test is what makes both meaningful: together they say the probe
     * never deletes and the sweep never discriminates, which is the complete answer ADR-0009 decision
     * 4 was owed.
     */
    public function testAnOverdueOrphanIsRemovedByTheOrdinarySweepWithNoSpecialHandling(): void
    {
        $now = new \DateTimeImmutable('2026-08-01T12:00:00+00:00');

        $overdueOrphan = $this->persistVerificationFor(
            $this->anAbsentUserId(),
            // Overdue: expires_at lands a day before the seven-day threshold.
            $now->modify(\sprintf('-%d seconds', EmailVerificationRequest::LIFETIME_SECONDS + EmailVerificationRequest::RETENTION_AFTER_EXPIRY_SECONDS + 86400)),
        );

        self::assertSame(1, $this->probe->countOrphanedEmailVerificationRequests());

        $deleted = $this->verificationRequests->deleteExpiredBefore(
            EmailVerificationRequest::retentionThreshold($now),
            100,
        );

        self::assertSame(1, $deleted);
        self::assertFalse($this->rowExists('identity_email_verification_request', $overdueOrphan));
        self::assertSame(0, $this->probe->countOrphanedEmailVerificationRequests(), 'The orphan resolved itself by expiring, not by being an orphan.');
    }

    /**
     * A `UserId` that is syntactically valid and matches no `identity_user` row.
     *
     * Legitimate precisely because the mapping holds no foreign key — the whole point of ADR-0009
     * decision 4, and the reason both Foundry factories already default to one of these.
     */
    private function anAbsentUserId(): UserId
    {
        return UserId::fromString(Uuid::v7()->toRfc4122());
    }

    private function persistVerificationFor(UserId $userId, \DateTimeImmutable $issuedAt): string
    {
        $id = $this->verificationRequests->nextIdentity();
        $request = EmailVerificationRequest::issue(
            $id,
            $userId,
            HashedVerificationToken::fromString(hash('sha256', random_bytes(32))),
            $issuedAt,
        );
        $request->releaseEvents();
        $this->verificationRequests->save($request);

        return $id->toString();
    }

    private function persistResetFor(UserId $userId, \DateTimeImmutable $issuedAt): string
    {
        $id = $this->resetRequests->nextIdentity();
        $request = PasswordResetRequest::issue(
            $id,
            $userId,
            HashedResetToken::fromString(hash('sha256', random_bytes(32))),
            $issuedAt,
        );
        $request->releaseEvents();
        $this->resetRequests->save($request);

        return $id->toString();
    }

    /**
     * Read straight from Postgres. The sweep deletes through native SQL and never notifies the
     * identity map, so an ORM read could return a cached object for a row that is gone.
     */
    private function rowExists(string $table, string $id): bool
    {
        return (bool) $this->entityManager->getConnection()->fetchOne(
            \sprintf('SELECT COUNT(*) FROM %s WHERE id = :id', $table),
            ['id' => $id],
        );
    }
}
