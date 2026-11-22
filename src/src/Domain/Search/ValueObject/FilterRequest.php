<?php

declare(strict_types=1);

namespace App\Domain\Search\ValueObject;

use App\Domain\Search\Exception\InvalidAttributeId;
use App\Domain\Search\Exception\InvalidOptionId;

/**
 * What a visitor **asked** to filter by — well-formed, but not yet checked against any category
 * (S-5, first half). "Parse, don't validate": only `FacetedQuery::resolve()` turns this into
 * something `SearchPort::search()` accepts, so an unresolved filter reaching SQL is a type error.
 *
 * Format only, and never throws — a hostile URL is a 200 with fewer filters (AC-1, AC-22):
 *
 * - an attribute key must be a well-formed UUID;
 * - a value must be a well-formed UUID (an option, `OptionId`) or exactly `yes`/`no` (a boolean) —
 *   `YES`, `true`, `1`, `" yes"` and `"<uuid>\n"` are all dropped;
 * - duplicates collapse (ids compared case-insensitively, as `UuidIdShape` lower-cases them), and two
 *   keys naming the same attribute merge;
 * - an attribute left with no value is dropped;
 * - caps apply **after** the above, first N in request order: `MAX_ATTRIBUTES` attributes,
 *   `MAX_VALUES` values per attribute. They bound the SQL (one `EXISTS` per selection, one count
 *   statement per selected facet — plan D5), not the visitor.
 *
 * A value's kind is not decided here: `yes` on a choice or an option id on a boolean is well-formed
 * and only `resolve()`, which knows the facet's kind, drops it.
 */
final readonly class FilterRequest
{
    public const int MAX_ATTRIBUTES = 20;
    public const int MAX_VALUES = 50;

    /**
     * @param list<array{attribute: AttributeId, values: list<OptionId|bool>}> $requested
     */
    private function __construct(
        private array $requested,
    ) {
    }

    /**
     * @param array<array-key, list<string>> $raw attribute id => values, as the query string's
     *                                            `f[<attribute>][]=<value>`; `array-key` because PHP
     *                                            turns a numeric string key into an int
     */
    public static function parse(array $raw): self
    {
        /** @var array<string, array{attribute: AttributeId, values: array<string, OptionId|bool>}> $byAttribute */
        $byAttribute = [];

        foreach ($raw as $key => $values) {
            try {
                $attribute = AttributeId::fromString((string) $key);
            } catch (InvalidAttributeId) {
                continue;
            }

            $id = $attribute->toString();

            if (!isset($byAttribute[$id]) && \count($byAttribute) >= self::MAX_ATTRIBUTES) {
                continue;
            }

            $kept = $byAttribute[$id]['values'] ?? [];

            foreach ($values as $value) {
                if (\count($kept) >= self::MAX_VALUES) {
                    break;
                }

                $parsed = self::value($value);

                if (null !== $parsed) {
                    $kept[\is_bool($parsed) ? ($parsed ? 'yes' : 'no') : $parsed->toString()] = $parsed;
                }
            }

            if ([] !== $kept) {
                $byAttribute[$id] = ['attribute' => $attribute, 'values' => $kept];
            }
        }

        return new self(array_values(array_map(
            static fn (array $entry): array => ['attribute' => $entry['attribute'], 'values' => array_values($entry['values'])],
            $byAttribute,
        )));
    }

    /**
     * Every surviving attribute with its values, in request order.
     *
     * @return list<array{attribute: AttributeId, values: list<OptionId|bool>}>
     */
    public function requested(): array
    {
        return $this->requested;
    }

    /**
     * Every requested option id, once — what `SearchPort::facetSchemaFor()` looks up, so it reads
     * only these and never a whole option list (the `optionLabels()` lesson).
     *
     * @return list<OptionId>
     */
    public function requestedOptionIds(): array
    {
        $ids = [];

        foreach ($this->requested as $entry) {
            foreach ($entry['values'] as $value) {
                if ($value instanceof OptionId) {
                    $ids[$value->toString()] = $value;
                }
            }
        }

        return array_values($ids);
    }

    private static function value(string $raw): OptionId|bool|null
    {
        if ('yes' === $raw || 'no' === $raw) {
            return 'yes' === $raw;
        }

        try {
            return OptionId::fromString($raw);
        } catch (InvalidOptionId) {
            return null;
        }
    }
}
