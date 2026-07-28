<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Identity\Entity;

use App\Domain\Identity\Entity\EmailVerificationRequest;
use App\Domain\Identity\Event\EmailVerificationRequested;
use App\Domain\Identity\Exception\EmailVerificationLinkAlreadyRedeemed;
use App\Domain\Identity\Exception\EmailVerificationLinkExpired;
use App\Domain\Identity\Exception\EmailVerificationTokenMismatch;
use App\Domain\Identity\ValueObject\Email;
use App\Domain\Identity\ValueObject\EmailVerificationRequestId;
use App\Domain\Identity\ValueObject\HashedVerificationToken;
use App\Domain\Identity\ValueObject\UserId;
use App\Domain\Identity\ValueObject\VerificationToken;
use PHPUnit\Framework\TestCase;

/**
 * Pure Domain unit tests for the `EmailVerificationRequest` aggregate — no kernel boot, no
 * persistence. Every instant is supplied explicitly, never read from the wall clock, which is what
 * makes the expiry-boundary assertions exact rather than "roughly a day".
 */
final class EmailVerificationRequestTest extends TestCase
{
    private const string ID = '018f5b2a-0000-7000-8000-000000000000';
    private const string USER_ID = '018f5b2a-0000-7000-8000-000000000001';

    /**
     * AC-1: `expiresAt` is derived from `issuedAt`, never taken as an independent parameter, and is
     * exactly +86400 seconds (`EmailVerificationRequest::LIFETIME_SECONDS`) — invariant I-8.
     */
    public function testIssueDerivesExpiresAtAtExactlyLifetimeSecondsAfterIssuedAt(): void
    {
        $issuedAt = new \DateTimeImmutable('2026-07-26T10:00:00+00:00');

        $request = $this->issueRequest(issuedAt: $issuedAt);

        self::assertSame(EmailVerificationRequest::LIFETIME_SECONDS, $request->expiresAt()->getTimestamp() - $issuedAt->getTimestamp());
        self::assertEquals($issuedAt, $request->issuedAt());
    }

    public function testIssueSetsTheIdUserIdAndTokenHashFromTheGivenArguments(): void
    {
        $id = EmailVerificationRequestId::fromString(self::ID);
        $userId = UserId::fromString(self::USER_ID);
        $hash = $this->aHash();

        $request = EmailVerificationRequest::issue($id, $userId, $hash, new \DateTimeImmutable('2026-07-26T10:00:00+00:00'));

        self::assertTrue($request->id()->equals($id));
        self::assertTrue($request->userId()->equals($userId));
        self::assertTrue($request->tokenHash()->equals($hash));
        self::assertNull($request->redeemedAt());
        self::assertFalse($request->isRedeemed());
    }

    /**
     * AC-26: issuing dispatches exactly one `EmailVerificationRequested`, carrying the request id,
     * the user id, `issuedAt` and `expiresAt` — and the payload contains no token and no email
     * address (there is no field to carry either, which this test asserts by exhaustively reading
     * every accessor the event exposes).
     */
    public function testIssueRecordsExactlyOneEmailVerificationRequestedEventWhosePayloadCarriesNoSecret(): void
    {
        $id = EmailVerificationRequestId::fromString(self::ID);
        $userId = UserId::fromString(self::USER_ID);
        $issuedAt = new \DateTimeImmutable('2026-07-26T10:00:00+00:00');

        $request = EmailVerificationRequest::issue($id, $userId, $this->aHash(), $issuedAt);
        $events = $request->releaseEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(EmailVerificationRequested::class, $events[0]);
        self::assertTrue($events[0]->requestId()->equals($id));
        self::assertTrue($events[0]->userId()->equals($userId));
        self::assertEquals($issuedAt, $events[0]->issuedAt());
        self::assertEquals($request->expiresAt(), $events[0]->expiresAt());

        // Exhaustive by construction: `EmailVerificationRequested` declares exactly four
        // constructor-promoted properties (`requestId`, `userId`, `issuedAt`, `expiresAt` —
        // `occurredAt()` is a method returning `issuedAt`, not a fifth property), and none of their
        // *exact* types is `VerificationToken` or `Email`. Asserted by reflection, comparing full
        // type names rather than substrings — `EmailVerificationRequestId` legitimately contains the
        // word "Email" and must not trip a naive `str_contains` check — so a future field added to
        // the event is caught here rather than trusted to a docblock.
        $properties = (new \ReflectionClass(EmailVerificationRequested::class))->getProperties();
        self::assertCount(4, $properties);

        $propertyTypeNames = array_map(
            static fn (\ReflectionProperty $p): ?string => $p->getType() instanceof \ReflectionNamedType ? $p->getType()->getName() : null,
            $properties,
        );

        self::assertNotContains(VerificationToken::class, $propertyTypeNames);
        self::assertNotContains(Email::class, $propertyTypeNames);
    }

