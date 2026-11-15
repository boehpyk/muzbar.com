<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exception;

use App\Domain\Catalog\ValueObject\CategorySlug;

/**
 * C-3: another category at the same level already uses this slug.
 *
 * Raised on both paths, as `EmailAlreadyRegistered` is: the handler's pre-check and the adapter's
 * translation of a unique-index violation. One exception for both, so the admin who loses a race
 * sees exactly what the admin who does not sees.
 */
final class CategorySlugAlreadyTaken extends \DomainException
{
    /**
     * `$previous` is for the adapter: when translating a unique-constraint violation, the operator
     * still wants the SQLSTATE in the log. The Domain neither inspects it nor knows what produced it.
     */
    public static function among(CategorySlug $slug, ?\Throwable $previous = null): self
    {
        return new self(\sprintf('The slug "%s" is already used by another category at this level.', $slug->toString()), 0, $previous);
    }
}
