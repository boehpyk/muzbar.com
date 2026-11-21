<?php

declare(strict_types=1);

namespace App\Application\Catalog\Command;

/** The intent "take this attribute out of every active schema, keeping it and its values" (C-28). */
final readonly class ArchiveAttribute
{
    public function __construct(
        public string $id,
    ) {
    }
}
