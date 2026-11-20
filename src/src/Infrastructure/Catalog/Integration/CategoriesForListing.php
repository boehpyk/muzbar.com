<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Integration;

use App\Domain\Catalog\Entity\Category;
use App\Domain\Listing\Port\Categories;
use App\Domain\Listing\ValueObject\AttributeId;
use App\Domain\Listing\ValueObject\AttributeKind;
use App\Domain\Listing\ValueObject\AttributeOptionId;
use App\Domain\Listing\ValueObject\AttributeRule;
use App\Domain\Listing\ValueObject\AttributeSchema;
use App\Domain\Listing\ValueObject\CategoryId;
use App\Domain\Listing\ValueObject\CategoryStatus;
use App\Infrastructure\Catalog\Persistence\Doctrine\PostgresCategoryTreeLock;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Listing's view of the catalog (ADR-0019, plan D3/D4) — one of the two cross-context *adapters*
 * (the third crossing is a UI read: `SellController` reuses `DbalCategoryOutline` for its category
 * select, plan D13, so a change to that outline's row shape is a change to `/sell` too). It lives with the data's owner: `catalog_category` is read here, in Catalog's
 * Infrastructure, and nowhere under `Infrastructure/Listing` (AC-7's data-ownership scan).
 *
 * `holdSteady()` takes the tree lock in shared mode, so `statusOf()` stays true until the listing
 * write commits: no category write (create under, move under, delete) can commit in between.
 */
final readonly class CategoriesForListing implements Categories
{
    public function __construct(
        private PostgresCategoryTreeLock $lock,
        private Connection $connection,
    ) {
    }

    public function holdSteady(\Closure $work): mixed
    {
        return $this->lock->sharedly($work);
    }

    public function statusOf(CategoryId $id): CategoryStatus
    {
        // No row: the category does not exist. Otherwise one row saying whether it has a child.
        // Served by the primary key and the leading `parent_id` of the sibling-slug index.
        $kind = $this->connection->fetchOne(
            "SELECT CASE WHEN EXISTS (SELECT 1 FROM catalog_category c WHERE c.parent_id = :id)
                         THEN 'branch' ELSE 'leaf' END
             FROM catalog_category WHERE id = :id",
            ['id' => $id->toString()],
        );

        return match ($kind) {
            false => CategoryStatus::Missing,
            'branch' => CategoryStatus::Branch,
            default => CategoryStatus::Leaf,
        };
    }

    /**
     * One bounded recursive query over the lineage — `DbalAttributeSchema::effectiveFor()`'s CTE,
     * bounded by `Category::MAX_DEPTH` so a corrupt cycle cannot loop — with each attribute's option
     * ids aggregated. Kinds map by value (`AttributeKind` shares `AttributeType`'s strings); an
     * unknown one is a `\LogicException`, failing closed. A legacy duplicate code along a branch
     * (pre-C-21 data) is two attribute ids, so it yields two rules without error.
     *
     * ponytail: every option id of every choice attribute in force, on every save — a 5 000-brand
     * list is 5 000 ids. Upgrade: load only the option ids the input mentions (plan D5).
     */
    public function schemaOf(CategoryId $id): AttributeSchema
    {
        $rows = $this->connection->fetchAllAssociative(
            "WITH RECURSIVE up (id, parent_id, depth_from_self) AS (
                SELECT id, parent_id, 0 FROM catalog_category WHERE id = :category
                UNION ALL
                SELECT c.id, c.parent_id, up.depth_from_self + 1
                FROM catalog_category c JOIN up ON c.id = up.parent_id
                WHERE up.depth_from_self < :bound
            )
            SELECT a.id, a.type, a.is_required,
                (a.number_rules->>'min')::bigint AS minimum, (a.number_rules->>'max')::bigint AS maximum,
                array_to_json(array_agg(o.id ORDER BY o.id) FILTER (WHERE o.id IS NOT NULL)) AS option_ids
            FROM up
            JOIN catalog_attribute a ON a.category_id = up.id
            LEFT JOIN catalog_attribute_option o ON o.attribute_id = a.id
            GROUP BY a.id
            ORDER BY a.id",
            ['category' => $id->toString(), 'bound' => Category::MAX_DEPTH],
            ['bound' => ParameterType::INTEGER],
        );

        return AttributeSchema::of(...array_map(self::toRule(...), $rows));
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function toRule(array $row): AttributeRule
    {
        $id = $row['id'];
        $type = $row['type'];

        if (!\is_string($id) || !\is_string($type)) {
            throw new \LogicException('A catalog_attribute row without a string id and type.');
        }

        $attributeId = AttributeId::fromString($id);
        $required = $row['is_required'];

        if (!\is_bool($required)) {
            throw new \LogicException(\sprintf('Attribute "%s" has a non-boolean is_required.', $id));
        }

        return match (AttributeKind::tryFrom($type)) {
            AttributeKind::Choice => AttributeRule::choice($attributeId, self::optionIds($row['option_ids']), $required),
            AttributeKind::Number => AttributeRule::number($attributeId, self::bound($row['minimum']), self::bound($row['maximum']), $required),
            AttributeKind::Boolean => AttributeRule::boolean($attributeId, $required),
            AttributeKind::Text => AttributeRule::text($attributeId, $required),
            null => throw new \LogicException(\sprintf('Attribute "%s" has an unknown type "%s".', $id, $type)),
        };
    }

    /**
     * @return list<AttributeOptionId>
     */
    private static function optionIds(mixed $json): array
    {
        // NULL when the attribute has no option at all (C-18 forbids it, a legacy row may not care).
        if (null === $json) {
            return [];
        }

        $ids = \is_string($json) ? json_decode($json, true, flags: \JSON_THROW_ON_ERROR) : null;

        if (!\is_array($ids)) {
            throw new \LogicException('Option ids did not come back as a JSON array.');
        }

        return array_values(array_map(
            static fn (mixed $optionId): AttributeOptionId => \is_string($optionId)
                ? AttributeOptionId::fromString($optionId)
                : throw new \LogicException('An option id that is not a string.'),
            $ids,
        ));
    }

    private static function bound(mixed $value): ?int
    {
        return match (true) {
            null === $value, \is_int($value) => $value,
            default => throw new \LogicException('A number bound that is not a bigint.'),
        };
    }
}
