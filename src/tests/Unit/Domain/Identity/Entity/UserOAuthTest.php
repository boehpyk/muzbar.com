<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Identity\Entity;

use App\Domain\Identity\Entity\User;
use App\Domain\Identity\Event\UserEmailVerified;
use App\Domain\Identity\Event\UserOAuthIdentityLinked;
use App\Domain\Identity\Event\UserPasswordChanged;
use App\Domain\Identity\Event\UserRegistered;
use App\Domain\Identity\Exception\OAuthProviderAlreadyLinked;
use App\Domain\Identity\ValueObject\Email;
use App\Domain\Identity\ValueObject\HashedPassword;
use App\Domain\Identity\ValueObject\OAuthIdentity;
use App\Domain\Identity\ValueObject\OAuthProvider;
use App\Domain\Identity\ValueObject\Role;
use App\Domain\Identity\ValueObject\UserId;
use PHPUnit\Framework\TestCase;

/**
 * The aggregate's OAuth behaviour (ADR-0014): provisioning, linking and the rules around them
 * (I-27, I-28, I-29). Pure Domain: every instant is passed in.
 */
final class UserOAuthTest extends TestCase
{
    private const string ID = '018f5b2a-0000-7000-8000-000000000000';
    private const string EMAIL = 'musician@example.com';

    private \DateTimeImmutable $registeredAt;
    private \DateTimeImmutable $linkedAt;

    protected function setUp(): void
    {
        $this->registeredAt = new \DateTimeImmutable('2026-11-01T10:00:00+00:00');
        $this->linkedAt = new \DateTimeImmutable('2026-11-14T12:30:00+00:00');
    }

    // ---- registerWithOAuth (AC-6, AC-16) ------------------------------------------------------

    public function testProvisioningCreatesAPasswordlessAccount(): void
    {
        $user = $this->provisioned('sub-1');

        self::assertNull($user->passwordHash());
        self::assertFalse($user->hasPassword());
    }

    public function testProvisioningCreatesAnAccountThatIsEmailVerifiedAtTheRegistrationInstant(): void
    {
        $user = $this->provisioned('sub-1');

        self::assertTrue($user->isEmailVerified());
        self::assertSame($this->registeredAt, $user->emailVerifiedAt());
        self::assertSame($this->registeredAt, $user->registeredAt());
    }

    public function testProvisioningHoldsExactlyTheGivenIdentityAndTheBaseRole(): void
    {
        $user = $this->provisioned('sub-1');

        self::assertCount(1, $user->oauthIdentities());
        self::assertTrue($user->hasOAuthIdentity($this->google('sub-1')));
        self::assertFalse($user->hasOAuthIdentity($this->google('sub-2')));
        self::assertSame([Role::User], $user->roles());
    }

    public function testProvisioningRecordsUserRegisteredThenUserEmailVerified(): void
    {
        $events = $this->provisioned('sub-1')->releaseEvents();

        self::assertCount(2, $events);
        self::assertInstanceOf(UserRegistered::class, $events[0]);
        self::assertInstanceOf(UserEmailVerified::class, $events[1]);
        self::assertSame(self::ID, $events[0]->userId()->toString());
        self::assertSame(self::EMAIL, $events[0]->email()->toString());
        self::assertSame($this->registeredAt, $events[1]->occurredAt());
    }

    public function testAProvisionedAccountIsUsableAndHasNeverChangedItsPassword(): void
    {
        $user = $this->provisioned('sub-1');

        self::assertTrue($user->isUsable());
        self::assertNull($user->passwordChangedAt());
    }

    // ---- linkOAuthIdentity on a VERIFIED account (AC-8) ---------------------------------------

    public function testLinkingToAVerifiedAccountKeepsThePasswordAndTheVerificationInstant(): void
    {
        $user = $this->passwordUser(verified: true);
        $hash = $user->passwordHash();

        $user->linkOAuthIdentity($this->google('sub-1'), $this->linkedAt);

        self::assertSame($hash, $user->passwordHash());
        self::assertTrue($user->hasPassword());
        self::assertSame($this->registeredAt, $user->emailVerifiedAt());
        self::assertTrue($user->hasOAuthIdentity($this->google('sub-1')));
        self::assertTrue($user->isUsable());
    }

    public function testLinkingToAVerifiedAccountRecordsOnlyUserOAuthIdentityLinkedWithoutDiscard(): void
    {
        $user = $this->passwordUser(verified: true);

        $user->linkOAuthIdentity($this->google('sub-1'), $this->linkedAt);

        $events = $user->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(UserOAuthIdentityLinked::class, $events[0]);
        self::assertFalse($events[0]->passwordDiscarded());
        self::assertSame(OAuthProvider::Google, $events[0]->provider());
        self::assertSame(self::ID, $events[0]->userId()->toString());
        self::assertSame($this->linkedAt, $events[0]->occurredAt());
    }

    // ---- linkOAuthIdentity on an UNVERIFIED account (AC-12, I-29) -----------------------------

    public function testLinkingToAnUnverifiedAccountDiscardsItsPassword(): void
    {
        $user = $this->passwordUser(verified: false);
        self::assertTrue($user->hasPassword());

        $user->linkOAuthIdentity($this->google('sub-1'), $this->linkedAt);

        self::assertNull($user->passwordHash());
        self::assertFalse($user->hasPassword());
    }