    public function testRedeemWithTheRightHashSetsRedeemedAt(): void
    {
        $hash = $this->aHash();
        $request = $this->issueRequest(hash: $hash);
        $redeemedAt = new \DateTimeImmutable('2026-07-26T11:00:00+00:00');

        $request->redeem($hash, $redeemedAt);

        self::assertTrue($request->isRedeemed());
        self::assertEquals($redeemedAt, $request->redeemedAt());
    }

    /**
     * `redeem()` records no event: the fact worth publishing is `UserEmailVerified`, and it belongs
     * to `User`, which raises it from its own `verifyEmail()`.
     */
    public function testRedeemRecordsNoEvent(): void
    {
        $hash = $this->aHash();
        $request = $this->issueRequest(hash: $hash);
        $request->releaseEvents(); // discard the EmailVerificationRequested from issue()

        $request->redeem($hash, new \DateTimeImmutable('2026-07-26T11:00:00+00:00'));

        self::assertSame([], $request->releaseEvents());
    }

    /**
     * AC-9 / invariant I-9: a request is redeemed at most once. A second `redeem()` call throws,
     * even with the correct hash and a valid instant — there is no un-redeem operation.
     */
    public function testASecondRedeemThrowsEmailVerificationLinkAlreadyRedeemed(): void
    {
        $hash = $this->aHash();
        $request = $this->issueRequest(hash: $hash);
        $request->redeem($hash, new \DateTimeImmutable('2026-07-26T11:00:00+00:00'));

        $this->expectException(EmailVerificationLinkAlreadyRedeemed::class);

        $request->redeem($hash, new \DateTimeImmutable('2026-07-26T12:00:00+00:00'));
    }

    public function testASecondRedeemLeavesTheOriginalRedeemedAtUnchanged(): void
    {
        $hash = $this->aHash();
        $request = $this->issueRequest(hash: $hash);
        $firstRedeemedAt = new \DateTimeImmutable('2026-07-26T11:00:00+00:00');
        $request->redeem($hash, $firstRedeemedAt);

        try {
            $request->redeem($hash, new \DateTimeImmutable('2026-07-26T12:00:00+00:00'));
        } catch (EmailVerificationLinkAlreadyRedeemed) {
            // expected — the assertion of interest is below
        }

        self::assertEquals($firstRedeemedAt, $request->redeemedAt());
    }

    /**
     * Invariant I-10, pinned at the boundary instant itself: redeeming exactly *at* `expiresAt`
     * succeeds. Timestamps are whole-second, so the boundary instant is still within the stated
     * 24-hour lifetime.
     */
    public function testRedeemingExactlyAtExpiresAtSucceeds(): void
    {
        $issuedAt = new \DateTimeImmutable('2026-07-26T10:00:00+00:00');
        $hash = $this->aHash();
        $request = $this->issueRequest(hash: $hash, issuedAt: $issuedAt);

        $request->redeem($hash, $request->expiresAt());

        self::assertTrue($request->isRedeemed());
    }

    /**
     * The other half of the same boundary: one second past `expiresAt` throws. `isExpiredAt()`
     * uses a strict `>` deliberately (its own docblock), so this is the exact instant that
     * distinguishes "still valid" from "expired" — a `>=` would have shortened every link by up to
     * a second and this test would not catch it, which is why both sides are pinned.
     */
    public function testRedeemingOneSecondAfterExpiresAtThrowsEmailVerificationLinkExpired(): void
    {
        $issuedAt = new \DateTimeImmutable('2026-07-26T10:00:00+00:00');
        $hash = $this->aHash();
        $request = $this->issueRequest(hash: $hash, issuedAt: $issuedAt);
        $oneSecondPastExpiry = $request->expiresAt()->modify('+1 second');

        $this->expectException(EmailVerificationLinkExpired::class);

        $request->redeem($hash, $oneSecondPastExpiry);
    }

