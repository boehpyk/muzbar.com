<?php

declare(strict_types=1);

namespace App\Application\Catalog\Command;

/** The intent "delete this category" — allowed only for a leaf (C-7). */
final readonly class DeleteCategory
{
    public function __construct(
        public string $id,
    ) {
    }
}
