<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exception;

use App\Domain\Identity\ValueObject\OAuthIdentity;
use App\Domain\Identity\ValueObject\OAuthProvider;

/**
 * A provider returned a subject we refuse to store — a malformed provider response, not a user
 * error. The message names the provider and the length, never the subject itself (a personal
 * identifier has no business in a log line).
 */
final class InvalidOAuthIdentity extends \DomainException
{
    public static function emptySubject(OAuthProvider $provider): self
    {
        return new self(\sprintf('An identity from provider "%s" has an empty subject.', $provider->value));
    }

    public static function subjectTooLong(OAuthProvider $provider, int $length): self
    {
        return new self(\sprintf('An identity subject from provider "%s" may not exceed %d characters, got %d.', $provider->value, OAuthIdentity::MAX_SUBJECT_LENGTH, $length));
    }
}
