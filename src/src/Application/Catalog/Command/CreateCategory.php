<?php

declare(strict_types=1);

namespace App\Application\Catalog\Command;

/**
 * The intent "add this category under that parent, or at root level when `$parentId` is null".
 *
 * Primitives in, as in `RegisterUser`: the handler builds the value objects, so the invariants hold
 * whichever adapter sent the command. `$slug` is already derived by the time it gets here — deriving
 * one from a name is a transliteration job for the Infrastructure boundary (plan D11).
 */
final readonly class CreateCategory
{
    public function __construct(
        public string $name,
        public string $slug,
        public ?string $parentId,
    ) {
    }
}
