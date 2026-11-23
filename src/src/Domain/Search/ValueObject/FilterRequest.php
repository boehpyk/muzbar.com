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
 * - a range bound must be `-?[0-9]{1,18}` exactly (ASCII digits, no `+`, no space, no newline — the
 *   `D` anchor — and 18 digits stay inside int64); blank is absent, never `0`; a range left with no
 *   bound is dropped; two keys naming one attribute keep the first range (S-10);
 * - a price filter needs an exact, upper-case code of {@see Currency} — without one it is dropped
 *   whole, bounds or not (Q1b); a price bound must be `[0-9]{1,7}` whole units (S-13);
 * - caps apply **after** the above, first N in request order: `MAX_ATTRIBUTES` **distinct**
 *   attributes across values and ranges together (values first, then ranges), `MAX_VALUES` values per
 *   attribute. They bound the SQL (one `EXISTS` per selection, one count statement per selected
 *   facet — plan D5), not the visitor.
 *
 * A value's kind is not decided here: `yes` on a choice, an option id on a boolean or a range on
 * either is well-formed, and only `resolve()`, which knows the facet's kind, drops it.
 */
final readonly class FilterRequest
{
    public const int MAX_ATTRIBUTES = 20;
    public const int MAX_VALUES = 50;

    /**
     * @param list<array{attribute: AttributeId, values: list<OptionId|bool>}> $requested
     * @param list<array{attribute: AttributeId, range: NumberRange}>          $ranges
     */
    private function __construct(
        private array $requested,
        private array $ranges,
        private ?PriceRange $price,
    ) {
    }

    /**
     * @param array<array-key, list<string>>                       $values attribute id => values, as the query string's
     *                                                                     `f[<attribute>][]=<value>`; `array-key` because PHP
     *                                                                     turns a numeric string key into an int
     * @param array<array-key, array{min: ?string, max: ?string}>  $ranges attribute id => bounds, as `r[<attribute>][min|max]`
     * @param array{currency: ?string, min: ?string, max: ?string} $price  as `price[currency|min|max]`
     */
    public static function parse(array $values, array $ranges, array $price): self
    {
        /** @var array<string, array{attribute: AttributeId, values: array<string, OptionId|bool>}> $byAttribute */
        $byAttribute = [];

        foreach ($values as $key => $raw) {
            $attribute = self::attribute($key);

            if (null === $attribute) {
                continue;
            }

            $id = $attribute->toString();

            if (!isset($byAttribute[$id]) && \count($byAttribute) >= self::MAX_ATTRIBUTES) {
                continue;
            }

            $kept = $byAttribute[$id]['values'] ?? [];

            foreach ($raw as $value) {
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

        /** @var array<string, array{attribute: AttributeId, range: NumberRange}> $rangeByAttribute */
        $rangeByAttribute = [];
        $distinct = \count($byAttribute);

        foreach ($ranges as $key => $bounds) {
            $attribute = self::attribute($key);
            $range = NumberRange::of(self::bound($bounds['min']), self::bound($bounds['max']));

            if (null === $attribute || null === $range) {
                continue;
            }

            $id = $attribute->toString();

            if (isset($rangeByAttribute[$id])) {
                continue;
            }

            // One cap across `f` and `r`: an attribute already counted by its values costs nothing more.
            if (!isset($byAttribute[$id])) {
                if ($distinct >= self::MAX_ATTRIBUTES) {
                    continue;
                }
                ++$distinct;
            }

            $rangeByAttribute[$id] = ['attribute' => $attribute, 'range' => $range];
        }

        return new self(
            array_values(array_map(
                static fn (array $entry): array => ['attribute' => $entry['attribute'], 'values' => array_values($entry['values'])],
                $byAttribute,
            )),
            array_values($rangeByAttribute),
            self::priceFilter($price),
        );
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

    /**
     * Every surviving range, in request order — at most one per attribute.
     *
     * @return list<array{attribute: AttributeId, range: NumberRange}>
     */
    public function requestedRanges(): array
    {
        return $this->ranges;
    }

    /** The price filter, or `null` when no allowed currency was given (Q1b). */
    public function price(): ?PriceRange
    {
        return $this->price;
    }

    private static function attribute(int|string $key): ?AttributeId
    {
        try {
            return AttributeId::fromString((string) $key);
        } catch (InvalidAttributeId) {
            return null;
        }
    }

    /** Blank is absent — never `0` (AC-21). No `u` modifier, so `[0-9]` is ASCII only. */
    private static function bound(?string $raw): ?int
    {
        return null !== $raw && 1 === preg_match('/^-?[0-9]{1,18}$/D', $raw) ? (int) $raw : null;
    }

    /**
     * @param array{currency: ?string, min: ?string, max: ?string} $raw
     */
    private static function priceFilter(array $raw): ?PriceRange
    {
        $currency = Currency::tryFrom($raw['currency'] ?? '');

        if (null === $currency) {
            return null;
        }

        $whole = static fn (?string $b): ?int => null !== $b && 1 === preg_match('/^[0-9]{1,7}$/D', $b) ? (int) $b : null;

        return PriceRange::inWholeUnits($currency, $whole($raw['min']), $whole($raw['max']));
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
