<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Persistence\Doctrine;

use App\Domain\Catalog\Port\CategoryTreeLock;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * One transaction holding one Postgres transaction-scoped advisory lock (plan D3, ADR-0016).
 *
 * `pg_advisory_xact_lock` blocks until no other transaction holds the key, and is released by
 * PostgreSQL itself at commit or rollback — there is no unlock call to forget. So every Catalog
 * write reads its facts and commits against a tree no other write is changing (no write skew).
 *
 * WHY DBAL `transactional()` AND NOT `EntityManager::wrapInTransaction()`: the latter **closes** the
 * EntityManager on any throwable, so a plain domain refusal (a cycle, a duplicate slug from the
 * pre-check) would leave the rest of the request with a dead EM. Clearing is the narrower fix: it
 * forgets the half-mutated aggregate — so a later `flush()` cannot write a rename whose move was
 * refused (AC-16) — without disabling the EM. A unique violation during `flush()` still closes it;
 * that is Doctrine's behaviour, which is why the admin forms re-render from `DbalCategoryOutline`.
 *
 * `clear()` detaches EVERY managed entity, not only categories. Safe today because no caller holds
 * other managed entities across a Catalog handler. A use case that calls one mid-way through its
 * own unit of work must re-fetch its entities after a refusal, or narrow this to Catalog entities.
 */
final readonly class PostgresCategoryTreeLock implements CategoryTreeLock
{
    /**
     * The one advisory-lock key meaning "the category tree" — the ASCII bytes of `cat_tree` read as
     * a bigint, so it is recognisable in `pg_locks.objid`. Nothing else in the application takes
     * advisory locks (the pruning job's lock is in Redis), so it collides with nothing.
     */
    public const int KEY = 0x6361745F74726565;

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function exclusively(\Closure $work): mixed
    {
        $connection = $this->entityManager->getConnection();

        try {
            return $connection->transactional(static function () use ($connection, $work): mixed {
                $connection->executeQuery('SELECT pg_advisory_xact_lock(:key)', ['key' => self::KEY], ['key' => ParameterType::INTEGER])->free();

                return $work();
            });
        } catch (\Throwable $e) {
            $this->entityManager->clear();

            throw $e;
        }
    }
}
