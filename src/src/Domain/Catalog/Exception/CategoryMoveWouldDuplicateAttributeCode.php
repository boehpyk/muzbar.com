<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exception;

use App\Domain\Catalog\ValueObject\AttributeCode;

/**
 * C-21 at a move: a code defined in the moving subtree is already in force along the new parent's
 * lineage, so after the move one branch would carry it twice (ADR-0018).
 *
 * Names the alphabetically first shared code, so the message is deterministic. A user error, not a
 * fault — never logged at `error`.
 */
final class CategoryMoveWouldDuplicateAttributeCode extends \DomainException
{
    private function __construct(
        private readonly AttributeCode $attributeCode,
    ) {
        parent::__construct(\sprintf('The category cannot be moved there: the attribute code "%s" would appear twice in one branch.', $attributeCode->toString()));
    }

    public static function for(AttributeCode $code): self
    {
        return new self($code);
    }

    /** For the form message. */
    public function code(): AttributeCode
    {
        return $this->attributeCode;
    }
}
