<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exception;

use App\Domain\Catalog\ValueObject\AttributeType;

/**
 * C-15, type coherence: number rules belong to a number attribute and options to a choice attribute.
 * Reaching this from the admin UI means a tampered request; the forms never offer it.
 */
final class AttributeRuleNotApplicable extends \DomainException
{
    public static function numberRules(AttributeType $type): self
    {
        return new self(\sprintf('Number rules apply only to a number attribute, not to a %s attribute.', $type->value));
    }

    public static function options(AttributeType $type): self
    {
        return new self(\sprintf('Options apply only to a choice attribute, not to a %s attribute.', $type->value));
    }
}
