<?php

declare(strict_types=1);

namespace App\Domain\Identity\Entity;

use App\Domain\Identity\Event\PasswordResetRequested;
use App\Domain\Identity\Exception\PasswordResetLinkAlreadyUsed;
use App\Domain\Identity\Exception\PasswordResetLinkExpired;
use App\Domain\Identity\Exception\PasswordResetLinkInvalidated;
use App\Domain\Identity\Exception\PasswordResetTokenMismatch;
use App\Domain\Identity\ValueObject\HashedResetToken;
use App\Domain\Identity\ValueObject\PasswordResetRequestId;
use App\Domain\Identity\ValueObject\UserId;
use App\Domain\Shared\Event\RecordsEvents;

/**
 * The `Identity` context's **third aggregate root**: one issued challenge that lets exactly one
 * person set a new password on one account without knowing the old one.
 *
 * READ THIS BEFORE "TIDYING UP" THE DUPLICATION. Line for line, this file is roughly **80%
 * identical to `EmailVerificationRequest`**, which sits in this same directory. Both hold a UUID, a
 * `UserId`, a digest and some timestamps; both are created by `issue()`; both derive `expiresAt`;
 * both compare `$at` against it with a strict `>`. The sameness is real, it is deliberate, and it is
 * **not** a candidate for a shared `Challenge` base class or a shared trait (ADR-0011 decision 9,
 * technical plan §*Reuse vs duplication*).
 *
 * The reason is one sentence: **the two classes share a *shape*, not *behaviour*, and a base class
 * abstracts behaviour or it abstracts nothing worth having.** The 80% overlap is a coincidence of
 * *storage* — of what these two concepts happen to need written down — while every rule that makes
 * either class interesting points the opposite way from its twin:
 *
 * | Rule | `EmailVerificationRequest` | `PasswordResetRequest` |
 * |---|---|---|
 * | Replaying a redeemed challenge | friendly no-op | **refused** (see `redeem()`) |
 * | Issuing a new challenge | leaves outstanding links alive | **invalidates them** (see `invalidate()`) |
 * | What burns the challenge | the GET on the link | **the POST that sets the password** (see `assertRedeemableWith()`) |
 * | Save order across the two aggregates | user first, request second | **request first, user second** (see `ResetPasswordWithTokenHandler`) |
 *
 * A shared base would have to guess on all four. Any guess it made would be right for one subclass
 * and a latent security bug in the other — and the one it would be wrong for is **this** one, where
 * "wrong" spells out as a replayable account-takeover primitive. Each of the four differences
 * therefore carries a comment at its own call site saying *why* it is inverted, not merely what it
 * does, because a future reader diffing the two files will find four differences and, absent
 * reasons, will eventually "fix" one.
 *
 * WHY IT IS ITS OWN AGGREGATE (ADR-0011 decision 1). Same test slice 2 applied, run again rather
 * than inherited: an aggregate boundary is drawn where a rule must be true at the end of **every**
 * transaction, so the question is whether the rule spanning this challenge and its `User` tolerates
 * a gap. Redemption changes two things — this request becomes redeemed, and the account's password
 * changes — and the crash windows are *not* symmetric:
 *
 * - **User first, request second** (slice 2's order) → a crash leaves a changed password and a
 *   **live token**, which can set yet another password. Rejected.
 * - **Request first, user second** → a crash leaves a burnt token and an unchanged password. The
 *   user asks for another link. Annoying; entirely safe. Adopted.
 *
 * Because a safe ordering exists, the rule does not need one transaction, and two aggregates remain
 * correct under Vernon's "one aggregate per transaction, eventual consistency between them". Note
 * what just happened: ADR-0009 decision 4's rule — *pick the order whose crash window is benign, and
 * write down why at the call site* — produced the **opposite** answer from the same input. A rule
 * that gives different answers to different questions is a rule; one that always gives the same
 * answer is a habit.
 *
 * This aggregate references its user **by `UserId`, never by a `User` object** (ADR-0009 decision 4,
 * AC-40). An object reference would let any caller holding this challenge reach across the boundary
 * and mutate the account through it, and it would tempt Doctrine into an association whose cascades
 * quietly decide transactional semantics on our behalf.
 *
 * Like every aggregate in the context, it never calls `new \DateTimeImmutable()`: every instant
 * arrives as a parameter, sourced from the `Clock` port by the Application handler. That is what
 * turns "expired one second ago" from a race against the wall clock into an assertable fact.
 *
 * **Invariants held here** *(the full table, including the ones held elsewhere, is in the technical
 * plan; I-20, I-21 and I-23 span instances or aggregates and are enforced by the handlers)*:
 *
 * - **I-14** — a request always carries a non-empty token hash and belongs to exactly one user.
 *   Held by `issue()`'s signature: it accepts `HashedResetToken` and `UserId`, never strings, which
 *   is also the proof that this class has never held a plaintext token.
 * - **I-15** — `expiresAt` is always exactly `issuedAt + LIFETIME_SECONDS`, and therefore always
 *   after `issuedAt`. Derived inside `issue()`; not a parameter, so no caller can shorten or extend
 *   it.
 * - **I-16** — a request is redeemed **at most once**: `redeemedAt` moves `null → instant` and never
 *   back. Held by `assertRedeemableWith()`; there is no un-redeem.
 * - **I-17** — a request is **never both** redeemed and invalidated. Held by two guards in two
 *   methods (`assertRedeemableWith()` refuses an invalidated request, `invalidate()` refuses a
 *   redeemed one) rather than by a state machine — see the note on the two nullable timestamps below.
 * - **I-18** — an expired request can never be redeemed. Held by `isExpiredAt()`, compared before
 *   anything mutates, with a strict `>`.
 * - **I-19** — only the token whose digest matches may redeem the request. Held by `hash_equals`
 *   inside `HashedResetToken::equals()`.
 */
