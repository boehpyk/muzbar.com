<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exception;

use App\Domain\Catalog\ValueObject\CategoryId;

/**
 * C-4b: a walk of the stored tree found a cycle, or a chain longer than the depth ceiling allows.
 *
 * A `\LogicException`, deliberately **not** a `\DomainException`: it is not a refusal of a user's
 * request, it is data that must not exist. It is meant to escape as a 500, be logged at `error`
 * and reach Sentry (AC-33) — no controller should catch it and render a friendly message.
 */
final class CorruptCategoryTree extends \LogicException
{
    public static function at(CategoryId $id, int $maxSteps): self
    {
        return new self(\sprintf('The category tree around "%s" is corrupt: a cycle or a chain longer than %d levels.', $id->toString(), $maxSteps));
    }
}
