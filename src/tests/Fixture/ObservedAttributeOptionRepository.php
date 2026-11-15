<?php

declare(strict_types=1);

namespace App\Tests\Fixture;

use App\Domain\Catalog\Entity\AttributeOption;
use App\Domain\Catalog\Port\AttributeOptionRepository;
use App\Domain\Catalog\ValueObject\AttributeId;
use App\Domain\Catalog\ValueObject\AttributeLabel;
use App\Domain\Catalog\ValueObject\AttributeOptionId;

/**
 * Delegates to the real repository and records, per call, whether the observed tree lock was held.
 */
final class ObservedAttributeOptionRepository implements AttributeOptionRepository
{
    /** @var list<array{string, bool}> */
    private array $calls = [];

    public function __construct(
        private readonly AttributeOptionRepository $inner,
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

    private function seen(string $method): void
    {
        $this->calls[] = [$method, $this->lock->isHeld()];
    }

    public function nextIdentity(): AttributeOptionId
    {
        $this->seen(__FUNCTION__);

        return $this->inner->nextIdentity();
    }

    public function get(AttributeOptionId $id): AttributeOption
    {
        $this->seen(__FUNCTION__);

        return $this->inner->get($id);
    }

    public function isLabelTaken(AttributeId $attributeId, AttributeLabel $label, ?AttributeOptionId $except): bool
    {
        $this->seen(__FUNCTION__);

        return $this->inner->isLabelTaken($attributeId, $label, $except);
    }

    public function countForAttribute(AttributeId $attributeId): int
    {
        $this->seen(__FUNCTION__);

        return $this->inner->countForAttribute($attributeId);
    }

    public function save(AttributeOption ...$options): void
    {
        $this->seen(__FUNCTION__);
        $this->inner->save(...$options);
    }

    public function remove(AttributeOption $option): void
    {
        $this->seen(__FUNCTION__);
        $this->inner->remove($option);
    }

    public function removeAllForAttribute(AttributeId $attributeId): void
    {
        $this->seen(__FUNCTION__);
        $this->inner->removeAllForAttribute($attributeId);
    }
}
