<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Persistence\Doctrine;

use Doctrine\DBAL\Connection;

/**
 * A category's attribute schema, for the admin screens.
 *
 * A read model with no port (the `DbalCategoryOutline` precedent): only Infrastructure controllers
 * consume it. It goes through DBAL, never the EntityManager, so a define/edit form can still be
 * re-rendered after a unique violation inside `flush()` has closed the EM (plan D11).
 *
 * Ids are taken as strings and compared as `uuid`; callers pass route values already constrained to
 * `Requirement::UUID`.
 *
 * @phpstan-type AttributeRow array{id: string, categoryId: string, code: string, label: string, type: string, required: bool, unit: ?string, minimum: ?int, maximum: ?int, optionCount: int}
 * @phpstan-type RawRow array{id: string, category_id: string, code: string, label: string, type: string, is_required: bool, unit: ?string, minimum: ?int, maximum: ?int, option_count: int}
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
     * The attributes `$categoryId` defines, with each one's option count, in **one** query (AC-33).
     * Ordered by label case-insensitively, id as the tie-break (owner, Q3).
     *
     * @return list<AttributeRow>
     */
    public function forCategory(string $categoryId): array
    {
        /** @var list<RawRow> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.self::COLUMNS.', count(o.id) AS option_count
            FROM catalog_attribute a
            LEFT JOIN catalog_attribute_option o ON o.attribute_id = a.id
            WHERE a.category_id = :category
            GROUP BY a.id
            ORDER BY lower(a.label), a.id',
            ['category' => $categoryId],
        );

        return array_map($this->row(...), $rows);
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
