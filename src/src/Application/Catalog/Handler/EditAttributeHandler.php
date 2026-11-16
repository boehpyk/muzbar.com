<?php

declare(strict_types=1);

namespace App\Application\Catalog\Handler;

use App\Application\Catalog\Command\EditAttribute;
use App\Domain\Catalog\Exception\AttributeCodeAlreadyTaken;
use App\Domain\Catalog\Exception\AttributeNotFound;
use App\Domain\Catalog\Exception\InvalidAttributeId;
use App\Domain\Catalog\Port\AttributeRepository;
use App\Domain\Catalog\Port\CategoryRepository;
use App\Domain\Catalog\Port\CategoryTreeLock;
use App\Domain\Catalog\ValueObject\AttributeCode;
use App\Domain\Catalog\ValueObject\AttributeCodes;
use App\Domain\Catalog\ValueObject\AttributeId;
use App\Domain\Catalog\ValueObject\AttributeLabel;
use App\Domain\Catalog\ValueObject\AttributeType;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Domain\Catalog\ValueObject\NumberRules;
use App\Domain\Catalog\ValueObject\Unit;

/**
 * Relabels, recodes, flips "required" and (for a number) changes the number rules of an attribute,
 * in one all-or-nothing step (AC-22).
 *
 * Every step runs inside one `exclusively()`: a refusal anywhere — `min > max` after a valid
 * relabel, say — rolls back the transaction, and the lock adapter clears the EntityManager, so the
 * half-applied aggregate is forgotten rather than flushed by someone else later. The lock also
 * keeps the branch's codes true between the C-21 read and the save (ADR-0018).
 */
final readonly class EditAttributeHandler
{
    public function __construct(
        private AttributeRepository $attributes,
        private CategoryRepository $categories,
        private CategoryTreeLock $lock,
    ) {
    }

    /**
     * @throws AttributeNotFound                                        unknown or malformed id
     * @throws \App\Domain\Catalog\Exception\InvalidAttributeLabel      (C-9)
     * @throws \App\Domain\Catalog\Exception\InvalidAttributeCode       (C-10)
     * @throws AttributeCodeAlreadyTaken                                in force on the branch (C-21)
     * @throws \App\Domain\Catalog\Exception\InvalidUnit                (C-16)
     * @throws \App\Domain\Catalog\Exception\InvalidNumberRules         (C-16)
     * @throws \App\Domain\Catalog\Exception\AttributeRuleNotApplicable number rules on another type (C-15)
     * @throws \App\Domain\Catalog\Exception\CategoryNotFound           recode only: defining category missing (no FK)
     * @throws \App\Domain\Catalog\Exception\CorruptCategoryTree        recode only: stored cycle on the branch
     */
    public function __invoke(EditAttribute $command): void
    {
        $this->lock->exclusively(function () use ($command): void {
            $attribute = $this->attributes->get($this->attributeId($command->id));

            $label = AttributeLabel::fromString($command->label);

            if (!$label->equals($attribute->label())) {
                $attribute->relabel($label);
            }

            $code = AttributeCode::fromString($command->code);

            // C-21: the aggregate decides on the branch's codes, read under the lock. Only read when
            // the code changes — `recode()` to the current code checks nothing anyway (AC-4). The
            // `(category_id, code)` unique index remains the backstop for the same-category case.
            if (!$code->equals($attribute->code())) {
                $attribute->recode($code, $this->codesInBranchOf($attribute->categoryId()));
            }

            if ($command->required !== $attribute->isRequired()) {
                $attribute->changeRequired($command->required);
            }

            // For a number attribute this applies the rules; for any other type with a rule field
            // set it is still called, so that the refusal comes from the aggregate — C-15's single
            // home — rather than from a second copy of the rule here.
            if (AttributeType::Number === $attribute->type()
                || null !== $command->unit || null !== $command->minimum || null !== $command->maximum
            ) {
                $attribute->changeNumberRules(NumberRules::of(
                    null === $command->unit ? null : Unit::fromString($command->unit),
                    $command->minimum,
                    $command->maximum,
                ));
            }

            $this->attributes->save($attribute);
        });
    }

    /**
     * The codes in force along `$categoryId`'s branch: its lineage (itself and every ancestor) plus
     * its descendants. Copied from `DefineAttributeHandler`; extract when a third caller appears.
     */
    private function codesInBranchOf(CategoryId $categoryId): AttributeCodes
    {
        return $this->attributes->codesDefinedOn([
            ...$this->categories->lineageOf($categoryId)->ids(),
            ...$this->categories->descendantsOf($categoryId),
        ]);
    }

    /** A malformed id names an attribute that does not exist, which is all the caller needs to know. */
    private function attributeId(string $value): AttributeId
    {
        try {
            return AttributeId::fromString($value);
        } catch (InvalidAttributeId $e) {
            throw AttributeNotFound::withMalformedId($value, $e);
        }
    }
}
