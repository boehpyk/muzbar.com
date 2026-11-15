<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Port;

use App\Domain\Catalog\Entity\Attribute;
use App\Domain\Catalog\Exception\AttributeCodeAlreadyTaken;
use App\Domain\Catalog\Exception\AttributeNotFound;
use App\Domain\Catalog\ValueObject\AttributeCode;
use App\Domain\Catalog\ValueObject\AttributeId;
use App\Domain\Catalog\ValueObject\CategoryId;

/**
 * The collection of Attributes, plus the facts about other attributes a write needs.
 *
 * The fact reads (`isCodeTaken`, `existsForCategory`) are only as true as the moment they were
 * read: call them inside `CategoryTreeLock::exclusively()`, together with the `save()` or `remove()`
 * they justify (ADR-0016, ADR-0017).
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
     * Whether an attribute other than `$except` already uses `$code` in `$categoryId`. The C-11
     * pre-check — it exists for the error message; the unique index is the guarantee.
     */
    public function isCodeTaken(CategoryId $categoryId, AttributeCode $code, ?AttributeId $except): bool;

    /** Whether `$categoryId` defines any attribute — the C-13 delete guard's fact. */
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
