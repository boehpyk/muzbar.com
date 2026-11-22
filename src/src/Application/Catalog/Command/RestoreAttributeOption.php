<?php

declare(strict_types=1);

namespace App\Application\Catalog\Command;

/** The intent "offer archived option `$optionId` of attribute `$attributeId` again" (C-28). */
final readonly class RestoreAttributeOption
{
    public function __construct(
        public string $attributeId,
        public string $optionId,
    ) {
    }
}