final class PasswordResetRequest
{
    use RecordsEvents;

    /**
     * How long a reset link stays valid: **one hour** — one twenty-fourth of a verification link,
     * and the ratio is the point rather than a coincidence (ADR-0011 decision 3).
     *
     * A *verification* token proves reachability: if one leaks, an attacker verifies an address they
     * would already have had to control in order to receive it. A *reset* token **is an
     * account-takeover primitive** — whoever holds it owns the account until it is redeemed or
     * expires. Same mechanism, different blast radius, so the exposure window should be as short as
     * usability allows and 24 hours is indefensible for it.
     *
     * The floor is set by **delivery latency, not by patience**. A queued message, an external relay
     * and a recipient's greylisting can easily cost five to fifteen minutes before the mail is even
     * readable, and someone who requests at a desk and reads on a phone needs slack on top of that.
     * Fifteen minutes — the tightest number anyone proposes — is uncomfortable against that floor;
     * one hour clears it with room, and sits inside the range OWASP ASVS calls short-lived.
     *
     * One hour is also exactly what `symfonycasts/reset-password-bundle` chose, and matching it is
     * deliberate: **we are declining the bundle's packaging, not claiming better judgement about its
     * parameters** (ADR-0011 decision 1).
     *
     * Like slice 2's, this is a **domain constant, not configuration**. It is policy — "how long do
     * we consider a recovery challenge fresh?" — and policy expressed as an env var is policy no
     * unit test can pin and that differs between environments for no stated reason. The accepted
     * cost is that changing it is a deploy. Public because the mail template, the tests and the
     * pruning job owed later must all quote one number rather than each remembering it.
     */
    public const int LIFETIME_SECONDS = 3600;

