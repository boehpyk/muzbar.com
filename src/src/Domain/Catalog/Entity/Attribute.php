<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Entity;

use App\Domain\Catalog\Exception\AttributeCodeAlreadyTaken;
use App\Domain\Catalog\Exception\AttributeNeedsAnOption;
use App\Domain\Catalog\Exception\AttributeRuleNotApplicable;
use App\Domain\Catalog\Exception\DuplicateAttributeOption;
use App\Domain\Catalog\Exception\InvalidAttributeOptions;
use App\Domain\Catalog\ValueObject\AttributeCode;
use App\Domain\Catalog\ValueObject\AttributeCodes;
use App\Domain\Catalog\ValueObject\AttributeId;
use App\Domain\Catalog\ValueObject\AttributeLabel;
use App\Domain\Catalog\ValueObject\AttributeOptionId;
use App\Domain\Catalog\ValueObject\AttributeType;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Domain\Catalog\ValueObject\NumberRules;

/**
 * One typed, admin-defined property of the gear filed under a category ("String count",
 * "Orientation", "Brand") — and one aggregate per attribute (plan D1, ADR-0017).
 *
 * It knows its **defining category by id** (`CategoryId`, never the `Category`), and that category
 * never changes (C-14). An attribute is stored once, on the category that defines it (plan D4): the
 * attributes a descendant inherits are a read-time derivation over the lineage (C-22, ADR-0018), so
 * a rename, re-slug or move of the category touches no attribute — and nothing here writes another
 * category's schema.
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
 * - C-21, a code appears at most once along any branch: every `define…()` and `recode()` decides it
 *   **first**, on the codes in force along the defining category's branch (its lineage plus its
 *   descendants), handed in as `AttributeCodes`. C-11 is now the same-category case of C-21; the
 *   `(category_id, code)` unique index remains its backstop. C-21 itself has no index: the lock is
 *   the whole guarantee (ADR-0018).
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
     * @throws AttributeCodeAlreadyTaken the code is in force on the branch (C-21, checked first)
     * @throws InvalidAttributeOptions   the list is empty (C-18)
     * @throws DuplicateAttributeOption  two labels share a `key()` (C-17, within this batch)
     */
    public static function defineChoice(
        AttributeId $id,
        CategoryId $categoryId,
        AttributeCode $code,
        AttributeLabel $label,
        bool $required,
        array $initialOptions,
        AttributeCodes $codesInBranch,
    ): self {
        self::refuseCodeInForce($code, $codesInBranch);

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

    /**
     * @throws AttributeCodeAlreadyTaken the code is in force on the branch (C-21)
     */
    public static function defineNumber(
        AttributeId $id,
        CategoryId $categoryId,
        AttributeCode $code,
        AttributeLabel $label,
        bool $required,
        NumberRules $numberRules,
        AttributeCodes $codesInBranch,
    ): self {
        self::refuseCodeInForce($code, $codesInBranch);

        return new self($id, $categoryId, $code, $label, AttributeType::Number, $required, $numberRules);
    }

    /**
     * @throws AttributeCodeAlreadyTaken the code is in force on the branch (C-21)
     */
    public static function defineBoolean(
        AttributeId $id,
        CategoryId $categoryId,
        AttributeCode $code,
        AttributeLabel $label,
        bool $required,
        AttributeCodes $codesInBranch,
    ): self {
        self::refuseCodeInForce($code, $codesInBranch);

        return new self($id, $categoryId, $code, $label, AttributeType::Boolean, $required, null);
    }

    /**
     * @throws AttributeCodeAlreadyTaken the code is in force on the branch (C-21)
     */
    public static function defineText(
        AttributeId $id,
        CategoryId $categoryId,
        AttributeCode $code,
        AttributeLabel $label,
        bool $required,
        AttributeCodes $codesInBranch,
    ): self {
        self::refuseCodeInForce($code, $codesInBranch);

        return new self($id, $categoryId, $code, $label, AttributeType::Text, $required, null);
    }

    public function relabel(AttributeLabel $label): void
    {
        $this->label = $label;
    }

    /**
     * Recoding to the current code is a no-op that checks nothing — the current code is always in
     * `$codesInBranch`, being in force on this attribute's own category. The shape of
     * `Category::moveUnder()` to the current parent; it is also why the fact read needs no `except`.
     *
     * @throws AttributeCodeAlreadyTaken the code is in force on the branch (C-21)
     */
    public function recode(AttributeCode $code, AttributeCodes $codesInBranch): void
    {
        if ($code->equals($this->code)) {
            return;
        }

        self::refuseCodeInForce($code, $codesInBranch);

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

    /**
     * The only sanctioned way to make an option (C-15): this aggregate is the factory for the other.
     * Whether the label is free within this attribute (C-17) is the handler's pre-check, under the
     * lock, plus the unique index — this object holds no list of its options to check against.
     *
     * @throws AttributeRuleNotApplicable this is not a choice attribute
     */
    public function newOption(AttributeOptionId $id, AttributeLabel $label): AttributeOption
    {
        if (AttributeType::Choice !== $this->type) {
            throw AttributeRuleNotApplicable::options($this->type);
        }

        return AttributeOption::create($id, $this->id, $label);
    }

    /**
     * Decides whether one of this attribute's options may be removed (C-18), on the current option
     * count the handler read **under `CategoryTreeLock`** — without the lock, two concurrent removes
     * of the last two options would each count 2 and both pass (write skew).
     *
     * A guard with no state change, deliberately: "at least one option" is this attribute's
     * invariant, so it decides; the option's removal is non-existence, which ADR-0012 keeps off every
     * aggregate.
     *
     * @throws AttributeRuleNotApplicable this is not a choice attribute
     * @throws AttributeNeedsAnOption     the option is the last one
     */
    public function allowOptionRemoval(int $optionCount): void
    {
        if (AttributeType::Choice !== $this->type) {
            throw AttributeRuleNotApplicable::options($this->type);
        }

        if ($optionCount < 1) {
            throw new \InvalidArgumentException(\sprintf('An option is being removed, so the attribute has at least 1; got %d.', $optionCount));
        }

        if (1 === $optionCount) {
            throw AttributeNeedsAnOption::cannotRemoveLast($this->id);
        }
    }

    /**
     * C-21 on facts read under `CategoryTreeLock`: the branch is the defining category's lineage plus
     * its descendants, so "in force" covers this category (C-11), above it and below it.
     */
    private static function refuseCodeInForce(AttributeCode $code, AttributeCodes $codesInBranch): void
    {
        if ($codesInBranch->contains($code)) {
            throw AttributeCodeAlreadyTaken::inBranch($code);
        }
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
