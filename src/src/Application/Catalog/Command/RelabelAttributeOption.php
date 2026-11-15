<?php

declare(strict_types=1);

namespace App\Application\Catalog\Command;

/**
 * The intent "option `$optionId` of attribute `$attributeId` is now labelled `$label`". The option
 * keeps its id (C-19); naming the attribute too means an option reached through the wrong attribute
 * is not found (AC-21).
 */
final readonly class RelabelAttributeOption
{
    public function __construct(
        public string $attributeId,
        public string $optionId,
        public string $label,
    ) {
    }
}