    /**
     * At most **three** issuances per user per rolling hour — where verification allows five.
     *
     * Both reasons for the lower cap are about the **mail**, not about the token:
     *
     * 1. A reset mail is the *alarming* one. It is the message an attacker uses to flood a victim's
     *    inbox with "someone tried to reset your password", and every extra one is free anxiety
     *    delivered on the attacker's behalf.
     * 2. Each new request **kills the previous link** (see `invalidate()`), so a high cap actively
     *    degrades the experience: the more resends we allow, the more often a user clicks the first
     *    mail they find and is told it is dead.
     *
     * Three covers a mistyped address, a mail that landed in spam, and one more try. It is not
     * enough to be a weapon.
     *
     * As in slice 2, note carefully that this is **not an aggregate invariant** (I-21). An instance
     * cannot count its siblings; the rule spans instances, exactly like email uniqueness (I-6). The
     * constant is declared here, where the policy belongs, and *enforced* by
     * `RequestPasswordResetHandler` over `PasswordResetRequestRepository::countIssuedForUserSince()`,
     * where counting is possible — which means it can lose a race under concurrent submissions. The
     * worst case is a fourth email, and the per-IP limiter at the HTTP boundary is the second layer.
     */
    public const int MAX_ISSUES_PER_HOUR = 3;

    /**
     * Properties are plain `private`, not `readonly`, even where nothing ever reassigns them
     * (`id`, `userId`, `tokenHash`, `issuedAt`, `expiresAt`): Doctrine hydrates this object by
     * reflection and readonly properties still trip its refresh/proxy paths (slice 1's reason,
     * inherited). Immutability from the outside is guaranteed by the private constructor and the
     * total absence of setters, which is the guarantee that matters — only `redeem()` and
     * `invalidate()` write anything at all.
     *
     * WHY TWO NULLABLE TIMESTAMPS RATHER THAN ONE `closedAt` PLUS A REASON ENUM. The compressed
     * shape looks tidier and is worse on three counts. First, the two columns answer questions worth
     * asking **separately** — "how many resets actually completed?" versus "how many were superseded
     * by a newer request?" — and each is a predicate a query wants on its own; `findOutstandingForUser()`
     * needs exactly `redeemedAt IS NULL AND invalidatedAt IS NULL`. Second, invariant I-16 then reads
     * directly off **one column** instead of off a column-plus-discriminator pair, so "redeemed at
     * most once" is a null check rather than a null check qualified by an enum comparison. Third, the
     * mutual exclusion I-17 becomes **two guards in two methods** — `assertRedeemableWith()` refuses
     * an invalidated request, `invalidate()` refuses a redeemed one — rather than a state machine
     * with transitions to enumerate, test and keep in step with the columns.
     */
    private function __construct(
        private PasswordResetRequestId $id,
        private UserId $userId,
        private HashedResetToken $tokenHash,
        private \DateTimeImmutable $issuedAt,
        private \DateTimeImmutable $expiresAt,
        private ?\DateTimeImmutable $redeemedAt,
        private ?\DateTimeImmutable $invalidatedAt,
    ) {
    }

    /**
     * The one and only way a password-reset request comes into existence.
     *
     * A named creation method rather than a public constructor because "issue" is the word the
     * glossary uses for a business act, while `new PasswordResetRequest(...)` is a memory allocation
     * with no meaning. It takes a `HashedResetToken`, never a `ResetToken` and certainly never a
     * string — **invariant I-14 enforced by the type system**, and the reason this class can be
     * trusted, by signature alone, never to have held the plaintext.
     *
     * `expiresAt` is **derived here and is not a parameter** (invariant I-15). That is the whole
     * point of the method: if callers could pass a lifetime, then "a reset link is valid for one
     * hour" would not be a rule, it would be a default — and the first caller in a hurry would
     * quietly become the second policy, on the flow where the policy is the security control.
     *
     * The arithmetic uses `add()` with an explicit `\DateInterval` rather than string parsing, for
     * slice 2's reason: `\DateTimeImmutable::add()` leaves the timezone and the sub-second field of
     * `$issuedAt` untouched, so the result **inherits** both the UTC zone and the whole-second
     * precision the `Clock` port mandates (ADR-0007) rather than reinterpreting them — no rounding,
     * no drift, and nothing for a locale or a `date.timezone` setting to have an opinion about.
     * (`strtotime` is banned here: it is a natural-language grammar, and dates are not a place for
     * one.)
     *
     * It records `PasswordResetRequested`, whose payload deliberately carries **no token and no
     * address** (AC-32); see that event for the argument.
     */
    public static function issue(
        PasswordResetRequestId $id,
        UserId $userId,
        HashedResetToken $tokenHash,
        \DateTimeImmutable $issuedAt,
    ): self {
        $expiresAt = $issuedAt->add(new \DateInterval(\sprintf('PT%dS', self::LIFETIME_SECONDS)));

        $request = new self($id, $userId, $tokenHash, $issuedAt, $expiresAt, null, null);
        $request->recordThat(new PasswordResetRequested($id, $userId, $issuedAt, $expiresAt));

        return $request;
    }

