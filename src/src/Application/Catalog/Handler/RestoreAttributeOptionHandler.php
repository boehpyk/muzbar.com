<?php

declare(strict_types=1);

namespace App\Application\Catalog\Handler;

use App\Application\Catalog\Command\RestoreAttributeOption;
use App\Domain\Catalog\Entity\Attribute;
use App\Domain\Catalog\Entity\AttributeOption;
use App\Domain\Catalog\Exception\AttributeNotFound;
use App\Domain\Catalog\Exception\AttributeOptionNotFound;
use App\Domain\Catalog\Exception\InvalidAttributeId;
use App\Domain\Catalog\Exception\InvalidAttributeOptionId;
use App\Domain\Catalog\Port\AttributeOptionRepository;
use App\Domain\Catalog\Port\AttributeRepository;
use App\Domain\Catalog\Port\CategoryTreeLock;
use App\Domain\Catalog\ValueObject\AttributeId;
use App\Domain\Catalog\ValueObject\AttributeOptionId;

/**
 * Restores an archived option (C-28). **Reads no facts and is never refused** (C-29): an archived
 * label stays reserved — `isLabelTaken()` and the `(attribute_id, label_key)` index include it — so
 * no other option of the attribute can have taken it meanwhile, and restoring only grows the active
 * set C-18 counts. Restoring an active option is the aggregate's no-op.
 */
final readonly class RestoreAttributeOptionHandler
{
    public function __construct(
        private AttributeRepository $attributes,
        private AttributeOptionRepository $options,
        private CategoryTreeLock $lock,
    ) {
    }

    /**
     * @throws AttributeNotFound       unknown or malformed attribute id
     * @throws AttributeOptionNotFound unknown, malformed, or another attribute's option
     */
    public function __invoke(RestoreAttributeOption $command): void
    {
        $this->lock->exclusively(function () use ($command): void {
            $attribute = $this->attributes->get($this->attributeId($command->attributeId));
            $option = $this->optionOf($attribute, $command->optionId);

            $option->restore();
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
     * attribute's option are the same fact to the caller, so the same exception (as in
     * `RemoveAttributeOptionHandler`).
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
