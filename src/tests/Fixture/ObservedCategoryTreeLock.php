<?php

declare(strict_types=1);

namespace App\Tests\Fixture;

use App\Domain\Catalog\Port\CategoryTreeLock;

/**
 * Wraps the real tree lock and records how many times, and whether right now, work is running
 * inside `exclusively()`. Paired with `ObservedCategoryRepository` it lets a test say "this call
 * happened under the lock" about every port call a handler makes.
 */
final class ObservedCategoryTreeLock implements CategoryTreeLock
{
    private bool $held = false;
    private int $entries = 0;

    public function __construct(
        private readonly CategoryTreeLock $inner,
    ) {
    }

    public function exclusively(\Closure $work): mixed
    {
        ++$this->entries;

        return $this->inner->exclusively(function () use ($work): mixed {
            $this->held = true;
            try {
                return $work();
            } finally {
                $this->held = false;
            }
        });
    }

    public function isHeld(): bool
    {
        return $this->held;
    }

    public function entries(): int
    {
        return $this->entries;
    }
}
