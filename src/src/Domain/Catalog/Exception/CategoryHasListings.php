<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exception;

use App\Domain\Catalog\ValueObject\CategoryId;

/**
 * C-23 (a listed-on category cannot be deleted) and C-24 (it cannot gain a child, by create or by
 * move). One concept, "listed-on"; the two named constructors carry the difference (plan D10).
 */
final class CategoryHasListings extends \DomainException
{
    private function __construct(
        string $message,
        private readonly CategoryId $categoryId,
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

    public function categoryId(): CategoryId
    {
        return $this->categoryId;
    }
}
