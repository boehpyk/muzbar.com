<?php

declare(strict_types=1);

namespace App\Application\Catalog\Command;

/**
 * The intent "attribute `$id` is now labelled, coded and required like this" — one submit of the
 * edit form, applied all or nothing. `$unit`/`$minimum`/`$maximum` are its number rules; for any
 * other type they must all be null, or the aggregate refuses (C-15). The type and the defining
 * category are not here: they never change (C-14).
 */
final readonly class EditAttribute
{
    public function __construct(
        public string $id,
        public string $label,
        public string $code,
        public bool $required,
        public ?string $unit = null,
        public ?int $minimum = null,
        public ?int $maximum = null,
    ) {
    }
}
