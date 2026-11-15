<?php

declare(strict_types=1);

namespace App\Tests\Fixture;

use App\Domain\Catalog\Entity\AttributeOption;
use App\Domain\Catalog\Port\AttributeOptionRepository;
use App\Domain\Catalog\ValueObject\AttributeId;
use App\Domain\Catalog\ValueObject\AttributeLabel;
use App\Domain\Catalog\ValueObject\AttributeOptionId;

/**
 * Delegates to the real repository, running one callback just before the first `save()`: another
 * admin's option landing between the handler's pre-check and its save. Raw DBAL on the same
 * connection, so the real `(attribute_id, label_key)` index decides.
 */
final class RacingAttributeOptionRepository implements AttributeOptionRepository
{
    private bool $fired = false;

    /**
     * @param \Closure(): void $interloper
     */
    public function __construct(
        private readonly AttributeOptionRepository $inner,
        private readonly \Closure $interloper,
    ) {
    }

    public function nextIdentity(): AttributeOptionId
    {
        return $this->inner->nextIdentity();
    }

    public function get(AttributeOptionId $id): AttributeOption
    {
        return $this->inner->get($id);
    }

    public function isLabelTaken(AttributeId $attributeId, AttributeLabel $label, ?AttributeOptionId $except): bool
    {
        return $this->inner->isLabelTaken($attributeId, $label, $except);
    }

    public function countForAttribute(AttributeId $attributeId): int
    {
        return $this->inner->countForAttribute($attributeId);
    }

    public function save(AttributeOption ...$options): void
    {
        if (!$this->fired) {
            $this->fired = true;
            ($this->interloper)();
        }

        $this->inner->save(...$options);
    }

    public function remove(AttributeOption $option): void
    {
        $this->inner->remove($option);
    }

    public function removeAllForAttribute(AttributeId $attributeId): void
    {
        $this->inner->removeAllForAttribute($attributeId);
    }
}
