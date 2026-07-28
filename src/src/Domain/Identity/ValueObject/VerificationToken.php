<?php

declare(strict_types=1);

namespace App\Domain\Identity\ValueObject;

use App\Domain\Identity\Exception\InvalidVerificationToken;

/**
 * The plaintext secret that proves an address is reachable — alive only in memory, from the
 * generator to the mail body, and from the URL to the hasher.
 *
 * It is a value object because the **token policy is domain knowledge**: "a verification token is
 * 32 bytes of randomness rendered as 43 URL-safe characters" is a statement about how strong the
 * challenge is, and it must hold for the resend form, the registration listener and any future
 * adapter — not just for the one route that happens to exist.
 *
 * THIS IS THE ONLY PLACE THAT FORMAT IS CHECKED, AND THE ROUTE DELIBERATELY DOES NOT REPEAT IT.
 * `app_verify_email` accepts any single path segment (`[^/]+`) and leaves the judgement here. This
 * is the *opposite* call from slice 1's `PlainPassword`, where the form constraints duplicate the
 * rule on purpose, and the difference is what the duplicate buys. A duplicated rule in a form
 * constraint buys a friendly field-level error next to the input, rendered before the Domain is
 * ever reached — real value, paid for with a copy. A duplicated rule in a route requirement buys
 * nothing and costs the user their way out: the router's rejection is a bare 404 that no `catch`
 * can turn into a redirect, so a mangled link — the mail client wrapped the line, someone pasted
 * badly — dead-ends instead of landing on the resend form that fixes it. AC-12 requires a
 * malformed token to answer exactly like an unknown one, and only this class can make that true.
 * Re-adding the regex to the route would reintroduce that bug silently, because every test that
 * exercises a *well-formed* token would still pass.
 *
 * The length is not arbitrary. 32 bytes is 256 bits of CSPRNG output; base64url encodes 3 bytes per
 * 4 characters, so 32 bytes becomes 43 characters plus one `=` of padding, which is stripped
 * because padding in a URL path is noise. Hence exactly 43, and hence an alphabet of `A-Z a-z 0-9
 * - _` with no `+`, `/` or `=` — the three characters that make standard base64 unsafe in a URL.
 *
 * NOTHING ABOUT THE DIGEST IS STATED HERE. How this token is turned into a `HashedVerificationToken`
 * is the `VerificationTokenGenerator` adapter's decision (SHA-256 today, per ADR-0009), and the
 * Domain deliberately cannot name it.
 *
 * Three guards keep the secret out of logs, and they are the same three `PlainPassword` carries:
 * `#[\SensitiveParameter]` on both the constructor and the factory so PHP redacts the argument in
 * stack traces, `__debugInfo()` masking the property so `var_dump()` and every dumper built on it
 * print `***`, and deliberately **no `__toString()`** — an accidental string interpolation must be
 * a `TypeError` at the moment it is written, not a token in a log file discovered a year later.
 * A unit test asserts that absence by reflection, because "we did not add a method" is otherwise
 * invisible to review.
 */
final readonly class VerificationToken
{
    /**
     * Exactly 43 characters: 32 CSPRNG bytes, base64url, unpadded. Public because two call sites
     * need to quote the same number rather than each remembering it — the failure message in
     * `InvalidVerificationToken::malformed()` and the `RandomVerificationTokenGenerator` adapter,
     * whose byte count is 43 characters' worth *because* of this constant. Notably **not** the
     * route, which does no format checking at all; see the class docblock for why.
     */
    public const int LENGTH = 43;

    /**
     * Length and charset in one expression, and **not** case-insensitive: base64url distinguishes
     * `a` from `A`, so lower-casing or accepting a case-folded variant would silently accept a
     * different token than the one that was mailed.
     */
    private const string FORMAT = '/^[A-Za-z0-9_-]{43}$/';

    private function __construct(
        #[\SensitiveParameter]
        private string $value,
    ) {
    }

    /**
     * The value is *not* trimmed. Trimming would be a guess about what the user meant, and a
     * whitespace-padded token is a token that arrived damaged — which is exactly the case this
     * method exists to refuse.
     */
    public static function fromString(#[\SensitiveParameter] string $value): self
    {
        if (1 !== preg_match(self::FORMAT, $value)) {
            throw InvalidVerificationToken::malformed(self::LENGTH);
        }

        return new self($value);
    }

    /**
     * The one and only reader, named to be uncomfortable — the same name `PlainPassword` uses, on
     * purpose: one verb across the whole context means one grep finds every place a secret is
     * unwrapped, whereas two synonyms mean a reviewer has to remember both.
     *
     * Unlike `PlainPassword`, this one has **two** legitimate call sites rather than one, and it is
     * worth knowing which: the `VerificationTokenGenerator` adapter, which digests it, and the
     * `VerificationMailer` adapter, which builds the link. Both are Infrastructure, both are the
     * outermost edge of the flow, and a third call site appearing in a diff is a design question,
     * not a detail.
     */
    public function reveal(): string
    {
        return $this->value;
    }

    /**
     * @return array{value: string}
     */
    public function __debugInfo(): array
    {
        return ['value' => '***'];
    }
}
