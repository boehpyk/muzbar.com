<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Entity;

use App\Domain\Catalog\Exception\AttributeRuleNotApplicable;
use App\Domain\Catalog\Exception\DuplicateAttributeOption;
use App\Domain\Catalog\Exception\InvalidAttributeOptions;
use App\Domain\Catalog\ValueObject\AttributeCode;
use App\Domain\Catalog\ValueObject\AttributeId;
use App\Domain\Catalog\ValueObject\AttributeLabel;
use App\Domain\Catalog\ValueObject\AttributeType;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Domain\Catalog\ValueObject\NumberRules;

/**
 * One typed, admin-defined property of the gear filed under a category ("String count",
 * "Orientation", "Brand") — and one aggregate per attribute (plan D1, ADR-0017).
 *
 * It knows its **defining category by id** (`CategoryId`, never the `Category`), and that category
 * never changes (C-14). An attribute is stored once, on the category that defines it (plan D4): the
 * attributes a descendant will inherit are a read-time derivation over the lineage (slice 3), so a
 * rename, re-slug or move of the category touches no attribute.
 *
 * **It holds no options** (plan D2). A choice attribute's options are separate `AttributeOption`
 * aggregates: the set is unbounded (a brand list runs to thousands), dependent options will
 * reference options of *another* attribute, and listings will reference options directly. What
 * stays here is every rule that is the *attribute's*:
 *
 * - C-15, only a choice attribute has options: options are made by `newOption()`, which refuses on
 *   any other type — this aggregate is the factory for the other.
 * - C-18, a choice attribute keeps at least one option: `defineChoice()` refuses an empty batch, and
 *   `allowOptionRemoval()` decides on the option count it is handed.
 * - C-17, unique option labels: decided here only for the batch handed to `defineChoice()`. For
 *   later adds and relabels, the handler pre-checks and a unique index guarantees.
 *
 * THE CONTRACT FOR FACTS (ADR-0016, extended by ADR-0017): the counts and lists this object decides
 * on are read by the handler, and they are only true while `CategoryTreeLock` is held — every
 * attribute and option write runs inside `exclusively()`.
 *
 * No `delete()` (ADR-0012), no setters for the id, the category or the type (C-14), no domain
 * events (plan D10). Every check precedes every assignment, so a refused call mutates nothing.
 *
 * Properties are plain `private`, not `readonly` (ADR-0007): Doctrine hydrates them.
 */
final class Attribute
{
    private function __construct(
        private AttributeId $id,
        private CategoryId $categoryId,
        private AttributeCode $code,
        private AttributeLabel $label,
        private AttributeType $type,
        private bool $required,
        private ?NumberRules $numberRules,
    ) {
    }

    /**
     * Takes the initial option labels **as facts to decide C-17/C-18 on, and does not keep them**.
     * The options themselves are separate aggregates the caller builds through `newOption()` once
     * this attribute exists.
     *
     * @param list<AttributeLabel> $initialOptions
     *
     * @throws InvalidAttributeOptions  the list is empty (C-18)
     * @throws DuplicateAttributeOption two labels share a `key()` (C-17, within this batch)
     */
    public static function defineChoice(
        AttributeId $id,
        CategoryId $categoryId,
        AttributeCode $code,
        AttributeLabel $label,
        bool $required,
        array $initialOptions,
    ): self {
        if ([] === $initialOptions) {
            throw InvalidAttributeOptions::none();
        }

        // No upper bound, deliberately (owner, Q5): a brand list may hold thousands.
        $seen = [];

        foreach ($initialOptions as $option) {
            if (isset($seen[$option->key()])) {
                throw DuplicateAttributeOption::of($option);
            }

            $seen[$option->key()] = true;
        }

        return new self($id, $categoryId, $code, $label, AttributeType::Choice, $required, null);
    }

    public static function defineNumber(
        AttributeId $id,
        CategoryId $categoryId,
        AttributeCode $code,
        AttributeLabel $label,
        bool $required,
        NumberRules $numberRules,
    ): self {
        return new self($id, $categoryId, $code, $label, AttributeType::Number, $required, $numberRules);
    }

    public static function defineBoolean(AttributeId $id, CategoryId $categoryId, AttributeCode $code, AttributeLabel $label, bool $required): self
    {
        return new self($id, $categoryId, $code, $label, AttributeType::Boolean, $required, null);
    }

    public static function defineText(AttributeId $id, CategoryId $categoryId, AttributeCode $code, AttributeLabel $label, bool $required): self
    {
        return new self($id, $categoryId, $code, $label, AttributeType::Text, $required, null);
    }

    public function relabel(AttributeLabel $label): void
    {
        $this->label = $label;
    }

    /** Uniqueness within the category (C-11) is the handler's pre-check plus a unique index. */
    public function recode(AttributeCode $code): void
    {
        $this->code = $code;
    }

    public function changeRequired(bool $required): void
    {
        $this->required = $required;
    }

    /**
     * @throws AttributeRuleNotApplicable this is not a number attribute (C-15)
     */
    public function changeNumberRules(NumberRules $numberRules): void
    {
        if (AttributeType::Number !== $this->type) {
            throw AttributeRuleNotApplicable::numberRules($this->type);
        }

        $this->numberRules = $numberRules;
    }

    public function id(): AttributeId
    {
        return $this->id;
    }

    public function categoryId(): CategoryId
    {
        return $this->categoryId;
    }

    public function code(): AttributeCode
    {
        return $this->code;
    }

    public function label(): AttributeLabel
    {
        return $this->label;
    }

    public function type(): AttributeType
    {
        return $this->type;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    /** Non-null exactly for a number attribute (C-15). */
    public function numberRules(): ?NumberRules
    {
        return $this->numberRules;
    }
}
