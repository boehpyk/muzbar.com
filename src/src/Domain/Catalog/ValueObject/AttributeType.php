<?php

declare(strict_types=1);

namespace App\Domain\Catalog\ValueObject;

/**
 * What kind of value an attribute takes, fixed when it is defined (C-14, plan D6).
 *
 * `Choice` is what the roadmap and ADR-0004 call "enum" — named so it never collides with PHP's own
 * `enum`. `Number` is a whole number (ADR-0004's `value_int`). The backing strings are what the
 * `type` column stores, so renaming one is a data migration.
 */
enum AttributeType: string
{
    case Choice = 'choice';
    case Number = 'number';
    case Boolean = 'boolean';
    case Text = 'text';
}
