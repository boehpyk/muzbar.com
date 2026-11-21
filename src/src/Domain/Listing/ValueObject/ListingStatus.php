<?php

declare(strict_types=1);

namespace App\Domain\Listing\ValueObject;

/**
 * Where a listing is in its life (L-12). Three statuses, three transitions, all owned by `Listing`:
 *
 * - `Draft → Published` and `Withdrawn → Published` by `publish()`;
 * - `Published → Withdrawn` by `withdraw()`.
 *
 * **Nothing returns to `Draft`**: "was public once" is a fact (`publishedAt` stays set), and
 * Phase 2's lifecycle will want to tell a withdrawn listing from a never-published one. The backing
 * string is what the `varchar(16)` `status` column stores, so a new case needs no DDL.
 */
enum ListingStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Withdrawn = 'withdrawn';
}
