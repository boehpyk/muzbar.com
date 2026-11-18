<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Application\Catalog\Command\AddAttributeOption;
use App\Application\Catalog\Command\CreateCategory;
use App\Application\Catalog\Command\DefineAttribute;
use App\Application\Catalog\Command\DeleteAttribute;
use App\Application\Catalog\Command\DeleteCategory;
use App\Application\Catalog\Command\EditAttribute;
use App\Application\Catalog\Command\EditCategory;
use App\Application\Catalog\Command\RelabelAttributeOption;
use App\Application\Catalog\Command\RemoveAttributeOption;
use App\Application\Catalog\Handler\AddAttributeOptionHandler;
use App\Application\Catalog\Handler\CreateCategoryHandler;
use App\Application\Catalog\Handler\DefineAttributeHandler;
use App\Application\Catalog\Handler\DeleteAttributeHandler;
use App\Application\Catalog\Handler\DeleteCategoryHandler;
use App\Application\Catalog\Handler\EditAttributeHandler;
use App\Application\Catalog\Handler\EditCategoryHandler;
use App\Application\Catalog\Handler\RelabelAttributeOptionHandler;
use App\Application\Catalog\Handler\RemoveAttributeOptionHandler;
use App\Application\Listing\Command\DraftListing;
use App\Application\Listing\Command\ReviseListing;
use App\Application\Listing\Handler\DraftListingHandler;
use App\Application\Listing\Handler\ReviseListingHandler;
use App\Domain\Catalog\Port\CategoryTreeLock;
use App\Domain\Listing\Port\Categories;
use App\Domain\Listing\Port\ListingRepository;
use App\Infrastructure\Catalog\Integration\CategoriesForListing;
use App\Infrastructure\Catalog\Persistence\Doctrine\DoctrineAttributeOptionRepository;
use App\Infrastructure\Catalog\Persistence\Doctrine\DoctrineAttributeRepository;
use App\Infrastructure\Catalog\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Infrastructure\Catalog\Persistence\Doctrine\PostgresCategoryTreeLock;
use App\Infrastructure\Listing\Integration\ListingCategoryUsage;
use App\Tests\Factory\AttributeFactory;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ListingFactory;
use App\Tests\Fixture\ObservedAttributeOptionRepository;
use App\Tests\Fixture\ObservedAttributeRepository;
use App\Tests\Fixture\ObservedCategories;
use App\Tests\Fixture\ObservedCategoryRepository;
use App\Tests\Fixture\ObservedCategoryTreeLock;
use App\Tests\Fixture\ObservedCategoryUsage;
use App\Tests\Fixture\ObservedListingRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * AC-17 (slice 1) and AC-26 (slice 2): the tree lock is real, and every Catalog handler takes it.
 *
 * WHAT IS NOT ASSERTED: that the lock is *released* when `exclusively()` returns. The lock is
 * `pg_advisory_xact_lock`, held to the end of the surrounding transaction, and DAMA wraps the whole
 * test in one outer transaction that only the harness commits or rolls back. So under DAMA the lock
 * taken by the first `exclusively()` call is, by design, still held when the test body ends. Release
 * at commit is PostgreSQL's `_xact_` semantics, not our code, and nothing here could fail because of
 * a bug of ours. Asserting it would need a test outside DAMA, and a test asserting release under
 * DAMA could only pass by accident.
 *
 * The consequence for this file: the "lock is free" control runs *before* the first `exclusively()`.
 */
