<?php

declare(strict_types=1);

namespace App\Domain\Listing\ValueObject;

/**
 * What Listing asks the catalog about the category it files a listing under (L-2): does it exist,
 * and is it a leaf?
 *
 * A **fact**, like Catalog's `Lineage`: it is only true while the catalog is held steady.
 * Nothing in this value can know whether an admin added a subcategory a moment after it was read;
 * `Categories::holdSteady()` is what keeps the catalog still between the read and the save
 * (ADR-0019 decision 5). A status read outside `holdSteady()` is a guess.
 *
 * Not backed: it is never stored.
 */
enum CategoryStatus
{
    case Missing;
    case Leaf;
    case Branch;
}
