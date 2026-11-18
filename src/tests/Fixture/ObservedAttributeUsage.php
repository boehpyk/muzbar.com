<?php

declare(strict_types=1);

namespace App\Tests\Fixture;

use App\Domain\Catalog\Port\AttributeUsage;
use App\Domain\Catalog\ValueObject\AttributeId;
use App\Domain\Catalog\ValueObject\AttributeOptionId;

/**
 * Delegates to the real adapter and records, per call, whether the observed tree lock was held
 * (C-25/C-26 must read usage inside `CategoryTreeLock::exclusively()`, AC-16). Swap it in by the
 * concrete id, `ListingAttributeUsage::class`.
 */
final class ObservedAttributeUsage implements AttributeUsage
{
    /** @var list<array{string, bool}> */
    private array $calls = [];

    public function __construct(
        private readonly AttributeUsage $inner,
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

    public function isInUse(AttributeId $attributeId): bool
    {
        $this->calls[] = [__FUNCTION__, $this->lock->isHeld()];

        return $this->inner->isInUse($attributeId);
    }

    public function isOptionInUse(AttributeId $attributeId, AttributeOptionId $optionId): bool
    {
        $this->calls[] = [__FUNCTION__, $this->lock->isHeld()];

        return $this->inner->isOptionInUse($attributeId, $optionId);
    }
}
