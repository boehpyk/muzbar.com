<?php

declare(strict_types=1);

namespace App\Domain\Identity\ValueObject;

use App\Domain\Identity\Exception\InvalidResetToken;

/**
 * The plaintext secret that lets someone set a new password without knowing the old one — alive only
 * in memory, from the generator to the mail body, and from the submitted link to the hasher.
 *
 * It is a value object because the **token policy is domain knowledge**: "a reset token is 32 bytes
 * of randomness rendered as 43 URL-safe characters" is a statement about how strong the challenge
 * is, and it must hold for the forgot-password form, the link check, the reset submission and any
 * future adapter — not just for the one route that happens to exist today.
 *
 * WHAT THIS TOKEN IS, AND WHY IT IS TREATED MORE HARSHLY THAN `VerificationToken`. A verification
 * token proves that a mailbox is reachable; whoever steals one can verify an address they would
 * already have had to control in order to receive it. A reset token **is an account-takeover
 * primitive** — whoever holds it owns the account until it is redeemed or expires. Structurally the
 * two classes are twins; in a threat model they are not, and that asymmetry is why the lifetime is
 * one hour rather than twenty-four (ADR-0011 decision 3) and why the token never travels in the URL
 * of a page the user sits on (ADR-0011 decision 5).
 *
 * A SEPARATE TYPE FROM `VerificationToken` ON PURPOSE — and the fact that the two files are nearly
 * identical is the strongest argument *for* keeping them apart, not against it. The type system is
 * exactly where the confusion must not be expressible: a verification token presented at the reset
 * endpoint, or a reset token presented at the verification endpoint, has to be a **compile error**,
 * not a lookup that quietly misses. One shared `ChallengeToken` would make both of those mistakes
 * writeable, and the digests are stored in different tables, so the miss would look like an ordinary
 * expired link rather than like the category error it is. See the technical plan's *Reuse vs
 * duplication* for the same argument made about the aggregates and the id value objects.
 *
 * THIS IS THE ONLY PLACE THAT FORMAT IS CHECKED, AND THE ROUTE DELIBERATELY DOES NOT REPEAT IT.
 * `app_reset_password_check` accepts any single path segment (`[^/]+`) and leaves the judgement
 * here, for the reason `VerificationToken` states at length: a route requirement's rejection is a
 * bare 404 that no `catch` can turn into the single neutral invalid-link response, so a mangled link
 * would dead-end instead of landing back on the form that fixes it. AC-16 requires a malformed token
 * to answer byte-identically to an unknown one, and only this class can make that true. AC-17
 * additionally requires the refusal to happen **before any database query**, which it does: the
 * handler builds this value object first and never reaches the repository.
 *
 * The length is not arbitrary. 32 bytes is 256 bits of CSPRNG output; base64url encodes 3 bytes per
 * 4 characters, so 32 bytes becomes 43 characters plus one `=` of padding, which is stripped because
 * padding in a URL path is noise. Hence exactly 43, and hence an alphabet of `A-Z a-z 0-9 - _` with
 * no `+`, `/` or `=` — the three characters that make standard base64 unsafe in a URL. Note which
 * half of that is Domain knowledge and which is not: **the policy — 32 bytes of CSPRNG entropy — is
 * ours**, because "how hard is this challenge to guess" is a business statement about how safe the
 * account is. The **encoding** is the `ResetTokenGenerator` adapter's choice; base64url merely
 * happens to render 256 bits as 43 characters, and a future adapter that chose base32 would need a
 * different constant here, not a different security posture.
 *
 * NOTHING ABOUT THE DIGEST IS STATED HERE either. How this token becomes a `HashedResetToken` is the
 * `ResetTokenGenerator` adapter's decision (SHA-256 today, per ADR-0011 decision 2), and the Domain
 * deliberately cannot name it.
 *
 * Four guards keep the secret out of logs, and they are the same four `PlainPassword` and
 * `VerificationToken` carry: `#[\SensitiveParameter]` on both the constructor and the factory so PHP
 * redacts the argument in stack traces, `__debugInfo()` masking the property so `var_dump()` and
 * every dumper built on it print `***`, a single deliberately uncomfortable reader, and deliberately
 * **no `__toString()`** — see `reveal()` and the note below it.
 */
final readonly class ResetToken
{
    /**
     * Exactly 43 characters: 32 CSPRNG bytes, base64url, unpadded. Public because two call sites
     * need to quote the same number rather than each remembering it — the failure message in
     * `InvalidResetToken::malformed()` and the `RandomResetTokenGenerator` adapter, whose byte count
     * is 43 characters' worth *because* of this constant. Notably **not** the route, which does no
     * format checking at all; see the class docblock for why.
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
     * method exists to refuse. Guessing is worse here than it was for verification: silently
     * "repairing" input on the way to an account-takeover primitive is how a lenient parser becomes
     * an attack surface, and the honest recovery — click the link again, or request a new one — is
     * one keystroke away for the legitimate user.
     */
    public static function fromString(#[\SensitiveParameter] string $value): self
    {
        if (1 !== preg_match(self::FORMAT, $value)) {
            throw InvalidResetToken::malformed(self::LENGTH);
        }

        return new self($value);
    }

    /**
     * The one and only reader, named to be uncomfortable — the same name `PlainPassword` and
     * `VerificationToken` use, on purpose: one verb across the whole context means one grep finds
     * every place a secret is unwrapped, whereas two synonyms mean a reviewer has to remember both.
     *
     * There are exactly two legitimate call sites, and it is worth knowing which: the
     * `ResetTokenGenerator` adapter, which digests it, and the `PasswordResetMailer` adapter, which
     * builds the link. Both are Infrastructure, both are the outermost edge of the flow, and a third
     * call site appearing in a diff is a design question, not a detail. (The controller that stashes
     * the token in the session handles the raw submitted string, never this object — it has not been
     * validated yet at that point.)
     *
     * AND DELIBERATELY NO `__toString()`. An implicit string conversion is precisely how a secret
     * ends up somewhere nobody chose to put it: `"reset for {$token}"` in a log line, `{{ token }}`
     * in a template, a string key in an array that gets dumped, a query parameter built by
     * concatenation. Every one of those compiles silently if the magic method exists. Without it,
     * each is a `TypeError` at the moment it is written, in the diff, in front of the person who
     * wrote it — the cheapest place a leak can possibly be caught. A unit test asserts the absence
     * by reflection, because "we did not add a method" is otherwise invisible to review.
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
