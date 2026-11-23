<?php

declare(strict_types=1);

namespace App\Infrastructure\Listing\Form;

use App\Domain\Listing\ValueObject\Currency;

/**
 * What a browser may submit to the sell and edit-draft forms.
 *
 * Mutable and nullable so a refused form re-renders with what was typed. **No business
 * constraints**: the value objects and `Listing` decide (L-2…L-5), and the controller maps their
 * refusals onto these fields with the failure contract's wording. **No seller field**: the seller is
 * always the signed-in user (plan D8), and a posted `seller` is an extra field (AC-25).
 */
final class ListingFormData
{
    public ?string $title = null;

    public ?string $description = null;

    /** Decimal text as typed ("1234,50"); parsed by `Price::fromDecimal()`, never through a float. */
    public ?string $price = null;

    /** Preselected per Q2: no per-user preference yet. */
    public ?string $currency = Currency::EUR->value;

    /** A category id from the leaf choices. */
    public ?string $category = null;

    /**
     * The "Details" section (edit page only): attribute id → the seller's text, an option id, or
     * `yes`/`no`; null for a field left empty. Read by the aggregate against the schema (L-9).
     *
     * @var array<string, ?string>
     */
    public array $attributes = [];
}
