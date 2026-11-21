<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Port;

use App\Domain\Catalog\Entity\AttributeOption;
use App\Domain\Catalog\Exception\AttributeOptionNotFound;
use App\Domain\Catalog\Exception\DuplicateAttributeOption;
use App\Domain\Catalog\ValueObject\AttributeId;
use App\Domain\Catalog\ValueObject\AttributeLabel;
use App\Domain\Catalog\ValueObject\AttributeOptionId;

/**
 * The collection of AttributeOptions, plus the facts the option set-rules are decided on.
 *
 * `isLabelTaken()` (C-17) and `countActiveForAttribute()` (C-18) are only true inside
 * `CategoryTreeLock::exclusively()`. C-18 has no index behind it at all, so a count read outside the
 * lock is a write-skew bug that no test of a single request can see (ADR-0017).
 */
interface AttributeOptionRepository
{
    /**
     * Mints the identity for an option that does not exist yet. UUIDv7 in the adapter: option order
     * **is** id order (plan D3), so this must mint monotonically.
     */
    public function nextIdentity(): AttributeOptionId;

    /**
     * @throws AttributeOptionNotFound
     */
    public function get(AttributeOptionId $id): AttributeOption;

    /**
     * Whether an option of `$attributeId` other than `$except` has the same `AttributeLabel::key()`
     * as `$label`. The C-17 pre-check, for the message; the unique index `(attribute_id, label_key)`
     * is the guarantee. Dependent options will widen it with a parent-option argument (plan D14).
     *
     * Archived options included, on purpose (C-29, ADR-0022): an archived label stays reserved, so
     * restoring an option needs no C-17 check.
     */
    public function isLabelTaken(AttributeId $attributeId, AttributeLabel $label, ?AttributeOptionId $except): bool;

    /**
     * How many **active** (not archived) options `$attributeId` has — the fact
     * `Attribute::allowOptionRemoval()` decides on (C-18 counts options sellers can pick, ADR-0022).
     * Renamed from `countForAttribute()` when its meaning narrowed, so no old caller keeps the old one.
     */
    public function countActiveForAttribute(AttributeId $attributeId): int;

    /**
     * Persists one or many options, new or changed, in one flush (a defining brand list may hold
     * thousands). The adapter translates a unique-index violation into the domain exception (C-17).
     *
     * @throws DuplicateAttributeOption
     */
    public function save(AttributeOption ...$options): void;

    /** Deletes one option. Whether it may go (C-18) is the caller's check, under the lock. */
    public function remove(AttributeOption $option): void;

    /**
     * Deletes every option of `$attributeId` in one set-based statement (C-20). Legitimate per
     * ADR-0012: once the attribute is gone, C-18 has no subject, so the sweep bypasses no rule.
     * A set-based delete does not detach managed entities, so call it without having loaded any of
     * these options in the same unit of work.
     */
    public function removeAllForAttribute(AttributeId $attributeId): void;
}
