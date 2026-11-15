<?php

declare(strict_types=1);

namespace App\Application\Catalog\Command;

/** The intent "delete this attribute, and with it its options" (C-20). */
final readonly class DeleteAttribute
{
    public function __construct(
        public string $id,
    ) {
    }
}
