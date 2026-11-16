<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Persistence\Doctrine;

use App\Domain\Catalog\Entity\Attribute;
use App\Domain\Catalog\Exception\AttributeCodeAlreadyTaken;
use App\Domain\Catalog\Exception\AttributeNotFound;
use App\Domain\Catalog\Port\AttributeRepository;
use App\Domain\Catalog\ValueObject\AttributeCode;
use App\Domain\Catalog\ValueObject\AttributeCodes;
use App\Domain\Catalog\ValueObject\AttributeId;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Infrastructure\Catalog\Persistence\Doctrine\Type\AttributeCodeType;
use App\Infrastructure\Catalog\Persistence\Doctrine\Type\AttributeIdType;
use App\Infrastructure\Catalog\Persistence\Doctrine\Type\CategoryIdType;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The fact reads are plain DBAL on the EntityManager's own connection, so inside
 * `CategoryTreeLock::exclusively()` they run in the lock's transaction and see exactly what the lock
 * is protecting (the `DoctrineCategoryRepository` shape).
 */
final readonly class DoctrineAttributeRepository implements AttributeRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function nextIdentity(): AttributeId
    {
        return AttributeId::fromString(Uuid::v7()->toRfc4122());
    }

    public function get(AttributeId $id): Attribute
    {
        return $this->entityManager->find(Attribute::class, $id) ?? throw AttributeNotFound::withId($id);
    }

    public function isCodeTaken(CategoryId $categoryId, AttributeCode $code, ?AttributeId $except): bool
    {
        // The cast is needed because PostgreSQL cannot infer a type for a parameter it only ever
        // sees compared with NULL.
        return (bool) $this->entityManager->getConnection()->fetchOne(
            'SELECT EXISTS (
                SELECT 1 FROM catalog_attribute
                WHERE category_id = :category
                  AND code = :code
                  AND (CAST(:except AS uuid) IS NULL OR id <> CAST(:except AS uuid))
            )',
            ['category' => $categoryId, 'code' => $code, 'except' => $except],
            ['category' => CategoryIdType::NAME, 'code' => AttributeCodeType::NAME, 'except' => AttributeIdType::NAME],
        );
    }

    public function codesDefinedOn(array $categories): AttributeCodes
    {
        if ([] === $categories) {
            return AttributeCodes::none();
        }

        // Served by `uniq_catalog_attribute_category_code` (leading `category_id`).
        /** @var list<string> $codes */
        $codes = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT code FROM catalog_attribute WHERE category_id IN (:categories)',
            ['categories' => array_map(static fn (CategoryId $id): string => $id->toString(), $categories)],
            ['categories' => ArrayParameterType::STRING],
        );

        return AttributeCodes::of(...array_map(AttributeCode::fromString(...), $codes));
    }

    public function existsForCategory(CategoryId $categoryId): bool
    {
        return (bool) $this->entityManager->getConnection()->fetchOne(
            'SELECT EXISTS (SELECT 1 FROM catalog_attribute WHERE category_id = :category)',
            ['category' => $categoryId],
            ['category' => CategoryIdType::NAME],
        );
    }

    public function save(Attribute $attribute): void
    {
        $this->entityManager->persist($attribute);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $e) {
            // `(category_id, code)` is the only unique constraint besides the primary key. The
            // EntityManager is closed now (Doctrine behaviour): callers re-render from
            // `DbalAttributeSchema`, never through the EM (plan D11).
            throw AttributeCodeAlreadyTaken::inCategory($attribute->code(), $e);
        }
    }

    public function remove(Attribute $attribute): void
    {
        $this->entityManager->remove($attribute);
        $this->entityManager->flush();
    }
}
