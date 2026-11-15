<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exception;

use App\Domain\Catalog\ValueObject\AttributeId;

/** C-18: the last option of a choice attribute cannot be removed — delete the attribute instead. */
final class AttributeNeedsAnOption extends \DomainException
{
    public static function cannotRemoveLast(AttributeId $id): self
    {
        return new self(\sprintf('Attribute "%s" needs at least one option; its last option cannot be removed.', $id->toString()));
    }
}
