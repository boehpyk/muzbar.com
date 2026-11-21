<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Application\Catalog\Command\CreateCategory;
use App\Application\Catalog\Command\DeleteAttribute;
use App\Application\Catalog\Command\DeleteCategory;
use App\Application\Catalog\Command\EditCategory;
use App\Application\Catalog\Command\RemoveAttributeOption;
use App\Application\Catalog\Handler\CreateCategoryHandler;
use App\Application\Catalog\Handler\DeleteAttributeHandler;
use App\Application\Catalog\Handler\DeleteCategoryHandler;
use App\Application\Catalog\Handler\EditCategoryHandler;
use App\Application\Catalog\Handler\RemoveAttributeOptionHandler;
use App\Domain\Catalog\Exception\AttributeInUse;
use App\Domain\Catalog\Exception\CategoryHasListings;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ListingFactory;
use App\Tests\Support\BuildsTheValuesTree;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * listing-publish AC-14 — ADR-0019's "status is not yet a filter" watch item, discharged: Catalog's
 * guards (C-23 … C-27) count listings of **every** status. Each case below has no draft anywhere,
 * only the status under test, so a guard that filtered `status = 'draft'` would let the write through.
 */
final class StatusBlindGuardsTest extends KernelTestCase
{
    use BuildsTheValuesTree;

    private Connection $db;

    protected function setUp(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $this->db = $db;
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

    private function rows(string $table, string $where = 'true'): int
    {
        $n = $this->db->fetchOne(\sprintf('SELECT count(*) FROM %s WHERE %s', $table, $where));
        self::assertIsNumeric($n);

        return (int) $n;
    }

    /** The values tree with exactly one listing on Electric — of `$status`, holding brand = Fender — and no draft anywhere. */
    private function treeWithOnlyAListingOfStatus(string $status): void
    {
        $this->buildTheValuesTree();
        $factory = ListingFactory::new()->inCategory($this->electric)->withValues([
            $this->condition->id()->toString() => $this->optionIdOf($this->condition, 'Used'),
            $this->brand->id()->toString() => $this->optionIdOf($this->brand, 'Fender'),
        ]);
        ('published' === $status ? $factory->published() : $factory->withdrawn())->create();

        self::assertSame(1, $this->rows('listing_listing'));
        self::assertSame(1, $this->rows('listing_listing', \sprintf("status = '%s'", $status)));
    }

    /**
     * @return array{string}[]
     */
    public static function nonDraftStatuses(): array
    {
        return [['published'], ['withdrawn']];
    }

    /** C-23 — deleting a category with listings filed under it. A bare leaf: no C-13 in the way. */
    #[DataProvider('nonDraftStatuses')]
    public function testDeletingACategoryWithOnlyAListingOfThatStatusIsRefused(string $status): void
    {
        $leaf = CategoryFactory::createOne();
        $factory = ListingFactory::new()->inCategory($leaf);
        ('published' === $status ? $factory->published() : $factory->withdrawn())->create();

        try {
            ($this->service(DeleteCategoryHandler::class))(new DeleteCategory($leaf->id()->toString()));
            self::fail('A category with a '.$status.' listing was deleted.');
        } catch (CategoryHasListings) {
            self::assertSame(1, $this->rows('catalog_category', \sprintf("id = '%s'", $leaf->id()->toString())));
        }
    }

    /** C-24 — giving a listed-on category a child. */
    #[DataProvider('nonDraftStatuses')]
    public function testCreatingAChildUnderACategoryWithOnlyAListingOfThatStatusIsRefused(string $status): void
    {
        $leaf = CategoryFactory::createOne();
        $factory = ListingFactory::new()->inCategory($leaf);
        ('published' === $status ? $factory->published() : $factory->withdrawn())->create();
        $categories = $this->rows('catalog_category');

        try {
            ($this->service(CreateCategoryHandler::class))(new CreateCategory('Solid body', 'solid-body', $leaf->id()->toString()));
            self::fail('A child was created under a category with a '.$status.' listing.');
        } catch (CategoryHasListings) {
            self::assertSame($categories, $this->rows('catalog_category'));
        }
    }

    /** C-25 — deleting an attribute some listing of that status holds a value for. */
    #[DataProvider('nonDraftStatuses')]
    public function testDeletingAnAttributeThatOnlyAListingOfThatStatusHoldsIsRefused(string $status): void
    {
        $this->treeWithOnlyAListingOfStatus($status);

        try {
            ($this->service(DeleteAttributeHandler::class))(new DeleteAttribute($this->brand->id()->toString()));
            self::fail('A brand in use by a '.$status.' listing was deleted.');
        } catch (AttributeInUse) {
            self::assertSame(1, $this->rows('catalog_attribute', \sprintf("id = '%s'", $this->brand->id()->toString())));
        }
    }

    /** C-26 — removing an option a listing of that status holds. */
    #[DataProvider('nonDraftStatuses')]
    public function testRemovingAnOptionThatOnlyAListingOfThatStatusHoldsIsRefused(string $status): void
    {
        $this->treeWithOnlyAListingOfStatus($status);
        $fender = $this->optionIdOf($this->brand, 'Fender');

        try {
            ($this->service(RemoveAttributeOptionHandler::class))(new RemoveAttributeOption($this->brand->id()->toString(), $fender));
            self::fail('An option in use by a '.$status.' listing was removed.');
        } catch (AttributeInUse) {
            self::assertSame(1, $this->rows('catalog_attribute_option', \sprintf("id = '%s'", $fender)));
        }
    }

    /** C-27 — moving a category whose subtree a listing of that status holds values in. */
    #[DataProvider('nonDraftStatuses')]
    public function testMovingACategoryWhoseSubtreeOnlyAListingOfThatStatusHoldsValuesInIsRefused(string $status): void
    {
        $this->treeWithOnlyAListingOfStatus($status);

        try {
            ($this->service(EditCategoryHandler::class))(new EditCategory($this->electric->id()->toString(), 'Electric', 'electric', $this->instruments->id()->toString()));
            self::fail('Electric was moved while a '.$status.' listing holds values.');
        } catch (CategoryHasListings $e) {
            self::assertTrue($e->isMove());
            self::assertSame($this->guitars->id()->toString(), $this->db->fetchOne('SELECT parent_id FROM catalog_category WHERE id = ?', [$this->electric->id()->toString()]));
        }
    }
}
