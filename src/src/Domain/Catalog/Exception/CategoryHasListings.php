<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exception;

use App\Domain\Catalog\ValueObject\CategoryId;

/**
 * C-23 (a listed-on category cannot be deleted), C-24 (it cannot gain a child, by create or by
 * move) and C-27 (a category cannot be moved while any listing filed in its subtree holds any
 * value — ADR-0020 decision 6, coarse on purpose until `listing-schema-drift`). One concept,
 * "listed-on"; the three named constructors carry the difference (listing-draft plan D10).
 *
 * `isMove()` exists because the admin form puts C-27 on the **parent** field with its own message,
 * while C-24 on the same edit is about the *new* parent; the flag is the smallest way to tell them
 * apart without a second exception for one concept.
 */
final class CategoryHasListings extends \DomainException
{
    private function __construct(
        string $message,
        private readonly CategoryId $categoryId,
        private readonly bool $move = false,
    ) {
        parent::__construct($message);
    }

    public static function cannotDelete(CategoryId $id): self
    {
        return new self(\sprintf('Category "%s" has listings filed under it and cannot be deleted.', $id->toString()), $id);
    }

    public static function cannotGainChild(CategoryId $id): self
    {
        return new self(\sprintf('Category "%s" has listings filed under it and cannot have subcategories.', $id->toString()), $id);
    }

    /** C-27: `$id` is the category being moved, not the new parent. */
    public static function cannotMove(CategoryId $id): self
    {
        return new self(\sprintf('Listings under category "%s" have details filled in, so it cannot be moved.', $id->toString()), $id, true);
    }

    /** True exactly for `cannotMove()`. */
    public function isMove(): bool
    {
        return $this->move;
    }

    public function categoryId(): CategoryId
    {
        return $this->categoryId;
    }
}