    public function testIsExpiredAtAgreesWithTheSameBoundary(): void
    {
        $issuedAt = new \DateTimeImmutable('2026-07-26T10:00:00+00:00');
        $request = $this->issueRequest(issuedAt: $issuedAt);

        self::assertFalse($request->isExpiredAt($request->expiresAt()));
        self::assertTrue($request->isExpiredAt($request->expiresAt()->modify('+1 second')));
    }

    /**
     * Invariant I-11: only the token whose digest matches this request's own may redeem it — a
     * near-miss (a completely different digest) throws rather than silently failing to match.
     */
    public function testRedeemingWithAMismatchedHashThrowsEmailVerificationTokenMismatch(): void
    {
        $request = $this->issueRequest(hash: HashedVerificationToken::fromString('the-correct-digest'));
        $wrongHash = HashedVerificationToken::fromString('a-completely-different-digest');

        $this->expectException(EmailVerificationTokenMismatch::class);

        $request->redeem($wrongHash, new \DateTimeImmutable('2026-07-26T11:00:00+00:00'));
    }

    /**
     * AC-31: the near-miss case at its sharpest — a single character off — still throws. This is
     * the assertion that actually distinguishes `hash_equals` from `===`'s short-circuiting, since a
     * completely different string would fail either comparison.
     */
    public function testRedeemingWithAOneCharacterOffHashThrowsEmailVerificationTokenMismatch(): void
    {
        $request = $this->issueRequest(hash: HashedVerificationToken::fromString('the-correct-digest-value'));
        $nearMiss = HashedVerificationToken::fromString('the-correct-digest-valuE');

        $this->expectException(EmailVerificationTokenMismatch::class);

        $request->redeem($nearMiss, new \DateTimeImmutable('2026-07-26T11:00:00+00:00'));
    }

    public function testAMismatchedRedeemLeavesTheRequestUnredeemed(): void
    {
        $request = $this->issueRequest(hash: HashedVerificationToken::fromString('the-correct-digest'));
        $wrongHash = HashedVerificationToken::fromString('a-completely-different-digest');

        try {
            $request->redeem($wrongHash, new \DateTimeImmutable('2026-07-26T11:00:00+00:00'));
        } catch (EmailVerificationTokenMismatch) {
            // expected — the assertion of interest is below
        }

        self::assertFalse($request->isRedeemed());
        self::assertNull($request->redeemedAt());
    }

    /**
     * The check ordering is part of the contract: already-redeemed (I-9) is reported even when the
     * *also*-true expiry or mismatch conditions would otherwise apply, because it is a statement
     * about this object's own history and holds regardless of the clock or the presented token.
     */
    public function testAnAlreadyRedeemedRequestReportsAlreadyRedeemedEvenWhenAlsoExpiredAndMismatched(): void
    {
        $issuedAt = new \DateTimeImmutable('2026-07-26T10:00:00+00:00');
        $hash = $this->aHash();
        $request = $this->issueRequest(hash: $hash, issuedAt: $issuedAt);
        $request->redeem($hash, new \DateTimeImmutable('2026-07-26T11:00:00+00:00'));

        $this->expectException(EmailVerificationLinkAlreadyRedeemed::class);

        // Both well past expiresAt AND presented with the wrong hash — still reports "already
        // redeemed", per the documented check order.
        $request->redeem(HashedVerificationToken::fromString('a-totally-different-digest'), new \DateTimeImmutable('2030-01-01T00:00:00+00:00'));
    }

    public function testReleaseEventsEmptiesTheBufferSoASecondCallReturnsAnEmptyList(): void
    {
        $request = $this->issueRequest();

        $request->releaseEvents();
        $second = $request->releaseEvents();

        self::assertSame([], $second);
    }

    private function issueRequest(
        ?HashedVerificationToken $hash = null,
        ?\DateTimeImmutable $issuedAt = null,
    ): EmailVerificationRequest {
        return EmailVerificationRequest::issue(
            EmailVerificationRequestId::fromString(self::ID),
            UserId::fromString(self::USER_ID),
            $hash ?? $this->aHash(),
            $issuedAt ?? new \DateTimeImmutable('2026-07-26T10:00:00+00:00'),
        );
    }

    private function aHash(): HashedVerificationToken
    {
        return HashedVerificationToken::fromString('opaque-test-digest-value');
    }
}
