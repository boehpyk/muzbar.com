<?php

declare(strict_types=1);

namespace App\Tests\Integration\Listing;

use App\Application\Catalog\Command\ArchiveAttribute;
use App\Application\Catalog\Command\ArchiveAttributeOption;
use App\Application\Catalog\Command\EditCategory;
use App\Application\Catalog\Command\RestoreAttribute;
use App\Application\Catalog\Command\RestoreAttributeOption;
use App\Application\Catalog\Handler\ArchiveAttributeHandler;
use App\Application\Catalog\Handler\ArchiveAttributeOptionHandler;
use App\Application\Catalog\Handler\EditCategoryHandler;
use App\Application\Catalog\Handler\RestoreAttributeHandler;
use App\Application\Catalog\Handler\RestoreAttributeOptionHandler;
use App\Application\Listing\Command\ReviseListing;
use App\Application\Listing\Handler\ReviseListingHandler;
use App\Domain\Catalog\Entity\Attribute;
use App\Domain\Catalog\Entity\Category;
use App\Domain\Catalog\Exception\CategoryHasListings;
use App\Domain\Catalog\Exception\CategoryMoveWouldDuplicateAttributeCode;
use App\Domain\Catalog\Port\AttributeOptionRepository;
use App\Domain\Catalog\Port\AttributeRepository;
use App\Domain\Catalog\Port\CategoryTreeLock;
use App\Domain\Catalog\ValueObject\CategoryName;
use App\Domain\Catalog\ValueObject\CategorySlug;
use App\Domain\Listing\Entity\Listing;
use App\Domain\Listing\Exception\InvalidAttributeValue;
use App\Domain\Listing\Exception\InvalidAttributeValueReason;
use App\Domain\Listing\Exception\ListingIncomplete;
use App\Domain\Listing\Port\Categories;
use App\Domain\Listing\Port\ListingRepository;
use App\Domain\Listing\ValueObject\AttributeId;
use App\Domain\Listing\ValueObject\AttributeInput;
use App\Domain\Listing\ValueObject\AttributeSchema;
use App\Domain\Listing\ValueObject\CategoryId;
use App\Domain\Listing\ValueObject\ListingId;
use App\Domain\Listing\ValueObject\ListingStatus;
use App\Domain\Listing\ValueObject\SellerId;
use App\Infrastructure\Catalog\Persistence\Doctrine\DbalAttributeSchema;
use App\Tests\Factory\AttributeFactory;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ListingFactory;
use App\Tests\Fixture\FrozenClock;
use App\Tests\Fixture\SpyDomainEventDispatcher;
use App\Tests\Support\BuildsTheValuesTree;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * listing-schema-drift AC-9, AC-10 and AC-14 (C-28, L-17, C-27 retired), driven through the real
 * handlers against the real database (DAMA). The spec's fixture tree: the values tree plus a new
 * root **Vintage** (no listings) defining `era` (text, required). Ann's published draft **D** is on
 * Electric with condition = Used, brand = Fender, strings = 6; the archive Clock is frozen at T.
 *
 * "Rows of D byte-identical" compares raw `SELECT *` snapshots of D's listing row and value rows.
 * Every read-back that must reflect a handler's write clears the EntityManager and fetches the
 * repository afresh first (CLAUDE.md: the identity map serves pre-write objects otherwise).
 */
final class SchemaDriftTest extends KernelTestCase
{
    use BuildsTheValuesTree;

    private const string T = '2026-11-21 10:00:00';

    private Connection $db;
    private EntityManagerInterface $em;
    private Category $vintage;
    private Attribute $era;
    private ListingId $d;
    private string $ann;
    private string $fender;
    private string $gibson;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = $this->service(Connection::class);
        $this->em = $this->service(EntityManagerInterface::class);

