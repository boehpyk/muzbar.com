<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Entity;

use App\Domain\Catalog\ValueObject\AttributeId;
use App\Domain\Catalog\ValueObject\AttributeLabel;
use App\Domain\Catalog\ValueObject\AttributeOptionId;

/**
 * One entry of a choice attribute ("Left-handed", "Fender") — its own aggregate, not a part of
 * `Attribute` (plan D2, ADR-0017). It belongs to one attribute **by id**, for ever (C-14), and its id
 * survives a relabel (C-19), so a listing value that points at it survives a label fix.
 *
 * The set-rules — unique labels within an attribute (C-17), never the last option (C-18) — span
 * instances, so this object cannot decide them. They are handler checks under `CategoryTreeLock`,
 * with a unique index `(attribute_id, label_key)` as C-17's guarantee.
 *
 * `labelKey` is a persisted derived field: it exists only so one plain unique index can enforce
 * case-insensitive uniqueness (plan D3 — an expression index would re-diff forever). It is assigned
 * from `AttributeLabel::key()` in the same statement as the label, every time, and has no setter.
 *
 * C-28, archiving is a reversible **state**: `archive()` / `restore()` (ADR-0022). An archived option
 * is among no rule's options, but its row — and every listing value naming its id — stays. Whether
 * an active option may be archived (C-18, counting active options) is `Attribute::allowOptionRemoval()`.
 *
 * No `delete()` (ADR-0012), no domain events (plan D10). Order is insertion order, derived from the
 * UUIDv7 id; no position is stored.
 *
 * Properties are plain `private`, not `readonly` (ADR-0007): Doctrine hydrates them.
 */
final class AttributeOption
{
    private string $labelKey;

    /** Null while active (C-28); set to the first archive instant, whole seconds (Clock, ADR-0007). */
    private ?\DateTimeImmutable $archivedAt = null;

    private function __construct(
        private AttributeOptionId $id,
        private AttributeId $attributeId,
        private AttributeLabel $label,
    ) {
        $this->labelKey = $label->key();
    }

    /**
     * @internal call `Attribute::newOption()`, which decides whether this attribute may have options
     *           at all (C-15). Public only because PHP has no package visibility — a convention, not
     *           an enforcement (ADR-0017, Consequences).
     */
    public static function create(AttributeOptionId $id, AttributeId $attributeId, AttributeLabel $label): self
    {
        return new self($id, $attributeId, $label);
    }

    /**
     * Keeps the id (C-19). Whether another option of the attribute already uses the label (C-17) is
     * the handler's pre-check plus the unique index.
     */
    public function relabel(AttributeLabel $label): void
    {
        $this->label = $label;
        $this->labelKey = $label->key();
    }

    /**
     * C-28: no rule offers this option any more; values naming it become stale, not dormant (their
     * attribute is still in force). On an archived option a no-op that **keeps the first instant**.
     *
     * @param \DateTimeImmutable $now the `Clock` port's now (whole seconds, ADR-0007)
     */
    public function archive(\DateTimeImmutable $now): void
    {
        if (null !== $this->archivedAt) {
            return;
        }

        $this->archivedAt = $now;
    }

    /**
     * C-28, and C-29 is why this takes no facts and is never refused: an archived option's label
     * **stays reserved** — `isLabelTaken()` and the `(attribute_id, label_key)` index include archived
     * rows, so no other option of the attribute can have taken it meanwhile (ADR-0022). On an active
     * option a no-op.
     */
    public function restore(): void
    {
        $this->archivedAt = null;
    }

    public function id(): AttributeOptionId
    {
        return $this->id;
    }

    public function attributeId(): AttributeId
    {
        return $this->attributeId;
    }

    public function label(): AttributeLabel
    {
        return $this->label;
    }

    /** The case-folded label (`AttributeLabel::key()`), as stored for the unique index. */
    public function labelKey(): string
    {
        return $this->labelKey;
    }

    public function archivedAt(): ?\DateTimeImmutable
    {
        return $this->archivedAt;
    }

    public function isArchived(): bool
    {
        return null !== $this->archivedAt;
    }
}
