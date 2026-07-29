<?php

declare(strict_types=1);

namespace App\Domain\Identity\Port;

use App\Domain\Identity\Entity\PasswordResetRequest;
use App\Domain\Identity\ValueObject\HashedResetToken;
use App\Domain\Identity\ValueObject\PasswordResetRequestId;
use App\Domain\Identity\ValueObject\UserId;

/**
 * The collection of PasswordResetRequests, as the Domain wants to talk about it.
 *
 * One repository per aggregate root — this is the context's third, and its existence is the clearest
 * signal that `PasswordResetRequest` really is a root rather than a few columns on `User`. The
 * interface is declared here and implemented in `Infrastructure`: dependency inversion is what lets
 * the Domain state its needs without knowing that Postgres exists.
 *
 * Note what is **absent**: there is no `findByUser()` returning everything, no way to reach a `User`
 * from here, and — the deliberate one — **no `deleteExpired()`**. A repository should expose the
 * queries the use cases actually make, not the queries a table could answer. The pruning job owed
 * later (`identity-challenge-pruning` on the roadmap, which two tables now owe) will add its own
 * method when it is written, with its own justification; adding it now would be a method with no
 * caller, no test that means anything, and a retention policy nobody has decided.
 */
interface PasswordResetRequestRepository
{
    /**
     * Mints the identity for a request that does not exist yet.
     *
     * The repository generates the id — not the database — so the aggregate is complete and valid
     * the moment it is constructed, before it has ever met a transaction (ADR-0007:
     * application-assigned UUIDv7, mapped with `<generator strategy="NONE"/>`). That is what lets
     * `issue()` record an event carrying its own id, and what lets a request be built and discarded
     * on a rate-limit refusal without having touched the database at all.
     *
     * It is also **why `PasswordResetRequestId` has no `generate()`**: choosing a UUID version and an
     * implementation (`symfony/uid`) is a vendor decision, and the Domain states only what a valid id
     * looks like.
     */
    public function nextIdentity(): PasswordResetRequestId;

    /**
     * Persists the aggregate, new or changed.
     *
     * **This method declares no `@throws`, and the contrast with `UserRepository::save()` is the
     * point.** There, the adapter is obliged to catch a unique-index violation on `email` and
     * translate it into `EmailAlreadyRegistered`, because two people racing to register the same
     * address is an ordinary Tuesday and "that address is taken" is a business answer.
     *
     * Here the only unique index is on `token_hash`, and a collision would mean two independent
     * draws of 256 CSPRNG bits landed on the same value. There is no business answer to an
     * impossibility, because it is not a business event; the honest response is a 500 and an alert.
     * Wrapping it in a domain exception would dress an impossibility up as a handled case and would
     * leave a `catch` in the handler that no test can ever reach and no reader can ever evaluate. A
     * failure contract should describe things that happen.
     */
    public function save(PasswordResetRequest $request): void;

    /**
     * Finds the request holding this digest, **whether or not it is redeemed, invalidated or
     * expired**.
     *
     * The filtering that is *not* here is deliberate, and it is the same rule slice 2 wrote down. A
     * repository that silently skipped dead rows would look tidier at the call site and would be a
     * genuine bug: it would make "this link expired an hour ago", "a newer request superseded this
     * one", "this link was already used" and "this token was never issued" produce the **same
     * `null`**, and the system would lose the ability to tell them apart at the exact moment it most
     * wants to — in a log, in a test, and in any future decision about which of them is worth an
     * alert.
     *
     * That the *visitor* sees one identical response for all of them (AC-16) is a **presentation**
     * policy, chosen to defeat account enumeration, and presentation policy that lives three layers
     * out is never a reason to destroy information down here. Business state is judged by the
     * aggregate — `assertRedeemableWith()`, `isExpiredAt()` — because a repository that judges
     * business state is an aggregate with a SQL accent.
     */
    public function findByTokenHash(HashedResetToken $hash): ?PasswordResetRequest;

    /**
     * How many requests this user has been issued since `$since`.
     *
     * This is the anti-abuse rule I-21 in the only form it can take. "At most three per rolling
     * hour" spans *instances*, so no single aggregate can enforce it — an aggregate only ever sees
     * itself — exactly as email uniqueness (I-6) spans all users. The count lives here and the
     * comparison against `PasswordResetRequest::MAX_ISSUES_PER_HOUR` lives in the handler, which
     * makes it **before** the invalidation sweep so that a spammed form cannot kill a victim's
     * in-flight link.
     *
     * The window is passed in rather than assumed, because "an hour ago" is a value from the `Clock`
     * port and a repository that computed it would be reaching for the wall clock — the one thing
     * the `Clock` port exists to prevent.
     *
     * Unlike a unique index, this cannot be made race-free: two simultaneous submissions can both
     * read three and both write. Accepted knowingly — the worst case is a fourth email, and the
     * per-IP limiter at the HTTP boundary is the second layer.
     */
    public function countIssuedForUserSince(UserId $userId, \DateTimeImmutable $since): int;

    /**
     * The user's requests that are still **structurally open**: neither redeemed nor invalidated.
     *
     * THIS METHOD FILTERS AND `findByTokenHash()` DOES NOT, AND THE DISTINCTION IS A RULE RATHER
     * THAN A MOOD. It filters on **structure** — `redeemed_at IS NULL AND invalidated_at IS NULL`,
     * which is the aggregate's own recorded state, two columns it wrote itself. It must never filter
     * on **judgement**: expiry is not a column, it is a comparison against an instant, and an instant
     * comes from the `Clock` port, which a repository must not reach for. A repository that knew what
     * time it was would be able to answer questions whose truth changes between the query and the
     * assertion, and no test could pin it.
     *
     * So **expired-but-outstanding rows are returned and invalidated along with the rest**. That is
     * harmless — invalidating a request that was going to die of old age anyway costs one write and
     * makes the reason it stopped being live explicit — and it keeps clock arithmetic out of SQL,
     * which is worth much more than the write.
     *
     * The caller is `RequestPasswordResetHandler`'s reissue sweep (ADR-0011 decision 4), and it
     * invalidates each returned request **through the aggregate**, never with a bulk DQL `UPDATE`.
     * The result is bounded by `PasswordResetRequest::MAX_ISSUES_PER_HOUR`, so N ≤ 3 in practice —
     * which is what makes the principled choice also the cheap one.
     *
     * @return list<PasswordResetRequest> ordered by `issuedAt`; empty when the user has none open
     */
    public function findOutstandingForUser(UserId $userId): array;
}
