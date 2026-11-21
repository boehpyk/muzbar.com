<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Application\Catalog\Command\AddAttributeOption;
use App\Application\Catalog\Command\ArchiveAttribute;
use App\Application\Catalog\Command\ArchiveAttributeOption;
use App\Application\Catalog\Command\DefineAttribute;
use App\Application\Catalog\Command\DeleteAttribute;
use App\Application\Catalog\Command\EditAttribute;
use App\Application\Catalog\Command\EditCategory;
use App\Application\Catalog\Command\RemoveAttributeOption;
use App\Application\Catalog\Command\RestoreAttribute;
use App\Application\Catalog\Command\RestoreAttributeOption;
use App\Application\Catalog\Handler\AddAttributeOptionHandler;
use App\Application\Catalog\Handler\ArchiveAttributeHandler;
use App\Application\Catalog\Handler\ArchiveAttributeOptionHandler;
use App\Application\Catalog\Handler\DefineAttributeHandler;
use App\Application\Catalog\Handler\DeleteAttributeHandler;
use App\Application\Catalog\Handler\EditAttributeHandler;
use App\Application\Catalog\Handler\EditCategoryHandler;
use App\Application\Catalog\Handler\RemoveAttributeOptionHandler;
use App\Application\Catalog\Handler\RestoreAttributeHandler;
use App\Application\Catalog\Handler\RestoreAttributeOptionHandler;
use App\Domain\Catalog\Entity\Attribute;
use App\Domain\Catalog\Entity\Category;
use App\Domain\Catalog\Exception\AttributeCodeAlreadyTaken;
use App\Domain\Catalog\Exception\AttributeInUse;
use App\Domain\Catalog\Exception\AttributeNeedsAnOption;
use App\Domain\Catalog\Exception\AttributeNotFound;
use App\Domain\Catalog\Exception\AttributeOptionNotFound;
use App\Domain\Catalog\Exception\CategoryMoveWouldDuplicateAttributeCode;
use App\Domain\Catalog\Exception\DuplicateAttributeOption;
use App\Domain\Catalog\Port\AttributeOptionRepository;
use App\Domain\Catalog\Port\AttributeRepository;
use App\Domain\Catalog\Port\CategoryTreeLock;
use App\Domain\Listing\ValueObject\SellerId;
use App\Tests\Factory\AttributeFactory;
use App\Tests\Factory\AttributeOptionFactory;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ListingFactory;
use App\Tests\Fixture\FrozenClock;
use App\Tests\Support\BuildsTheValuesTree;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * listing-schema-drift AC-11 (C-18 counts active options), AC-12 (hard delete survives for unused
 * rows only), AC-13 (C-29: archived names stay reserved, restore is never refused), and C-28's
 * "archive keeps the first instant" through the handlers — the four new use cases and the two
 * amended ones, against the real database (DAMA).
 */
final class ArchiveHandlersTest extends KernelTestCase
{
    use BuildsTheValuesTree;

    private Connection $db;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = $this->service(Connection::class);
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

