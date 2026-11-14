<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Port;

/**
 * "The tree is changed one write at a time, atomically" — the guarantee `Category`'s correctness
 * depends on (plan D2/D3, ADR-0016).
 *
 * The aggregate decides C-4 and C-5 on facts read from the repository. Two concurrent moves (A
 * under B, B under A) would each read a lineage without the other's write, each pass, and together
 * commit a cycle: write skew, which no row lock or version column sees. Every Catalog write
 * therefore reads its facts and saves inside `exclusively()`. The rule is uniform on purpose, so
 * nobody has to decide per handler whether "this one is safe".
 *
 * It lives in the Domain because the guarantee is a domain need; how it is kept (a transaction
 * plus a Postgres advisory lock) is the adapter's business.
 */
interface CategoryTreeLock
{
    /**
     * Runs `$work` exclusively against every other category write, in one transaction: all of it
     * commits, or none of it does. Whatever `$work` throws is rethrown after the rollback.
     *
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    public function exclusively(\Closure $work): mixed;
}
