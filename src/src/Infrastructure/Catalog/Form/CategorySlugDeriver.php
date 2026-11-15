<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Form;

use App\Domain\Catalog\ValueObject\CategorySlug;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Turns a category name into a slug candidate when the admin left the slug blank (plan D11, AC-25).
 *
 * Transliteration is a vendor job, so it happens here at the boundary; `CategorySlug` only
 * validates what it is given. The injected slugger is a dedicated `AsciiSlugger('en', [])` (see
 * `services.yaml`), not the framework's `slugger` service: that one maps `&` to `and` and follows the
 * request locale, so the same name could yield different slugs for different admins.
 */
final readonly class CategorySlugDeriver
{
    public function __construct(
        private SluggerInterface $slugger,
    ) {
    }

    /** `"Bass & Guitars"` → `bass-guitars`; `"Электрогитары"` → `elektrogitary`; `"!!!"` → `''`. */
    public function derive(string $name): string
    {
        return $this->slugger->slug($name)->lower()->truncate(CategorySlug::MAX_LENGTH)->trimEnd('-')->toString();
    }
}