    private static function at(int $plusSeconds = 0): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-21 10:00:00', new \DateTimeZone('UTC'))->modify(\sprintf('+%d seconds', $plusSeconds));
    }

    private function archiveAttribute(Attribute $attribute, int $plusSeconds = 0): void
    {
        $handler = new ArchiveAttributeHandler($this->service(AttributeRepository::class), $this->service(CategoryTreeLock::class), new FrozenClock(self::at($plusSeconds)));
        $handler(new ArchiveAttribute($attribute->id()->toString()));
    }

    private function archiveOption(Attribute $attribute, string $label, int $plusSeconds = 0): void
    {
        $handler = new ArchiveAttributeOptionHandler(
            $this->service(AttributeRepository::class),
            $this->service(AttributeOptionRepository::class),
            $this->service(CategoryTreeLock::class),
            new FrozenClock(self::at($plusSeconds)),
        );
        $handler(new ArchiveAttributeOption($attribute->id()->toString(), $this->optionIdOf($attribute, $label)));
    }

    private function restoreOption(Attribute $attribute, string $label): void
    {
        ($this->service(RestoreAttributeOptionHandler::class))(new RestoreAttributeOption($attribute->id()->toString(), $this->optionIdOf($attribute, $label)));
    }

    private function removeOption(Attribute $attribute, string $label): void
    {
        ($this->service(RemoveAttributeOptionHandler::class))(new RemoveAttributeOption($attribute->id()->toString(), $this->optionIdOf($attribute, $label)));
    }

    private function deleteAttribute(Attribute $attribute): void
    {
        ($this->service(DeleteAttributeHandler::class))(new DeleteAttribute($attribute->id()->toString()));
    }

    private function attributeArchivedAt(Attribute $attribute): mixed
    {
        return $this->db->fetchOne('SELECT archived_at FROM catalog_attribute WHERE id = ?', [$attribute->id()->toString()]);
    }

    private function optionArchivedAt(Attribute $attribute, string $label): mixed
    {
        return $this->db->fetchOne('SELECT archived_at FROM catalog_attribute_option WHERE id = ?', [$this->optionIdOf($attribute, $label)]);
    }

    private function rows(string $table): int
    {
        $count = $this->db->fetchOne('SELECT count(*) FROM '.$table);
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function draftHolding(Attribute $attribute, string $label): void
    {
        ListingFactory::new()->inCategory($this->electric)->soldBy(SellerId::fromString(Uuid::v7()->toRfc4122()))
            ->withValues([$attribute->id()->toString() => $this->optionIdOf($attribute, $label)])
            ->create();
    }

    // ================================================================== C-28 through the handlers

    public function testArchivingAnArchivedAttributeKeepsTheFirstInstantAndSucceeds(): void
    {
        $this->archiveAttribute($this->brand);
        $first = $this->attributeArchivedAt($this->brand);

        $this->archiveAttribute($this->brand, 60);

        self::assertNotNull($first);
        self::assertSame($first, $this->attributeArchivedAt($this->brand));
    }

    public function testArchivingAnArchivedOptionKeepsTheFirstInstantEvenWhenItIsTheLastActiveOneAround(): void
    {
        $this->archiveOption($this->brand, 'Fender');
        $first = $this->optionArchivedAt($this->brand, 'Fender');

        // Fender is archived, so C-18 is not asked: a double-submit is a no-op, not a refusal.
        $this->archiveOption($this->brand, 'Fender', 60);

        self::assertNotNull($first);
        self::assertSame($first, $this->optionArchivedAt($this->brand, 'Fender'));
    }

    public function testRestoringAnActiveAttributeOrOptionIsASilentNoOp(): void
    {
        ($this->service(RestoreAttributeHandler::class))(new RestoreAttribute($this->brand->id()->toString()));
        $this->restoreOption($this->brand, 'Fender');

        self::assertNull($this->attributeArchivedAt($this->brand));
        self::assertNull($this->optionArchivedAt($this->brand, 'Fender'));
    }

    public function testArchiveAndRestoreOfAnUnknownOrMalformedAttributeAreAttributeNotFound(): void
    {
        foreach ([Uuid::v7()->toRfc4122(), 'not-a-uuid'] as $id) {
            foreach ([
                fn () => ($this->service(RestoreAttributeHandler::class))(new RestoreAttribute($id)),
                fn () => (new ArchiveAttributeHandler($this->service(AttributeRepository::class), $this->service(CategoryTreeLock::class), new FrozenClock(self::at())))(new ArchiveAttribute($id)),
            ] as $call) {
                try {
                    $call();
                    self::fail('Unknown attribute id '.$id.' did not throw.');
                } catch (AttributeNotFound) {
                    $this->addToAssertionCount(1);
                }
            }
        }
    }

    public function testAnOptionOfAnotherAttributeIsOptionNotFoundOnBothOptionActions(): void
    {
        $foreign = $this->optionIdOf($this->condition, 'New');

        foreach ([
            fn () => ($this->service(RestoreAttributeOptionHandler::class))(new RestoreAttributeOption($this->brand->id()->toString(), $foreign)),
            fn () => (new ArchiveAttributeOptionHandler(
                $this->service(AttributeRepository::class),
                $this->service(AttributeOptionRepository::class),
                $this->service(CategoryTreeLock::class),
                new FrozenClock(self::at()),
            ))(new ArchiveAttributeOption($this->brand->id()->toString(), $foreign)),
        ] as $call) {
            try {
                $call();
                self::fail("Another attribute's option was acted on.");
            } catch (AttributeOptionNotFound) {
                $this->addToAssertionCount(1);
            }
        }

        self::assertNull($this->optionArchivedAt($this->condition, 'New'));
    }

    // ================================================================== AC-11: C-18 counts active options

    public function testArchivingTheLastActiveOptionIsRefusedAndNothingChanges(): void
    {
        $this->archiveOption($this->brand, 'Fender');

        try {
            $this->archiveOption($this->brand, 'Gibson');
            self::fail('The last active option was archived.');
        } catch (AttributeNeedsAnOption) {
            self::assertNull($this->optionArchivedAt($this->brand, 'Gibson'));
        }
    }

    public function testDeletingTheLastActiveOptionIsRefusedEvenThoughAnArchivedOneExists(): void
    {
        $this->archiveOption($this->brand, 'Fender');

        try {
            $this->removeOption($this->brand, 'Gibson');
            self::fail('The last active option was deleted.');
        } catch (AttributeNeedsAnOption) {
            self::assertSame(1, $this->db->fetchOne("SELECT count(*) FROM catalog_attribute_option WHERE attribute_id = ? AND label = 'Gibson'", [$this->brand->id()->toString()]));
        }
    }

    public function testDeletingAnArchivedUnusedOptionSucceedsAlthoughTheOtherIsThenTheOnlyOption(): void
    {
        $this->archiveOption($this->brand, 'Fender');

        $this->removeOption($this->brand, 'Fender');

        self::assertSame(1, $this->db->fetchOne('SELECT count(*) FROM catalog_attribute_option WHERE attribute_id = ?', [$this->brand->id()->toString()]));
    }

    public function testArchivingOneOfTwoActiveOptionsSucceedsWhenAThirdIsAlreadyArchived(): void
    {
        $finish = AttributeFactory::new()->inCategory($this->electric)->coded('finish')->choice(['Matte', 'Gloss'])->create();
        AttributeOptionFactory::new()->forAttribute($finish)->labelled('Satin')->archived()->create();

        $this->archiveOption($finish, 'Gloss');

        self::assertNotNull($this->optionArchivedAt($finish, 'Gloss'));
        self::assertNull($this->optionArchivedAt($finish, 'Matte'));
    }

    public function testRestoringTheArchivedOptionMakesTheNextArchiveLegalAgain(): void
    {
        $this->archiveOption($this->brand, 'Fender');
        $this->restoreOption($this->brand, 'Fender');

        $this->archiveOption($this->brand, 'Gibson');

        self::assertNotNull($this->optionArchivedAt($this->brand, 'Gibson'));
    }

    // ================================================================== AC-12: hard delete only for unused rows

    public function testDeletingAnActiveAttributeAListingHoldsAValueForIsRefusedAndNothingChanges(): void
    {
        $this->draftHolding($this->brand, 'Fender');
        $attributes = $this->rows('catalog_attribute');
        $options = $this->rows('catalog_attribute_option');

        $this->expectException(AttributeInUse::class);

        try {
            $this->deleteAttribute($this->brand);
        } finally {
            self::assertSame($attributes, $this->rows('catalog_attribute'));
            self::assertSame($options, $this->rows('catalog_attribute_option'));
        }
    }

    public function testDeletingAnArchivedAttributeAListingHoldsAValueForIsRefusedToo(): void
    {
        $this->draftHolding($this->brand, 'Fender');
        $this->archiveAttribute($this->brand);

        $this->expectException(AttributeInUse::class);

        $this->deleteAttribute($this->brand);
    }

    public function testDeletingAnAttributeWhoseOnlyValuesAreDormantIsRefused(): void
    {
        $this->draftHolding($this->brand, 'Fender');
        $vintage = CategoryFactory::createOne();
        $this->move($this->electric, $vintage);

        $this->expectException(AttributeInUse::class);

        $this->deleteAttribute($this->brand);
    }

    public function testRemovingAnOptionAListingHoldsIsRefusedWhetherActiveOrArchived(): void
    {
        $this->draftHolding($this->brand, 'Fender');

        foreach ([false, true] as $archived) {
            if ($archived) {
                $this->archiveOption($this->brand, 'Fender');
            }

            try {
                $this->removeOption($this->brand, 'Fender');
                self::fail('An option in use was deleted (archived: '.var_export($archived, true).').');
            } catch (AttributeInUse) {
                self::assertSame(1, $this->db->fetchOne("SELECT count(*) FROM catalog_attribute_option WHERE attribute_id = ? AND label = 'Fender'", [$this->brand->id()->toString()]));
            }
        }
    }

    public function testDeletingAnArchivedAttributeNobodyUsesRemovesItAndSweepsItsOptions(): void
    {
        $this->archiveAttribute($this->brand);

        $this->deleteAttribute($this->brand);

        self::assertSame(0, $this->db->fetchOne('SELECT count(*) FROM catalog_attribute WHERE id = ?', [$this->brand->id()->toString()]));
        self::assertSame(0, $this->db->fetchOne('SELECT count(*) FROM catalog_attribute_option WHERE attribute_id = ?', [$this->brand->id()->toString()]));
    }

    private function move(Category $category, Category $under): void
    {
        ($this->service(EditCategoryHandler::class))(new EditCategory($category->id()->toString(), $category->name()->toString(), $category->slug()->toString(), $under->id()->toString()));
    }

    // ================================================================== AC-13: names stay reserved (C-29)

    public function testDefiningACodeHeldByAnArchivedAttributeOnTheBranchIsRefused(): void
    {
        $this->archiveAttribute($this->brand);

        $this->expectException(AttributeCodeAlreadyTaken::class);

        ($this->service(DefineAttributeHandler::class))(new DefineAttribute($this->electric->id()->toString(), 'text', 'Brand', 'brand', false));
    }

    public function testRecodingToACodeHeldByAnArchivedAttributeOnTheBranchIsRefused(): void
    {
        $this->archiveAttribute($this->brand);

        $this->expectException(AttributeCodeAlreadyTaken::class);

        ($this->service(EditAttributeHandler::class))(new EditAttribute($this->serial->id()->toString(), 'Serial', 'brand', false));
    }

    public function testMovingACategoryThatDefinesTheCodeOfAnArchivedAttributeUnderItsBranchIsRefused(): void
    {
        $this->archiveAttribute($this->brand);
        $other = CategoryFactory::createOne();
        AttributeFactory::new()->inCategory($other)->coded('brand')->text()->create();

        $this->expectException(CategoryMoveWouldDuplicateAttributeCode::class);

        $this->move($other, $this->guitars);
    }

    public function testAddingAnOptionWhoseLabelMatchesAnArchivedOptionIsRefused(): void
    {
        $this->archiveOption($this->brand, 'Fender');

        $this->expectException(DuplicateAttributeOption::class);

        ($this->service(AddAttributeOptionHandler::class))(new AddAttributeOption($this->brand->id()->toString(), 'fender'));
    }

    public function testRestoreIsNeverRefusedInAnyOfTheStatesThatRefusedANewName(): void
    {
        $this->archiveAttribute($this->brand);
        $this->archiveOption($this->condition, 'New');

        // Every door the reserved names closed has been tried against them; restore needs no facts.
        foreach ([
            fn () => ($this->service(DefineAttributeHandler::class))(new DefineAttribute($this->electric->id()->toString(), 'text', 'Brand', 'brand', false)),
            fn () => ($this->service(AddAttributeOptionHandler::class))(new AddAttributeOption($this->condition->id()->toString(), 'new')),
        ] as $refused) {
            try {
                $refused();
                self::fail('A reserved name was taken.');
            } catch (AttributeCodeAlreadyTaken|DuplicateAttributeOption) {
                $this->addToAssertionCount(1);
            }
        }

        ($this->service(RestoreAttributeHandler::class))(new RestoreAttribute($this->brand->id()->toString()));
        $this->restoreOption($this->condition, 'New');

        self::assertNull($this->attributeArchivedAt($this->brand));
        self::assertNull($this->optionArchivedAt($this->condition, 'New'));
    }
}
