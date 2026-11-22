<?php

declare(strict_types=1);

namespace App\Domain\Search\ValueObject;

/**
 * One **resolved** selection (S-5): a facet of the browsed category and the values of it the visitor
 * ticked — option ids that are active buckets of **this** facet for a choice, `true`/`false` for a
 * boolean — never empty. Within it the values are OR'd, across selections AND'd (S-6).
 *
 * @internal built only by {@see FacetedQuery::resolve()} — public only because PHP has no package
 *           visibility. A reviewer must catch a `new Selection(...)` anywhere else: it would be an
 *           unresolved filter reaching SQL.
 */
final readonly class Selection
{
    /**
     * @param non-empty-list<OptionId>|non-empty-list<bool> $values option ids for a choice, booleans for a boolean
     */
    public function __construct(
        private AttributeId $attributeId,
        private FacetKind $kind,
        private array $values,
    ) {
    }

    public function attributeId(): AttributeId
    {
        return $this->attributeId;
    }

    public function kind(): FacetKind
    {
        return $this->kind;
    }

    /** @return list<OptionId> empty for a boolean */
    public function optionIds(): array
    {
        return array_values(array_filter($this->values, static fn (OptionId|bool $v): bool => $v instanceof OptionId));
    }

    /** @return list<bool> empty for a choice */
    public function booleans(): array
    {
        return array_values(array_filter($this->values, \is_bool(...)));
    }

    /** Whether the bucket whose value is `$value` (an option id, or `yes`/`no`) is ticked. */
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
