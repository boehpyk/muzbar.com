<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Application\Catalog\Command\CreateCategory;
use App\Application\Catalog\Command\DeleteCategory;
use App\Application\Catalog\Command\EditCategory;
use App\Application\Catalog\Handler\CreateCategoryHandler;
use App\Application\Catalog\Handler\DeleteCategoryHandler;
use App\Application\Catalog\Handler\EditCategoryHandler;
use App\Domain\Catalog\Port\CategoryTreeLock;
use App\Infrastructure\Catalog\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Infrastructure\Catalog\Persistence\Doctrine\PostgresCategoryTreeLock;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Fixture\ObservedCategoryRepository;
use App\Tests\Fixture\ObservedCategoryTreeLock;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * AC-17: the tree lock is real, and every Catalog handler takes it.
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
}
