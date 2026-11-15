<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exception;

/** C-18 at definition: a choice attribute is defined with at least one option. */
final class InvalidAttributeOptions extends \DomainException
{
    public static function none(): self
    {
        return new self('A choice attribute needs at least one option.');
    }
}