        $this->buildTheValuesTree();
        $this->vintage = CategoryFactory::createOne(['name' => CategoryName::fromString('Vintage'), 'slug' => CategorySlug::fromString('vintage')]);
        $this->era = AttributeFactory::new(['required' => true])->inCategory($this->vintage)->coded('era')->labelled('Era')->text()->create();

        $this->ann = Uuid::v7()->toRfc4122();
        $this->fender = $this->optionIdOf($this->brand, 'Fender');
        $this->gibson = $this->optionIdOf($this->brand, 'Gibson');
        $this->d = ListingFactory::new()->inCategory($this->electric)
            ->soldBy(SellerId::fromString($this->ann))
            ->withValues([
                $this->condition->id()->toString() => $this->optionIdOf($this->condition, 'Used'),
                $this->brand->id()->toString() => $this->fender,
                $this->strings->id()->toString() => '6',
            ])
            ->published(self::t())
            ->create()->id();

        self::assertCount(3, $this->rowsOfD()[1], 'D must hold three value rows, or every "byte-identical" below is vacuous.');
    }

    private static function t(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::T, new \DateTimeZone('UTC'));
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

    // ------------------------------------------------------------------ the four use cases, Clock frozen at T

    private function archiveAttribute(Attribute $attribute): void
    {
        $handler = new ArchiveAttributeHandler($this->service(AttributeRepository::class), $this->service(CategoryTreeLock::class), new FrozenClock(self::t()));
        $handler(new ArchiveAttribute($attribute->id()->toString()));
    }

    private function restoreAttribute(Attribute $attribute): void
    {
        ($this->service(RestoreAttributeHandler::class))(new RestoreAttribute($attribute->id()->toString()));
    }

    private function archiveOption(Attribute $attribute, string $optionId): void
    {
        $handler = new ArchiveAttributeOptionHandler(
            $this->service(AttributeRepository::class),
            $this->service(AttributeOptionRepository::class),
            $this->service(CategoryTreeLock::class),
            new FrozenClock(self::t()),
        );
        $handler(new ArchiveAttributeOption($attribute->id()->toString(), $optionId));
    }

    private function restoreOption(Attribute $attribute, string $optionId): void
    {
        ($this->service(RestoreAttributeOptionHandler::class))(new RestoreAttributeOption($attribute->id()->toString(), $optionId));
    }

    private function move(Category $category, Category $under): void
    {
        ($this->service(EditCategoryHandler::class))(new EditCategory($category->id()->toString(), $category->name()->toString(), $category->slug()->toString(), $under->id()->toString()));
    }

    /**
     * @param array<string, string> $attributes
     */
    private function revise(array $attributes, string $title = 'Edited title'): void
    {
        $handler = new ReviseListingHandler(
            $this->service(ListingRepository::class),
            $this->service(Categories::class),
            new FrozenClock(self::t()),
            new SpyDomainEventDispatcher(),
        );
        $handler(new ReviseListing($this->d->toString(), $this->ann, $this->electric->id()->toString(), $title, 'Desc', '1234.50', 'EUR', $attributes, false));
    }

    // ------------------------------------------------------------------ read helpers

    /**
     * @return array{list<array<string, mixed>>, list<array<string, mixed>>}
     */
    private function rowsOfD(): array
    {
        return [
            $this->db->fetchAllAssociative('SELECT * FROM listing_listing WHERE id = ?', [$this->d->toString()]),
            $this->db->fetchAllAssociative('SELECT * FROM listing_attribute_value WHERE listing_id = ? ORDER BY attribute_id', [$this->d->toString()]),
        ];
    }

    /**
     * @return list<string> the attribute ids D stores a value for, sorted
     */
    private function storedAttributeIdsOfD(): array
    {
        /** @var list<string> $ids */
        $ids = $this->db->fetchFirstColumn('SELECT attribute_id FROM listing_attribute_value WHERE listing_id = ? ORDER BY attribute_id', [$this->d->toString()]);

        return $ids;
    }

    private function storedOptionOfD(Attribute $attribute): mixed
    {
        return $this->db->fetchOne('SELECT option_id FROM listing_attribute_value WHERE listing_id = ? AND attribute_id = ?', [$this->d->toString(), $attribute->id()->toString()]);
    }

    private function freshD(): Listing
    {
        $this->em->clear();

        return $this->service(ListingRepository::class)->get($this->d);
    }

    private function schemaOf(Category $category): AttributeSchema
    {
        return $this->service(Categories::class)->schemaOf(CategoryId::fromString($category->id()->toString()));
    }

    private function inForce(AttributeSchema $schema, Attribute $attribute): bool
    {
        return $schema->has(AttributeId::fromString($attribute->id()->toString()));
    }

    private function accepts(AttributeSchema $schema, Attribute $attribute, string $raw): bool
    {
        try {
            return !$schema->read(AttributeInput::fromArray([$attribute->id()->toString() => $raw]))->isEmpty();
        } catch (InvalidAttributeValue) {
            return false;
        }
    }

    /**
     * @return list<string> the codes of the category's effective schema, in the read model's order
     */
    private function effectiveCodes(Category $category): array
    {
        return array_map(
            static fn (array $row): string => $row['code'],
            $this->service(DbalAttributeSchema::class)->effectiveFor($category->id()->toString()),
        );
    }

    /**
     * @return array<string, string> what the seller form sends for Electric today: condition and strings, no brand
     */
    private function inputsWithoutBrand(): array
    {
        return [$this->condition->id()->toString() => $this->optionIdOf($this->condition, 'Used'), $this->strings->id()->toString() => '6'];
    }

    // ================================================================== AC-9: archive an attribute in use

    public function testArchivingAnAttributeInUseStampsTheClockAndTouchesNoListingRow(): void
    {
        $before = $this->rowsOfD();

        $this->archiveAttribute($this->brand);

        $archivedAt = $this->db->fetchOne('SELECT archived_at FROM catalog_attribute WHERE id = ?', [$this->brand->id()->toString()]);
        self::assertIsString($archivedAt);
        self::assertSame(self::t()->getTimestamp(), (new \DateTimeImmutable($archivedAt))->getTimestamp());
        self::assertSame($before, $this->rowsOfD());
        self::assertSame(ListingStatus::Published, $this->freshD()->status());
    }

    public function testAnArchivedAttributeLeavesTheActiveSchemaAndTheEffectiveSchemaButStaysListedOnItsDefiningCategory(): void
    {
        $this->archiveAttribute($this->brand);

        $schema = $this->schemaOf($this->electric);
        self::assertFalse($this->inForce($schema, $this->brand));
        self::assertTrue($this->inForce($schema, $this->condition));
        self::assertNotContains('brand', $this->effectiveCodes($this->electric));
        self::assertContains('condition', $this->effectiveCodes($this->electric));

        $archived = $this->service(DbalAttributeSchema::class)->archivedDefinedOn($this->guitars->id()->toString());
        self::assertSame(['brand'], array_map(static fn (array $row): string => $row['code'], $archived));
        self::assertSame([], $this->service(DbalAttributeSchema::class)->archivedDefinedOn($this->electric->id()->toString()));
    }

    public function testASellerSaveAfterTheArchiveKeepsThePublishedListingAndItsDormantBrandValue(): void
    {
        $this->archiveAttribute($this->brand);

        $this->revise($this->inputsWithoutBrand(), 'Fixed a typo');

        $listing = $this->freshD();
        self::assertSame('Fixed a typo', $listing->title()->toString());
        self::assertSame(ListingStatus::Published, $listing->status());
        self::assertSame($this->fender, $this->storedOptionOfD($this->brand));
    }

    public function testRestoringAnAttributeBringsItsValueBackIntoForceAndWritesNothingElse(): void
    {
        $before = $this->rowsOfD();
        $this->archiveAttribute($this->brand);

        $this->restoreAttribute($this->brand);

        self::assertSame($before, $this->rowsOfD());
        self::assertNull($this->db->fetchOne('SELECT archived_at FROM catalog_attribute WHERE id = ?', [$this->brand->id()->toString()]));
        $schema = $this->schemaOf($this->electric);
        self::assertTrue($this->inForce($schema, $this->brand));
        $inForce = $this->freshD()->attributeValues()->onlyFor($schema)->for(AttributeId::fromString($this->brand->id()->toString()));
        self::assertSame($this->fender, $inForce?->optionId()?->toString());
    }

    public function testRestoringAfterASellerSaveStillFindsTheFenderTheSellerNeverSaw(): void
    {
        $this->archiveAttribute($this->brand);
        $this->revise($this->inputsWithoutBrand());

        $this->restoreAttribute($this->brand);

        $inForce = $this->freshD()->attributeValues()->onlyFor($this->schemaOf($this->electric))->for(AttributeId::fromString($this->brand->id()->toString()));
        self::assertSame($this->fender, $inForce?->optionId()?->toString());
    }

    // ================================================================== AC-10: archive an option in use

    public function testArchivingAnOptionInUseTouchesNoListingRowAndNarrowsTheRule(): void
    {
        $before = $this->rowsOfD();

        $this->archiveOption($this->brand, $this->fender);

        self::assertSame($before, $this->rowsOfD());
        $schema = $this->schemaOf($this->electric);
        self::assertTrue($this->accepts($schema, $this->brand, $this->gibson));
        self::assertFalse($this->accepts($schema, $this->brand, $this->fender));
    }

    public function testTheOptionListOffersGibsonOnlyUnlessTheSellersStoredFenderIsKept(): void
    {
        $this->archiveOption($this->brand, $this->fender);
        $schema = $this->service(DbalAttributeSchema::class);
        $brand = $this->brand->id()->toString();

        $plain = $schema->optionsFor([$brand]);
        self::assertSame([$this->gibson], array_column($plain[$brand], 'id'));

        $kept = $schema->optionsFor([$brand], [$this->fender]);
        $byId = array_column($kept[$brand], 'archived', 'id');
        self::assertEqualsCanonicalizing([$this->fender, $this->gibson], array_keys($byId));
        self::assertTrue($byId[$this->fender]);
        self::assertFalse($byId[$this->gibson]);
    }

    public function testASellerSaveKeepingTheArchivedFenderIsRefusedAndWritesNothing(): void
    {
        $this->archiveOption($this->brand, $this->fender);
        $before = $this->rowsOfD();

        try {
            $this->revise([...$this->inputsWithoutBrand(), $this->brand->id()->toString() => $this->fender], 'Not saved');
            self::fail('A stale archived option was saved.');
        } catch (InvalidAttributeValue $e) {
            self::assertSame(InvalidAttributeValueReason::NotAnOption, $e->reason());
            self::assertSame($this->brand->id()->toString(), $e->attributeId()?->toString());
        }

        self::assertSame($before, $this->rowsOfD());
    }

    public function testASellerSaveWithGibsonInsteadIsSaved(): void
    {
        $this->archiveOption($this->brand, $this->fender);

        $this->revise([...$this->inputsWithoutBrand(), $this->brand->id()->toString() => $this->gibson]);

        self::assertSame($this->gibson, $this->storedOptionOfD($this->brand));
    }

    public function testRestoringTheOptionOffersItAgainAndWritesNothing(): void
    {
        $before = $this->rowsOfD();
        $this->archiveOption($this->brand, $this->fender);

        $this->restoreOption($this->brand, $this->fender);

        self::assertSame($before, $this->rowsOfD());
        self::assertTrue($this->accepts($this->schemaOf($this->electric), $this->brand, $this->fender));
    }

    // ================================================================== AC-14: the move (C-27 retired)

    public function testMovingTheValueHoldingListedOnCategoryUnderAnotherRootSucceedsAndTouchesNoRow(): void
    {
        $before = $this->rowsOfD();

        $this->move($this->electric, $this->vintage);

        self::assertSame($this->vintage->id()->toString(), $this->db->fetchOne('SELECT parent_id FROM catalog_category WHERE id = ?', [$this->electric->id()->toString()]));
        self::assertSame($before, $this->rowsOfD());
        self::assertSame(ListingStatus::Published, $this->freshD()->status());
    }

    public function testAfterTheMoveElectricsSchemaIsEraStringsHasCaseSerialAndConditionAndBrandAreDormant(): void
    {
        $this->move($this->electric, $this->vintage);

        $schema = $this->schemaOf($this->electric);
        self::assertTrue($this->inForce($schema, $this->era));
        self::assertTrue($this->inForce($schema, $this->strings));
        self::assertTrue($this->inForce($schema, $this->hasCase));
        self::assertTrue($this->inForce($schema, $this->serial));
        self::assertFalse($this->inForce($schema, $this->condition));
        self::assertFalse($this->inForce($schema, $this->brand));
        self::assertEqualsCanonicalizing(['era', 'strings', 'has-case', 'serial'], $this->effectiveCodes($this->electric));
    }

    public function testASaveAfterTheMoveWithoutEraNamesEraAndWritesNothing(): void
    {
        $this->move($this->electric, $this->vintage);
        $before = $this->rowsOfD();

        try {
            $this->revise([$this->strings->id()->toString() => '6']);
            self::fail('A published listing was saved without the new required era.');
        } catch (ListingIncomplete $e) {
            self::assertSame([$this->era->id()->toString()], array_map(static fn (AttributeId $a): string => $a->toString(), $e->attributeIds()));
        }

        self::assertSame($before, $this->rowsOfD());
    }

    public function testASaveAfterTheMoveWithEraIsSavedAndKeepsTheDormantConditionAndBrand(): void
    {
        $this->move($this->electric, $this->vintage);

        $this->revise([$this->strings->id()->toString() => '6', $this->era->id()->toString() => '1970s']);

        self::assertSame(ListingStatus::Published, $this->freshD()->status());
        self::assertEqualsCanonicalizing(
            array_map(static fn (Attribute $a): string => $a->id()->toString(), [$this->condition, $this->brand, $this->strings, $this->era]),
            $this->storedAttributeIdsOfD(),
        );
        self::assertSame($this->fender, $this->storedOptionOfD($this->brand));
    }

    public function testMovingBackWakesConditionAndBrandAndLeavesErasValueDormant(): void
    {
        $this->move($this->electric, $this->vintage);
        $this->revise([$this->strings->id()->toString() => '6', $this->era->id()->toString() => '1970s']);

        $this->move($this->electric, $this->guitars);

        $schema = $this->schemaOf($this->electric);
        self::assertTrue($this->inForce($schema, $this->condition));
        self::assertTrue($this->inForce($schema, $this->brand));
        self::assertFalse($this->inForce($schema, $this->era));
        $values = $this->freshD()->attributeValues();
        self::assertSame($this->fender, $values->onlyFor($schema)->for(AttributeId::fromString($this->brand->id()->toString()))?->optionId()?->toString());
        self::assertSame('1970s', $values->notIn($schema)->for(AttributeId::fromString($this->era->id()->toString()))?->text());
    }

    public function testMovingUnderAListedOnCategoryIsStillRefusedByC24(): void
    {
        $this->expectException(CategoryHasListings::class);

        $this->move($this->bass, $this->electric);
    }

    public function testMovingACategoryThatDefinesABrandUnderGuitarsIsStillRefusedByC21(): void
    {
        $other = CategoryFactory::createOne();
        AttributeFactory::new()->inCategory($other)->coded('brand')->text()->create();

        $this->expectException(CategoryMoveWouldDuplicateAttributeCode::class);

        $this->move($other, $this->guitars);
    }
}
