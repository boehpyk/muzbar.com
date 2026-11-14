<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Identity\ValueObject;

use App\Domain\Identity\Exception\InvalidOAuthIdentity;
use App\Domain\Identity\ValueObject\OAuthIdentity;
use App\Domain\Identity\ValueObject\OAuthProvider;
use PHPUnit\Framework\TestCase;

final class OAuthIdentityTest extends TestCase
{
    public function testItExposesTheProviderAndTheSubjectItWasBuiltFrom(): void
    {
        $identity = OAuthIdentity::of(OAuthProvider::Google, '110169484474386276334');

        self::assertSame(OAuthProvider::Google, $identity->provider());
        self::assertSame('110169484474386276334', $identity->subject());
    }

    public function testAnEmptySubjectIsRejected(): void
    {
        $this->expectException(InvalidOAuthIdentity::class);

        OAuthIdentity::of(OAuthProvider::Google, '');
    }

    public function testASubjectOfExactlyTheMaximumLengthIsAccepted(): void
    {
        $subject = str_repeat('a', OAuthIdentity::MAX_SUBJECT_LENGTH);

        self::assertSame($subject, OAuthIdentity::of(OAuthProvider::Google, $subject)->subject());
    }

    public function testASubjectOneCharacterOverTheMaximumIsRejected(): void
    {
        $this->expectException(InvalidOAuthIdentity::class);

        OAuthIdentity::of(OAuthProvider::Google, str_repeat('a', OAuthIdentity::MAX_SUBJECT_LENGTH + 1));
    }

    public function testTheMaximumIsCountedInCharactersNotBytes(): void
    {
        $subject = str_repeat('é', OAuthIdentity::MAX_SUBJECT_LENGTH);

        self::assertSame($subject, OAuthIdentity::of(OAuthProvider::Google, $subject)->subject());
    }

    public function testTheSubjectIsOpaqueAndIsNeitherTrimmedNorCaseFolded(): void
    {
        $identity = OAuthIdentity::of(OAuthProvider::Google, ' AbC ');

        self::assertSame(' AbC ', $identity->subject());
    }

    public function testAWhitespaceOnlySubjectIsNotEmptyAndIsKeptAsIs(): void
    {
        self::assertSame(' ', OAuthIdentity::of(OAuthProvider::Google, ' ')->subject());
    }

    public function testRejectionMessagesNeverQuoteTheSubject(): void
    {
        $subject = str_repeat('SECRET-SUBJECT', 30);

        try {
            OAuthIdentity::of(OAuthProvider::Google, $subject);
            self::fail('An over-long subject must be rejected.');
        } catch (InvalidOAuthIdentity $e) {
            self::assertStringNotContainsString('SECRET-SUBJECT', $e->getMessage());
        }
    }

    public function testIdentitiesWithTheSameProviderAndSubjectAreEqual(): void
    {
        self::assertTrue(
            OAuthIdentity::of(OAuthProvider::Google, 'sub-1')->equals(OAuthIdentity::of(OAuthProvider::Google, 'sub-1')),
        );
    }

    public function testIdentitiesWithDifferentSubjectsAreNotEqual(): void
    {
        self::assertFalse(
            OAuthIdentity::of(OAuthProvider::Google, 'sub-1')->equals(OAuthIdentity::of(OAuthProvider::Google, 'sub-2')),
        );
    }

    public function testSubjectComparisonIsCaseSensitive(): void
    {
        self::assertFalse(
            OAuthIdentity::of(OAuthProvider::Google, 'Sub')->equals(OAuthIdentity::of(OAuthProvider::Google, 'sub')),
        );
    }

    public function testItCannotBeImplicitlyConvertedToAString(): void
    {
        self::assertFalse((new \ReflectionClass(OAuthIdentity::class))->hasMethod('__toString'));
    }

    public function testTheProviderIsStoredUnderItsPersistedName(): void
    {
        self::assertSame('google', OAuthProvider::Google->value);
    }
}
