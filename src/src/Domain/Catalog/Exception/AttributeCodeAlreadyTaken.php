<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exception;

use App\Domain\Catalog\ValueObject\AttributeCode;

/**
 * C-21: the code is already in force on the branch — used by an attribute of this category, of an
 * ancestor or of a descendant (ADR-0018). C-11 is the same-category case of it.
 *
 * Raised on two paths: `inBranch()` by the `Attribute` aggregate, deciding on the branch's codes
 * read under the tree lock; `inCategory()` by the adapter, translating a violation of the
 * `(category_id, code)` unique index, which remains the backstop for the same-category subset. The
 * form shows one message for both, so both expose the code.
 */
final class AttributeCodeAlreadyTaken extends \DomainException
{
    private function __construct(
        private readonly AttributeCode $attributeCode,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function inBranch(AttributeCode $code): self
    {
        return new self($code, \sprintf('The code "%s" is already used by an attribute of this category or of a category above or below it.', $code->toString()));
    }

    /** `$previous` is for the adapter: the operator still wants the SQLSTATE in the log. */
    public static function inCategory(AttributeCode $code, ?\Throwable $previous = null): self
    {
        return new self($code, \sprintf('The code "%s" is already used by another attribute of this category.', $code->toString()), $previous);
    }

    public function code(): AttributeCode
    {
        return $this->attributeCode;
    }
}
