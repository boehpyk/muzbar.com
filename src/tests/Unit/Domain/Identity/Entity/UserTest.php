<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Identity\Entity;

use App\Domain\Identity\Entity\User;
use App\Domain\Identity\Event\UserEmailVerified;
use App\Domain\Identity\Event\UserPasswordChanged;
use App\Domain\Identity\Event\UserRegistered;
use App\Domain\Identity\Exception\CannotRevokeBaseRole;
use App\Domain\Identity\ValueObject\Email;
use App\Domain\Identity\ValueObject\HashedPassword;
use App\Domain\Identity\ValueObject\Role;
use App\Domain\Identity\ValueObject\UserId;
use PHPUnit\Framework\TestCase;

/**
 * Pure Domain unit tests for the `User` aggregate — no kernel boot, no persistence. Every
 * timestamp is supplied explicitly, never read from the wall clock, which is what makes these
 * assertions exact rather than "roughly now".
 */
final class UserTest extends TestCase
{
    private const string EMAIL = 'max@example.com';

    public function testRegisterSeedsExactlyTheBaseUserRole(): void
    {
        $user = $this->registerUser();

        self::assertSame([Role::User], $user->roles());
    }

    public function testRegisterLeavesEmailVerifiedAtNull(): void
    {
        $user = $this->registerUser();

        self::assertNull($user->emailVerifiedAt());
        self::assertFalse($user->isEmailVerified());
    }

    public function testRegisterSetsTheIdEmailAndRegisteredAtFromTheGivenArguments(): void
    {
        $id = UserId::fromString('018f5b2a-0000-7000-8000-000000000000');
        $email = Email::fromString(self::EMAIL);
        $registeredAt = new \DateTimeImmutable('2026-07-25T10:00:00+00:00');

        $user = User::register($id, $email, $this->aHash(), $registeredAt);

        self::assertTrue($user->id()->equals($id));
        self::assertTrue($user->email()->equals($email));
        self::assertSame($registeredAt, $user->registeredAt());
    }

    /**
     * AC-27: a successful registration dispatches exactly one `UserRegistered`, carrying the
     * new `UserId` and `Email`.
     */
    public function testRegisterRecordsExactlyOneUserRegisteredEventCarryingIdAndEmail(): void
    {
        $id = UserId::fromString('018f5b2a-0000-7000-8000-000000000000');
        $email = Email::fromString(self::EMAIL);
        $registeredAt = new \DateTimeImmutable('2026-07-25T10:00:00+00:00');

        $user = User::register($id, $email, $this->aHash(), $registeredAt);
        $events = $user->releaseEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(UserRegistered::class, $events[0]);
        self::assertTrue($events[0]->userId()->equals($id));
        self::assertTrue($events[0]->email()->equals($email));
        self::assertEquals($registeredAt, $events[0]->occurredAt());
    }

    public function testVerifyEmailSetsTheTimestampAndMarksTheEmailVerified(): void
    {
        $user = $this->registerUser();
        $verifiedAt = new \DateTimeImmutable('2026-07-26T09:30:00+00:00');

        $user->verifyEmail($verifiedAt);

        self::assertTrue($user->isEmailVerified());
        self::assertEquals($verifiedAt, $user->emailVerifiedAt());
    }

    /**
     * AC-27: a successful verification dispatches exactly one `UserEmailVerified`.
     */
    public function testVerifyEmailRecordsExactlyOneUserEmailVerifiedEvent(): void
    {
        $id = UserId::fromString('018f5b2a-0000-7000-8000-000000000000');
        $user = User::register($id, Email::fromString(self::EMAIL), $this->aHash(), new \DateTimeImmutable());
        $user->releaseEvents(); // discard the UserRegistered event so this test isolates verifyEmail()

        $verifiedAt = new \DateTimeImmutable('2026-07-26T09:30:00+00:00');
        $user->verifyEmail($verifiedAt);
        $events = $user->releaseEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(UserEmailVerified::class, $events[0]);
        self::assertTrue($events[0]->userId()->equals($id));
        self::assertEquals($verifiedAt, $events[0]->occurredAt());
    }

    /**
     * Invariant I-4: `emailVerifiedAt` moves `null -> timestamp` exactly once and never back. A
     * second call is a no-op: no event, and the original timestamp survives untouched even if a
     * caller (wrongly) passes a different instant.
     */
    public function testASecondVerifyEmailCallIsANoOpRecordingNoEventAndLeavingTheTimestampUnchanged(): void
    {
        $user = $this->registerUser();
        $firstVerifiedAt = new \DateTimeImmutable('2026-07-26T09:30:00+00:00');
        $user->verifyEmail($firstVerifiedAt);
        $user->releaseEvents();

        $user->verifyEmail(new \DateTimeImmutable('2026-08-01T00:00:00+00:00'));
        $events = $user->releaseEvents();

        self::assertSame([], $events);
        self::assertEquals($firstVerifiedAt, $user->emailVerifiedAt());
    }

