<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity\Security;

use App\Domain\Identity\Port\ResetTokenGenerator;
use App\Domain\Identity\ValueObject\HashedResetToken;
use App\Domain\Identity\ValueObject\ResetToken;

/**
 * The one place in the system where the reset flow's cryptography is actually chosen — and the one
 * class in `Identity` whose output, if it is predictable, is an account rather than a mailbox claim.
 *
 * BOTH CHOICES LIVE HERE, AND THAT IS THE POINT OF THE CLASS. The entropy source and the digest are
 * not two decisions that happen to sit next to each other; they are **one decision written in two
 * expressions**. A fast SHA-256 is the correct digest here *only because* the input is 256 bits of
 * CSPRNG output — the argument in ADR-0011 decision 2 is entirely about the pre-image, and it
 * evaporates the instant the generator stops producing one. Split them across two classes or two
 * ports and nothing stops a future change weakening the generator — a timestamp, a counter, a
 * truncated draw, `uniqid()` — while the digest carries on vouching for it, each half satisfying its
 * own contract perfectly and passing every test it has in isolation. Held together in fourteen lines
 * of body, a reviewer weakening either one has to look at the other while doing it.
 *
 * WHY `random_bytes()` AND NOTHING ELSE. It is PHP's interface to the operating system's CSPRNG
 * (`getrandom(2)` on Linux), and its contract is the single property this whole feature reduces to:
 * an attacker who has seen every previous output still cannot predict the next one. Every
 * alternative fails that invisibly. `mt_rand()` is a Mersenne Twister — fast, uniform, and fully
 * reconstructible from 624 observed outputs, which an attacker farms by requesting resets on
 * accounts they own. `uniqid()` is a formatted microsecond timestamp, so an attacker who knows
 * roughly when the mail was sent faces a search space of a few million rather than 2^256. `shuffle()`
 * and `str_shuffle()` are seeded from the same non-cryptographic generator and are worse still,
 * because they *look* random in the output while being trivially reproducible.
 *
 * And `random_bytes()` **throws** when it cannot reach a secure source rather than quietly returning
 * something weaker. That is exactly the behaviour you want from a primitive whose output is an
 * account-takeover primitive: a loud failure on the forgot-password endpoint beats guessable reset
 * links that nobody notices until an account is gone. A generator that degrades gracefully is a
 * generator that degrades silently.
 *
 * WHY 32 BYTES. 256 bits, and both things that matter downstream depend on that number rather than
 * on a taste for round figures. It is what makes the fast digest defensible (ADR-0011 decision 2):
 * there is no dictionary of likely 256-bit draws to precompute, so a slow KDF would add latency to
 * every click on every reset link — on the endpoint people reach when they are already locked out —
 * and buy nothing. And it is what makes a brute-force search over the unique index on `token_hash`
 * uninteresting: the attacker is not searching the space of live tokens, they are searching the
 * space of possible tokens, and at 2^256 the size of the table is irrelevant.
 *
 * WHY base64url AND NOT HEX. Same entropy either way; hex spends 64 characters saying what base64url
 * says in 43. The length matters because the value has to survive a URL path segment, an email body
 * that a client may reflow or line-wrap, and a copy-paste out of a plain-text part — and every extra
 * character is another chance for one of those to break it. The URL-safe alphabet matters for a
 * blunter reason: standard base64's `+`, `/` and `=` all mean something in a URL, and a mail client's
 * link detector or an over-eager percent-encoder will happily mangle them into a token that no longer
 * matches the digest we stored. `ResetToken::LENGTH` is 43 *because* this constant is 32, and the
 * value object re-checks the result rather than trusting this class.
 *
 * WHAT THIS CLASS DELIBERATELY DOES NOT DO, AND WHERE TO ARGUE ABOUT IT (ADR-0011 decision 7). There
 * is **no selector/verifier split** and **no HMAC pepper**, which is the one place this design is
 * measurably simpler than `symfonycasts/reset-password-bundle`. Both of the things we are giving up
 * are real, and both are worth about zero here:
 *
 * - A **pepper**'s value is proportional to how guessable the pre-image is, and a 43-character
 *   base64url draw from `random_bytes(32)` has no guessable pre-image at any budget. This is the
 *   "why not Argon2" argument one level up: peppering a high-entropy secret protects against an
 *   offline search that was never feasible.
 * - A **selector** — a separate non-secret lookup key, with the secret half never used in a `WHERE`
 *   clause — buys the removal of a timing channel from the index scan. An index scan over a unique
 *   btree keyed on a 256-bit-entropy digest leaks nothing an attacker can walk toward a valid token,
 *   and `HashedResetToken::equals()` already compares in constant time.
 *
 * Against those two ~0s: a second column, an application secret living inside the trust boundary,
 * and a key-rotation story nobody has written. **Declined on purpose.** This is the slice's
 * designated place to disagree — it is flagged as such in the technical plan and the ADR, so that a
 * reviewer or a security pass reading this file knows the decision was *made* rather than missed. If
 * the counter-case is made, it is reopened rather than defended.
 */
