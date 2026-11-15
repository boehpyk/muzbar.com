<?php

declare(strict_types=1);

namespace App\Application\Catalog\Command;

/** The intent "choice attribute `$attributeId` gains an option labelled `$label`", added last. */
final readonly class AddAttributeOption
{
    public function __construct(
        public string $attributeId,
        public string $label,
    ) {
    }
}
