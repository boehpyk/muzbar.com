<?php

declare(strict_types=1);

namespace App\Application\Catalog\Handler;

use App\Application\Catalog\Command\EditAttribute;
use App\Domain\Catalog\Exception\AttributeCodeAlreadyTaken;
use App\Domain\Catalog\Exception\AttributeNotFound;
use App\Domain\Catalog\Exception\InvalidAttributeId;
use App\Domain\Catalog\Port\AttributeRepository;
use App\Domain\Catalog\Port\CategoryTreeLock;
use App\Domain\Catalog\ValueObject\AttributeCode;
use App\Domain\Catalog\ValueObject\AttributeId;
use App\Domain\Catalog\ValueObject\AttributeLabel;
use App\Domain\Catalog\ValueObject\AttributeType;
use App\Domain\Catalog\ValueObject\NumberRules;
use App\Domain\Catalog\ValueObject\Unit;

/**
 * Relabels, recodes, flips "required" and (for a number) changes the number rules of an attribute,
 * in one all-or-nothing step (AC-22).
 *
 * Every step runs inside one `exclusively()`: a refusal anywhere — `min > max` after a valid
 * relabel, say — rolls back the transaction, and the lock adapter clears the EntityManager, so the
 * half-applied aggregate is forgotten rather than flushed by someone else later.
 */
final readonly class EditAttributeHandler
{
    public function __construct(
        private AttributeRepository $attributes,
        private CategoryTreeLock $lock,
    ) {
    }

    /**
     * @throws AttributeNotFound                                        unknown or malformed id
     * @throws \App\Domain\Catalog\Exception\InvalidAttributeLabel      (C-9)
     * @throws \App\Domain\Catalog\Exception\InvalidAttributeCode       (C-10)
     * @throws AttributeCodeAlreadyTaken                                (C-11)
     * @throws \App\Domain\Catalog\Exception\InvalidUnit                (C-16)
     * @throws \App\Domain\Catalog\Exception\InvalidNumberRules         (C-16)
     * @throws \App\Domain\Catalog\Exception\AttributeRuleNotApplicable number rules on another type (C-15)
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
            $codeChanged = !$code->equals($attribute->code());

            if ($codeChanged) {
                $attribute->recode($code);
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

            // C-11 pre-check, for the message; the unique index is the guarantee. `except` is the
            // attribute itself, so saving its own unchanged code is never a collision.
            if ($codeChanged && $this->attributes->isCodeTaken($attribute->categoryId(), $code, $attribute->id())) {
                throw AttributeCodeAlreadyTaken::inCategory($code);
            }

            $this->attributes->save($attribute);
        });
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
