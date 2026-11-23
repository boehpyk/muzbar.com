<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Search;

use App\Domain\Search\ValueObject\AttributeId;
use App\Domain\Search\ValueObject\CategoryId;
use App\Domain\Search\ValueObject\FacetAttribute;
use App\Domain\Search\ValueObject\FacetedQuery;
use App\Domain\Search\ValueObject\FacetKind;
use App\Domain\Search\ValueObject\FacetSchema;
use App\Domain\Search\ValueObject\FilterRequest;
use App\Domain\Search\ValueObject\OptionId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * search-browse AC-2 (`resolve()` — S-5, second half) and AC-3 (the page — S-8). The schema is built
 * by hand: Brand and Finish are choice facets, Has case a boolean one; Fender and Gibson are Brand's
 * requested active options, Gloss is Finish's. Nothing here boots a kernel.
 */
final class FacetedQueryTest extends TestCase
{
    private const string BRAND = '018f5b2a-0000-7000-8000-0000000000a1';
    private const string FINISH = '018f5b2a-0000-7000-8000-0000000000a2';
    private const string HAS_CASE = '018f5b2a-0000-7000-8000-0000000000a3';
    private const string SIBLING = '018f5b2a-0000-7000-8000-0000000000a4'; // an attribute of another branch: not in the schema
    private const string FENDER = '018f5b2a-0000-7000-8000-0000000000b1';
    private const string GIBSON = '018f5b2a-0000-7000-8000-0000000000b2';
    private const string GLOSS = '018f5b2a-0000-7000-8000-0000000000b3';
    private const string ARCHIVED = '018f5b2a-0000-7000-8000-0000000000b4'; // not in the schema's options: archived or unknown
    private const string CATEGORY = '018f5b2a-0000-7000-8000-0000000000c1';

    private function schema(): FacetSchema
    {
        $brand = AttributeId::fromString(self::BRAND);
        $finish = AttributeId::fromString(self::FINISH);

        return FacetSchema::of(
            [
                new FacetAttribute($brand, FacetKind::Choice, 'Brand'),
                new FacetAttribute($finish, FacetKind::Choice, 'Finish'),
                new FacetAttribute(AttributeId::fromString(self::HAS_CASE), FacetKind::Boolean, 'Has case'),
            ],
            [
                self::FENDER => ['attribute' => $brand, 'label' => 'Fender'],
                self::GIBSON => ['attribute' => $brand, 'label' => 'Gibson'],
                self::GLOSS => ['attribute' => $finish, 'label' => 'Gloss'],
            ],
        );
    }

    /**
     * @param array<string, list<string>> $filters
     */
    private function resolve(array $filters, ?FacetSchema $schema = null, int $page = 1): FacetedQuery
    {
        return FacetedQuery::resolve(
            CategoryId::fromString(self::CATEGORY),
            FilterRequest::parse($filters, [], ['currency' => null, 'min' => null, 'max' => null]),
            $schema ?? $this->schema(),
            $page,
        );
    }

    /**
     * @return list<string> each surviving selection as `attribute=value,value`, option ids and yes/no alike
     */
    private function described(FacetedQuery $query): array
    {
        return array_map(static function ($selection): string {
            $values = [
                ...array_map(static fn (OptionId $id): string => $id->toString(), $selection->optionIds()),
                ...array_map(static fn (bool $b): string => $b ? 'yes' : 'no', $selection->booleans()),
            ];

            return $selection->attributeId()->toString().'='.implode(',', $values);
        }, $query->selections());
    }

    // ---------------------------------------------------------------- AC-2

    public function testASelectionOnAnAttributeNotInTheSchemaIsDropped(): void
    {
        $query = $this->resolve([self::SIBLING => [self::FENDER, 'yes'], self::BRAND => [self::FENDER]]);

        self::assertSame([self::BRAND.'='.self::FENDER], $this->described($query));
    }

    public function testAChoiceFacetKeepsOnlyOptionIdsTheSchemaListsForThatAttribute(): void
    {
        $query = $this->resolve([self::BRAND => [self::FENDER, self::GIBSON]]);

        self::assertSame([self::BRAND.'='.self::FENDER.','.self::GIBSON], $this->described($query));
    }