    public function testChangePasswordReplacesTheHashAndSetsPasswordChangedAt(): void
    {
        $user = $this->registerUser();
        $newHash = HashedPassword::fromString('a-different-opaque-hash');
        $changedAt = new \DateTimeImmutable('2026-07-28T09:00:00+00:00');

        $user->changePassword($newHash, $changedAt);

        self::assertTrue($user->passwordHash()->equals($newHash));
        self::assertEquals($changedAt, $user->passwordChangedAt());
    }

    /**
     * AC-33: a successful `changePassword()` dispatches exactly one `UserPasswordChanged`, carrying
     * a `UserId` and an instant — and, per the event's own docblock, no hash and certainly no
     * plaintext. Asserted by exhaustively reading the event's declared properties rather than only
     * checking the two fields we expect, since the thing worth catching is a *future* property
     * quietly reintroducing the secret.
     */
    public function testChangePasswordRecordsExactlyOneUserPasswordChangedEventCarryingNoSecret(): void
    {
        $id = UserId::fromString('018f5b2a-0000-7000-8000-000000000000');
        $user = User::register($id, Email::fromString(self::EMAIL), $this->aHash(), new \DateTimeImmutable('2026-07-25T10:00:00+00:00'));
        $user->releaseEvents(); // discard the UserRegistered event so this test isolates changePassword()

        $changedAt = new \DateTimeImmutable('2026-07-28T09:00:00+00:00');
        $user->changePassword(HashedPassword::fromString('a-different-opaque-hash'), $changedAt);
        $events = $user->releaseEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(UserPasswordChanged::class, $events[0]);
        self::assertTrue($events[0]->userId()->equals($id));
        self::assertEquals($changedAt, $events[0]->occurredAt());

        $properties = (new \ReflectionClass(UserPasswordChanged::class))->getProperties();
        self::assertCount(2, $properties);

        $propertyTypeNames = array_map(
            static fn (\ReflectionProperty $p): ?string => $p->getType() instanceof \ReflectionNamedType ? $p->getType()->getName() : null,
            $properties,
        );

        self::assertNotContains(HashedPassword::class, $propertyTypeNames);

        // No accessor returns a HashedPassword either — the property check above rules out a
        // field, this rules out a computed reader that only wraps one.
        foreach ((new \ReflectionClass(UserPasswordChanged::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $returnType = $method->getReturnType();
            $returnTypeName = $returnType instanceof \ReflectionNamedType ? $returnType->getName() : null;

            self::assertNotSame(HashedPassword::class, $returnTypeName);
        }
    }

    /**
     * `changePassword()` is deliberately **not** idempotent (the aggregate's own docblock, point 3):
     * unlike `verifyEmail()`, a repeat is the dangerous case, and it is a new fact each time rather
     * than a re-announcement of an old one. Two calls therefore record two events, not one.
     */
    public function testChangePasswordTwiceRecordsTwoEvents(): void
    {
        $user = $this->registerUser();
        $user->releaseEvents();

        $user->changePassword(HashedPassword::fromString('first-new-hash'), new \DateTimeImmutable('2026-07-28T09:00:00+00:00'));
        $user->changePassword(HashedPassword::fromString('second-new-hash'), new \DateTimeImmutable('2026-07-28T09:05:00+00:00'));
        $events = $user->releaseEvents();

        self::assertCount(2, $events);
        self::assertInstanceOf(UserPasswordChanged::class, $events[0]);
        self::assertInstanceOf(UserPasswordChanged::class, $events[1]);
    }

    /**
     * AC-31: `changePassword()` deliberately does not verify the email — that coupling belongs to
     * `ResetPasswordWithTokenHandler`, not to the aggregate (the aggregate's own docblock, point 1).
     * This is the assertion that would catch someone "tidying" the `verifyEmail()` call out of the
     * handler and into `changePassword()`: an unverified account must still be unverified after a
     * password change, because only the handler knows whether the channel that produced this call
     * actually proved control of the mailbox.
     */
    public function testChangePasswordDoesNotVerifyTheEmail(): void
    {
        $user = $this->registerUser();

        self::assertFalse($user->isEmailVerified());

        $user->changePassword(HashedPassword::fromString('a-different-opaque-hash'), new \DateTimeImmutable('2026-07-28T09:00:00+00:00'));

        self::assertFalse($user->isEmailVerified());
        self::assertNull($user->emailVerifiedAt());
    }

    /**
     * AC-30: `verifyEmail()` remains idempotent even after a password change has happened in
     * between — a call on a still-unverified account records exactly one `UserEmailVerified`.
     */
    public function testVerifyEmailAfterChangePasswordStillRecordsExactlyOneEventOnAnUnverifiedUser(): void
    {
        $id = UserId::fromString('018f5b2a-0000-7000-8000-000000000000');
        $user = User::register($id, Email::fromString(self::EMAIL), $this->aHash(), new \DateTimeImmutable('2026-07-25T10:00:00+00:00'));
        $user->changePassword(HashedPassword::fromString('a-different-opaque-hash'), new \DateTimeImmutable('2026-07-28T09:00:00+00:00'));
        $user->releaseEvents(); // discard UserRegistered + UserPasswordChanged

        $verifiedAt = new \DateTimeImmutable('2026-07-28T09:05:00+00:00');
        $user->verifyEmail($verifiedAt);
        $events = $user->releaseEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(UserEmailVerified::class, $events[0]);
        self::assertTrue($user->isEmailVerified());
    }

    /**
     * AC-30, the other half: `verifyEmail()` after a password change on an **already-verified**
     * account still records nothing — the password change has no bearing on the idempotency
     * `verifyEmail()` already guarantees on its own.
     */
    public function testVerifyEmailAfterChangePasswordRecordsNothingOnAnAlreadyVerifiedUser(): void
    {
        $user = $this->registerUser();
        $user->verifyEmail(new \DateTimeImmutable('2026-07-26T09:30:00+00:00'));
        $user->changePassword(HashedPassword::fromString('a-different-opaque-hash'), new \DateTimeImmutable('2026-07-28T09:00:00+00:00'));
        $user->releaseEvents();

        $user->verifyEmail(new \DateTimeImmutable('2026-07-28T09:05:00+00:00'));
        $events = $user->releaseEvents();

        self::assertSame([], $events);
    }

    /**
     * AC-24: `isUsable()` is the ADR-0005 invariant expressed in code — false for an unverified
     * account, true the moment verification happens. Enforcement at login is deliberately
     * deferred (that lives in Infrastructure/security.yaml), but the Domain's own opinion must be
     * exact.
     */
    public function testIsUsableIsFalseBeforeVerificationAndTrueAfter(): void
    {
        $user = $this->registerUser();

        self::assertFalse($user->isUsable());

        $user->verifyEmail(new \DateTimeImmutable());

        self::assertTrue($user->isUsable());
    }

    /**
     * Invariant I-3: the base role is the floor of every account and cannot be revoked, because
     * an account holding no role at all is not a lesser account — it is a broken one.
     */
    public function testRevokingTheBaseUserRoleThrows(): void
    {
        $user = $this->registerUser();

        $this->expectException(CannotRevokeBaseRole::class);

        $user->revokeRole(Role::User);
    }

    public function testRevokingTheBaseRoleLeavesTheRoleListUntouched(): void
    {
        $user = $this->registerUser();

        try {
            $user->revokeRole(Role::User);
        } catch (CannotRevokeBaseRole) {
            // expected — the assertion of interest is below
        }

        self::assertSame([Role::User], $user->roles());
    }

    public function testGrantRoleAddsANewRole(): void
    {
        $user = $this->registerUser();

        $user->grantRole(Role::Business);

        self::assertSame([Role::User, Role::Business], $user->roles());
    }

    /**
     * Invariant I-3: granting an already-held role is a no-op, not a duplicate entry.
     */
    public function testGrantRoleDeduplicatesAnAlreadyHeldRole(): void
    {
        $user = $this->registerUser();
        $user->grantRole(Role::Business);

        $user->grantRole(Role::Business);

        self::assertSame([Role::User, Role::Business], $user->roles());
    }

    /**
     * Revoking a non-base role from the middle of the list must not leave a gapped array key
     * behind — the JSON mapping and the `list<Role>` type both depend on a re-indexed list.
     */
    public function testRevokeRoleLeavesAProperListWithNoKeyGaps(): void
    {
        $user = $this->registerUser();
        $user->grantRole(Role::Business);
        $user->grantRole(Role::Admin);

        $user->revokeRole(Role::Business);

        $roles = $user->roles();
        self::assertSame([Role::User, Role::Admin], $roles);
        self::assertSame([0, 1], array_keys($roles));
    }

    public function testReleaseEventsEmptiesTheBufferSoASecondCallReturnsAnEmptyList(): void
    {
        $user = $this->registerUser();

        $user->releaseEvents();
        $second = $user->releaseEvents();

        self::assertSame([], $second);
    }

    /**
     * AC-29: the aggregate implements no framework interface — the single most important line in
     * the Infrastructure/Security section of the technical plan, asserted here by reflection
     * rather than left to a reviewer noticing a `use Symfony\...\UserInterface;` at a glance.
     */
    public function testImplementsNoInterfaceAtAll(): void
    {
        $reflection = new \ReflectionClass(User::class);

        self::assertSame([], $reflection->getInterfaceNames());
    }

    /**
     * AC-29: no ORM attribute (or any attribute at all) is attached to the aggregate class —
     * Doctrine's metadata lives entirely in `User.orm.xml`, outside the Domain.
     */
    public function testCarriesNoClassLevelAttributes(): void
    {
        $reflection = new \ReflectionClass(User::class);

        self::assertSame([], $reflection->getAttributes());
    }

    private function registerUser(): User
    {
        return User::register(
            UserId::fromString('018f5b2a-0000-7000-8000-000000000000'),
            Email::fromString(self::EMAIL),
            $this->aHash(),
            new \DateTimeImmutable('2026-07-25T10:00:00+00:00'),
        );
    }

    private function aHash(): HashedPassword
    {
        return HashedPassword::fromString('opaque-test-hash-value');
    }
}
