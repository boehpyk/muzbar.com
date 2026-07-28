<?php

declare(strict_types=1);

namespace App\Domain\Identity\Entity;

use App\Domain\Identity\Event\EmailVerificationRequested;
use App\Domain\Identity\Exception\EmailVerificationLinkAlreadyRedeemed;
use App\Domain\Identity\Exception\EmailVerificationLinkExpired;
use App\Domain\Identity\Exception\EmailVerificationTokenMismatch;
use App\Domain\Identity\ValueObject\EmailVerificationRequestId;
use App\Domain\Identity\ValueObject\HashedVerificationToken;
use App\Domain\Identity\ValueObject\UserId;
use App\Domain\Shared\Event\RecordsEvents;

/**
 * The `Identity` context's **second aggregate root**: one issued attempt to prove that one User's
 * address is reachable.
 *
 * WHY THIS IS ITS OWN AGGREGATE AND NOT A FEW FIELDS ON `User` (ADR-0009, decision 3). This is the
 * modelling judgement of the slice, so it is argued here rather than assumed.
 *
 * The tempting shape is `User::$verificationTokenHash` plus `$verificationExpiresAt`. It is wrong on
 * two counts. The soft one is clock speed: a `User` lives for years and a challenge lives for a day,
 * so bolting the second onto the first grows the root for a concern that churns hundreds of times
 * faster, and parks a short-lived credential in the same row as the account's identity. Making it a
 * *collection* of challenges inside `User` is worse — every resend appends a row, so the root
 * acquires an unbounded collection, the classic aggregate smell, and every login risks hydrating it.
 *
 * The hard count is the invariant test, which is the part worth internalising: **an aggregate
 * boundary is drawn where a rule must be true at the end of every transaction.** So the question is
 * not "do these two things feel related?" — they obviously do — but "is there a rule spanning them
 * that cannot tolerate a gap?". Redemption changes two things: this request becomes redeemed, and
 * the user becomes verified. Order them **user first, request second** and every crash window is
 * benign:
 *
 * - Crash after verifying the user, before marking the request redeemed → a verified user and a
 *   still-live token. Redeeming that token later is a **no-op**, because the handler short-circuits
 *   on an already-verified user (AC-8). Nothing is wrong.
 * - The reverse order would leave a burnt token and an unverified user — somebody locked out of
 *   their own link, with no way back except the resend form.
 *
 * So the ordering is not cosmetic; it is *what makes two aggregates safe here*, and it is written
 * into `VerifyEmailWithTokenHandler` with a comment saying so. Because a safe ordering exists, the
 * rule does not require a single transaction, and Vernon's "modify one aggregate per transaction,
 * use eventual consistency between them" applies.
 *
 * *(A happy accident worth knowing and worth **not** relying on: with the Doctrine adapter both
 * aggregates are managed by one `EntityManager`, so the first `save()`'s flush actually commits both
 * changes together. The port's per-aggregate `save()` is an abstraction over a global flush. The
 * ordering above is what keeps the design correct the day that stops being true.)*
 *
 * The pay-off is visible in the diff of this slice: `User` is **not touched at all**. Its
 * `verifyEmail()`, `isEmailVerified()` and `isUsable()` already did the right thing.
 *
 * This aggregate references its user **by `UserId`, never by a `User` object** (AC-34). That is the
 * other half of the same idea: an object reference would let a caller reach across the boundary and
 * mutate a second aggregate through this one, and it would tempt Doctrine into an association whose
 * cascades quietly decide transactional semantics for us.
 *
 * Like `User`, it never calls `new \DateTimeImmutable()` — every instant arrives as a parameter,
 * sourced from the `Clock` port by the Application handler, which is what turns "expired one second
 * ago" from a race against the wall clock into an assertable fact.
 */
final class EmailVerificationRequest
{
    use RecordsEvents;

    /**
     * How long a verification link stays valid: 24 hours.
     *
     * This is a **domain constant, not configuration**, and that is deliberate (technical plan, Risk
     * 5). It is policy — "how long do we consider a mailbox challenge fresh?" — and policy expressed
     * as an env var is policy that no unit test can pin and that differs between environments for no
     * stated reason. The accepted cost is that changing it is a deploy. Public because the mail
     * template, the tests and the pruning job owed later all need to quote the same number rather
     * than each remembering it.
     */
    public const int LIFETIME_SECONDS = 86400;

    /**
     * At most five issuances per user per rolling hour.
     *
     * It lives on the aggregate because it is the same kind of knowledge as the lifetime — but note
     * carefully that it is **not an aggregate invariant** (I-12). An instance cannot count its
     * siblings; the rule spans instances, exactly like email uniqueness (I-6) in slice 1. So the
     * constant is declared here, where the policy belongs, and *enforced* by
     * `RequestEmailVerificationHandler` over `EmailVerificationRequestRepository::countIssuedForUserSince()`,
     * where the counting is possible.
     *
     * That split has a consequence worth stating rather than discovering: the check can lose a race
     * under concurrent submissions. The worst case is a sixth email, and the per-IP limiter at the
     * HTTP boundary is the second layer. The asymmetry between a rule an aggregate can guarantee and
     * a rule it can only declare is the lesson here, not a defect.
     */
    public const int MAX_ISSUES_PER_HOUR = 5;

    /**
     * Properties are plain `private`, not `readonly`, even where nothing ever reassigns them
     * (`id`, `userId`, `tokenHash`, `issuedAt`, `expiresAt`): Doctrine hydrates this object by
     * reflection and readonly properties still trip its refresh/proxy paths. Immutability from the
     * outside is guaranteed by the private constructor and the total absence of setters, which is
     * the guarantee that matters — and `redeem()` is the only method in the class that writes
     * anything at all.
     */
    private function __construct(
        private EmailVerificationRequestId $id,
        private UserId $userId,
        private HashedVerificationToken $tokenHash,
        private \DateTimeImmutable $issuedAt,
        private \DateTimeImmutable $expiresAt,
        private ?\DateTimeImmutable $redeemedAt,
    ) {
    }