final class CategoryTreeLockTest extends KernelTestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $this->db = $db;
    }

    /**
     * A genuinely separate session: a new connection built from the same parameters (so it targets
     * `muzbar_test`), outside DAMA's static connection.
     */
    private function secondSession(): Connection
    {
        // @phpstan-ignore argument.type (getParams() is the documented way to clone a connection's settings)
        return DriverManager::getConnection($this->db->getParams());
    }

    private function probe(Connection $session): bool
    {
        $acquired = $session->fetchOne(
            'SELECT pg_try_advisory_xact_lock(:key)',
            ['key' => PostgresCategoryTreeLock::KEY],
            ['key' => ParameterType::INTEGER],
        );

        return true === $acquired;
    }

    public function testASecondSessionCanTakeTheLockWhenNobodyHoldsIt(): void
    {
        $session = $this->secondSession();

        try {
            self::assertTrue($this->probe($session), 'Control: the probe must be able to answer true.');
        } finally {
            $session->close();
        }
    }

    public function testASecondSessionCannotTakeTheLockWhileWorkRunsInsideExclusively(): void
    {
        $lock = self::getContainer()->get(CategoryTreeLock::class);
        self::assertInstanceOf(CategoryTreeLock::class, $lock);
        $session = $this->secondSession();

        try {
            $probed = $lock->exclusively(fn (): bool => $this->probe($session));
        } finally {
            $session->close();
        }

        self::assertFalse($probed, 'The tree lock was not held while the work ran.');
    }

    public function testExclusivelyReturnsTheWorksResult(): void
    {
        $lock = self::getContainer()->get(CategoryTreeLock::class);
        self::assertInstanceOf(CategoryTreeLock::class, $lock);

        self::assertSame('result', $lock->exclusively(static fn (): string => 'result'));
    }

    public function testExclusivelyRethrowsWhatTheWorkThrows(): void
    {
        $lock = self::getContainer()->get(CategoryTreeLock::class);
        self::assertInstanceOf(CategoryTreeLock::class, $lock);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('refused');

        $lock->exclusively(static fn (): never => throw new \DomainException('refused'));
    }

    /**
     * Swaps the two concrete services (never the port aliases: Symfony rewrites alias references to
     * their targets at compile time, so a handler is already wired to the concrete id) and takes
     * the handlers from the container, so a handler wired to some other lock would also fail here.
     *
     * @return array{ObservedCategoryTreeLock, ObservedCategoryRepository}
     */
    private function observe(): array
    {
        $container = self::getContainer();
        // The real adapters are built here, not fetched: a service the container has already
        // initialised can no longer be replaced.
        $em = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $observedLock = new ObservedCategoryTreeLock(new PostgresCategoryTreeLock($em));
        $observedRepository = new ObservedCategoryRepository(new DoctrineCategoryRepository($em), $observedLock);
        $container->set(PostgresCategoryTreeLock::class, $observedLock);
        $container->set(DoctrineCategoryRepository::class, $observedRepository);

        return [$observedLock, $observedRepository];
    }

    /**
     * @param list<array{string, bool}> $calls
     */
    private function assertEveryCallWasUnderTheLock(array $calls, string $mustInclude): void
    {
        $methods = array_column($calls, 0);
        self::assertContains($mustInclude, $methods, 'The handler never reached its write.');
        foreach ($calls as [$method, $held]) {
            self::assertTrue($held, \sprintf('%s() ran outside CategoryTreeLock::exclusively().', $method));
        }
    }

    public function testCreateCategoryHandlerReadsAndSavesInsideTheLock(): void
    {
        $guitars = CategoryFactory::createOne();
        [$lock, $repository] = $this->observe();
        $handler = self::getContainer()->get(CreateCategoryHandler::class);
        self::assertInstanceOf(CreateCategoryHandler::class, $handler);

        $handler(new CreateCategory('Electric', 'electric', $guitars->id()->toString()));

        self::assertSame(1, $lock->entries());
        $this->assertEveryCallWasUnderTheLock($repository->calls(), 'save');
    }

    public function testEditCategoryHandlerReadsAndSavesInsideTheLock(): void
    {
        $guitars = CategoryFactory::createOne();
        $electric = CategoryFactory::new()->childOf($guitars)->create();
        [$lock, $repository] = $this->observe();
        $handler = self::getContainer()->get(EditCategoryHandler::class);
        self::assertInstanceOf(EditCategoryHandler::class, $handler);

        $handler(new EditCategory($electric->id()->toString(), 'Renamed', 'renamed', null));

        self::assertSame(1, $lock->entries());
        $this->assertEveryCallWasUnderTheLock($repository->calls(), 'save');
        self::assertContains('subtreeHeightOf', array_column($repository->calls(), 0), 'The move was not exercised.');
    }

    public function testDeleteCategoryHandlerReadsAndRemovesInsideTheLock(): void
    {
        $leaf = CategoryFactory::createOne();
        [$lock, $repository] = $this->observe();
        $handler = self::getContainer()->get(DeleteCategoryHandler::class);
        self::assertInstanceOf(DeleteCategoryHandler::class, $handler);

        $handler(new DeleteCategory($leaf->id()->toString()));

        self::assertSame(1, $lock->entries());
        $this->assertEveryCallWasUnderTheLock($repository->calls(), 'remove');
    }

    /**
     * The attribute-side twin of `observe()`: the lock and all three repositories, swapped by
     * concrete service id before any handler is fetched.
     *
     * @return array{ObservedCategoryTreeLock, ObservedAttributeRepository, ObservedAttributeOptionRepository, ObservedCategoryRepository}
     */
    private function observeAttributes(): array
    {
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $lock = new ObservedCategoryTreeLock(new PostgresCategoryTreeLock($em));
        $categories = new ObservedCategoryRepository(new DoctrineCategoryRepository($em), $lock);
        $attributes = new ObservedAttributeRepository(new DoctrineAttributeRepository($em), $lock);
        $options = new ObservedAttributeOptionRepository(new DoctrineAttributeOptionRepository($em), $lock);
        $container->set(PostgresCategoryTreeLock::class, $lock);
        $container->set(DoctrineCategoryRepository::class, $categories);
        $container->set(DoctrineAttributeRepository::class, $attributes);
        $container->set(DoctrineAttributeOptionRepository::class, $options);

        return [$lock, $attributes, $options, $categories];
    }

    public function testDefineAttributeHandlerReadsAndSavesInsideTheLock(): void
    {
        $category = CategoryFactory::createOne();
        [$lock, $attributes, $options] = $this->observeAttributes();
        $handler = self::getContainer()->get(DefineAttributeHandler::class);
        self::assertInstanceOf(DefineAttributeHandler::class, $handler);

        $handler(new DefineAttribute($category->id()->toString(), 'choice', 'Orientation', 'orientation', false, optionLabels: ['Left', 'Right']));

        self::assertSame(1, $lock->entries());
        $this->assertEveryCallWasUnderTheLock($attributes->calls(), 'save');
        $this->assertEveryCallWasUnderTheLock($options->calls(), 'save');
    }

    public function testEditAttributeHandlerReadsAndSavesInsideTheLock(): void
    {
        $attribute = AttributeFactory::new()->inCategory(CategoryFactory::createOne())->create();
        [$lock, $attributes] = $this->observeAttributes();
        $handler = self::getContainer()->get(EditAttributeHandler::class);
        self::assertInstanceOf(EditAttributeHandler::class, $handler);

        $handler(new EditAttribute($attribute->id()->toString(), 'Renamed', 'renamed', true));

        self::assertSame(1, $lock->entries());
        $this->assertEveryCallWasUnderTheLock($attributes->calls(), 'save');
        self::assertContains('codesDefinedOn', array_column($attributes->calls(), 0), 'The C-21 branch read was not exercised.');
    }

    public function testAddAttributeOptionHandlerReadsAndSavesInsideTheLock(): void
    {
        $attribute = AttributeFactory::new()->choice(['Left'])->create();
        [$lock, $attributes, $options] = $this->observeAttributes();
        $handler = self::getContainer()->get(AddAttributeOptionHandler::class);
        self::assertInstanceOf(AddAttributeOptionHandler::class, $handler);

        $handler(new AddAttributeOption($attribute->id()->toString(), 'Right'));

        self::assertSame(1, $lock->entries());
        $this->assertEveryCallWasUnderTheLock($attributes->calls(), 'get');
        $this->assertEveryCallWasUnderTheLock($options->calls(), 'save');
        self::assertContains('isLabelTaken', array_column($options->calls(), 0), 'The label pre-check was not exercised.');
    }

    public function testRelabelAttributeOptionHandlerReadsAndSavesInsideTheLock(): void
    {
        $attribute = AttributeFactory::new()->choice(['Left'])->create();
        $optionId = $this->optionIdOf($attribute->id()->toString());
        [$lock, $attributes, $options] = $this->observeAttributes();
        $handler = self::getContainer()->get(RelabelAttributeOptionHandler::class);
        self::assertInstanceOf(RelabelAttributeOptionHandler::class, $handler);

        $handler(new RelabelAttributeOption($attribute->id()->toString(), $optionId, 'Sinistral'));

        self::assertSame(1, $lock->entries());
        $this->assertEveryCallWasUnderTheLock($attributes->calls(), 'get');
        $this->assertEveryCallWasUnderTheLock($options->calls(), 'save');
        self::assertContains('isLabelTaken', array_column($options->calls(), 0), 'The label pre-check was not exercised.');
    }

    public function testRemoveAttributeOptionHandlerReadsAndRemovesInsideTheLock(): void
    {
        $attribute = AttributeFactory::new()->choice(['Left', 'Right'])->create();
        $optionId = $this->optionIdOf($attribute->id()->toString());
        [$lock, $attributes, $options] = $this->observeAttributes();
        $handler = self::getContainer()->get(RemoveAttributeOptionHandler::class);
        self::assertInstanceOf(RemoveAttributeOptionHandler::class, $handler);

        $handler(new RemoveAttributeOption($attribute->id()->toString(), $optionId));

        self::assertSame(1, $lock->entries());
        $this->assertEveryCallWasUnderTheLock($attributes->calls(), 'get');
        $this->assertEveryCallWasUnderTheLock($options->calls(), 'remove');
        self::assertContains('countForAttribute', array_column($options->calls(), 0), 'The last-option count was not read.');
    }

    public function testDeleteAttributeHandlerRemovesAndSweepsInsideTheLock(): void
    {
        $attribute = AttributeFactory::new()->choice(['Left', 'Right'])->create();
        [$lock, $attributes, $options] = $this->observeAttributes();
        $handler = self::getContainer()->get(DeleteAttributeHandler::class);
        self::assertInstanceOf(DeleteAttributeHandler::class, $handler);

        $handler(new DeleteAttribute($attribute->id()->toString()));

        self::assertSame(1, $lock->entries());
        $this->assertEveryCallWasUnderTheLock($attributes->calls(), 'remove');
        $this->assertEveryCallWasUnderTheLock($options->calls(), 'removeAllForAttribute');
    }

    public function testDeleteCategoryHandlerReadsTheAttributeGuardInsideTheLock(): void
    {
        $leaf = CategoryFactory::createOne();
        [$lock, $attributes] = $this->observeAttributes();
        $handler = self::getContainer()->get(DeleteCategoryHandler::class);
        self::assertInstanceOf(DeleteCategoryHandler::class, $handler);

        $handler(new DeleteCategory($leaf->id()->toString()));

        self::assertSame(1, $lock->entries());
        $this->assertEveryCallWasUnderTheLock($attributes->calls(), 'existsForCategory');
    }

    /**
     * AC-21. C-21 is decided on facts, and a fact read outside `exclusively()` is a guess: each of
     * the three doors (define, recode, move) must read the branch's categories and codes *inside*
     * the lock, and the test names the reads so that dropping one cannot pass for "all calls held".
     *
     * @param list<array{string, bool}> $calls
     * @param list<string>              $reads
     */
    private function assertReadsWereUnderTheLock(array $calls, array $reads): void
    {
        foreach ($reads as $read) {
            $held = array_column(array_filter($calls, static fn (array $call): bool => $call[0] === $read), 1);
            self::assertNotSame([], $held, $read.'() was never called, so C-21 was decided without it.');
            self::assertNotContains(false, $held, $read.'() ran outside CategoryTreeLock::exclusively().');
        }
    }

    public function testDefineAttributeHandlerReadsTheBranchFactsInsideTheLock(): void
    {
        $parent = CategoryFactory::createOne();
        $category = CategoryFactory::new()->childOf($parent)->create();
        CategoryFactory::new()->childOf($category)->create();
        [$lock, $attributes, , $categories] = $this->observeAttributes();
        $handler = self::getContainer()->get(DefineAttributeHandler::class);
        self::assertInstanceOf(DefineAttributeHandler::class, $handler);

        $handler(new DefineAttribute($category->id()->toString(), 'text', 'Serial', 'serial', false));

        self::assertSame(1, $lock->entries());
        $this->assertReadsWereUnderTheLock($categories->calls(), ['lineageOf', 'descendantsOf']);
        $this->assertReadsWereUnderTheLock($attributes->calls(), ['codesDefinedOn']);
    }

    public function testEditAttributeHandlerReadsTheBranchFactsInsideTheLockWhenTheCodeChanges(): void
    {
        $category = CategoryFactory::createOne();
        $attribute = AttributeFactory::new()->inCategory($category)->coded('old-code')->create();
        [$lock, $attributes, , $categories] = $this->observeAttributes();
        $handler = self::getContainer()->get(EditAttributeHandler::class);
        self::assertInstanceOf(EditAttributeHandler::class, $handler);

        $handler(new EditAttribute($attribute->id()->toString(), 'New', 'new-code', false));

        self::assertSame(1, $lock->entries());
        $this->assertReadsWereUnderTheLock($categories->calls(), ['lineageOf', 'descendantsOf']);
        $this->assertReadsWereUnderTheLock($attributes->calls(), ['codesDefinedOn']);
    }

    public function testEditCategoryHandlerReadsTheMoveFactsInsideTheLockWhenTheParentChanges(): void
    {
        $oldParent = CategoryFactory::createOne();
        $moving = CategoryFactory::new()->childOf($oldParent)->create();
        CategoryFactory::new()->childOf($moving)->create();
        $newParent = CategoryFactory::createOne();
        [$lock, $attributes, , $categories] = $this->observeAttributes();
        $handler = self::getContainer()->get(EditCategoryHandler::class);
        self::assertInstanceOf(EditCategoryHandler::class, $handler);

        $handler(new EditCategory($moving->id()->toString(), $moving->name()->toString(), $moving->slug()->toString(), $newParent->id()->toString()));

        self::assertSame(1, $lock->entries());
        $this->assertReadsWereUnderTheLock($categories->calls(), ['lineageOf', 'subtreeHeightOf', 'descendantsOf']);
        $this->assertReadsWereUnderTheLock($attributes->calls(), ['codesDefinedOn']);
        $this->assertEveryCallWasUnderTheLock($categories->calls(), 'save');
    }

    private function optionIdOf(string $attributeId): string
    {
        $id = $this->db->fetchOne('SELECT id FROM catalog_attribute_option WHERE attribute_id = ? ORDER BY id LIMIT 1', [$attributeId]);
        self::assertIsString($id);

        return $id;
    }

    // ------------------------------------------------------------------------------------------
    // listing-draft: AC-18 (who calls what, inside which lock) and AC-19 (the two real modes).
    // ------------------------------------------------------------------------------------------

    /**
     * Swaps the real `CategoryUsage` adapter (by its concrete id, see `observe()`) for one that logs
     * whether `exclusively()` was active, on top of `observe()`'s lock and repository.
     *
     * @return array{ObservedCategoryTreeLock, ObservedCategoryUsage}
     */
    private function observeUsage(): array
    {
        [$lock] = $this->observe();
        $container = self::getContainer();
        $usage = new ObservedCategoryUsage(new ListingCategoryUsage($this->db), $lock);
        $container->set(ListingCategoryUsage::class, $usage);

        return [$lock, $usage];
    }

    /**
     * @return array{ObservedCategories, DraftListingHandler, ReviseListingHandler}
     */
    private function observeListingHandlers(): array
    {
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $repository = $container->get(ListingRepository::class);
        self::assertInstanceOf(ListingRepository::class, $repository);

        $categories = new ObservedCategories(new CategoriesForListing(new PostgresCategoryTreeLock($em), $this->db));
        $listings = new ObservedListingRepository($repository, $categories);

        return [$categories, new DraftListingHandler($listings, $categories), new ReviseListingHandler($listings, $categories)];
    }

    public function testDraftListingHandlerReadsTheStatusAndSavesInsideHoldSteady(): void
    {
        $leaf = CategoryFactory::createOne();
        [$categories, $draft] = $this->observeListingHandlers();

        $draft(new DraftListing(Uuid::v7()->toRfc4122(), $leaf->id()->toString(), 'Fender Precision V', '', '10', 'EUR'));

        self::assertSame(1, $categories->entries());
        $methods = array_column($categories->calls(), 0);
        self::assertContains('statusOf', $methods);
        self::assertContains('save', $methods);
        foreach ($categories->calls() as [$method, $held]) {
            self::assertTrue($held, \sprintf('%s() ran outside Categories::holdSteady().', $method));
        }
    }

    public function testReviseListingHandlerReadsLoadsAndSavesInsideHoldSteady(): void
    {
        $bass = CategoryFactory::createOne();
        $listing = ListingFactory::new()->inCategory(CategoryFactory::createOne())->create();
        [$categories, , $revise] = $this->observeListingHandlers();

        $revise(new ReviseListing($listing->id()->toString(), $listing->sellerId()->toString(), $bass->id()->toString(), 'Changed title', '', '10', 'EUR'));

        self::assertSame(1, $categories->entries());
        $methods = array_column($categories->calls(), 0);
        self::assertContains('get', $methods);
        self::assertContains('statusOf', $methods);
        self::assertContains('save', $methods);
        foreach ($categories->calls() as [$method, $held]) {
            self::assertTrue($held, \sprintf('%s() ran outside Categories::holdSteady().', $method));
        }
    }

    public function testDeleteCategoryHandlerReadsListingsInsideTheLock(): void
    {
        $leaf = CategoryFactory::createOne();
        [$lock, $usage] = $this->observeUsage();
        $handler = self::getContainer()->get(DeleteCategoryHandler::class);
        self::assertInstanceOf(DeleteCategoryHandler::class, $handler);

        $handler(new DeleteCategory($leaf->id()->toString()));

        self::assertSame(1, $lock->entries());
        self::assertSame([['hasListings', true]], $usage->calls());
    }

    public function testCreateCategoryHandlerReadsListingsInsideTheLockWhenThereIsAParent(): void
    {
        $parent = CategoryFactory::createOne();
        [$lock, $usage] = $this->observeUsage();
        $handler = self::getContainer()->get(CreateCategoryHandler::class);
        self::assertInstanceOf(CreateCategoryHandler::class, $handler);

        $handler(new CreateCategory('Electric', 'electric', $parent->id()->toString()));

        self::assertSame(1, $lock->entries());
        self::assertSame([['hasListings', true]], $usage->calls());
    }

    public function testCreateCategoryHandlerReadsNoListingsForARoot(): void
    {
        [$lock, $usage] = $this->observeUsage();
        $handler = self::getContainer()->get(CreateCategoryHandler::class);
        self::assertInstanceOf(CreateCategoryHandler::class, $handler);

        $handler(new CreateCategory('Guitars', 'guitars', null));

        self::assertSame(1, $lock->entries(), 'The root create did not run (a vacuous pass otherwise).');
        self::assertSame([], $usage->calls());
    }

    public function testEditCategoryHandlerReadsListingsInsideTheLockWhenTheParentChanges(): void
    {
        $oldParent = CategoryFactory::createOne();
        $moving = CategoryFactory::new()->childOf($oldParent)->create();
        $newParent = CategoryFactory::createOne();
        [$lock, $usage] = $this->observeUsage();
        $handler = self::getContainer()->get(EditCategoryHandler::class);
        self::assertInstanceOf(EditCategoryHandler::class, $handler);

        $handler(new EditCategory($moving->id()->toString(), $moving->name()->toString(), $moving->slug()->toString(), $newParent->id()->toString()));

        self::assertSame(1, $lock->entries());
        // listing-attribute-values (C-27) adds the second read on purpose, after C-24's.
        self::assertSame([['hasListings', true], ['hasAttributeValues', true]], $usage->calls());
    }

    public function testEditCategoryHandlerReadsNoListingsWhenTheParentIsUnchanged(): void
    {
        $parent = CategoryFactory::createOne();
        $child = CategoryFactory::new()->childOf($parent)->create();
        [$lock, $usage] = $this->observeUsage();
        $handler = self::getContainer()->get(EditCategoryHandler::class);
        self::assertInstanceOf(EditCategoryHandler::class, $handler);

        $handler(new EditCategory($child->id()->toString(), 'Renamed', 'renamed', $parent->id()->toString()));

        self::assertSame(1, $lock->entries(), 'The edit did not run (a vacuous pass otherwise).');
        self::assertSame([], $usage->calls());
    }

    private function sharedProbe(Connection $session): bool
    {
        $acquired = $session->fetchOne(
            'SELECT pg_try_advisory_xact_lock_shared(:key)',
            ['key' => PostgresCategoryTreeLock::KEY],
            ['key' => ParameterType::INTEGER],
        );

        return true === $acquired;
    }

    public function testControlBothProbesSucceedWhenNobodyHoldsTheLock(): void
    {
        $session = $this->secondSession();

        try {
            self::assertTrue($this->probe($session), 'Control: the exclusive probe must be able to answer true.');
            self::assertTrue($this->sharedProbe($session), 'Control: the shared probe must be able to answer true.');
        } finally {
            $session->close();
        }
    }

    public function testHoldSteadyBlocksAnExclusiveProbeButNotASharedOne(): void
    {
        $categories = self::getContainer()->get(Categories::class);
        self::assertInstanceOf(Categories::class, $categories);
        $session = $this->secondSession();

        try {
            // Both answers are taken inside the closure: after it returns DAMA keeps the lock held anyway.
            [$exclusive, $shared] = $categories->holdSteady(fn (): array => [$this->probe($session), $this->sharedProbe($session)]);
        } finally {
            $session->close();
        }

        self::assertFalse($exclusive, 'holdSteady() did not hold the lock: an exclusive (Catalog) writer could get in.');
        self::assertTrue($shared, 'holdSteady() took the lock exclusively: Listing writes would queue behind each other.');
    }

    public function testExclusivelyBlocksASharedProbe(): void
    {
        $lock = self::getContainer()->get(CategoryTreeLock::class);
        self::assertInstanceOf(CategoryTreeLock::class, $lock);
        $session = $this->secondSession();

        try {
            $shared = $lock->exclusively(fn (): bool => $this->sharedProbe($session));
        } finally {
            $session->close();
        }

        self::assertFalse($shared, 'A Listing write could commit while a Catalog write held the tree.');
    }
}
