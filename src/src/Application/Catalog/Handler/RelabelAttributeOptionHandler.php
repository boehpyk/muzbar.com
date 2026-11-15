<?php

declare(strict_types=1);

namespace App\Application\Catalog\Handler;

use App\Application\Catalog\Command\RelabelAttributeOption;
use App\Domain\Catalog\Entity\Attribute;
use App\Domain\Catalog\Entity\AttributeOption;
use App\Domain\Catalog\Exception\AttributeNotFound;
use App\Domain\Catalog\Exception\AttributeOptionNotFound;
use App\Domain\Catalog\Exception\DuplicateAttributeOption;
use App\Domain\Catalog\Exception\InvalidAttributeId;
use App\Domain\Catalog\Exception\InvalidAttributeOptionId;
use App\Domain\Catalog\Port\AttributeOptionRepository;
use App\Domain\Catalog\Port\AttributeRepository;
use App\Domain\Catalog\Port\CategoryTreeLock;
use App\Domain\Catalog\ValueObject\AttributeId;
use App\Domain\Catalog\ValueObject\AttributeLabel;
use App\Domain\Catalog\ValueObject\AttributeOptionId;

/**
 * Relabels an option, keeping its id (C-19) — so a listing that later points at it survives the fix.
 * C-17 is a pre-check under the lock that excludes the option itself, so changing only the case of
 * its own label ("Right" → "RIGHT") is accepted; the unique index is the guarantee.
 */
final readonly class RelabelAttributeOptionHandler
{
    public function __construct(
        private AttributeRepository $attributes,
        private AttributeOptionRepository $options,
        private CategoryTreeLock $lock,
    ) {
    }

    /**
     * @throws AttributeNotFound                                   unknown or malformed attribute id
     * @throws AttributeOptionNotFound                             unknown, malformed, or another attribute's option
     * @throws \App\Domain\Catalog\Exception\InvalidAttributeLabel (C-9)
     * @throws DuplicateAttributeOption                            (C-17)
     */
    public function __invoke(RelabelAttributeOption $command): void
    {
        $this->lock->exclusively(function () use ($command): void {
            $attribute = $this->attributes->get($this->attributeId($command->attributeId));
            $option = $this->optionOf($attribute, $command->optionId);
            $label = AttributeLabel::fromString($command->label);

            if ($this->options->isLabelTaken($attribute->id(), $label, $option->id())) {
                throw DuplicateAttributeOption::of($label);
            }

            $option->relabel($label);
            $this->options->save($option);
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

    /**
     * The option, provided it belongs to `$attribute`. A malformed id, an unknown one and another
     * attribute's option are the same fact to the caller (AC-21), so the same exception.
     */
    private function optionOf(Attribute $attribute, string $value): AttributeOption
    {
        try {
            $option = $this->options->get(AttributeOptionId::fromString($value));
        } catch (InvalidAttributeOptionId $e) {
            throw AttributeOptionNotFound::withMalformedId($value, $e);
        }

        if (!$option->attributeId()->equals($attribute->id())) {
            throw AttributeOptionNotFound::withId($option->id());
        }

        return $option;
    }
}
