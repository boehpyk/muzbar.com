<?php

declare(strict_types=1);

namespace App\Domain\Identity\Port;

use App\Domain\Identity\ValueObject\HashedResetToken;
use App\Domain\Identity\ValueObject\ResetToken;

/**
 * Mints password-reset tokens and digests them.
 *
 * TWO METHODS, ONE PORT, ON PURPOSE. The instinct that produced `PasswordHasher` (one verb, one
 * port) would split this into a generator and a hasher, on the grounds that minting and digesting
 * are different jobs. Resist it — and resist it harder here than in slice 2, because the thing
 * behind this digest is the account rather than a mailbox claim.
 *
 * The entropy source and the digest are **a single cryptographic decision**. The reason a fast
 * SHA-256 is the *right* digest is that the input is 256 bits of CSPRNG output (ADR-0011 decision
 * 2); that argument collapses the instant the generator changes. Split them into two interfaces and
 * nothing stops a future adapter pairing a weakened generator — a timestamp, a counter, a truncated
 * draw, `uniqid()` — with the same fast digest, while satisfying **both contracts perfectly** and
 * passing every test either one has in isolation. Held together, the two choices must be read,
 * changed and reviewed in one class, where the dependency between them is visible.
 *
 * A rule of thumb worth keeping: interface segregation separates *responsibilities*, not
 * *co-dependent halves of one decision*. Splitting the second kind produces contracts that are each
 * individually satisfiable and jointly wrong.
 *
 * WHY THIS IS NOT MERGED WITH `VerificationTokenGenerator`, WHICH IT OTHERWISE MIRRORS EXACTLY. The
 * types differ, and that is not an accident to be smoothed away: merging the two ports would require
 * a shared token type, and a shared token type is precisely the abstraction this slice declines
 * (`ResetToken`, technical plan §*Reuse vs duplication*). **A verification token presented at the
 * reset endpoint must be a compile error**, not a lookup that quietly misses — the digests live in
 * different tables, so the miss would present as an ordinary expired link rather than as the
 * category error it is. Two near-identical interfaces are the cheap half of that guarantee.
 *
 * The Domain names no algorithm. `ResetToken` states what a token looks like (43 URL-safe
 * characters), `HashedResetToken` states only that a digest is non-empty and fits the column, and
 * everything between those two facts belongs to `RandomResetTokenGenerator` in `Infrastructure`.
 */
interface ResetTokenGenerator
{
    /**
     * A fresh, unpredictable token. Every call must return a new value from a cryptographically
     * secure source — the whole security of the flow reduces to this sentence, and on this flow the
     * account reduces to it too.
     */
    public function generate(): ResetToken;

    /**
     * The digest that will be stored and compared.
     *
     * Deterministic and unsalted, unlike a password hash: the redemption lookup finds a request *by*
     * this value, so the same token must always produce the same digest. That is safe here for the
     * reason a salt exists to defeat elsewhere — there is no dictionary of likely 256-bit random
     * values to precompute.
     */
    public function hash(ResetToken $token): HashedResetToken;
}
