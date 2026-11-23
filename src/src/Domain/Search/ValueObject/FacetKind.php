<?php

declare(strict_types=1);

namespace App\Domain\Search\ValueObject;

/**
 * The kinds of attribute Search can facet on (S-3). The backing strings are **the same** as
 * Catalog's `AttributeType`, so the adapter maps by value, never by import (ADR-0023 fence point 4).
 * `text` is never a facet.
 */
enum FacetKind: string
{
    case Choice = 'choice';
    case Boolean = 'boolean';
    case Number = 'number';
}
