<?php

declare(strict_types=1);

namespace App\Application\Catalog\Handler;

use App\Application\Catalog\Command\ArchiveAttribute;
use App\Domain\Catalog\Exception\AttributeNotFound;
use App\Domain\Catalog\Exception\InvalidAttributeId;
use App\Domain\Catalog\Port\AttributeRepository;
use App\Domain\Catalog\Port\CategoryTreeLock;
use App\Domain\Catalog\ValueObject\AttributeId;
use App\Domain\Shared\Port\Clock;

/**
 * Archives an attribute (C-28, ADR-0022): the in-use path that `DeleteAttributeHandler` refuses.
 * Nothing is deleted and no listing row is touched — values for it simply go dormant (L-17), and a
 * restore wakes them. No usage check: archiving strands nothing, which is the point. Archiving an
 * archived attribute is the aggregate's no-op and keeps the first instant.
 *
 * Inside `exclusively()` like every Catalog write: Listing writes read the schema under the same
 * lock in shared mode, so none can validate against an attribute this archive is taking away.
 */
final readonly class ArchiveAttributeHandler
{
    public function __construct(
        private AttributeRepository $attributes,
        private CategoryTreeLock $lock,
        private Clock $clock,
    ) {
    }

    /**
     * @throws AttributeNotFound unknown or malformed id
     */
    public function __invoke(ArchiveAttribute $command): void
    {
        $this->lock->exclusively(function () use ($command): void {
            $attribute = $this->attributes->get(self::attributeId($command->id));
            $attribute->archive($this->clock->now());
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
