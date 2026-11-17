<?php

declare(strict_types=1);

namespace App\Domain\Listing\Exception;

use App\Domain\Listing\ValueObject\ListingTitle;

final class InvalidListingTitle extends \DomainException
{
    public static function length(int $length): self
    {
        return new self(\sprintf('A listing title must be %d to %d characters, got %d.', ListingTitle::MIN_LENGTH, ListingTitle::MAX_LENGTH, $length));
    }

    public static function containsControlCharacters(): self
    {
        return new self('A listing title may not contain line breaks, control characters or invalid UTF-8.');
    }
}