    public function testAChoiceFacetDropsAnotherFacetsOptionAnUnlistedOptionAndYesNo(): void
    {
        $query = $this->resolve([self::BRAND => [self::GLOSS, self::ARCHIVED, 'yes', 'no', self::FENDER]]);

        self::assertSame([self::BRAND.'='.self::FENDER], $this->described($query));
    }

    public function testABooleanFacetKeepsYesAndNo(): void
    {
        $query = $this->resolve([self::HAS_CASE => ['no', 'yes']]);

        self::assertSame([self::HAS_CASE.'=no,yes'], $this->described($query));
        self::assertSame([false, true], $query->selections()[0]->booleans());
    }

    public function testABooleanFacetDropsAnOptionId(): void
    {
        $query = $this->resolve([self::HAS_CASE => [self::FENDER, 'yes']]);

        self::assertSame([self::HAS_CASE.'=yes'], $this->described($query));
    }

    public function testASelectionLeftEmptyIsDroppedWhole(): void
    {
        $query = $this->resolve([
            self::BRAND => [self::GLOSS, 'yes'],
            self::HAS_CASE => [self::FENDER],
            self::FINISH => [self::GLOSS],
        ]);

        self::assertSame([self::FINISH.'='.self::GLOSS], $this->described($query));
    }

    public function testSurvivingSelectionsKeepRequestOrder(): void
    {
        $query = $this->resolve([self::HAS_CASE => ['yes'], self::FINISH => [self::GLOSS], self::BRAND => [self::FENDER]]);

        self::assertSame(
            [self::HAS_CASE.'=yes', self::FINISH.'='.self::GLOSS, self::BRAND.'='.self::FENDER],
            $this->described($query),
        );
    }

    public function testWithNoCategoryTheSchemaIsEmptyAndEverySelectionIsDropped(): void
    {
        $request = FilterRequest::parse([self::BRAND => [self::FENDER], self::HAS_CASE => ['yes']], [], ['currency' => null, 'min' => null, 'max' => null]);

        $query = FacetedQuery::resolve(null, $request, FacetSchema::none(), 1);

        self::assertNull($query->category());
        self::assertSame([], $query->selections());
    }

    public function testASchemaThatListsNoRequestedOptionDropsEveryChoiceSelection(): void
    {
        $facetsOnly = FacetSchema::of([new FacetAttribute(AttributeId::fromString(self::BRAND), FacetKind::Choice, 'Brand')], []);

        $query = $this->resolve([self::BRAND => [self::FENDER]], $facetsOnly);

        self::assertSame([], $query->selections());
    }

    public function testTheQueryKeepsTheCategoryAndTheSchemaItWasResolvedAgainst(): void
    {
        $schema = $this->schema();

        $query = $this->resolve([], $schema);

        self::assertSame(self::CATEGORY, $query->category()?->toString());
        self::assertSame($schema, $query->facets());
    }

    // ---------------------------------------------------------------- AC-3

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function pages(): iterable
    {
        yield 'zero' => [0, 1];
        yield 'minus three' => [-3, 1];
        yield 'PHP_INT_MIN' => [\PHP_INT_MIN, 1];
        yield 'one' => [1, 1];
        yield 'seven' => [7, 7];
        yield 'the last page' => [50, 50];
        yield 'one past the last' => [51, 50];
        yield 'PHP_INT_MAX' => [\PHP_INT_MAX, 50];
    }

    #[DataProvider('pages')]
    public function testThePageIsClampedToOneThroughFifty(int $asked, int $expected): void
    {
        self::assertSame($expected, $this->resolve([], null, $asked)->page());
    }

    public function testTheConstantsAndTheOffsetFollowTheSpec(): void
    {
        self::assertSame(20, FacetedQuery::PER_PAGE);
        self::assertSame(50, FacetedQuery::MAX_PAGE);
        self::assertSame(0, $this->resolve([], null, 1)->offset());
        self::assertSame(20, $this->resolve([], null, 2)->offset());
        self::assertSame(980, $this->resolve([], null, 50)->offset());
        self::assertSame(980, $this->resolve([], null, \PHP_INT_MAX)->offset(), 'a clamped page bounds OFFSET');
        self::assertSame(0, $this->resolve([], null, -3)->offset());
    }
}