final readonly class RandomResetTokenGenerator implements ResetTokenGenerator
{
    /**
     * 32 bytes = 256 bits, which base64 renders as 43 characters plus one `=` of padding. The
     * relationship to `ResetToken::LENGTH` is load-bearing, not cosmetic: change this number without
     * changing that one and every `generate()` throws `InvalidResetToken` on the very next request.
     * The value object asserts it at runtime, which is the cheapest available place to find out.
     */
    private const int ENTROPY_BYTES = 32;

    /**
     * Named rather than inlined so the algorithm appears once, in a place a `grep` for "sha256"
     * finds, and so the reason it is allowed to be fast is attached to the constant rather than to a
     * string literal buried inside an expression.
     */
    private const string DIGEST_ALGORITHM = 'sha256';

    public function generate(): ResetToken
    {
        // base64url in three steps, each of them doing work:
        //
        //   base64_encode  — 32 raw bytes into ASCII. Raw bytes survive neither a URL, nor a mail
        //                    body, nor a copy-paste into a browser bar.
        //   strtr(+/ -> -_)— standard base64's `+` and `/` are both meaningful in a URL (`/` would
        //                    split the path segment outright, `+` decodes to a space in a query
        //                    string), so RFC 4648 §5's URL-safe alphabet replaces them.
        //   rtrim('=')     — padding only tells a decoder how many bytes the final group held. This
        //                    value is never decoded — it is compared as an opaque string — so the
        //                    padding is noise, and `=` is a third character with meaning in a URL.
        //                    Dropping it turns 44 characters into the 43 the value object requires.
        //
        // The value object then re-validates the result instead of trusting this line, which is the
        // whole point of having one: the rule holds even for a future adapter that builds the string
        // some other way.
        return ResetToken::fromString(
            rtrim(strtr(base64_encode(random_bytes(self::ENTROPY_BYTES)), '+/', '-_'), '='),
        );
    }

    public function hash(ResetToken $token): HashedResetToken
    {
        // `reveal()` is the deliberately uncomfortable reader on `ResetToken`, and this method is one
        // of its only two sanctioned call sites — the other is `TwigPasswordResetMailer`, which puts
        // it in a link. A third appearing in a diff is a design question, not a detail.
        //
        // Lower-case hex (`hash()`'s `$binary = false` default) rather than raw bytes: the result
        // goes into a `VARCHAR(255)`, into a unique index, and into `psql` output during an
        // incident, and 64 printable characters cost 32 bytes nobody will miss.
        //
        // Unsalted and deterministic, which would be a defect for a password and is a requirement
        // here: `PasswordResetRequestRepository::findByTokenHash()` locates the request *by* this
        // value, so the same token must always digest to the same string.
        return HashedResetToken::fromString(hash(self::DIGEST_ALGORITHM, $token->reveal()));
    }
}
