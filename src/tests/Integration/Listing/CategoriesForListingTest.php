<?php

declare(strict_types=1);

namespace App\Tests\Integration\Listing;

use App\Application\Catalog\Command\EditAttribute;
use App\Application\Catalog\Handler\EditAttributeHandler;
use App\Domain\Catalog\Entity\Attribute;
use App\Domain\Catalog\Entity\Category;
use App\Domain\Catalog\ValueObject\NumberRules;
use App\Domain\Listing\Exception\InvalidAttributeValue;
use App\Domain\Listing\Port\Categories;
use App\Domain\Listing\ValueObject\AttributeId;
use App\Domain\Listing\ValueObject\AttributeInput;
use App\Domain\Listing\ValueObject\AttributeSchema;
use App\Domain\Listing\ValueObject\AttributeValues;
use App\Domain\Listing\ValueObject\CategoryId;
use App\Domain\Listing\ValueObject\CategoryStatus;
use App\Tests\Factory\AttributeFactory;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Support\BuildsTheValuesTree;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * AC-17 (listing-draft), first bullet: `Categories::statusOf()` against the real catalog tables; and
 * AC-11 (listing-attribute-values): `Categories::schemaOf()`, observed through the schema's two public
 * behaviours (`has()` and `read()`) because a rule's kind, options and bounds are private.
 */
final class CategoriesForListingTest extends KernelTestCase
{
    use BuildsTheValuesTree;

    private Categories $categories;

    protected function setUp(): void
    {
        self::bootKernel();
        $categories = self::getContainer()->get(Categories::class);
        self::assertInstanceOf(Categories::class, $categories);
        $this->categories = $categories;
    }

    public function testStatusOfALeafABranchAndAnUnknownId(): void
    {
        $guitars = CategoryFactory::createOne();
        $electric = CategoryFactory::new()->childOf($guitars)->create();

        self::assertSame(CategoryStatus::Leaf, $this->categories->statusOf(CategoryId::fromString($electric->id()->toString())));
        self::assertSame(CategoryStatus::Branch, $this->categories->statusOf(CategoryId::fromString($guitars->id()->toString())));
        self::assertSame(CategoryStatus::Missing, $this->categories->statusOf(CategoryId::fromString(Uuid::v7()->toRfc4122())));
    }

    public function testARootWithNoChildrenIsALeaf(): void
    {
        $root = CategoryFactory::createOne();

        self::assertSame(CategoryStatus::Leaf, $this->categories->statusOf(CategoryId::fromString($root->id()->toString())));
    }

    public function testACategoryBecomesABranchTheMomentItGainsAChild(): void
    {
        $parent = CategoryFactory::createOne();
        $id = CategoryId::fromString($parent->id()->toString());
        self::assertSame(CategoryStatus::Leaf, $this->categories->statusOf($id));

        CategoryFactory::new()->childOf($parent)->create();

        self::assertSame(CategoryStatus::Branch, $this->categories->statusOf($id));
    }

    private function schemaOf(Category $category): AttributeSchema
    {
        return $this->categories->schemaOf(CategoryId::fromString($category->id()->toString()));
    }

    /**
     * @return array<string, string> the attribute's code => whether `has()` says it is in force
     */
    private function inForce(AttributeSchema $schema): array
    {
        $out = [];
        foreach (['condition' => $this->condition, 'brand' => $this->brand, 'strings' => $this->strings, 'has-case' => $this->hasCase, 'serial' => $this->serial, 'bass-strings' => $this->bassStrings] as $code => $attribute) {
            $out[$code] = $schema->has(AttributeId::fromString($attribute->id()->toString())) ? 'yes' : 'no';
        }

        return $out;
    }

    private function accepts(AttributeSchema $schema, Attribute $attribute, string $raw): bool
    {
        try {
            return !$schema->read(AttributeInput::fromArray([$attribute->id()->toString() => $raw]))->isEmpty();
        } catch (InvalidAttributeValue) {
            return false;
        }
    }

    public function testElectricsSchemaHoldsItsOwnAndEveryAncestorsAttributesAndNotBasss(): void
    {
        $this->buildTheValuesTree();

        self::assertSame(
            ['condition' => 'yes', 'brand' => 'yes', 'strings' => 'yes', 'has-case' => 'yes', 'serial' => 'yes', 'bass-strings' => 'no'],
            $this->inForce($this->schemaOf($this->electric)),
        );
    }

    public function testBasssSchemaHoldsTheRootsAttributeAndItsOwnOnly(): void
    {
        $this->buildTheValuesTree();

        self::assertSame(
            ['condition' => 'yes', 'brand' => 'no', 'strings' => 'no', 'has-case' => 'no', 'serial' => 'no', 'bass-strings' => 'yes'],
            $this->inForce($this->schemaOf($this->bass)),
        );
    }

    public function testAnAncestorsSchemaIsNotItsDescendants(): void
    {
        $this->buildTheValuesTree();

        self::assertSame(
            ['condition' => 'yes', 'brand' => 'yes', 'strings' => 'no', 'has-case' => 'no', 'serial' => 'no', 'bass-strings' => 'no'],
            $this->inForce($this->schemaOf($this->guitars)),
        );
    }

    public function testAnUnknownCategoryHasAnEmptySchema(): void
    {
        $this->buildTheValuesTree();

        $schema = $this->categories->schemaOf(CategoryId::fromString(Uuid::v7()->toRfc4122()));

        self::assertSame(array_fill_keys(['condition', 'brand', 'strings', 'has-case', 'serial', 'bass-strings'], 'no'), $this->inForce($schema));
    }

