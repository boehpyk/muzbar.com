<?php

declare(strict_types=1);

namespace App\Application\Catalog\Handler;

use App\Application\Catalog\Command\RestoreAttribute;
use App\Domain\Catalog\Exception\AttributeNotFound;
use App\Domain\Catalog\Exception\InvalidAttributeId;
use App\Domain\Catalog\Port\AttributeRepository;
use App\Domain\Catalog\Port\CategoryTreeLock;
use App\Domain\Catalog\ValueObject\AttributeId;

/**
 * Restores an archived attribute (C-28). **Reads no facts and is never refused** (C-29): an archived
 * code stays reserved on its branch, so nothing can have taken it meanwhile — this is not a fourth
 * door to C-21. Free archived codes and this handler must read the branch codes and hand them to the
 * aggregate (ADR-0022). Restoring an active attribute is the aggregate's no-op.
 *
 * Inside `exclusively()` like every Catalog write: the schema Listing writes read changes here.
 */
final readonly class RestoreAttributeHandler
{
    public function __construct(
        private AttributeRepository $attributes,
        private CategoryTreeLock $lock,
    ) {
    }

    /**
     * @throws AttributeNotFound unknown or malformed id
     */
    public function __invoke(RestoreAttribute $command): void
    {
        $this->lock->exclusively(function () use ($command): void {
            $attribute = $this->attributes->get(self::attributeId($command->id));
            $attribute->restore();
            $this->attributes->save($attribute);
        });
    }

    /** A malformed id names an attribute that does not exist, which is all the caller needs to know. */
    private static function attributeId(string $value): AttributeId
    {
        try {
            return AttributeId::fromString($value);
        } catch (InvalidAttributeId $e) {
            throw AttributeNotFound::withMalformedId($value, $e);
        }
    }
}
