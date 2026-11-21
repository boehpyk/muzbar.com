<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Port;

use App\Domain\Catalog\ValueObject\AttributeId;
use App\Domain\Catalog\ValueObject\AttributeOptionId;

/**
 * Catalog's view of which attributes and options listings use (ADR-0020 decision 6) — Catalog's
 * second port into Listing, stated in Catalog's own words like `CategoryUsage`, so neither
 * Catalog's Domain nor its Application layer imports anything from `Listing`. The adapter lives
 * with the data's owner, in `Infrastructure/Listing`.
 *
 * It backs C-25 (an attribute in use cannot be deleted) and C-26 (an option in use cannot be
 * removed): the guards of **hard** delete, which keep a stored value from pointing at nothing. An
 * attribute or option in use is archived instead (C-28, ADR-0022). Both count **dormant** values
 * too — they are real rows naming real ids, and a restore or a move back wakes them.
 *
 * Both answers are **only true inside `CategoryTreeLock::exclusively()`**: Listing writes hold the
 * same lock in shared mode, so none can store a value between this read and the caller's commit.
 */
interface AttributeUsage
{
    /** Whether any listing, of any status, holds a value for `$attributeId`. */
    public function isInUse(AttributeId $attributeId): bool;

    /**
     * Whether any listing, of any status, holds `$optionId` as its value for `$attributeId`. The
     * attribute id is redundant for the answer and there for the index: values are indexed on
     * `(attribute_id, option_id)`.
     */
    public function isOptionInUse(AttributeId $attributeId, AttributeOptionId $optionId): bool;
}