    public function testEachRuleCarriesItsKindOptionsAndBounds(): void
    {
        $this->buildTheValuesTree();
        $electric = $this->schemaOf($this->electric);

        // choice: that attribute's own options only
        self::assertTrue($this->accepts($electric, $this->condition, $this->optionIdOf($this->condition, 'Used')));
        self::assertTrue($this->accepts($electric, $this->brand, $this->optionIdOf($this->brand, 'Gibson')));
        self::assertFalse($this->accepts($electric, $this->brand, $this->optionIdOf($this->condition, 'Used')));
        // number: bounds 4 and 12, inclusive
        self::assertSame([false, true, true, false], [$this->accepts($electric, $this->strings, '3'), $this->accepts($electric, $this->strings, '4'), $this->accepts($electric, $this->strings, '12'), $this->accepts($electric, $this->strings, '13')]);
        // boolean and text
        self::assertTrue($this->accepts($electric, $this->hasCase, 'no'));
        self::assertFalse($this->accepts($electric, $this->hasCase, 'maybe'));
        self::assertTrue($this->accepts($electric, $this->serial, 'SN-1'));
        self::assertFalse($this->accepts($electric, $this->serial, "a\nb"));
    }

    public function testANumberWithNoBoundsHasNoBoundsInTheSchemaEither(): void
    {
        $this->buildTheValuesTree();
        $bass = $this->schemaOf($this->bass);

        self::assertTrue($this->accepts($bass, $this->bassStrings, '-5'));
        self::assertTrue($this->accepts($bass, $this->bassStrings, '999999999999999999'));
    }

    public function testNumberBoundsBeyondInt32SurviveTheSqlCast(): void
    {
        $category = CategoryFactory::createOne();
        $big = AttributeFactory::new()->inCategory($category)->coded('big')->number(NumberRules::of(null, 3_000_000_000, 9_000_000_000_000))->create();

        $schema = $this->schemaOf($category);

        self::assertTrue($this->accepts($schema, $big, '3000000000'));
        self::assertTrue($this->accepts($schema, $big, '9000000000000'));
        self::assertFalse($this->accepts($schema, $big, '2999999999'));
        self::assertFalse($this->accepts($schema, $big, '9000000000001'));
    }

    public function testALegacyDuplicateCodeOnOneBranchYieldsBothAttributesWithoutError(): void
    {
        $this->buildTheValuesTree();
        // AttributeFactory bypasses C-21, which is exactly how pre-C-21 data came to exist (ADR-0018 d.7).
        $duplicate = AttributeFactory::new()->inCategory($this->electric)->coded('brand')->text()->create();

        $schema = $this->schemaOf($this->electric);

        self::assertTrue($schema->has(AttributeId::fromString($this->brand->id()->toString())));
        self::assertTrue($schema->has(AttributeId::fromString($duplicate->id()->toString())));
    }

    public function testAChoiceWithNoOptionsAtAllReadsAsAChoiceThatRefusesEverything(): void
    {
        // A legacy row C-18 would forbid today: the adapter must not throw on the NULL option aggregate.
        $category = CategoryFactory::createOne();
        $empty = AttributeFactory::new()->inCategory($category)->coded('empty')->choice(['Only'])->create();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $db->executeStatement('DELETE FROM catalog_attribute_option WHERE attribute_id = ?', [$empty->id()->toString()]);

        $schema = $this->schemaOf($category);

        self::assertTrue($schema->has(AttributeId::fromString($empty->id()->toString())));
        self::assertFalse($this->accepts($schema, $empty, Uuid::v7()->toRfc4122()));
    }

    /**
     * @return list<string> the codes of the attributes `schemaOf()` reports as required for `$category`
     */
    private function requiredCodes(Category $category): array
    {
        $byId = [];
        foreach (['condition' => $this->condition, 'brand' => $this->brand, 'strings' => $this->strings, 'has-case' => $this->hasCase, 'serial' => $this->serial] as $code => $attribute) {
            $byId[$attribute->id()->toString()] = $code;
        }

        $codes = array_map(
            static fn (AttributeId $id): string => $byId[$id->toString()] ?? '?',
            $this->schemaOf($category)->missingFrom(AttributeValues::none()),
        );
        sort($codes);

        return $codes;
    }

    /** listing-publish AC-11. */
    public function testElectricsSchemaReportsConditionAndBrandAsRequiredAndTheRestAsNot(): void
    {
        $this->buildTheValuesTree();

        self::assertSame(['brand', 'condition'], $this->requiredCodes($this->electric));
    }

    public function testTogglingRequiredOnAnAttributeFlipsItOnTheNextRead(): void
    {
        $this->buildTheValuesTree();
        $edit = self::getContainer()->get(EditAttributeHandler::class);
        self::assertInstanceOf(EditAttributeHandler::class, $edit);

        $edit(new EditAttribute($this->strings->id()->toString(), 'Strings', 'strings', true, 'pcs', 4, 12));
        self::assertSame(['brand', 'condition', 'strings'], $this->requiredCodes($this->electric));

        $edit(new EditAttribute($this->condition->id()->toString(), 'Condition', 'condition', false));
        self::assertSame(['brand', 'strings'], $this->requiredCodes($this->electric));
    }

    public function testRequiredIsReportedForInheritedAttributesOnlyAlongTheLineage(): void
    {
        $this->buildTheValuesTree();

        self::assertSame(['condition'], $this->requiredCodes($this->bass));
    }
}
