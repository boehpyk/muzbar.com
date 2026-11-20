<?php

declare(strict_types=1);

namespace App\Domain\Listing\ValueObject;

/**
 * What kind of value an attribute takes, in Listing's words (ADR-0020 decision 8): it decides which
 * rule a seller's input is read against (L-9) and which typed column stores it.
 *
 * The backing strings are **the same** as Catalog's `AttributeType`, so the adapter maps one to the
 * other by value — never by import (ListingIsolationTest). An unknown string fails closed in the
 * adapter rather than guessing a kind.
 */
enum AttributeKind: string
{
    case Choice = 'choice';
    case Number = 'number';
    case Boolean = 'boolean';
    case Text = 'text';
}
