<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Catalog\ValueObject;

use App\Domain\Catalog\Exception\InvalidCategoryId;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Domain\Identity\ValueObject\UserId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CategoryIdTest extends TestCase
{
    public function testAcceptsAnRfc4122String(): void
    {
        self::assertSame(
            '018f5b2a-0000-7000-8000-000000000000',
            CategoryId::fromString('018f5b2a-0000-7000-8000-000000000000')->toString(),
        );
    }

    public function testNormalisesToLowerCase(): void
    {
        self::assertSame(
            'abcdef01-0000-7000-8000-00000000abcd',
            CategoryId::fromString('ABCDEF01-0000-7000-8000-00000000ABCD')->toString(),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejected(): iterable
    {
        yield 'empty' => [''];
        yield 'not a uuid' => ['not-a-uuid'];
        yield 'missing hyphens' => ['018f5b2a000070008000000000000000'];
        yield 'non-hex digit' => ['018f5b2a-0000-7000-8000-00000000000g'];
        yield 'trailing junk' => ['018f5b2a-0000-7000-8000-000000000000x'];
    }

    #[DataProvider('rejected')]
    public function testRejectsAnythingElse(string $value): void
    {
        $this->expectException(InvalidCategoryId::class);

        CategoryId::fromString($value);
    }

    public function testEqualsIgnoresHexCase(): void
    {
        self::assertTrue(
            CategoryId::fromString('ABCDEF01-0000-7000-8000-00000000ABCD')
                ->equals(CategoryId::fromString('abcdef01-0000-7000-8000-00000000abcd')),
        );
    }

    public function testEqualsRejectsADifferentIdType(): void
    {
        $category = CategoryId::fromString('018f5b2a-0000-7000-8000-000000000000');
        $user = UserId::fromString('018f5b2a-0000-7000-8000-000000000000');

        $this->expectException(\TypeError::class);

        // @phpstan-ignore argument.type (the runtime TypeError is the behaviour under test)
        self::assertFalse($category->equals($user));
    }
}
