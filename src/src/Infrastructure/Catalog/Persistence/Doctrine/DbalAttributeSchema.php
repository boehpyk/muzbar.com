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
 * @phpstan-type FoundRawRow array{id: string, category_id: string, code: string, label: string, type: string, is_required: bool, unit: ?string, minimum: ?int, maximum: ?int, option_count: int, archived_at: ?string}
 * @phpstan-type ArchivedRawRow array{id: string, category_id: string, code: string, label: string, type: string, is_required: bool, unit: ?string, minimum: ?int, maximum: ?int, option_count: int, archived_at: string}
 * @phpstan-type EffectiveRawRow array{id: string, category_id: string, code: string, label: string, type: string, is_required: bool, unit: ?string, minimum: ?int, maximum: ?int, option_count: int, defined_on_id: string, defined_on_name: string, inherited: bool}
 * @phpstan-type FoundRow array{id: string, categoryId: string, code: string, label: string, type: string, required: bool, unit: ?string, minimum: ?int, maximum: ?int, optionCount: int, archivedAt: ?string}
 * @phpstan-type ArchivedRow array{id: string, categoryId: string, code: string, label: string, type: string, required: bool, unit: ?string, minimum: ?int, maximum: ?int, optionCount: int, archivedAt: string}
 * @phpstan-type OptionRow array{id: string, label: string, archivedAt: ?string}
 * @phpstan-type OfferedOptionRow array{id: string, label: string, archived: bool}
 *
 * **Active by default** (ADR-0022 D9): `effectiveFor()` serves the admin list, the seller form and
 * the public page, so it never returns an archived attribute or counts an archived option. Archived
 * rows appear only where the admin asks for them (`archivedDefinedOn()`, `find()`, `optionsOf()`)
 * and where a seller's own stored choice must stay visible (`optionsFor(…, keep)`). `optionLabels()`
 * labels stored ids whatever their state (Q5: a stale value is shown as stored).
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
     * Active only: an archived attribute is absent and `optionCount` counts active options — both
     * filtered in the join conditions (D9).
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
            JOIN catalog_attribute a ON a.category_id = up.id AND a.archived_at IS NULL
            LEFT JOIN catalog_attribute_option o ON o.attribute_id = a.id AND o.archived_at IS NULL
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
     * The archived attributes **defined on** `$categoryId` itself — never inherited ones: an archived
     * row is listed (and restored) only on its defining category (AC-16). Ordered by label
     * case-insensitively, then id. `optionCount` is always 0 (the admin restores or deletes the
     * attribute as a whole). One query, admin index only.
     *
     * @return list<ArchivedRow>
     */
    public function archivedDefinedOn(string $categoryId): array
    {
        /** @var list<ArchivedRawRow> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.self::COLUMNS.', 0 AS option_count, a.archived_at
             FROM catalog_attribute a
             WHERE a.category_id = :category AND a.archived_at IS NOT NULL
             ORDER BY lower(a.label), a.id',
            ['category' => $categoryId],
        );

        return array_map(fn (array $row): array => $this->row($row) + ['archivedAt' => $row['archived_at']], $rows);
    }

    /**
     * One attribute, archived or not, or null when there is none. `optionCount` is always 0 here:
     * the edit page lists the options themselves through `optionsOf()`.
     *
     * @return FoundRow|null
     */
    public function find(string $id): ?array
    {
        /** @var FoundRawRow|false $row */
        $row = $this->connection->fetchAssociative(
            'SELECT '.self::COLUMNS.', 0 AS option_count, a.archived_at FROM catalog_attribute a WHERE a.id = :id',
            ['id' => $id],
        );

        return false === $row ? null : $this->row($row) + ['archivedAt' => $row['archived_at']];
    }

    /**
     * Every option of `$attributeId`, archived ones included (the admin edit page splits them), in
     * insertion order: `ORDER BY id` over UUIDv7 ids (plan D3, C-19). Unpaginated on purpose (owner,
     * Q10).
     *
     * @return list<OptionRow>
     */
    public function optionsOf(string $attributeId): array
    {
        /** @var list<array{id: string, label: string, archived_at: ?string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, label, archived_at FROM catalog_attribute_option WHERE attribute_id = :attribute ORDER BY id',
            ['attribute' => $attributeId],
        );

        return array_map(
            static fn (array $row): array => ['id' => $row['id'], 'label' => $row['label'], 'archivedAt' => $row['archived_at']],
            $rows,
        );
    }

    /**
     * Every option of every attribute in `$attributeIds`, grouped by attribute, each list in id
     * (insertion) order — in **one** query however many choice attributes there are (the sell edit
     * page's budget, listing-attribute-values AC-25). No ids: `[]` without a query. An attribute
     * with no options is absent from the result.
     *
     * Active options only, **plus** the ids in `$keep` whatever their state — the seller's own stored
     * choices, so a stale archived one still renders selected (ADR-0022 D10); each row says whether
     * it is `archived`. A new draft passes no `$keep`, so an archived option is never offered.
     *
     * @param list<string> $attributeIds
     * @param list<string> $keep
     *
     * @return array<string, list<OfferedOptionRow>>
     */
    public function optionsFor(array $attributeIds, array $keep = []): array
    {
        if ([] === $attributeIds) {
            return [];
        }

        // `IN ()` is a syntax error, so an empty keep list becomes a uuid no row has.
        /** @var list<array{attribute_id: string, id: string, label: string, archived: bool}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT attribute_id, id, label, (archived_at IS NOT NULL) AS archived
             FROM catalog_attribute_option
             WHERE attribute_id IN (:attributes) AND (archived_at IS NULL OR id IN (:keep))
             ORDER BY attribute_id, id',
            ['attributes' => $attributeIds, 'keep' => [] === $keep ? ['00000000-0000-0000-0000-000000000000'] : $keep],
            ['attributes' => ArrayParameterType::STRING, 'keep' => ArrayParameterType::STRING],
        );

        $byAttribute = [];

        foreach ($rows as $row) {
            $byAttribute[$row['attribute_id']][] = ['id' => $row['id'], 'label' => $row['label'], 'archived' => $row['archived']];
        }

        return $byAttribute;
    }

    /**
     * The labels of exactly these options, keyed by option id, in **one** query by primary key — the
     * public listing page labels a stored choice without loading the whole list (a Brand list has
     * thousands; listing-publish D11). No ids: `[]` without a query. Unknown ids are absent.
     *
     * @param list<string> $optionIds
     *
     * @return array<string, string>
     */
    public function optionLabels(array $optionIds): array
    {
        if ([] === $optionIds) {
            return [];
        }

        /** @var array<string, string> $labels */
        $labels = $this->connection->fetchAllKeyValue(
            'SELECT id, label FROM catalog_attribute_option WHERE id IN (:ids)',
            ['ids' => $optionIds],
            ['ids' => ArrayParameterType::STRING],
        );

        return $labels;
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
