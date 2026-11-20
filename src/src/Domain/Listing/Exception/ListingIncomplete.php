<?php

declare(strict_types=1);

namespace App\Domain\Listing\Exception;

use App\Domain\Listing\ValueObject\AttributeId;

/**
 * L-13: a listing that is, or is becoming, public lacks a value for a required attribute of its
 * category's **current** effective schema. Names **every** missing attribute at once, so the seller
 * sees each field to fill in one round trip (plan D2).
 */
final class ListingIncomplete extends \DomainException
{
    /**
     * @param list<AttributeId> $attributeIds
     */
    private function __construct(
        string $message,
        private readonly array $attributeIds,
    ) {
        parent::__construct($message);
    }

    /**
     * @param non-empty-list<AttributeId> $attributeIds
     */
    public static function missing(array $attributeIds): self
    {
        return new self(
            \sprintf('Required attributes without a value: %s.', implode(', ', array_map(
                static fn (AttributeId $id): string => $id->toString(),
                $attributeIds,
            ))),
            $attributeIds,
        );
    }

    /**
     * @return list<AttributeId>
     */
    public function attributeIds(): array
    {
        return $this->attributeIds;
    }
}