    public function testLinkingToAnUnverifiedAccountVerifiesItAtTheLinkInstant(): void
    {
        $user = $this->passwordUser(verified: false);

        $user->linkOAuthIdentity($this->google('sub-1'), $this->linkedAt);

        self::assertSame($this->linkedAt, $user->emailVerifiedAt());
        self::assertTrue($user->isUsable());
    }

    public function testLinkingToAnUnverifiedAccountRecordsEmailVerifiedThenLinkedWithDiscardFlag(): void
    {
        $user = $this->passwordUser(verified: false);

        $user->linkOAuthIdentity($this->google('sub-1'), $this->linkedAt);

        $events = $user->releaseEvents();
        self::assertCount(2, $events);
        self::assertInstanceOf(UserEmailVerified::class, $events[0]);
        self::assertInstanceOf(UserOAuthIdentityLinked::class, $events[1]);
        self::assertTrue($events[1]->passwordDiscarded());
    }

    public function testDiscardingAnUnprovenPasswordIsNotAPasswordChange(): void
    {
        $user = $this->passwordUser(verified: false);

        $user->linkOAuthIdentity($this->google('sub-1'), $this->linkedAt);

        foreach ($user->releaseEvents() as $event) {
            self::assertNotInstanceOf(UserPasswordChanged::class, $event, 'A "your password was changed" mail must not go out.');
        }
        self::assertNull($user->passwordChangedAt());
    }

    // ---- idempotency and one-per-provider (AC-11, I-28) ---------------------------------------

    public function testLinkingTheSameIdentityTwiceIsANoOpWithNoSecondEvent(): void
    {
        $user = $this->passwordUser(verified: true);
        $user->linkOAuthIdentity($this->google('sub-1'), $this->linkedAt);
        $user->releaseEvents();

        $user->linkOAuthIdentity($this->google('sub-1'), $this->linkedAt->modify('+1 hour'));

        self::assertCount(1, $user->oauthIdentities());
        self::assertSame([], $user->releaseEvents());
    }

    public function testRelinkingTheSameIdentityOnADiscardedAccountChangesNothing(): void
    {
        $user = $this->passwordUser(verified: false);
        $user->linkOAuthIdentity($this->google('sub-1'), $this->linkedAt);
        $user->releaseEvents();

        $user->linkOAuthIdentity($this->google('sub-1'), $this->linkedAt->modify('+1 hour'));

        self::assertSame($this->linkedAt, $user->emailVerifiedAt());
        self::assertSame([], $user->releaseEvents());
    }

    public function testLinkingADifferentSubjectFromTheSameProviderIsRefused(): void
    {
        $user = $this->provisioned('sub-1');

        $this->expectException(OAuthProviderAlreadyLinked::class);

        $user->linkOAuthIdentity($this->google('sub-2'), $this->linkedAt);
    }

    public function testARefusedLinkLeavesPasswordIdentitiesAndEventsUntouched(): void
    {
        $user = $this->passwordUser(verified: true);
        $user->linkOAuthIdentity($this->google('sub-1'), $this->linkedAt);
        $hash = $user->passwordHash();
        $user->releaseEvents();

        try {
            $user->linkOAuthIdentity($this->google('sub-2'), $this->linkedAt->modify('+1 day'));
            self::fail('A second Google subject must be refused.');
        } catch (OAuthProviderAlreadyLinked) {
        }

        self::assertSame($hash, $user->passwordHash());
        self::assertCount(1, $user->oauthIdentities());
        self::assertTrue($user->hasOAuthIdentity($this->google('sub-1')));
        self::assertFalse($user->hasOAuthIdentity($this->google('sub-2')));
        self::assertSame([], $user->releaseEvents());
    }

    // ---- changePassword on a passwordless account (AC-15) -------------------------------------

    public function testAPasswordlessAccountCanGainAPasswordThroughChangePassword(): void
    {
        $user = $this->provisioned('sub-1');
        $user->releaseEvents();
        $hash = HashedPassword::fromString('a-brand-new-hash');

        $user->changePassword($hash, $this->linkedAt);

        self::assertTrue($user->hasPassword());
        self::assertSame($hash, $user->passwordHash());
        self::assertSame($this->linkedAt, $user->passwordChangedAt());
    }

    public function testGainingAPasswordRecordsUserPasswordChangedAndKeepsTheLinkedIdentity(): void
    {
        $user = $this->provisioned('sub-1');
        $user->releaseEvents();

        $user->changePassword(HashedPassword::fromString('a-brand-new-hash'), $this->linkedAt);

        $events = $user->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(UserPasswordChanged::class, $events[0]);
        self::assertTrue($user->hasOAuthIdentity($this->google('sub-1')));
        self::assertTrue($user->isUsable());
    }

    // ---- helpers ---------------------------------------------------------------------------

    private function google(string $subject): OAuthIdentity
    {
        return OAuthIdentity::of(OAuthProvider::Google, $subject);
    }

    private function provisioned(string $subject): User
    {
        return User::registerWithOAuth(
            UserId::fromString(self::ID),
            Email::fromString(self::EMAIL),
            $this->google($subject),
            $this->registeredAt,
        );
    }

    private function passwordUser(bool $verified): User
    {
        $user = User::register(
            UserId::fromString(self::ID),
            Email::fromString(self::EMAIL),
            HashedPassword::fromString('opaque-attacker-chosen-hash'),
            $this->registeredAt,
        );

        if ($verified) {
            $user->verifyEmail($this->registeredAt);
        }

        $user->releaseEvents();

        return $user;
    }
}
