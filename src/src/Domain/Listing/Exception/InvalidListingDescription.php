<?php

declare(strict_types=1);

namespace App\Domain\Listing\Exception;

use App\Domain\Listing\ValueObject\ListingDescription;

final class InvalidListingDescription extends \DomainException
{
    public static function tooLong(int $length): self
    {
        return new self(\sprintf('A listing description may not exceed %d characters, got %d.', ListingDescription::MAX_LENGTH, $length));
    }

    public static function containsControlCharacters(): self
    {
        return new self('A listing description may not contain control characters (other than line breaks and tabs) or invalid UTF-8.');
    }
}
