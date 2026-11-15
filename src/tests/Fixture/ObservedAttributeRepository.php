<?php

declare(strict_types=1);

namespace App\Tests\Fixture;

use App\Domain\Catalog\Entity\Attribute;
use App\Domain\Catalog\Port\AttributeRepository;
use App\Domain\Catalog\ValueObject\AttributeCode;
use App\Domain\Catalog\ValueObject\AttributeId;
use App\Domain\Catalog\ValueObject\CategoryId;

/**
 * Delegates to the real repository and records, per call, whether the observed tree lock was held.
 */
final class ObservedAttributeRepository implements AttributeRepository
{
    /** @var list<array{string, bool}> */
    private array $calls = [];

    public function __construct(
        private readonly AttributeRepository $inner,
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

    public function nextIdentity(): AttributeId
    {
        $this->seen(__FUNCTION__);

        return $this->inner->nextIdentity();
    }

    public function get(AttributeId $id): Attribute
    {
        $this->seen(__FUNCTION__);

        return $this->inner->get($id);
    }

    public function isCodeTaken(CategoryId $categoryId, AttributeCode $code, ?AttributeId $except): bool
    {
        $this->seen(__FUNCTION__);

        return $this->inner->isCodeTaken($categoryId, $code, $except);
    }

    public function existsForCategory(CategoryId $categoryId): bool
    {
        $this->seen(__FUNCTION__);

        return $this->inner->existsForCategory($categoryId);
    }

    public function save(Attribute $attribute): void
    {
        $this->seen(__FUNCTION__);
        $this->inner->save($attribute);
    }

    public function remove(Attribute $attribute): void
    {
        $this->seen(__FUNCTION__);
        $this->inner->remove($attribute);
    }
}
