<?php

declare(strict_types=1);

namespace App\Application\Catalog\Handler;

use App\Application\Catalog\Command\DeleteAttribute;
use App\Domain\Catalog\Exception\AttributeNotFound;
use App\Domain\Catalog\Exception\InvalidAttributeId;
use App\Domain\Catalog\Port\AttributeOptionRepository;
use App\Domain\Catalog\Port\AttributeRepository;
use App\Domain\Catalog\Port\CategoryTreeLock;
use App\Domain\Catalog\ValueObject\AttributeId;

/**
 * Hard-deletes an attribute and sweeps its options in the same transaction (C-20, plan D7). No
 * aggregate has a `delete()` (ADR-0012), and the set-based sweep bypasses no rule: C-18 governs a
 * living choice attribute, and once the attribute is gone it has no subject.
 *
 * Hard delete only until values exist (ADR-0004 amendment, 2026-11-15): the Listing slice turns
 * this into archiving, or adds its "in use by listings" guard, **here**.
 *
 * Inside `exclusively()` like every Catalog write, so no option can be added to the attribute
 * between the delete and the sweep.
 */
final readonly class DeleteAttributeHandler
{
    public function __construct(
        private AttributeRepository $attributes,
        private AttributeOptionRepository $options,
        private CategoryTreeLock $lock,
    ) {
    }

    /**
     * @throws AttributeNotFound unknown or malformed id
     */
    public function __invoke(DeleteAttribute $command): void
    {
        $this->lock->exclusively(function () use ($command): void {
            $attribute = $this->attributes->get($this->attributeId($command->id));

            // THE ORDER IS DELIBERATE (plan D7, ADR-0017 decision 8): attribute removed FIRST,
            // options swept after. The attribute row is the commit point of the definition. Should
            // this ever be split out of one transaction, the leftover is options without their
            // attribute — inert, since every option route goes through its attribute — never a
            // choice attribute with no options (C-18). `DefineAttributeHandler` does the mirror
            // image on purpose (plan D13): options saved first, attribute last. Do not "align" them.
            // No option is loaded on this path, so the set-based sweep leaves no stale managed
            // entity behind (see `removeAllForAttribute()`).
            $this->attributes->remove($attribute);
            $this->options->removeAllForAttribute($attribute->id());
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
