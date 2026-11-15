<?php

declare(strict_types=1);

namespace App\Application\Catalog\Command;

/** The intent "remove option `$optionId` from attribute `$attributeId`" — never its last one (C-18). */
final readonly class RemoveAttributeOption
{
    public function __construct(
        public string $attributeId,
        public string $optionId,
    ) {
    }
}
