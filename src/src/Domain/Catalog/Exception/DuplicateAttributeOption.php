<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exception;

use App\Domain\Catalog\ValueObject\AttributeLabel;

/**
 * C-17: an attribute already has (or is being defined with) an option of this label, compared
 * case-insensitively through `AttributeLabel::key()`.
 *
 * Raised on every path — `Attribute::defineChoice()`'s in-memory batch check, the handlers'
 * pre-check, and the adapter's translation of a unique-index violation — so the admin who loses a
 * race sees what the admin who does not sees. It carries the offending label for the message.
 */
final class DuplicateAttributeOption extends \DomainException
{
    private function __construct(
        private readonly AttributeLabel $label,
        ?\Throwable $previous,
    ) {
        parent::__construct(\sprintf('"%s" is already an option of this attribute.', $label->toString()), 0, $previous);
    }

    /**
     * `$previous` is for the adapter: the operator still wants the SQLSTATE in the log.
     */
    public static function of(AttributeLabel $label, ?\Throwable $previous = null): self
    {
        return new self($label, $previous);
    }

    public function label(): AttributeLabel
    {
        return $this->label;
    }
}
