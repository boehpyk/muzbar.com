<?php

declare(strict_types=1);

namespace App\Domain\Search\ValueObject;

/**
 * One **resolved** selection on a facet of the browsed category, in one of two forms:
 *
 * - **values** (S-5) — the buckets the visitor ticked: option ids that are active buckets of **this**
 *   facet for a choice, `true`/`false` for a boolean — never empty. OR'd within the selection;
 * - **range** (S-10) — a `NumberRange` on a number facet. A listing matches when it holds a value for
 *   that attribute inside it (S-11); one with no value never matches.
 *
 * Across selections everything is AND'd (S-6). At most one selection per attribute.
 *
 * @internal built only by {@see FacetedQuery::resolve()} — public only because PHP has no package
 *           visibility. A reviewer must catch a `Selection::of…()` call anywhere else: it would be an
 *           unresolved filter reaching SQL.
 */
final readonly class Selection
{
    /**
     * @param list<OptionId>|list<bool> $values empty exactly when `$range` is set
     */
    private function __construct(
        private AttributeId $attributeId,
        private FacetKind $kind,
        private array $values,
        private ?NumberRange $range,
    ) {
    }

    /**
     * @param non-empty-list<OptionId>|non-empty-list<bool> $values option ids for a choice, booleans for a boolean
     */
    public static function ofValues(AttributeId $attributeId, FacetKind $kind, array $values): self
    {
        return new self($attributeId, $kind, $values, null);
    }

    public static function ofRange(AttributeId $attributeId, NumberRange $range): self
    {
        return new self($attributeId, FacetKind::Number, [], $range);
    }

    public function attributeId(): AttributeId
    {
        return $this->attributeId;
    }

    public function kind(): FacetKind
    {
        return $this->kind;
    }

    /** @return list<OptionId> empty for a boolean or a range */
    public function optionIds(): array
    {
        return array_values(array_filter($this->values, static fn (OptionId|bool $v): bool => $v instanceof OptionId));
    }

    /** @return list<bool> empty for a choice or a range */
    public function booleans(): array
    {
        return array_values(array_filter($this->values, \is_bool(...)));
    }

    /** The range of a number facet's selection; `null` for a choice or a boolean. */
    public function range(): ?NumberRange
    {
        return $this->range;
    }

    /** Whether the bucket whose value is `$value` (an option id, or `yes`/`no`) is ticked. Never, for a range. */
    public function selects(string $value): bool
    {
        foreach ($this->values as $v) {
            if ((\is_bool($v) ? ($v ? 'yes' : 'no') : $v->toString()) === $value) {
                return true;
            }
        }

        return false;
    }
}
