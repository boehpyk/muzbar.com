<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exception;

use App\Domain\Catalog\ValueObject\AttributeId;
use App\Domain\Catalog\ValueObject\AttributeOptionId;

/**
 * C-25 (an attribute in use cannot be deleted) and C-26 (an option in use cannot be removed) —
 * one concept, "a listing holds a value for it", so one exception; the named constructors carry the
 * difference (ADR-0020 decision 6). Refusal is the fail-closed placeholder until
 * `listing-schema-drift` archives instead.
 */
final class AttributeInUse extends \DomainException
{
    private function __construct(
        string $message,
        private readonly AttributeId|AttributeOptionId $subjectId,
    ) {
        parent::__construct($message);
    }

    public static function cannotDelete(AttributeId $id): self
    {
        return new self(\sprintf('Attribute "%s" is used by listings and cannot be deleted.', $id->toString()), $id);
    }

    public static function cannotRemoveOption(AttributeOptionId $id): self
    {
        return new self(\sprintf('Option "%s" is used by listings and cannot be removed.', $id->toString()), $id);
    }

    /** The attribute (C-25) or the option (C-26) that is in use. */
    public function id(): AttributeId|AttributeOptionId
    {
        return $this->subjectId;
    }
}
