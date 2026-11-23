<?php

declare(strict_types=1);

namespace App\Domain\Search\ValueObject;

/**
 * How a request with search terms was answered (S-18): by the **exact** match, or — only because the
 * exact match found nothing — by the **similar** (typo) match. Decided once per request; a page never
 * mixes the two.
 */
enum TextMatch
{
    case Exact;
    case Similar;
}
