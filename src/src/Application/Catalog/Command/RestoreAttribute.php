<?php

declare(strict_types=1);

namespace App\Application\Catalog\Command;

/** The intent "put this archived attribute back in force" (C-28); its dormant values wake with it. */
final readonly class RestoreAttribute
{
    public function __construct(
        public string $id,
    ) {
    }
}
