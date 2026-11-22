<?php

declare(strict_types=1);

namespace App\Application\Catalog\Handler;

use App\Application\Catalog\Command\DeleteAttribute;
use App\Domain\Catalog\Exception\AttributeInUse;
use App\Domain\Catalog\Exception\AttributeNotFound;
use App\Domain\Catalog\Exception\InvalidAttributeId;
use App\Domain\Catalog\Port\AttributeOptionRepository;
use App\Domain\Catalog\Port\AttributeRepository;
use App\Domain\Catalog\Port\AttributeUsage;
use App\Domain\Catalog\Port\CategoryTreeLock;
use App\Domain\Catalog\ValueObject\AttributeId;

/**
 * Hard-deletes an attribute and sweeps its options in the same transaction (C-20, plan D7). No
 * aggregate has a `delete()` (ADR-0012), and the set-based sweep bypasses no rule: C-18 governs a
 * living choice attribute, and once the attribute is gone it has no subject.
 *
 * Hard delete, for rows no listing uses. C-25 (ADR-0020 decision 6): an attribute any listing
 * holds a value for — dormant values included — is refused, read under the lock so no seller can
 * store a value between the read and the delete. Archiving is the in-use path
 * (`ArchiveAttributeHandler`, ADR-0022).
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
        private AttributeUsage $usage,
    ) {
    }

    /**
     * @throws AttributeNotFound unknown or malformed id
     * @throws AttributeInUse    a listing holds a value for it (C-25)
     */
    public function __invoke(DeleteAttribute $command): void
    {
        $this->lock->exclusively(function () use ($command): void {
            $attribute = $this->attributes->get($this->attributeId($command->id));

            if ($this->usage->isInUse($attribute->id())) {
                throw AttributeInUse::cannotDelete($attribute->id());
            }

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
