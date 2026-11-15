<?php

declare(strict_types=1);

namespace App\Application\Catalog\Command;

/**
 * The intent "category `$categoryId` now has this attribute". One command for all four types: the
 * fields a type does not use are ignored — `$unit`/`$minimum`/`$maximum` for anything but a number,
 * `$optionLabels` for anything but a choice — because the four `Attribute::define…` constructors
 * have nowhere to put them.
 *
 * Primitives in: the handler builds the value objects. `$code` is already derived from the label
 * when the admin left it blank — transliteration is the Infrastructure's job (plan D9). `$type` is
 * an `AttributeType` value; anything else is a caller bug, since the route already restricts it.
 */
final readonly class DefineAttribute
{
    /**
     * @param list<string> $optionLabels one per initial option of a choice attribute, in order
     */
    public function __construct(
        public string $categoryId,
        public string $type,
        public string $label,
        public string $code,
        public bool $required,
        public ?string $unit = null,
        public ?int $minimum = null,
        public ?int $maximum = null,
        public array $optionLabels = [],
    ) {
    }
}
