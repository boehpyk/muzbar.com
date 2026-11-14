<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Domain\Identity\ValueObject\OAuthIdentity;
use App\Domain\Identity\ValueObject\OAuthProvider;
use App\Infrastructure\Identity\Persistence\Doctrine\Type\OAuthIdentitySetType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\Type;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `OAuthIdentitySetType` against the real registered type and the real Postgres platform: the
 * stored shape (AC-21: exactly `provider` and `subject`), the round trip, and the refusal to
 * hydrate a row this build cannot reason about.
 */
final class OAuthIdentitySetTypeTest extends KernelTestCase
{
    private Type $type;
    private AbstractPlatform $platform;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->platform = $connection->getDatabasePlatform();
        $this->type = Type::getType(OAuthIdentitySetType::NAME);
    }

    public function testTheTypeIsRegisteredUnderItsName(): void
    {
        self::assertInstanceOf(OAuthIdentitySetType::class, $this->type);
    }

    public function testAnIdentityIsStoredWithExactlyTheKeysProviderAndSubject(): void
    {
        $stored = $this->type->convertToDatabaseValue([OAuthIdentity::of(OAuthProvider::Google, 'sub-1')], $this->platform);

        self::assertSame('[{"provider":"google","subject":"sub-1"}]', $stored);
    }

    public function testAListRoundTripsToEqualIdentities(): void
    {
        $original = [OAuthIdentity::of(OAuthProvider::Google, 'sub-€-ünïcode')];

        $restored = $this->type->convertToPHPValue(
            $this->type->convertToDatabaseValue($original, $this->platform),
            $this->platform,
        );

        self::assertIsArray($restored);
        self::assertCount(1, $restored);
        self::assertInstanceOf(OAuthIdentity::class, $restored[0]);
        self::assertTrue($original[0]->equals($restored[0]));
    }

    public function testAnEmptyListIsStoredAsAnEmptyJsonArrayAndReadBackEmpty(): void
    {
        $stored = $this->type->convertToDatabaseValue([], $this->platform);

        self::assertSame('[]', $stored);
        self::assertSame([], $this->type->convertToPHPValue($stored, $this->platform));
    }

    public function testNullStaysNull(): void
    {
        self::assertNull($this->type->convertToDatabaseValue(null, $this->platform));
        self::assertNull($this->type->convertToPHPValue(null, $this->platform));
    }

    public function testASubjectSurvivesAPostgresJsonbRoundTripByteForByte(): void
    {
        $subject = ' MiXeD "quoted" \\ back\\slash ';
        $json = $this->type->convertToDatabaseValue([OAuthIdentity::of(OAuthProvider::Google, $subject)], $this->platform);

        $fromPostgres = $this->connection->fetchOne('SELECT CAST(:v AS jsonb)', ['v' => $json]);

        $restored = $this->type->convertToPHPValue($fromPostgres, $this->platform);
        self::assertIsArray($restored);
        self::assertInstanceOf(OAuthIdentity::class, $restored[0]);
        self::assertSame($subject, $restored[0]->subject());
    }

    public function testAValueThatIsNotAListIsRefusedOnTheWayDown(): void
    {
        $this->expectException(InvalidType::class);

        $this->type->convertToDatabaseValue('google:sub-1', $this->platform);
    }

    public function testAListHoldingSomethingOtherThanAnIdentityIsRefusedOnTheWayDown(): void
    {
        $this->expectException(InvalidType::class);

        $this->type->convertToDatabaseValue(['google:sub-1'], $this->platform);
    }

    #[DataProvider('corruptRows')]
    public function testACorruptRowIsRefusedInsteadOfBeingSilentlyDropped(string $json): void
    {
        $this->expectException(ValueNotConvertible::class);

        $this->type->convertToPHPValue($json, $this->platform);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function corruptRows(): iterable
    {
        yield 'a JSON object instead of a list' => ['{"provider":"google","subject":"x"}'];
        yield 'a bare string' => ['"google"'];
        yield 'an element that is a string' => ['["google"]'];
        yield 'an unknown provider' => ['[{"provider":"github","subject":"x"}]'];
        yield 'an empty subject' => ['[{"provider":"google","subject":""}]'];
        yield 'a subject over 255 characters' => ['[{"provider":"google","subject":"'.str_repeat('a', 256).'"}]'];
        yield 'a missing subject' => ['[{"provider":"google"}]'];
        yield 'a missing provider' => ['[{"subject":"x"}]'];
        yield 'a numeric subject' => ['[{"provider":"google","subject":5}]'];
        yield 'one bad element among good ones' => ['[{"provider":"google","subject":"ok"},{"provider":"nope","subject":"x"}]'];
    }

    public function testACorruptRowErrorNeverQuotesTheSubject(): void
    {
        $json = '[{"provider":"google","subject":"'.str_repeat('PERSONAL-ID', 40).'"}]';

        try {
            $this->type->convertToPHPValue($json, $this->platform);
            self::fail('An over-long subject must be refused.');
        } catch (ValueNotConvertible $e) {
            self::assertStringNotContainsString('PERSONAL-ID', $e->getMessage());
        }
    }
}
