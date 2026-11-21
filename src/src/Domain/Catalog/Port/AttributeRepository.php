<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Port;

use App\Domain\Catalog\Entity\Attribute;
use App\Domain\Catalog\Exception\AttributeCodeAlreadyTaken;
use App\Domain\Catalog\Exception\AttributeNotFound;
use App\Domain\Catalog\ValueObject\AttributeCodes;
use App\Domain\Catalog\ValueObject\AttributeId;
use App\Domain\Catalog\ValueObject\CategoryId;

/**
 * The collection of Attributes, plus the facts about other attributes a write needs.
 *
 * The fact reads (`codesDefinedOn`, `existsForCategory`) are only as true as the moment they were
 * read: call them inside `CategoryTreeLock::exclusively()`, together with the `save()` or `remove()`
 * they justify (ADR-0016, ADR-0017, ADR-0018).
 */
interface AttributeRepository
{
    /** Mints the identity for an Attribute that does not exist yet (UUIDv7 in the adapter). */
    public function nextIdentity(): AttributeId;

    /**
     * @throws AttributeNotFound
     */
    public function get(AttributeId $id): Attribute;

    /**
     * The codes of every attribute defined on any of `$categories` — the C-21 fact (ADR-0018). The
     * caller passes a branch (`Lineage::ids()` plus `CategoryRepository::descendantsOf()`) or a
     * subtree. De-duplicated, because a set may lawfully hold one code twice (sibling branches) and
     * legacy data may too. An empty list is `AttributeCodes::none()` without asking anything.
     *
     * Archived attributes included, on purpose (C-29, ADR-0022): an archived code stays reserved on
     * its branch, so restore is not a fourth door to C-21.
     *
     * @param list<CategoryId> $categories
     */
    public function codesDefinedOn(array $categories): AttributeCodes;

    /**
     * Whether `$categoryId` defines any attribute — the C-13 delete guard's fact. Archived attributes
     * included, on purpose (C-13): an archived row is still a row that names the category.
     */
    public function existsForCategory(CategoryId $categoryId): bool;

    /**
     * Persists the aggregate, new or changed. The adapter translates a violation of the
     * `(category_id, code)` unique index into the domain exception (C-11).
     *
     * @throws AttributeCodeAlreadyTaken
     */
    public function save(Attribute $attribute): void;

    /**
     * Deletes the attribute row only. Its options are swept by
     * `AttributeOptionRepository::removeAllForAttribute()` in the same transaction (C-20).
     */
    public function remove(Attribute $attribute): void;
}
