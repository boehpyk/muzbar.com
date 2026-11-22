<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Application\Catalog\Command\DeleteAttribute;
use App\Application\Catalog\Command\EditAttribute;
use App\Application\Catalog\Command\EditCategory;
use App\Application\Catalog\Command\RelabelAttributeOption;
use App\Application\Catalog\Command\RemoveAttributeOption;
use App\Application\Catalog\Handler\DeleteAttributeHandler;
use App\Application\Catalog\Handler\EditAttributeHandler;
use App\Application\Catalog\Handler\EditCategoryHandler;
use App\Application\Catalog\Handler\RelabelAttributeOptionHandler;
use App\Application\Catalog\Handler\RemoveAttributeOptionHandler;
use App\Domain\Catalog\Entity\Category;
use App\Domain\Catalog\Exception\AttributeInUse;
use App\Domain\Catalog\Exception\AttributeNeedsAnOption;
use App\Domain\Catalog\Exception\CategoryHasListings;
use App\Domain\Catalog\Exception\CategoryMoveWouldCreateCycle;
use App\Tests\Factory\AttributeFactory;
use App\Tests\Factory\ListingFactory;
use App\Tests\Support\BuildsTheValuesTree;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * AC-12 … AC-15 of listing-attribute-values: the guards (C-25, C-26; C-27 retired by
 * listing-schema-drift, its tests inverted) and the writes that are deliberately unguarded, driven through the real handlers against a real database. Draft D is
 * Ann's, on Electric (the feature spec's fixture tree).
 */
final class ListingValueGuardsTest extends KernelTestCase
{
    use BuildsTheValuesTree;

    private Connection $db;

    protected function setUp(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $this->db = $db;
        $this->buildTheValuesTree();
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }

    /**
     * @param array<string, string> $values
     */
    private function draftWith(array $values, ?Category $in = null): void
    {
        ListingFactory::new()->inCategory($in ?? $this->electric)->withValues($values)->create();
    }

    private function draftHoldingBrand(): void
    {
        $this->draftWith([$this->brand->id()->toString() => $this->optionIdOf($this->brand, 'Fender')]);
    }

