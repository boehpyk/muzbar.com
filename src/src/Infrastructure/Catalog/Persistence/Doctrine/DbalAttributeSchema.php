<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Persistence\Doctrine;

use App\Domain\Catalog\Entity\Category;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * A category's effective attribute schema, and single attributes, for the admin screens.
 *
 * A read model with no port (the `DbalCategoryOutline` precedent): only Infrastructure controllers
 * consume it. It goes through DBAL, never the EntityManager, so a define/edit form can still be
 * re-rendered after a unique violation inside `flush()` has closed the EM (plan D11).
 *
 * Ids are taken as strings and compared as `uuid`; callers pass route values already constrained to
 * `Requirement::UUID`.
 *
 * @phpstan-type AttributeRow array{id: string, categoryId: string, code: string, label: string, type: string, required: bool, unit: ?string, minimum: ?int, maximum: ?int, optionCount: int}
 * @phpstan-type EffectiveRow array{id: string, categoryId: string, code: string, label: string, type: string, required: bool, unit: ?string, minimum: ?int, maximum: ?int, optionCount: int, definedOnId: string, definedOnName: string, inherited: bool}
 * @phpstan-type RawRow array{id: string, category_id: string, code: string, label: string, type: string, is_required: bool, unit: ?string, minimum: ?int, maximum: ?int, option_count: int}
 * @phpstan-type EffectiveRawRow array{id: string, category_id: string, code: string, label: string, type: string, is_required: bool, unit: ?string, minimum: ?int, maximum: ?int, option_count: int, defined_on_id: string, defined_on_name: string, inherited: bool}
 * @phpstan-type OptionRow array{id: string, label: string}
 */
final readonly class DbalAttributeSchema
{
    // `bigint`, not `int`: a bound is any PHP int (`NumberRules` sets no range), and an int4 cast
    // would 500 the list, edit and delete pages of a number whose bound exceeds 2^31 - 1.
    private const string COLUMNS = "a.id, a.category_id, a.code, a.label, a.type, a.is_required,
        a.number_rules->>'unit' AS unit, (a.number_rules->>'min')::bigint AS minimum, (a.number_rules->>'max')::bigint AS maximum";

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * The **effective schema** of `$categoryId`: the attributes defined on every category of its
     * lineage, each with its option count and the category that defines it, in **one** query (AC-8,
     * AC-24). Ordered root first (Q4: general to specific), then by label case-insensitively, then
     * by id. `inherited` is false only for the category's own attributes. Unknown id: `[]`.
     *
     * Derived on every read, never stored (C-22). The walk up is bounded by `Category::MAX_DEPTH`,
     * so a planted cycle repeats rows up to the bound and terminates rather than hanging the page;
     * refusing a corrupt tree is the writes' job (`CorruptCategoryTree`). A code stored twice on one
     * branch (data from before C-21, plan D10) is listed twice, without error, so the page that
     * fixes it still renders.
     *
     * @return list<EffectiveRow>
     */
    public function effectiveFor(string $categoryId): array
    {
        /** @var list<EffectiveRawRow> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'WITH RECURSIVE up (id, parent_id, depth_from_self) AS (
                SELECT id, parent_id, 0 FROM catalog_category WHERE id = :category
                UNION ALL
                SELECT c.id, c.parent_id, up.depth_from_self + 1
                FROM catalog_category c JOIN up ON c.id = up.parent_id
                WHERE up.depth_from_self < :bound
            )
            SELECT '.self::COLUMNS.', count(o.id) AS option_count,
                cat.id AS defined_on_id, cat.name AS defined_on_name, (up.depth_from_self > 0) AS inherited
            FROM up
            JOIN catalog_category cat ON cat.id = up.id
            JOIN catalog_attribute a ON a.category_id = up.id
            LEFT JOIN catalog_attribute_option o ON o.attribute_id = a.id
            GROUP BY a.id, cat.id, cat.name, up.depth_from_self
            ORDER BY up.depth_from_self DESC, lower(a.label), a.id',
            ['category' => $categoryId, 'bound' => Category::MAX_DEPTH],
            ['bound' => ParameterType::INTEGER],
        );

        return array_map(
            fn (array $row): array => $this->row($row) + [
                'definedOnId' => $row['defined_on_id'],
                'definedOnName' => $row['defined_on_name'],
                'inherited' => $row['inherited'],
            ],
            $rows,
        );
    }

    /**
     * One attribute, or null when there is none. `optionCount` is always 0 here: the edit page
     * lists the options themselves through `optionsOf()`.
     *
     * @return AttributeRow|null
     */
    public function find(string $id): ?array
    {
        /** @var RawRow|false $row */
        $row = $this->connection->fetchAssociative(
            'SELECT '.self::COLUMNS.', 0 AS option_count FROM catalog_attribute a WHERE a.id = :id',
            ['id' => $id],
        );

        return false === $row ? null : $this->row($row);
    }

    /**
     * Every option of `$attributeId`, in insertion order: `ORDER BY id` over UUIDv7 ids (plan D3,
     * C-19). Unpaginated on purpose (owner, Q10).
     *
     * @return list<OptionRow>
     */
    public function optionsOf(string $attributeId): array
    {
        /** @var list<OptionRow> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, label FROM catalog_attribute_option WHERE attribute_id = :attribute ORDER BY id',
            ['attribute' => $attributeId],
        );

        return $rows;
    }

    /**
     * Every option of every attribute in `$attributeIds`, grouped by attribute, each list in id
     * (insertion) order — in **one** query however many choice attributes there are (the sell edit
     * page's budget, listing-attribute-values AC-25). No ids: `[]` without a query. An attribute
     * with no options is absent from the result.
     *
     * @param list<string> $attributeIds
     *
     * @return array<string, list<OptionRow>>
     */
    public function optionsFor(array $attributeIds): array
    {
        if ([] === $attributeIds) {
            return [];
        }

        /** @var list<array{attribute_id: string, id: string, label: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT attribute_id, id, label FROM catalog_attribute_option WHERE attribute_id IN (:attributes) ORDER BY attribute_id, id',
            ['attributes' => $attributeIds],
            ['attributes' => ArrayParameterType::STRING],
        );

        $byAttribute = [];

        foreach ($rows as $row) {
            $byAttribute[$row['attribute_id']][] = ['id' => $row['id'], 'label' => $row['label']];
        }

        return $byAttribute;
    }

    /**
     * @param RawRow $row
     *
     * @return AttributeRow
     */
    private function row(array $row): array
    {
        return [
            'id' => $row['id'],
            'categoryId' => $row['category_id'],
            'code' => $row['code'],
            'label' => $row['label'],
            'type' => $row['type'],
            'required' => $row['is_required'],
            'unit' => $row['unit'],
            'minimum' => $row['minimum'],
            'maximum' => $row['maximum'],
            'optionCount' => $row['option_count'],
        ];
    }
}
