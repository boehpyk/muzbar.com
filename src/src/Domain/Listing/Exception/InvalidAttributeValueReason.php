<?php

declare(strict_types=1);

namespace App\Domain\Listing\Exception;

/**
 * Why a seller's input for one attribute was refused (L-9, L-10). The edit form chooses its message
 * from this, so a `match` over it is exhaustive: a new reason is a compile-time question for every
 * place that explains a refusal, not a silent default.
 */
enum InvalidAttributeValueReason
{
    case NotAnOption;
    case NotAWholeNumber;
    case OutOfRange;
    case NotYesOrNo;
    case BadText;
    case NotInForce;
}
