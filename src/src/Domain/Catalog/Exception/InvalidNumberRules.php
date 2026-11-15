<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exception;

/** C-16: a number attribute's minimum may not exceed its maximum. */
final class InvalidNumberRules extends \DomainException
{
    public static function minimumAboveMaximum(int $minimum, int $maximum): self
    {
        return new self(\sprintf('The minimum (%d) may not be above the maximum (%d).', $minimum, $maximum));
    }
}