    private function rows(string $table): int
    {
        $count = $this->db->fetchOne('SELECT count(*) FROM '.$table);
        self::assertIsNumeric($count);

        return (int) $count;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function valueTable(): array
    {
        return $this->db->fetchAllAssociative('SELECT * FROM listing_attribute_value ORDER BY listing_id, attribute_id');
    }

    private function parentOf(Category $category): ?string
    {
        $parent = $this->db->fetchOne('SELECT parent_id FROM catalog_category WHERE id = ?', [$category->id()->toString()]);

        return \is_string($parent) ? $parent : null;
    }

    private function move(Category $category, ?Category $under): void
    {
        ($this->service(EditCategoryHandler::class))(new EditCategory($category->id()->toString(), $category->name()->toString(), $category->slug()->toString(), $under?->id()->toString()));
    }

    // ---------------------------------------------------------------- AC-12 (C-25)

    public function testDeletingAnAttributeThatAListingHoldsAValueForIsRefusedAndNothingIsRemoved(): void
    {
        $this->draftHoldingBrand();
        $attributes = $this->rows('catalog_attribute');
        $options = $this->rows('catalog_attribute_option');

        try {
            ($this->service(DeleteAttributeHandler::class))(new DeleteAttribute($this->brand->id()->toString()));
            self::fail('A brand in use was deleted.');
        } catch (AttributeInUse $e) {
            self::assertSame($this->brand->id()->toString(), $e->id()->toString());
        }

        self::assertSame($attributes, $this->rows('catalog_attribute'));
        self::assertSame($options, $this->rows('catalog_attribute_option'));
        self::assertSame(2, $this->db->fetchOne('SELECT count(*) FROM catalog_attribute_option WHERE attribute_id = ?', [$this->brand->id()->toString()]));
    }

    public function testDeletingAnAttributeNoListingHoldsIsAllowedEvenThoughADraftIsFiledUnderItsCategory(): void
    {
        $this->draftHoldingBrand();

        ($this->service(DeleteAttributeHandler::class))(new DeleteAttribute($this->serial->id()->toString()));

        self::assertSame(0, $this->db->fetchOne('SELECT count(*) FROM catalog_attribute WHERE id = ?', [$this->serial->id()->toString()]));
    }

    // ---------------------------------------------------------------- AC-13 (C-26)

    public function testRemovingAnOptionInUseIsRefusedAndAnUnusedSiblingCanBeRemoved(): void
    {
        $this->draftHoldingBrand();
        $fender = $this->optionIdOf($this->brand, 'Fender');
        $gibson = $this->optionIdOf($this->brand, 'Gibson');

        try {
            ($this->service(RemoveAttributeOptionHandler::class))(new RemoveAttributeOption($this->brand->id()->toString(), $fender));
            self::fail('An option in use was removed.');
        } catch (AttributeInUse $e) {
            self::assertSame($fender, $e->id()->toString());
        }
        self::assertSame(1, $this->db->fetchOne('SELECT count(*) FROM catalog_attribute_option WHERE id = ?', [$fender]));

        ($this->service(RemoveAttributeOptionHandler::class))(new RemoveAttributeOption($this->brand->id()->toString(), $gibson));

        self::assertSame(0, $this->db->fetchOne('SELECT count(*) FROM catalog_attribute_option WHERE id = ?', [$gibson]));
    }

    public function testTheLastOptionInUseReportsTheAttributeNeedsAnOptionNotInUse(): void
    {
        $finish = AttributeFactory::new()->inCategory($this->electric)->coded('finish')->choice(['Matte'])->create();
        $this->draftWith([$finish->id()->toString() => $this->optionIdOf($finish, 'Matte')]);

        $this->expectException(AttributeNeedsAnOption::class);

        ($this->service(RemoveAttributeOptionHandler::class))(new RemoveAttributeOption($finish->id()->toString(), $this->optionIdOf($finish, 'Matte')));
    }

    // ---------------------------------------------------------------- AC-14 (C-27, retired)
    //
    // listing-schema-drift (ADR-0022) retired C-27: a move with values is allowed — values whose
    // attribute leaves the lineage go dormant (L-17) and wake if the move is undone. These two tests
    // are the slice-2 refusals **inverted on purpose**: the move succeeds and no value row changes.

    public function testMovingTheCategoryOfADraftThatHoldsAValueSucceedsAndLeavesItsValuesUntouched(): void
    {
        $this->draftHoldingBrand();
        $before = $this->valueTable();

        $this->move($this->electric, $this->instruments);

        self::assertSame($this->instruments->id()->toString(), $this->parentOf($this->electric));
        self::assertSame($before, $this->valueTable());
    }

    public function testMovingAnAncestorOfADraftThatHoldsAValueSucceedsToo(): void
    {
        $this->draftHoldingBrand();
        $before = $this->valueTable();

        $this->move($this->guitars, $this->bass);

        self::assertSame($this->bass->id()->toString(), $this->parentOf($this->guitars));
        self::assertSame($before, $this->valueTable());
    }

    public function testWithNoValuesHeldBothMovesSucceed(): void
    {
        ListingFactory::new()->inCategory($this->electric)->create();

        $this->move($this->electric, $this->instruments);
        $this->move($this->guitars, $this->bass);

        self::assertSame($this->instruments->id()->toString(), $this->parentOf($this->electric));
        self::assertSame($this->bass->id()->toString(), $this->parentOf($this->guitars));
    }

    public function testRenamingAndResluggingAValueHoldingCategorySucceeds(): void
    {
        $this->draftHoldingBrand();

        ($this->service(EditCategoryHandler::class))(new EditCategory($this->electric->id()->toString(), 'Electric guitars', 'electric-guitars', $this->guitars->id()->toString()));

        self::assertSame('Electric guitars', $this->db->fetchOne('SELECT name FROM catalog_category WHERE id = ?', [$this->electric->id()->toString()]));
        self::assertSame('electric-guitars', $this->db->fetchOne('SELECT slug FROM catalog_category WHERE id = ?', [$this->electric->id()->toString()]));
        self::assertSame($this->guitars->id()->toString(), $this->parentOf($this->electric));
    }

    public function testACycleIsReportedBeforeTheValuesAreLookedAt(): void
    {
        $this->draftHoldingBrand();

        $this->expectException(CategoryMoveWouldCreateCycle::class);

        $this->move($this->guitars, $this->electric);
    }

    /** C-24 still guards a move of a value-holding subtree (C-27's retirement left it alone). */
    public function testMovingAValueHoldingSubtreeUnderAListedOnCategoryIsStillRefusedByC24(): void
    {
        $this->draftHoldingBrand();
        ListingFactory::new()->inCategory($this->bass)->create();

        try {
            $this->move($this->guitars, $this->bass);
            self::fail('Guitars was moved under a listed-on category.');
        } catch (CategoryHasListings $e) {
            self::assertSame($this->bass->id()->toString(), $e->categoryId()->toString());
        }
    }

    // ---------------------------------------------------------------- AC-15 (unguarded on purpose)

    public function testRecodingRelabellingRequiredAndBoundsChangesAllSucceedAndLeaveEveryStoredRowByteIdentical(): void
    {
        $this->draftWith($this->allFiveElectricInputs());
        $before = $this->valueTable();
        $fender = $this->optionIdOf($this->brand, 'Fender');
        self::assertCount(5, $before);

        ($this->service(EditAttributeHandler::class))(new EditAttribute($this->strings->id()->toString(), 'Strings', 'string-count', false, 'pcs', 4, 12));
        ($this->service(EditAttributeHandler::class))(new EditAttribute($this->brand->id()->toString(), 'Make', 'brand', true));
        ($this->service(RelabelAttributeOptionHandler::class))(new RelabelAttributeOption($this->brand->id()->toString(), $fender, 'Fender Musical Instruments'));
        ($this->service(EditAttributeHandler::class))(new EditAttribute($this->condition->id()->toString(), 'Condition', 'condition', false));
        ($this->service(EditAttributeHandler::class))(new EditAttribute($this->strings->id()->toString(), 'Strings', 'string-count', false, 'pcs', 7, 8));

        self::assertSame($before, $this->valueTable());
        self::assertSame('string-count', $this->db->fetchOne('SELECT code FROM catalog_attribute WHERE id = ?', [$this->strings->id()->toString()]));
        self::assertSame('Make', $this->db->fetchOne('SELECT label FROM catalog_attribute WHERE id = ?', [$this->brand->id()->toString()]));
        self::assertSame('Fender Musical Instruments', $this->db->fetchOne('SELECT label FROM catalog_attribute_option WHERE id = ?', [$fender]));
    }
}
