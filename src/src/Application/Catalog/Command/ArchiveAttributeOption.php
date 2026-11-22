<?php

declare(strict_types=1);

namespace App\Application\Catalog\Command;

/**
 * The intent "stop offering option `$optionId` of attribute `$attributeId`, keeping it and the values
 * that name it" (C-28) — never the last active one (C-18).
 */
final readonly class ArchiveAttributeOption
{
    public function __construct(
        public string $attributeId,
        public string $optionId,
    ) {
    }
}
