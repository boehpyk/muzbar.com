<?php

declare(strict_types=1);

namespace App\Tests\Fixture;

use App\Domain\Catalog\Port\CategoryUsage;
use App\Domain\Catalog\ValueObject\CategoryId;

/**
 * Delegates to the real adapter and records, per call, whether the observed tree lock was held
 * (C-23/C-24 must read `hasListings()` inside `CategoryTreeLock::exclusively()`, AC-18).
 */
final class ObservedCategoryUsage implements CategoryUsage
{
    /** @var list<array{string, bool}> */
    private array $calls = [];

    public function __construct(
        private readonly CategoryUsage $inner,
        private readonly ObservedCategoryTreeLock $lock,
    ) {
    }

    /**
     * @return list<array{string, bool}> method name and "lock held when called"
     */
    public function calls(): array
    {
        return $this->calls;
    }

    public function hasListings(CategoryId $id): bool
    {
        $this->calls[] = [__FUNCTION__, $this->lock->isHeld()];

        return $this->inner->hasListings($id);
    }
}