    /**
     * The one and only way a verification request comes into existence.
     *
     * A named creation method rather than a public constructor because "issue" is the word the
     * glossary uses for a business act, while `new EmailVerificationRequest(...)` is a memory
     * allocation with no meaning. It takes a `HashedVerificationToken`, never a `VerificationToken`
     * and certainly never a string — invariant I-7 enforced by the type system, and the reason this
     * class can be trusted, by signature alone, never to have held the plaintext.
     *
     * `expiresAt` is **derived here and is not a parameter** (invariant I-8). That is the whole
     * point of the method: if callers could pass a lifetime, then "a verification link is valid for
     * 24 hours" would not be a rule, it would be a default — and the first caller in a hurry would
     * quietly become the second policy.
     *
     * The arithmetic uses `add()` with an explicit `\DateInterval` rather than string parsing.
     * `\DateTimeImmutable::add()` leaves the timezone and the sub-second field of `$issuedAt`
     * untouched, so the result inherits both the UTC zone and the whole-second precision that the
     * `Clock` port mandates — no rounding, no drift, and nothing for a locale or a `date.timezone`
     * setting to reinterpret. (`strtotime` is banned here for that last reason: it is a natural
     * language grammar, and dates are not a place for one.)
     */
    public static function issue(
        EmailVerificationRequestId $id,
        UserId $userId,
        HashedVerificationToken $tokenHash,
        \DateTimeImmutable $issuedAt,
    ): self {
        $expiresAt = $issuedAt->add(new \DateInterval(\sprintf('PT%dS', self::LIFETIME_SECONDS)));

        $request = new self($id, $userId, $tokenHash, $issuedAt, $expiresAt, null);
        $request->recordThat(new EmailVerificationRequested($id, $userId, $issuedAt, $expiresAt));

        return $request;
    }

    /**
     * Burns the challenge: the presented token is checked against this request's digest and, if it
     * holds up, `redeemedAt` is set once and forever.
     *
     * **The order of the three checks is part of the contract**, not an accident of writing. Already
     * redeemed (I-9) comes first because it is a statement about this object's own history and is
     * true regardless of the clock or the token. Expiry (I-10) comes second, so an expired-and-used
     * link reports the more specific fact. The token comparison (I-11) comes last, because it is the
     * only one that touches a secret and there is no reason to perform it for a request that could
     * not be redeemed anyway. All three are indistinguishable to the visitor by design (AC-10 …
     * AC-12); they are distinguishable in a log and in a unit test, which is where the distinction
     * is worth anything.
     *
     * WHY THIS RE-CHECKS THE HASH IT WAS JUST LOOKED UP BY. `EmailVerificationRequestRepository::findByTokenHash()`
     * finds this request *by* `tokenHash`, so `$presented` is, in today's only flow, guaranteed to
     * match — the comparison is strictly redundant. It stays because **a repository query is a
     * convenience and an aggregate is a rule-holder.** "This token matches my challenge" is this
     * object's rule to enforce, and a second adapter, a future lookup by user id, a fixture or a
     * console tool must not be able to bypass it by arriving through a different door. An invariant
     * that only holds because of how the caller happened to find the object is not an invariant.
     *
     * **It records no event.** The fact that matters to the rest of the system is not "a challenge
     * was burnt" but "this account's address is verified" — and that fact belongs to `User`, which
     * raises `UserEmailVerified` from its own `verifyEmail()`. Two events for one occurrence would
     * force every future listener to work out which of them it actually cared about.
     *
     * @throws EmailVerificationLinkAlreadyRedeemed when this request has already been redeemed
     * @throws EmailVerificationLinkExpired         when `$at` is past `expiresAt`
     * @throws EmailVerificationTokenMismatch       when the presented digest is not this request's
     */
    public function redeem(HashedVerificationToken $presented, \DateTimeImmutable $at): void
    {
        if (null !== $this->redeemedAt) {
            throw EmailVerificationLinkAlreadyRedeemed::forRequest($this->id, $this->redeemedAt);
        }

        if ($this->isExpiredAt($at)) {
            throw EmailVerificationLinkExpired::forRequest($this->id, $this->expiresAt);
        }

        if (!$this->tokenHash->equals($presented)) {
            throw EmailVerificationTokenMismatch::forRequest($this->id);
        }

        $this->redeemedAt = $at;
    }

    public function isRedeemed(): bool
    {
        return null !== $this->redeemedAt;
    }

    /**
     * Strictly *after* `expiresAt`, so the boundary instant itself is still valid.
     *
     * The choice matters more than it looks: timestamps are stored to whole-second precision, so a
     * `>=` here would silently shorten every link by up to one second and would make "expires 24
     * hours after issuance" a lie in exactly the case someone bothers to test. The unit test pins
     * the boundary at `expiresAt` (valid) and `expiresAt + 1 s` (expired).
     */
    public function isExpiredAt(\DateTimeImmutable $at): bool
    {
        return $at > $this->expiresAt;
    }

    public function id(): EmailVerificationRequestId
    {
        return $this->id;
    }

    public function userId(): UserId
    {
        return $this->userId;
    }

    public function tokenHash(): HashedVerificationToken
    {
        return $this->tokenHash;
    }

    public function issuedAt(): \DateTimeImmutable
    {
        return $this->issuedAt;
    }

    public function expiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function redeemedAt(): ?\DateTimeImmutable
    {
        return $this->redeemedAt;
    }
}
