<?php

declare(strict_types=1);

namespace App\Domain\Identity\Port;

use App\Domain\Identity\ValueObject\HashedVerificationToken;
use App\Domain\Identity\ValueObject\VerificationToken;

/**
 * Mints verification tokens and digests them.
 *
 * TWO METHODS, ONE PORT, ON PURPOSE — and this is the interesting design decision in an otherwise
 * unremarkable interface. The instinct that produced `PasswordHasher` (one verb, one port) would
 * split this into a `VerificationTokenGenerator` and a `VerificationTokenHasher`, on the grounds
 * that generating and hashing are different jobs. Resist it.
 *
 * The entropy source and the digest are **a single cryptographic decision**: the reason a fast
 * SHA-256 is the right digest here is that the input is 256 bits of CSPRNG output (ADR-0009,
 * decision 2), and that argument collapses the instant the generator changes. Split them into two
 * interfaces and nothing stops a future adapter pairing a weakened generator — a timestamp, a
 * counter, a truncated draw — with the same fast digest, while satisfying both contracts perfectly
 * and passing every test either one has. Held together, the two choices have to be read, changed and
 * reviewed in one class, where the dependency between them is visible.
 *
 * A rule of thumb worth keeping: interface segregation separates *responsibilities*, not
 * *co-dependent halves of one decision*. Splitting the second kind produces contracts that are each
 * individually satisfiable and jointly wrong.
 *
 * The Domain names no algorithm. `VerificationToken` states what a token looks like (43 URL-safe
 * characters), `HashedVerificationToken` states only that a digest is non-empty and fits the column,
 * and everything between those two facts belongs to the adapter.
 */
interface VerificationTokenGenerator
{
    /**
     * A fresh, unpredictable token. Every call must return a new value from a cryptographically
     * secure source — the whole security of the flow reduces to this sentence.
     */
    public function generate(): VerificationToken;

    /**
     * The digest that will be stored and compared.
     *
     * Deterministic and unsalted, unlike a password hash: the redemption lookup finds a request *by*
     * this value, so the same token must always produce the same digest. That is safe here for the
     * reason a salt exists to defeat elsewhere — there is no dictionary of likely 256-bit random
     * values to precompute.
     */
    public function hash(VerificationToken $token): HashedVerificationToken;
}
