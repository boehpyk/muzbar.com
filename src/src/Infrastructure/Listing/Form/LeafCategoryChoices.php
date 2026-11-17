<?php

declare(strict_types=1);

namespace App\Infrastructure\Listing\Form;

/**
 * Turns the admin outline (`DbalCategoryOutline::rows()`, depth-first) into category labels by full
 * path — "Instruments › Guitars › Electric" — so the listing form needs no catalog SQL of its own
 * (plan D13, Q8). Pure PHP over rows.
 *
 * Keyed by id, not by label: two sibling categories may share a name (only slugs are unique), and
 * a label-keyed choice list would silently drop one of them.
 *
 * The row shape is spelled out rather than `@phpstan-import-type`d: Deptrac reads an imported alias
 * as a class in this namespace and fails `--fail-on-uncovered`.
 */
final class LeafCategoryChoices
{
    private const string SEPARATOR = ' › ';

    /**
     * Leaves only (rows that are nobody's parent), in outline order — what a listing may be filed
     * under (L-2). The Domain still decides; this only keeps branches out of the `<select>`.
     *
     * @param list<array{id: string, parentId: ?string, name: string, depth: int}> $rows
     *
     * @return array<string, string> id => path label
     */
    public static function leaves(array $rows): array
    {
        $parents = array_flip(array_filter(array_column($rows, 'parentId')));

        return array_diff_key(self::paths($rows), $parents);
    }

    /**
     * Every category's path label, in outline order — for showing where an existing draft is filed.
     *
     * @param list<array{id: string, parentId: ?string, name: string, depth: int}> $rows
     *
     * @return array<string, string> id => path label
     */
    public static function paths(array $rows): array
    {
        $names = [];
        $paths = [];

        foreach ($rows as $row) {
            // Depth-first order means every ancestor was seen just before, at a smaller depth.
            $names = \array_slice($names, 0, $row['depth'] - 1);
            $names[] = $row['name'];
            $paths[$row['id']] = implode(self::SEPARATOR, $names);
        }

        return $paths;
    }
}
