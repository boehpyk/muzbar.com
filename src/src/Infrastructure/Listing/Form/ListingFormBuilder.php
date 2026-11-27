<?php

declare(strict_types=1);

namespace App\Infrastructure\Listing\Form;

use App\Infrastructure\Catalog\Persistence\Doctrine\DbalAttributeSchema;

/**
 * What the listing form offers: the categories, and the Details for one of them (listing-wizard
 * plan D2). One builder for the page, the POST and the morph, so all three render and bind **the
 * same fields** — a POST that built a different form from the page would refuse its own fields as
 * "extra".
 *
 * The Details are read from Catalog's admin read model (`DbalAttributeSchema`, listing-attribute-
 * values plan D11 — a UI crossing), while the Domain decides on `Categories::schemaOf()` under the
 * lock at POST. A schema change in between only produces a refusal: fail closed.
 *
 * The row shape is spelled out rather than `@phpstan-import-type`d: Deptrac reads an imported alias
 * as a class in this namespace and fails `--fail-on-uncovered`.
 */
final class ListingFormBuilder
{
    private const string NO_LONGER_OFFERED_LABEL = '%s (no longer offered)';

    public function __construct(private readonly DbalAttributeSchema $schema)
    {
    }

    /**
     * The leaves, plus the listing's current category when it is not one (legacy data; impossible
     * through the app) so it can be edited without re-filing (failure contract, AC-5). A current id
     * that names no category adds nothing.
     *
     * @param list<array{id: string, parentId: ?string, name: string, depth: int}> $rows
     *
     * @return array<string, string> id => path label
     */
    public function choices(array $rows, ?string $currentCategoryId = null): array
    {
        $choices = LeafCategoryChoices::leaves($rows);

        if (null !== $currentCategoryId) {
            $paths = LeafCategoryChoices::paths($rows);

            if (isset($paths[$currentCategoryId])) {
                $choices[$currentCategoryId] = $paths[$currentCategoryId];
            }
        }

        return $choices;
    }

    /**
     * The Details sub-form's rows: the effective schema (root first, then label) with each choice
     * attribute's active options plus the archived one the listing stores for it, fetched for all of
     * them in one query. An archived option is labelled "… (no longer offered)"; the second element
     * maps each such option id to its plain label, for the refusal.
     *
     * **Nothing is read unless `$categoryId` is among `$offered`** (AC-6, AC-17): an unknown,
     * malformed or tampered id never reaches a statement, and a branch gets no Details.
     *
     * `keep` is built from the **choice** attributes' stored values only (AC-5): a choice value is
     * always an option id, while a text value that merely looks like one must neither reach
     * `id IN (…)` (a non-uuid string there is a 500) nor put an archived option onto another
     * field's select.
     *
     * @param array<string, string> $offered the categories the form offers, id => path label
     * @param array<string, string> $stored  the listing's stored values, by attribute id
     *
     * @return array{list<array{id: string, label: string, type: string, required: bool, unit: ?string, options: list<array{id: string, label: string}>}>, array<string, string>}
     */
    public function details(array $offered, ?string $categoryId, array $stored = []): array
    {
        if (null === $categoryId || !isset($offered[$categoryId])) {
            return [[], []];
        }

        $rows = $this->schema->effectiveFor($categoryId);
        $choiceIds = array_column(array_filter($rows, static fn (array $row): bool => 'choice' === $row['type']), 'id');
        $keep = array_values(array_intersect_key($stored, array_flip($choiceIds)));
        $options = $this->schema->optionsFor($choiceIds, $keep);

        $noLongerOffered = [];

        foreach ($options as $attributeOptions) {
            foreach ($attributeOptions as $option) {
                if ($option['archived']) {
                    $noLongerOffered[$option['id']] = $option['label'];
                }
            }
        }

        $schema = array_map(static fn (array $row): array => [
            'id' => $row['id'],
            'label' => $row['label'],
            'type' => $row['type'],
            'required' => $row['required'],
            'unit' => $row['unit'],
            'options' => array_map(
                static fn (array $option): array => [
                    'id' => $option['id'],
                    'label' => $option['archived'] ? \sprintf(self::NO_LONGER_OFFERED_LABEL, $option['label']) : $option['label'],
                ],
                $options[$row['id']] ?? [],
            ),
        ], $rows);

        return [$schema, $noLongerOffered];
    }

    /**
     * @param list<array{id: string, parentId: ?string, name: string, slug: string, depth: int}> $rows
     */
    public function nameOf(array $rows, string $categoryId): string
    {
        return array_column($rows, 'name', 'id')[$categoryId] ?? 'the new category';
    }
}