    /**
     * The whole redemption rule, **with zero mutation**: may this presented token set a password on
     * this request, at this instant?
     *
     * INVERSION #3 vs `EmailVerificationRequest`, AND THE REASON IT EXISTS AT ALL. Slice 2 has no
     * method like this, because there the **GET on the link burns the challenge**. Here the GET
     * *cannot* burn anything, and the reason is not taste — it is **mail-scanner prefetch**. Security
     * appliances and mail clients fetch every URL in an incoming message before a human sees it. If
     * `GET /reset-password/{token}` redeemed, the scanner would consume the link and the actual user
     * would open a dead one, every time, on the flow people reach when they are already locked out.
     * So the GET only **judges** — it calls this method, which touches nothing — and the **POST that
     * sets the password** is what burns the token (`redeem()`).
     *
     * Slice 2 could afford the opposite arrangement because *its* replay is harmless: verifying an
     * already-verified address is a no-op, so the scanner's prefetch and the human's click compose
     * to the same end state. Redemption here is destructive and repeatable, so the same design would
     * be a bug. **Do not "align" the two aggregates on this.**
     *
     * WHY THIS IS A `void`-OR-THROW METHOD RATHER THAN A `bool` PREDICATE. There are two entry
     * points — the GET's judgement and the POST's redemption — and they must apply **exactly one
     * rule with exactly one implementation**, or the day they drift is the day a link the GET called
     * good gets refused by the POST (or, far worse, the reverse). A `bool` would additionally
     * collapse four distinguishable causes into one **in the layer that has to keep them distinct**:
     * the log needs to know whether a link was superseded, spent, stale or forged, and so does every
     * unit test that claims to pin these branches. That the *visitor* sees one identical neutral
     * response for all four (AC-16) is a **presentation** policy chosen to defeat enumeration, and
     * presentation policy is never a reason to throw information away three layers down.
     *
     * **The order of the four checks is part of the contract**, not an accident of writing:
     *
     * 1. **Invalidated** first — the most recent thing to have happened to this request, and the one
     *    the user can act on ("you asked for a newer link; use that one").
     * 2. **Already used** second — a statement about this object's own history, true regardless of
     *    the clock or the token.
     * 3. **Expired** third, so a spent-and-expired link reports the more specific fact.
     * 4. **Token mismatch last, on purpose** — it is the only check that touches a secret, and there
     *    is no reason whatsoever to perform a cryptographic comparison for a request that could not
     *    have been redeemed anyway.
     *
     * Note that 1 and 2 are mutually exclusive by I-17; the order still has to be written down,
     * because "unreachable today" and "unreachable" are different words.
     *
     * @throws PasswordResetLinkInvalidated when a newer request superseded this one
     * @throws PasswordResetLinkAlreadyUsed when this request has already been redeemed
     * @throws PasswordResetLinkExpired     when `$at` is past `expiresAt`
     * @throws PasswordResetTokenMismatch   when the presented digest is not this request's
     */
    public function assertRedeemableWith(HashedResetToken $presented, \DateTimeImmutable $at): void
    {
        if (null !== $this->invalidatedAt) {
            throw PasswordResetLinkInvalidated::forRequest($this->id, $this->invalidatedAt);
        }

        if (null !== $this->redeemedAt) {
            throw PasswordResetLinkAlreadyUsed::forRequest($this->id, $this->redeemedAt);
        }

        if ($this->isExpiredAt($at)) {
            throw PasswordResetLinkExpired::forRequest($this->id, $this->expiresAt);
        }

        if (!$this->tokenHash->equals($presented)) {
            throw PasswordResetTokenMismatch::forRequest($this->id);
        }
    }

