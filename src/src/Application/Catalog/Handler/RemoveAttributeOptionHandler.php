<?php

declare(strict_types=1);

namespace App\Application\Catalog\Handler;

use App\Application\Catalog\Command\RemoveAttributeOption;
use App\Domain\Catalog\Entity\Attribute;
use App\Domain\Catalog\Entity\AttributeOption;
use App\Domain\Catalog\Exception\AttributeNeedsAnOption;
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
 * Removes one option of a choice attribute — never the last (C-18).
 *
 * The precondition is the **attribute's** rule, so the attribute decides
 * (`allowOptionRemoval()`), on a count read here. That count is only true under the lock: without
 * it, two concurrent removes of the last two options would each count 2 and both succeed — write
 * skew, which no index can catch. The option's own removal is non-existence, so it has no method on
 * any aggregate (ADR-0012). The Listing slice adds its "option in use" guard here (ADR-0004
 * amendment, 2026-11-15).
 */
final readonly class RemoveAttributeOptionHandler
{
    public function __construct(
        private AttributeRepository $attributes,
        private AttributeOptionRepository $options,
        private CategoryTreeLock $lock,
    ) {
    }

    /**
     * @throws AttributeNotFound                                        unknown or malformed attribute id
     * @throws AttributeOptionNotFound                                  unknown, malformed, or another attribute's option
     * @throws AttributeNeedsAnOption                                   the last option (C-18)
     * @throws \App\Domain\Catalog\Exception\AttributeRuleNotApplicable not a choice attribute (C-15)
     */
    public function __invoke(RemoveAttributeOption $command): void
    {
        $this->lock->exclusively(function () use ($command): void {
            $attribute = $this->attributes->get($this->attributeId($command->attributeId));
            $option = $this->optionOf($attribute, $command->optionId);

            $attribute->allowOptionRemoval($this->options->countForAttribute($attribute->id()));

            $this->options->remove($option);
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
