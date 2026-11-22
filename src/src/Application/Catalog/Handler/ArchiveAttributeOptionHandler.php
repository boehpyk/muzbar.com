<?php

declare(strict_types=1);

namespace App\Application\Catalog\Handler;

use App\Application\Catalog\Command\ArchiveAttributeOption;
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
use App\Domain\Shared\Port\Clock;

/**
 * Archives one option of a choice attribute (C-28, ADR-0022) — the in-use path that
 * `RemoveAttributeOptionHandler` refuses (C-26). No listing row is touched: a value naming the
 * option becomes *stale* (its attribute is still in force), shown as stored and refused on the
 * listing's next write.
 *
 * C-18 still bites: the attribute decides (`allowOptionRemoval()`) on the active-option count read
 * here, under the lock — asked only when the option is active, because archiving an archived option
 * is the aggregate's no-op and takes nothing from sellers.
 */
final readonly class ArchiveAttributeOptionHandler
{
    public function __construct(
        private AttributeRepository $attributes,
        private AttributeOptionRepository $options,
        private CategoryTreeLock $lock,
        private Clock $clock,
    ) {
    }

    /**
     * @throws AttributeNotFound                                        unknown or malformed attribute id
     * @throws AttributeOptionNotFound                                  unknown, malformed, or another attribute's option
     * @throws AttributeNeedsAnOption                                   the last active option (C-18)
     * @throws \App\Domain\Catalog\Exception\AttributeRuleNotApplicable not a choice attribute (C-15)
     */
    public function __invoke(ArchiveAttributeOption $command): void
    {
        $this->lock->exclusively(function () use ($command): void {
            $attribute = $this->attributes->get($this->attributeId($command->attributeId));
            $option = $this->optionOf($attribute, $command->optionId);

            if (!$option->isArchived()) {
                $attribute->allowOptionRemoval($this->options->countActiveForAttribute($attribute->id()));
            }

            $option->archive($this->clock->now());
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
