<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Domain\Identity\Port\UserRepository;
use App\Domain\Identity\ValueObject\Email;
use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Functional/integration tests for `muzbar:identity:verify-email` against the real
 * `muzbar_test` database (DAMA rollback).
 */
final class VerifyEmailCommandTest extends KernelTestCase
{
    private UserRepository $users;
    private CommandTester $command;

    protected function setUp(): void
    {
        self::bootKernel();

        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $this->users = $users;

        $application = new Application($this->kernel());
        $command = $application->find('muzbar:identity:verify-email');
        $this->command = new CommandTester($command);
    }

    private function kernel(): KernelInterface
    {
        if (!self::$kernel instanceof KernelInterface) {
            self::fail('Expected the kernel to be booted.');
        }

        return self::$kernel;
    }

    /**
     * AC-25: verifying an unverified user exits 0 and sets `email_verified_at`.
     */
    public function testVerifyingAnUnverifiedUserExitsSuccessfullyAndSetsTheTimestamp(): void
    {
        UserFactory::createOne(['email' => Email::fromString('console-verify@example.com')]);

        $exitCode = $this->command->execute(['email' => 'console-verify@example.com']);

        self::assertSame(0, $exitCode);
        $found = $this->users->findByEmail(Email::fromString('console-verify@example.com'));
        self::assertNotNull($found);
        self::assertTrue($found->isEmailVerified());
        self::assertTrue($found->isUsable());
    }

    /**
     * AC-25: a second run is idempotent — exit 0, message contains "already verified", and the
     * stored timestamp is unchanged.
     */
    public function testRunningItASecondTimeIsIdempotentAndLeavesTheTimestampUnchanged(): void
    {
        UserFactory::createOne(['email' => Email::fromString('console-idempotent@example.com')]);

        $this->command->execute(['email' => 'console-idempotent@example.com']);
        $firstTimestamp = $this->users->findByEmail(Email::fromString('console-idempotent@example.com'))?->emailVerifiedAt();
        self::assertNotNull($firstTimestamp);

        // A fresh CommandTester, since PHPUnit re-executing the same one twice re-triggers the
        // same command instance rather than a new one — reconstructing mirrors two separate
        // operator invocations more faithfully.
        $application = new Application($this->kernel());
        $command = $application->find('muzbar:identity:verify-email');
        $secondRun = new CommandTester($command);

        $exitCode = $secondRun->execute(['email' => 'console-idempotent@example.com']);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('already verified', $secondRun->getDisplay());

        $found = $this->users->findByEmail(Email::fromString('console-idempotent@example.com'));
        self::assertNotNull($found);
        self::assertEquals($firstTimestamp, $found->emailVerifiedAt());
    }

    /**
     * AC-26: an unknown email exits non-zero, with a clear message, and mutates nothing (there is
     * nothing to check for mutation beyond "no user now exists with this email", since none did
     * before either).
     */
    public function testUnknownEmailExitsNonZeroAndMutatesNothing(): void
    {
        $exitCode = $this->command->execute(['email' => 'never-registered-console@example.com']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('No user found with email', $this->command->getDisplay());
        self::assertNull($this->users->findByEmail(Email::fromString('never-registered-console@example.com')));
    }
}
