<?php

declare(strict_types=1);

namespace App\Domain\Identity\ValueObject;

use App\Domain\Identity\Exception\InvalidOAuthIdentity;

/**
 * "This person is subject X at provider Y" — the identifier a provider vouches for (ADR-0014).
 *
 * THE SUBJECT IS THE IDENTIFIER, NOT THE EMAIL. Google documents `sub` as the stable account id and
 * the email as a mutable attribute, so a returning user is recognised by this value object and the
 * email is consulted only when no user holds it.
 *
 * THE SUBJECT IS OPAQUE, SO IT IS MATCHED BYTE-FOR-BYTE. Unlike `Email`, nothing here trims or
 * case-folds: the provider defines what the string means, and "normalising" a value we do not own
 * could only ever make two different subjects collide.
 *
 * DELIBERATELY NO `__toString()`. The subject is a personal identifier; without implicit string
 * conversion it cannot slip into a log line or exception message by interpolation — reading it takes
 * an explicit `subject()` call that a reviewer can see.
 */
final readonly class OAuthIdentity
{
    /**
     * Also the bound the persistence layer validates against on read.
     */
    public const int MAX_SUBJECT_LENGTH = 255;

    private function __construct(
        private OAuthProvider $provider,
        private string $subject,
    ) {
    }

    public static function of(OAuthProvider $provider, string $subject): self
    {
        if ('' === $subject) {
            throw InvalidOAuthIdentity::emptySubject($provider);
        }

        $length = mb_strlen($subject);

        if ($length > self::MAX_SUBJECT_LENGTH) {
            throw InvalidOAuthIdentity::subjectTooLong($provider, $length);
        }

        return new self($provider, $subject);
    }

    public function provider(): OAuthProvider
    {
        return $this->provider;
    }

    public function subject(): string
    {
        return $this->subject;
    }

    public function isFrom(OAuthProvider $provider): bool
    {
        // PHPStan is right that this is always true today: `OAuthProvider` has one case. The
        // comparison is still the rule — it is what keeps `equals()` and invariant I-28 ("one
        // identity per provider") correct the day a second case lands — so the finding is ignored
        // here, once, rather than the rule being deleted. The ignore expires itself: with a second
        // case the error disappears and PHPStan reports the ignore as unmatched, failing the build
        // until this comment and the ignore are removed.
        // @phpstan-ignore identical.alwaysTrue
        return $this->provider === $provider;
    }

    public function equals(self $other): bool
    {
        return $this->isFrom($other->provider) && $this->subject === $other->subject;
    }
}
