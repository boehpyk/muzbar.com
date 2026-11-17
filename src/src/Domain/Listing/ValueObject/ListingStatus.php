<?php

declare(strict_types=1);

namespace App\Domain\Listing\ValueObject;

/**
 * Where a listing is in its life. This slice has one status and no transition (L-7); it is stored
 * anyway (plan D12) so `listing-publish` adds `Published` to a column whose every row is already
 * `draft`, instead of a backfilled column. The backing string is what `status` stores.
 */
enum ListingStatus: string
{
    case Draft = 'draft';
}
