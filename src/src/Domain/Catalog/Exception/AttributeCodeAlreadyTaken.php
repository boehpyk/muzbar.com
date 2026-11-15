<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exception;

use App\Domain\Catalog\ValueObject\AttributeCode;

/**
 * C-11: another attribute of the same defining category already uses this code.
 *
 * Raised on both paths, as `CategorySlugAlreadyTaken` is: the handler's pre-check and the adapter's
 * translation of a unique-index violation.
 */
final class AttributeCodeAlreadyTaken extends \DomainException
{
    /** `$previous` is for the adapter: the operator still wants the SQLSTATE in the log. */
    public static function inCategory(AttributeCode $code, ?\Throwable $previous = null): self
    {
        return new self(\sprintf('The code "%s" is already used by another attribute of this category.', $code->toString()), 0, $previous);
    }
}
