<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Persistence\Doctrine;

use App\Domain\Catalog\Entity\AttributeOption;
use App\Domain\Catalog\Exception\AttributeOptionNotFound;
use App\Domain\Catalog\Exception\DuplicateAttributeOption;
use App\Domain\Catalog\Port\AttributeOptionRepository;
use App\Domain\Catalog\ValueObject\AttributeId;
use App\Domain\Catalog\ValueObject\AttributeLabel;
use App\Domain\Catalog\ValueObject\AttributeOptionId;
use App\Infrastructure\Catalog\Persistence\Doctrine\Type\AttributeIdType;
use App\Infrastructure\Catalog\Persistence\Doctrine\Type\AttributeOptionIdType;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The fact reads (`isLabelTaken`, `countActiveForAttribute`) and the sweep are plain DBAL on the
 * EntityManager's own connection, so inside `CategoryTreeLock::exclusively()` they run in the lock's
 * transaction (ADR-0017 decision 3).
 *
 * `removeAllForAttribute()` is a set-based DELETE and does not detach managed entities. The only
 * caller, `DeleteAttributeHandler`, never loads an option on that path; a caller that did would
 * hold a managed entity for a row that no longer exists.
 */
final readonly class DoctrineAttributeOptionRepository implements AttributeOptionRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Symfony's `UuidV7` is monotonic within one process, and every option insert runs under the
     * tree lock, so mint order is insertion order is `ORDER BY id` (plan D3).
     */
    public function nextIdentity(): AttributeOptionId
    {
        return AttributeOptionId::fromString(Uuid::v7()->toRfc4122());
    }

    public function get(AttributeOptionId $id): AttributeOption
    {
        return $this->entityManager->find(AttributeOption::class, $id) ?? throw AttributeOptionNotFound::withId($id);
    }

    public function isLabelTaken(AttributeId $attributeId, AttributeLabel $label, ?AttributeOptionId $except): bool
    {
        // `label_key` against `key()`: the one definition of "same label" (C-17), the same value the
        // unique index compares.
        return (bool) $this->entityManager->getConnection()->fetchOne(
            'SELECT EXISTS (
                SELECT 1 FROM catalog_attribute_option
                WHERE attribute_id = :attribute
                  AND label_key = :key
                  AND (CAST(:except AS uuid) IS NULL OR id <> CAST(:except AS uuid))
            )',
            ['attribute' => $attributeId, 'key' => $label->key(), 'except' => $except],
            ['attribute' => AttributeIdType::NAME, 'except' => AttributeOptionIdType::NAME],
        );
    }

    public function countActiveForAttribute(AttributeId $attributeId): int
    {
        $count = $this->entityManager->getConnection()->fetchOne(
            'SELECT count(*) FROM catalog_attribute_option WHERE attribute_id = :attribute AND archived_at IS NULL',
            ['attribute' => $attributeId],
            ['attribute' => AttributeIdType::NAME],
        );

        // pdo_pgsql returns a PostgreSQL bigint as a PHP int.
        \assert(\is_int($count));

        return $count;
    }

    public function save(AttributeOption ...$options): void
    {
        foreach ($options as $option) {
            $this->entityManager->persist($option);
        }

        try {
            // One flush for the whole batch: a defining brand list may hold thousands (plan D13).
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $e) {
            // `(attribute_id, label_key)` is the only unique constraint besides the primary key.
            // A batch only comes from a define, whose labels `defineChoice()` already checked in
            // memory under a brand-new attribute id, so in practice the collision is a one-option
            // add or relabel and the first label is the one that collided. The EM is closed now:
            // the option actions redirect, so the next page gets a fresh one (plan D11).
            throw DuplicateAttributeOption::of(($options[0] ?? throw $e)->label(), $e);
        }
    }

    public function remove(AttributeOption $option): void
    {
        $this->entityManager->remove($option);
        $this->entityManager->flush();
    }

    public function removeAllForAttribute(AttributeId $attributeId): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'DELETE FROM catalog_attribute_option WHERE attribute_id = :attribute',
            ['attribute' => $attributeId],
            ['attribute' => AttributeIdType::NAME],
        );
    }
}
