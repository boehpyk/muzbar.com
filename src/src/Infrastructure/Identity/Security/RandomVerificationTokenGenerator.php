<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity\Security;

use App\Domain\Identity\Port\VerificationTokenGenerator;
use App\Domain\Identity\ValueObject\HashedVerificationToken;
use App\Domain\Identity\ValueObject\VerificationToken;

/**
 * The one place in the system where the verification flow's cryptography is actually chosen.
 *
 * The port's docblock argues at length why generating and digesting are a *single* decision rather
 * than two, and this class is what that argument buys: both halves are here, side by side, in
 * fourteen lines of body. A reviewer weakening either one has to look at the other while doing it,
 * and no adapter can pair a strong generator with a weak digest without rewriting a file whose only
 * subject matter is that pairing.
 *
 * WHY `random_bytes()` AND NOTHING ELSE. It is PHP's interface to the operating system's CSPRNG —
 * `getrandom(2)` on Linux — and its contract is the one property this whole feature reduces to:
 * output an attacker cannot predict even knowing every previous output. The alternatives all fail
 * that in ways that are invisible until somebody exploits them. `mt_rand()` is a Mersenne Twister:
 * fast, uniform, and fully reconstructible from 624 observed outputs, which an attacker can farm by
 * registering accounts. `uniqid()` is a formatted microsecond timestamp — an attacker who knows
 * roughly when a mail was sent has a search space of a few million, not 2^256. And `random_bytes()`
 * **throws** when it cannot reach a secure source rather than silently returning something weaker,
 * which is the behaviour you want from a primitive you are trusting absolutely: a loud failure at
 * registration beats predictable tokens nobody notices.
 *
 * WHY A FAST SHA-256 AND NOT ARGON2. The full argument lives on `HashedVerificationToken`'s
 * docblock — read it there rather than in a copy that will drift — but the one-line version is that
 * a password needs a slow KDF because it is low-entropy and guessable offline, while a token that is
 * literally 256 bits of CSPRNG output has no dictionary to guess from, so slowing each guess adds
 * latency to every click and buys nothing. The Domain deliberately cannot see this choice:
 * `HashedVerificationToken` validates only that a digest is non-empty and fits the column, so moving
 * to BLAKE2 tomorrow is a change to this file and to nothing else.
 *
 * The digest is unsalted and deterministic, which would be a defect for passwords and is a
 * requirement here: `EmailVerificationRequestRepository::findByTokenHash()` locates a request *by*
 * this value, so the same token must always digest to the same string. That is safe for exactly the
 * reason a salt exists to defeat elsewhere — there is no precomputable dictionary of likely 256-bit
 * random values.
 */
final readonly class RandomVerificationTokenGenerator implements VerificationTokenGenerator
{
    /**
     * 32 bytes = 256 bits, which base64 renders as 43 characters plus one `=` of padding. That is
     * not a coincidence to be maintained by hand: `VerificationToken::LENGTH` is 43 *because* this
     * number is 32, and changing one without the other makes every `generate()` throw
     * `InvalidVerificationToken` on the very next request. The relationship is asserted by the value
     * object at runtime, which is the cheapest possible place to find out.
     */
    private const int ENTROPY_BYTES = 32;

    /**
     * Named rather than inlined so the algorithm appears once, in a place a `grep` for "sha256"
     * finds, and so the reason it is fast is attached to the constant rather than to a string
     * literal buried in an expression.
     */
    private const string DIGEST_ALGORITHM = 'sha256';

    public function generate(): VerificationToken
    {
        // base64url in three steps, and each one is doing something:
        //
        //   base64_encode  — 32 raw bytes into ASCII, because a token has to survive a URL, an
        //                    email body a mail client may reflow, and a copy-paste into a browser
        //                    bar. Raw bytes survive none of those.
        //   strtr(+/ -> -_)— standard base64's `+` and `/` are both meaningful in a URL path (`/`
        //                    would split the path segment outright and `+` decodes to a space in a
        //                    query string), so the URL-safe alphabet from RFC 4648 §5 replaces them.
        //   rtrim('=')     — padding tells a decoder how many bytes the last group held. We never
        //                    decode this value — it is compared as an opaque string — so the
        //                    padding is pure noise, and `=` is another character with meaning in a
        //                    URL. Dropping it is what turns 44 characters into the 43 the value
        //                    object requires.
        //
        // The value object then re-checks the result rather than trusting this line, which is the
        // point of having a value object at all: the rule holds even for a future adapter that
        // builds the string some other way.
        return VerificationToken::fromString(
            rtrim(strtr(base64_encode(random_bytes(self::ENTROPY_BYTES)), '+/', '-_'), '='),
        );
    }

    public function hash(VerificationToken $token): HashedVerificationToken
    {
        // `reveal()` is deliberately the uncomfortable name it is — this and `TwigVerificationMailer`
        // are the only two legitimate call sites in the codebase, and a third appearing in a diff is
        // a design question rather than a detail.
        //
        // Lower-case hex (`hash()`'s default, `$binary = false`) rather than raw bytes: the result
        // goes into a `VARCHAR(255)`, into a unique index, and into `psql` output during an
        // incident, and 64 printable characters cost 32 bytes of storage that nobody will ever miss.
        return HashedVerificationToken::fromString(hash(self::DIGEST_ALGORITHM, $token->reveal()));
    }
}