    /**
     * Burns the challenge: applies the full judgement and, if it holds, sets `redeemedAt` once and
     * forever (invariant I-16).
     *
     * INVERSION #1 vs `EmailVerificationRequest`: **a replay is refused, not absorbed.** Slice 2
     * treats "this link was already used" as a friendly no-op, and it is right to — mail scanners
     * prefetch, so the human's click is *often already the replay*, and punishing it would make a
     * correct flow look broken. That reasoning does not survive the change of effect. Setting a
     * password is **destructive and repeatable**: absorbing a replay would leave a spent link still
     * able to reach a password-setting form, which is precisely the primitive the single-use rule
     * exists to destroy. "Already used" is the honest answer here, and the prefetch problem is
     * solved instead by never burning the challenge on the GET (see `assertRedeemableWith()`) — so
     * nothing is lost by refusing.
     *
     * WHY THIS RE-CHECKS A HASH IT WAS JUST LOOKED UP BY. `PasswordResetRequestRepository::findByTokenHash()`
     * finds this request *by* `tokenHash`, so in today's only flow `$presented` is guaranteed to
     * match and the comparison is strictly redundant. It stays because **a repository query is a
     * convenience and an aggregate is a rule-holder.** "This token matches my challenge" is this
     * object's rule, and a second adapter, a future lookup by user id, a fixture or a console tool
     * must not be able to bypass it by arriving through a different door. An invariant that holds
     * only because of *how* the caller happened to find the object is not an invariant.
     *
     * **It records no event.** The fact the rest of the system cares about is not "a challenge was
     * burnt" but "**this account's password changed**" — and that fact belongs to `User`, which
     * raises `UserPasswordChanged` from its own `changePassword()`. Two events for one occurrence
     * would force every future listener to work out which of them it actually cared about; the
     * security-notification listener that is coming wants the account's fact, not this row's.
     *
     * @throws PasswordResetLinkInvalidated when a newer request superseded this one
     * @throws PasswordResetLinkAlreadyUsed when this request has already been redeemed
     * @throws PasswordResetLinkExpired     when `$at` is past `expiresAt`
     * @throws PasswordResetTokenMismatch   when the presented digest is not this request's
     */
    public function redeem(HashedResetToken $presented, \DateTimeImmutable $at): void
    {
        $this->assertRedeemableWith($presented, $at);

        $this->redeemedAt = $at;
    }

