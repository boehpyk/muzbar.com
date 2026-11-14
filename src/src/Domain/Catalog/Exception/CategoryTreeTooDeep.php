<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exception;

/**
 * C-5: the write would put some category deeper than `Category::MAX_DEPTH`.
 *
 * A user error, not a fault — never logged at `error` (AC-33).
 */
final class CategoryTreeTooDeep extends \DomainException
{
    public static function create(int $maxDepth): self
    {
        return new self(\sprintf('A category cannot be created there: categories can be at most %d levels deep.', $maxDepth));
    }

    public static function move(int $maxDepth): self
    {
        return new self(\sprintf('A category cannot be moved there: its subtree would end up deeper than %d levels.', $maxDepth));
    }
}
