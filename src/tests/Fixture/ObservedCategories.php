<?php

declare(strict_types=1);

namespace App\Tests\Fixture;

use App\Domain\Listing\Port\Categories;
use App\Domain\Listing\ValueObject\CategoryId;
use App\Domain\Listing\ValueObject\CategoryStatus;

/**
 * Delegates to the real `Categories` adapter and records, per call, whether work was running inside
 * `holdSteady()` (AC-18). `ObservedListingRepository` shares it, so a listing handler's every port
 * call can be asked "were you under the shared lock?".
 */
final class ObservedCategories implements Categories
{
    private int $depth = 0;
    private int $entries = 0;

    /** @var list<array{string, bool}> */
    private array $calls = [];

    public function __construct(
        private readonly Categories $inner,
    ) {
    }

    public function holdSteady(\Closure $work): mixed
    {
        ++$this->entries;

        return $this->inner->holdSteady(function () use ($work): mixed {
            ++$this->depth;
            try {
                return $work();
            } finally {
                --$this->depth;
            }
        });
    }

    public function statusOf(CategoryId $id): CategoryStatus
    {
        $this->record(__FUNCTION__);

        return $this->inner->statusOf($id);
    }

    public function isHeld(): bool
    {
        return $this->depth > 0;
    }

    public function entries(): int
    {
        return $this->entries;
    }

    /**
     * @return list<array{string, bool}> method name and "inside holdSteady() when called"
     */
    public function calls(): array
    {
        return $this->calls;
    }

    /** Called by `ObservedListingRepository` so both share one ordered log. */
    public function record(string $method): void
    {
        $this->calls[] = [$method, $this->isHeld()];
    }
}
