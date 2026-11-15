<?php

declare(strict_types=1);

namespace App\Application\Catalog\Handler;

use App\Application\Catalog\Command\AddAttributeOption;
use App\Domain\Catalog\Exception\AttributeNotFound;
use App\Domain\Catalog\Exception\DuplicateAttributeOption;
use App\Domain\Catalog\Exception\InvalidAttributeId;
use App\Domain\Catalog\Port\AttributeOptionRepository;
use App\Domain\Catalog\Port\AttributeRepository;
use App\Domain\Catalog\Port\CategoryTreeLock;
use App\Domain\Catalog\ValueObject\AttributeId;
use App\Domain\Catalog\ValueObject\AttributeLabel;
use App\Domain\Catalog\ValueObject\AttributeOptionId;

/**
 * Adds an option to a choice attribute. The attribute makes it (C-15, `newOption()`); the label's
 * uniqueness within the attribute (C-17) is a pre-check under the lock, with the
 * `(attribute_id, label_key)` unique index as the guarantee.
 *
 * Inside `exclusively()` for two reasons: the pre-check's answer must still hold at the save, and
 * the new id is minted under the lock, so `ORDER BY id` stays insertion order (plan D3).
 */
final readonly class AddAttributeOptionHandler
{
    public function __construct(
        private AttributeRepository $attributes,
        private AttributeOptionRepository $options,
        private CategoryTreeLock $lock,
    ) {
    }

    /**
     * @throws AttributeNotFound                                        unknown or malformed id
     * @throws \App\Domain\Catalog\Exception\InvalidAttributeLabel      (C-9)
     * @throws DuplicateAttributeOption                                 (C-17)
     * @throws \App\Domain\Catalog\Exception\AttributeRuleNotApplicable not a choice attribute (C-15)
     */
    public function __invoke(AddAttributeOption $command): AttributeOptionId
    {
        return $this->lock->exclusively(function () use ($command): AttributeOptionId {
            $attribute = $this->attributes->get($this->attributeId($command->attributeId));
            $label = AttributeLabel::fromString($command->label);

            if ($this->options->isLabelTaken($attribute->id(), $label, null)) {
                throw DuplicateAttributeOption::of($label);
            }

            $option = $attribute->newOption($this->options->nextIdentity(), $label);
            $this->options->save($option);

            return $option->id();
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