    /**
     * Supersedes this request: it can never be redeemed again, no matter how long it had left.
     *
     * INVERSION #2 vs `EmailVerificationRequest`: **issuing a new challenge kills the outstanding
     * ones here, and does not there** (ADR-0011 decision 4). This is not a new argument — it is
     * ADR-0009 decision 5's own carve-out being cashed in, and the clause it wrote down in advance
     * is what makes that check possible rather than a matter of memory.
     *
     * Slice 2 could decline invalidation because of a **specific property**: once *any* verification
     * token verifies the account, every other live token is inert, since redeeming one hits the
     * already-verified short-circuit. Reset has no such property. **Each live reset token is an
     * independent, repeatable chance to set a password**, so leaving old ones alive would mean a
     * single stolen link stays useful for its whole hour no matter how many times the legitimate
     * owner reissues. Hence `RequestPasswordResetHandler` sweeps `findOutstandingForUser()` through
     * *this* method — one aggregate at a time, never a bulk DQL `UPDATE`, because a bulk update
     * would set `invalidated_at` without the aggregate ever agreeing, making I-17 unenforceable and
     * parking a rule in SQL where no unit test can reach it. The sweep is bounded by
     * `MAX_ISSUES_PER_HOUR`, so N ≤ 3, which is what makes the principled choice also the cheap one.
     *
     * The general lesson, worth more than the rule: **when a design declines a safeguard because
     * some property makes it unnecessary, name the property rather than the conclusion** — that is
     * what lets a later slice check whether it still holds. Here it did not.
     *
     * **Redeemed is refused; already-invalidated returns early.** The asymmetry is invariant I-17,
     * the mutual exclusion, held by two guards in two methods rather than by a state machine: a
     * redeemed request must never also carry an `invalidatedAt`, because "how many resets completed"
     * would stop being answerable off one column. A second `invalidate()` is idempotent instead,
     * because the sweep is a loop over rows that a retry, a crash or a concurrent request can
     * legitimately run twice, and a re-run of it must be harmless. Note the deliberate consequence:
     * the *first* `invalidatedAt` wins, so the recorded instant is when the request actually stopped
     * being live.
     *
     * **IT RECORDS NO EVENT, AND THE REASON IS THE BAR RATHER THAN THE EFFORT** (AC-34). A
     * `PasswordResetRequestInvalidated` event would fail the same two-part test the other two events
     * clear: it must name a fact **a domain expert would recognise** without being taught the code,
     * and its payload must be complete without a second query. "A reset challenge was issued" and "a
     * password changed" clear it. "A row we wrote earlier was marked superseded by a row we wrote
     * just now" does not — it names a *bookkeeping step this system performs on itself*, not
     * something that happened in the business, and nothing outside this aggregate needs to know it.
     * An event bus filled with the internal consequences of other events is how "recorded history"
     * degrades into "a log with a serialiser".
     *
     * @throws PasswordResetLinkAlreadyUsed when this request has already been redeemed (I-17)
     */
    public function invalidate(\DateTimeImmutable $at): void
    {
        if (null !== $this->redeemedAt) {
            throw PasswordResetLinkAlreadyUsed::forRequest($this->id, $this->redeemedAt);
        }

        if (null !== $this->invalidatedAt) {
            return;
        }

        $this->invalidatedAt = $at;
    }

    public function isRedeemed(): bool
    {
        return null !== $this->redeemedAt;
    }

    public function isInvalidated(): bool
    {
        return null !== $this->invalidatedAt;
    }

    /**
     * Strictly *after* `expiresAt`, so the boundary instant itself is still valid (invariant I-18).
     *
     * The operator matters more than it looks. Timestamps are stored to whole-second precision
     * (ADR-0007: `datetimetz_immutable` discards microseconds at the *type*), so a `>=` here would
     * silently shorten **every** link by up to a second and would make "valid for one hour after
     * issuance" a lie in exactly the case someone bothers to test. The unit test pins both sides:
     * valid at `expiresAt`, expired at `expiresAt + 1 s` — asserting only one side would prove
     * nothing about which operator is actually in the code.
     */
    public function isExpiredAt(\DateTimeImmutable $at): bool
    {
        return $at > $this->expiresAt;
    }

    /**
     * True when this request could still be redeemed by the right token: not redeemed, not
     * superseded, not expired.
     *
     * This is the **positive** reading of the same three structural facts `assertRedeemableWith()`
     * judges, minus the token comparison — deliberately, because a caller asking "is this row still
     * alive?" is asking about the request, not presenting a credential. It is a convenience for
     * reporting and for tests; it is **not** the redemption gate, and no flow may use it as one. The
     * gate throws, because it must distinguish the four causes.
     */
    public function isLiveAt(\DateTimeImmutable $at): bool
    {
        return null === $this->redeemedAt
            && null === $this->invalidatedAt
            && !$this->isExpiredAt($at);
    }

    public function id(): PasswordResetRequestId
    {
        return $this->id;
    }

    public function userId(): UserId
    {
        return $this->userId;
    }

    public function tokenHash(): HashedResetToken
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

    public function invalidatedAt(): ?\DateTimeImmutable
    {
        return $this->invalidatedAt;
    }
}
