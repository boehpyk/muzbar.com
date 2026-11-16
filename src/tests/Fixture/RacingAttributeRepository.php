<?php

declare(strict_types=1);

namespace App\Tests\Fixture;

use App\Domain\Catalog\Entity\Attribute;
use App\Domain\Catalog\Port\AttributeRepository;
use App\Domain\Catalog\ValueObject\AttributeCode;
use App\Domain\Catalog\ValueObject\AttributeCodes;
use App\Domain\Catalog\ValueObject\AttributeId;
use App\Domain\Catalog\ValueObject\CategoryId;

/**
 * Delegates to the real repository, running one callback just before the first `save()`: a second
 * admin's write landing between the handler's pre-check and its save. The callback acts through raw
 * DBAL on the same connection, so the real unique index decides. It receives the id of the last
 * attribute `nextIdentity()` minted, so a test can plant a row that collides with the one being
 * defined.
 */
final class RacingAttributeRepository implements AttributeRepository
{
    private bool $fired = false;
    private ?AttributeId $lastMinted = null;

    /**
     * @param \Closure(?AttributeId): mixed $interloper
     */
    public function __construct(
        private readonly AttributeRepository $inner,
        private readonly \Closure $interloper,
    ) {
    }

    public function lastMinted(): AttributeId
    {
        return $this->lastMinted ?? throw new \LogicException('nextIdentity() has not been called yet.');
    }

    public function nextIdentity(): AttributeId
    {
        return $this->lastMinted = $this->inner->nextIdentity();
    }

    public function get(AttributeId $id): Attribute
    {
        return $this->inner->get($id);
    }

    public function isCodeTaken(CategoryId $categoryId, AttributeCode $code, ?AttributeId $except): bool
    {
        return $this->inner->isCodeTaken($categoryId, $code, $except);
    }

    public function codesDefinedOn(array $categories): AttributeCodes
    {
        return $this->inner->codesDefinedOn($categories);
    }

    public function existsForCategory(CategoryId $categoryId): bool
    {
        return $this->inner->existsForCategory($categoryId);
    }

    public function save(Attribute $attribute): void
    {
        if (!$this->fired) {
            $this->fired = true;
            ($this->interloper)($this->lastMinted);
        }

        $this->inner->save($attribute);
    }

    public function remove(Attribute $attribute): void
    {
        $this->inner->remove($attribute);
    }
}
