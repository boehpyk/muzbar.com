<?php

declare(strict_types=1);

namespace App\Application\Catalog\Command;

/**
 * The intent "this category is now called `$name`, keyed `$slug`, under `$parentId`" (null: at
 * root level). One submit of the edit form — any of the three may have changed, and the handler
 * applies all of them or none.
 */
final readonly class EditCategory
{
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public ?string $parentId,
    ) {
    }
}
